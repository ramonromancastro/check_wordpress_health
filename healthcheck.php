<?php
/*
Plugin Name: Custom Healthcheck (MU)
Description: Endpoint REST JSON para monitorear WordPress.
Author: Ramón Román Castro <ramonromancastro@gmail.com>
Version: 0.20260114.4
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
    
    if (!get_option('wp_healthcheck_verifyssl')) {
        update_option('wp_healthcheck_verifyssl', 1);
    }
});

// Elimina la clave de API cuando se desactiva el plugin
register_deactivation_hook(__FILE__, function () {
    delete_option('wp_healthcheck_api_key');
    delete_option('wp_healthcheck_verifyssl');
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

    if (strpos($route, '/wp-json/healthcheck/v1/status') !== false) {
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
    register_rest_route("healthcheck/v1", "/status", [
        "methods" => "GET",
        "callback" => "wp_healthcheck_status",
        "permission_callback" => "__return_true",
        "args" => [
            "exclude" => [
                "required" => false,
                "validate_callback" => function($param, $request, $key) {
                    return is_string($param);
                },
                "default" => ""
            ],
        ],
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
            ["status" => "FORBIDDEN", "message" => "Invalid or missing token"],
            403
        );
    }

    // Procesamos las exclusiones
    $exclude_raw = $request->get_param("exclude");
    $exclude_list = [];
    if (!empty($exclude_raw)) {
        $exclude_list = array_map('trim', explode(',', $exclude_raw));
    }

    // Continúa con los chequeos normales...
    $status = "OK";
    $results = [];

    // 
    // Load page test
    //
    
    if (!in_array('load', $exclude_list)) {
        $sslverify = get_option('wp_healthcheck_sslverify');
        $url_args = array( 'timeout' => 10);
        if ($sslverify == 0) {
            $url_args['sslverify'] = false;
        }

        $url = home_url( '/' );
        $response = wp_remote_get( $url, $url_args );

        if ( is_wp_error( $response ) ) {
            $results['load'] = [
                'status'  => 'CRITICAL',
                'message' => $response->get_error_message(),
            ];
            $status = 'CRITICAL';
        }
        else {
            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            if ( $code === 200 && strpos( $body, '<title>' ) !== false ) {
                $results['load'] = [
                    'status'  => 'OK',
                    'message' => 'Web connection successful',
                ];
            } else {
                $results['load'] = [
                    'status'  => 'CRITICAL',
                    'message' => 'The page did not return the expected content',
                ];
                $status = 'CRITICAL';
            }
        }
    }
    
    // 
    // Database test
    //
    
    if (!in_array('database', $exclude_list)) {
        global $wpdb;
        try {
            $wpdb->query("SELECT 1");
            $results["database"] = ["status" => "OK","message" => "Database connection successful"];
        } catch (Exception $e) {
            $results["database"] = [
                "status" => 'CRITICAL',
                "message" => $e->getMessage(),
            ];
            $status = 'CRITICAL';
        }
    }
    
    // 
    // FileSystem test
    //
    
    if (!in_array('filesystem', $exclude_list)) {
        $path = WP_CONTENT_DIR;
        if (is_dir($path) && is_writable($path)) {
            $results["filesystem"] = ["status" => "OK", "message" => "wp-content directory is writable"];
        } else {
            $results["filesystem"] = [
                "status" => 'CRITICAL',
                "message" => "wp-content directory is not writable",
            ];
            $status = 'CRITICAL';
        }
    }
    
    // 
    // Cron test
    //

    if (!in_array('cron', $exclude_list)) {
        $cron = _get_cron_array();
        if (!is_array($cron) || count($cron) === 0) {
            $cron_status = [
                "status" => 'CRITICAL',
                "message" => "No WP-Cron execution detected",
            ];
            $status = 'CRITICAL';
        } else {
            $last_run = get_option("cron_last_run");

            if ($last_run) {
                $elapsed = time() - $last_run;
                if ($elapsed > 86400) {
                    $cron_status = [
                        "status" => "WARNING",
                        "message" =>
                            "WP-Cron has not run within the last 24 hours",
                        "last_run_seconds_ago" => $elapsed,
                    ];
                    if ($status !== 'CRITICAL') {
                        $status = "WARNING";
                    }
                } else {
                    $cron_status["status"] = "OK";
                    $cron_status["message"] = "WP-Cron has run within the last 24 hours";
                    $cron_status["last_run_seconds_ago"] = $elapsed;
                }
            } else {
                $cron_status = [
                    "status" => 'CRITICAL',
                    "message" => "Last WP-Cron execution not recorded",
                ];
                $status = 'CRITICAL';
            }
        }
        $results["cron"] = $cron_status;
    }

    // 
    // Updates test
    //
    
    if (!in_array('updates', $exclude_list)) {

        // Forzar comprobaciones
        // wp_version_check();     // Core
        // wp_update_plugins();    // Plugins
        // wp_update_themes();     // Temas

        // Obtener resultados de cada tipo
        $core_updates   = get_site_transient('update_core');
        $plugin_updates = get_site_transient('update_plugins');
        $theme_updates  = get_site_transient('update_themes');
        $translation_updates = get_site_transient('update_translations');

        // Inicializar contadores
        $updates = [];
        $last_checked_times = [];

        // ----------------------
        // Core
        // ----------------------
        if ( isset($core_updates->updates) && is_array($core_updates->updates) ) {
            foreach ($core_updates->updates as $update) {
                if ( isset($update->response) && $update->response === 'upgrade' ) {
                    $updates[] = [
                        'type'    => 'core',
                        'name'    => 'WordPress Core',
                        'version' => $update->current ?? 'unknown'
                    ];
                }
            }
            if (isset($core_updates->last_checked)) {
                $last_checked_times[] = $core_updates->last_checked;
            }
        }

        // ----------------------
        // Plugins
        // ----------------------
        if ( isset($plugin_updates->response) && !empty($plugin_updates->response) ) {
            foreach ($plugin_updates->response as $plugin_file => $plugin_info) {
                $updates[] = [
                    'type'    => 'plugin',
                    'name'    => $plugin_info->slug ?? $plugin_file,
                    'version' => $plugin_info->new_version ?? 'unknown'
                ];
            }
            if (isset($plugin_updates->last_checked)) {
                $last_checked_times[] = $plugin_updates->last_checked;
            }
        }

        // ----------------------
        // Temas
        // ----------------------
        if ( isset($theme_updates->response) && !empty($theme_updates->response) ) {
            foreach ($theme_updates->response as $theme_slug => $theme_info) {
                $updates[] = [
                    'type'    => 'theme',
                    'name'    => $theme_slug,
                    'version' => $theme_info['new_version'] ?? 'unknown'
                ];
            }
            if (isset($theme_updates->last_checked)) {
                $last_checked_times[] = $theme_updates->last_checked;
            }
        }

        // ----------------------
        // Traducciones
        // ----------------------
        if ( isset($translation_updates->updates) && !empty($translation_updates->updates) ) {
            foreach ($translation_updates->updates as $translation) {
                $updates[] = [
                    'type'    => 'translation',
                    'name'    => $translation->slug ?? 'translation',
                    'version' => $translation->version ?? 'unknown'
                ];
            }
            if (isset($translation_updates->last_checked)) {
                $last_checked_times[] = $translation_updates->last_checked;
            }
        }

        // ----------------------
        // Resultado final
        // ----------------------

        if (empty($updates)) {
            $message = 'No updates available.';
        } else {
            $updates = array_unique($updates, SORT_REGULAR);
            $total = count($updates);
            $last_check_time = !empty($last_checked_times)
                ? date('Y-m-d H:i:s', max($last_checked_times))
                : 'unknown';

            $lines = [];
            $lines[] = "{$total} updates available. Last check: {$last_check_time}";

            foreach ($updates as $update) {
                $lines[] = "- [{$update['type']}] {$update['name']}: {$update['version']}";
            }

            $message = implode("\n", $lines);
        }

        $results['updates'] = [
            'status'  => empty($updates) ? 'OK' : 'WARNING',
            'message' => $message,
        ];
        
        if (!empty($updates) && ($status !== 'CRITICAL')) {
            $status = "WARNING";
        }
    }

    ksort($results);
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
        update_option('wp_healthcheck_sslverify', isset($_POST['wp_healthcheck_sslverify']) ? 1 : 0);
    }

    $current_key = wp_healthcheck_get_api_key();
    $current_sslverify = get_option('wp_healthcheck_sslverify');
    $endpoint_url = rest_url('healthcheck/v1/status') . '?token=' . urlencode($current_key);
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
        
        <table class="form-table">
            <tr>
                <th scope="row"><label for="wp_healthcheck_sslverify">Disable SSL Verification</label></th>
                <td>
                    <input type="checkbox" name="wp_healthcheck_sslverify" id="wp_healthcheck_sslverify" value="1" <?php if ($current_sslverify == 1) { echo 'checked'; }; ?> />
                    <p class="description">Usa esta opción para desactivar la comprobación de certificados a la hora de realizar el chequeo de carga de la web.</p>
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
    const endpointUrl = '<?php echo rest_url('healthcheck/v1/status'); ?>?token=' + key;
    document.getElementById('endpoint-url').textContent = endpointUrl;
}
</script>
    <?php
}
