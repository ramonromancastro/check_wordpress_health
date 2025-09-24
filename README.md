# check_wordpress_health
Plugin de Nagios para comprobar el estado de salud de una instancia de WordPress

## Elementos monitorizados

- Acceso a la base de datos
- Permisos de escritura sobre el directorio wp-content
- Estado del cron
- Carga de la página web

## Instalación

### Instancia de WordPress

> La instancia de WordPress debe tener habilitado **WordPress REST API (wp-json)**

El archivo _healthcheck.php_ debe copiarse en el directorio _wp-content/mu-plugins/_ de la instancia de WordPress que se desea monitorizar.

#### Ajustes en la GUI de administración de WordPress

En la sección _Ajustes > Healthcheck API_ se puede configurar el token de acceso a los chequeos y si queremos obviar los errores de certificados a la hora de relizar la comprobación de carga de la página web.

## Opciones del plugin

```
Usage: check_wordpress_health.sh -H <host> -p <port> -a <token> [-u <endpoint>]
                [-S] [-k] [-t <timeout>] [-x] [-v] [-V] [-h]

Options:
  -H  Host donde se ejecuta la aplicación Spring Boot (requerido)
  -p  Puerto del servicio (requerido)
  -a  Token API (requerido)
  -u  Ruta del endpoint (por defecto: /wp-json/healthcheck/v1/status)
  -S  Usar HTTPS en lugar de HTTP
  -k  Ignorar errores de certificado SSL
  -t  Timeout en segundos para la petición (por defecto: 10)
  -x  Incluir performance data en la salida
  -v  Modo verbose (muestra la respuesta completa del endpoint)
  -V  Muestra la versión del plugin
  -h  Muestra esta ayuda
```
