<?php
/*
Plugin Name: Custom Healthcheck (MU)
Description: Endpoint REST JSON para monitorear WordPress.
Author: Ramón Román Castro <ramonromancastro@gmail.com>
Version: 0.20250924.1
*/

// Genera una clave segura de 32 caracteres hexadecimales
function wp_healthcheck_generate_password()
{
    return bin2hex(random_bytes(16));
}

// Crea la clave de API si no existe al activar el módulo
register_activation_hook(__FILE__, function () {
    if (!get_option('wp_healthcheck_api_key')) {
        $key = wp_healthcheck_generate_password();
        update_option('wp_healthcheck_api_key', $key);
    }
});

// Elimina la clave de API cuando se desactiva el plugin
register_deactivation_hook(__FILE__, function () {
    delete_option('wp_healthcheck_api_key');
});

// Clave de API autogenerada si no está definida como constante
function wp_healthcheck_get_api_key()
{
    // Si está definida como constante en wp-config.php, úsala
    if (defined('WP_HEALTHCHECK_API_KEY')) {
        return WP_HEALTHCHECK_API_KEY;
    }

    // Intentamos obtenerla de la base de datos
    $key = get_option('wp_healthcheck_api_key');

    // Si no existe, la generamos
    if (empty($key)) {
        $key = wp_healthcheck_generate_password();
        update_option('wp_healthcheck_api_key', $key);
    }

    return $key;
}

// Crea la clave de API si no existe al cargar el módulo (útil si lo instalamos en mu-plugins)
wp_healthcheck_get_api_key();

// WordPress permita acceder sin iniciar sesión
add_filter('rest_authentication_errors', function ($result) {
    if (!empty($result)) {
        return $result; // Otro plugin ya bloqueó el acceso
    }

    $route = $_SERVER['REQUEST_URI'];

    if (strpos($route, '/wp-json/wp-healthcheck/v1/status') !== false) {
        return true; // Permitimos este endpoint sin login
    }

  // Por defecto, sigue el comportamiento normal
    return $result;
});


// WordPress no almacena nativamente la hora de última ejecución del cron
add_action("shutdown", function () {
    if (defined("DOING_CRON") && DOING_CRON) {
        update_option("cron_last_run", time());
    }
});

add_action("rest_api_init", function () {
    register_rest_route("wp-healthcheck/v1", "/status", [
        "methods" => "GET",
        "callback" => "wp_healthcheck_status",
        "permission_callback" => "__return_true",
    ]);
});

function wp_healthcheck_status(WP_REST_Request $request)
{
    $provided_token = $request->get_param("token");

    // También acepta header: X-API-Key
    if (!$provided_token) {
        $headers = $request->get_headers();
        if (isset($headers["x_api_key"])) {
            $provided_token = $headers["x_api_key"][0];
        }
    }

    $expected_token = wp_healthcheck_get_api_key();

    if (!$expected_token || $provided_token !== $expected_token) {
        return new WP_REST_Response(
            ["status" => "FORBIDDEN", "error" => "Token inválido o ausente."],
            403
        );
    }

    // Continúa con los chequeos normales...
    $status = "OK";
    $results = [];

    global $wpdb;
    try {
        $wpdb->query("SELECT 1");
        $results["database"] = ["status" => "OK"];
    } catch (Exception $e) {
        $results["database"] = [
            "status" => "FAIL",
            "error" => $e->getMessage(),
        ];
        $status = "FAIL";
    }

    $path = WP_CONTENT_DIR;
    if (is_dir($path) && is_writable($path)) {
        $results["filesystem"] = ["status" => "OK"];
    } else {
        $results["filesystem"] = [
            "status" => "FAIL",
            "error" => "No writable access to $path",
        ];
        $status = "FAIL";
    }

    $cron = _get_cron_array();
    if (!is_array($cron) || count($cron) === 0) {
        $cron_status = [
            "status" => "FAIL",
            "error" => "No hay tareas cron registradas",
        ];
        $status = "FAIL";
    } else {
        $last_run = get_option("cron_last_run");

        if ($last_run) {
            $elapsed = time() - $last_run;
            if ($elapsed > 86400) {
                $cron_status = [
                    "status" => "WARNING",
                    "warning" =>
                        "El cron no se ha ejecutado en las últimas 24 horas",
                    "last_run_seconds_ago" => $elapsed,
                ];
                if ($status !== "FAIL") {
                    $status = "WARNING";
                }
            } else {
                $cron_status["status"] = "OK";
                $cron_status["last_run_seconds_ago"] = $elapsed;
            }
        } else {
            $cron_status = [
                "status" => "FAIL",
                "error" => "No hay registro de la última ejecución del cron",
            ];
            $status = "FAIL";
        }
    }
    $results["cron"] = $cron_status;

    return new WP_REST_Response(
        ["status" => $status, "checks" => $results],
        200
    );
}

// Agrega la página de opciones en el admin
add_action('admin_menu', function () {
    add_options_page(
        'Healthcheck API',
        'Healthcheck API',
        'manage_options',
        'healthcheck-api-settings',
        'wp_healthcheck_settings_page'
    );
});

// Registro y manejo del formulario
function wp_healthcheck_settings_page()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('wp_healthcheck_save')) {
        if (isset($_POST['wp_healthcheck_api_key'])) {
            $new_key = sanitize_text_field($_POST['wp_healthcheck_api_key']);
            update_option('wp_healthcheck_api_key', $new_key);
            echo '<div class="updated"><p>Ajustes guardados.</p></div>';
        }
    }

    $current_key = wp_healthcheck_get_api_key();
    $endpoint_url = rest_url('wp-healthcheck/v1/status') . '?token=' . urlencode($current_key);
    ?>
<div class="wrap">
    <h1>Configuración del Healthcheck</h1>

    <p>Este plugin permite comprobar de forma segura si el sitio web funciona correctamente, revisando aspectos clave como la conexión a la base de datos, los permisos de archivos y la ejecución de tareas automáticas.</p>

    <form method="post">
        <?php wp_nonce_field('wp_healthcheck_save'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="wp_healthcheck_api_key">API Key</label></th>
                <td>
                    <input type="text" name="wp_healthcheck_api_key" id="wp_healthcheck_api_key" value="<?php echo esc_attr($current_key); ?>" size="40" />
                    <button type="button" class="button" onclick="generateRandomKey()">Generar nueva</button>
                    <p class="description">Usa este token como parámetro <code>?token=...</code> o en el header <code>X-API-Key</code>.</p>
                    <p><strong>URL del endpoint:</strong><br><code id="endpoint-url"><?php echo esc_url($endpoint_url); ?></code></p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" class="button-primary" value="Guardar configuración">
        </p>
    </form>
</div>

<script>
function generateRandomKey() {
    const key = Array.from(crypto.getRandomValues(new Uint8Array(16)))
        .map(b => b.toString(16).padStart(2, '0'))
        .join('');
    const input = document.getElementById('wp_healthcheck_api_key');
    input.value = key;

    // Actualiza la URL del endpoint
    const endpointUrl = '<?php echo rest_url('wp-healthcheck/v1/status'); ?>?token=' + key;
    document.getElementById('endpoint-url').textContent = endpointUrl;
}
</script>
    <?php
}