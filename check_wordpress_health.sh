#!/bin/bash
#
# check_wordpress_health.sh checks WordPress status using healthcheck endpoint
# Copyright (C) 2025  Ramón Román Castro <ramonromancastro@gmail.com>
# 
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
# 
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
# 
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <https://www.gnu.org/licenses/>.
#

VERSION="1.8"
PROGNAME=$(basename "$0")

# Constantes de estado Nagios
STATE_OK=0
STATE_WARNING=1
STATE_CRITICAL=2
STATE_UNKNOWN=3

# Config por defecto
HOST="localhost"
PORT="80"
TOKEN=""
ENDPOINT="/wp-json/healthcheck/v1/status"
USE_SSL=0
IGNORE_CERT=0
VERBOSE=0
TIMEOUT=10
EXCLUDE=

print_usage() {
cat << EOF
Usage: $PROGNAME -H <host> -p <port> -a <token> [-u <endpoint>]
                [-S] [-k] [-t <timeout>] [-v] [-V] [-h]

Options:
  -H  Host donde se ejecuta la aplicación (requerido)
  -p  Puerto del servicio (requerido)
  -a  Token API (requerido)
  -u  Ruta del endpoint (por defecto: ${ENDPOINT})
  -S  Usar HTTPS en lugar de HTTP
  -k  Ignorar errores de certificado SSL
  -t  Timeout en segundos para la petición (por defecto: ${TIMEOUT})
  -x  Comprobaciones excluídas separadas por coma (por defecto: <vacío>)
      Valores disponibles: cron,database,filesystem,load,updates
  -v  Modo verbose (muestra la respuesta completa del endpoint)
  -V  Muestra la versión del plugin
  -h  Muestra esta ayuda

Nagios exit codes:
  $STATE_OK OK
  $STATE_WARNING WARNING
  $STATE_CRITICAL CRITICAL
  $STATE_UNKNOWN UNKNOWN
EOF
}

print_version() {
  echo "$PROGNAME version $VERSION"
}

# Comprobación de argumentos
if [[ $# -eq 0 ]]; then
  print_usage
  exit $STATE_UNKNOWN
fi

# Parseo de argumentos
while getopts "H:p:a:u:Skvt:x:Vh" opt; do
  case "$opt" in
    H) HOST="$OPTARG" ;;
    p) PORT="$OPTARG" ;;
    a) TOKEN="$OPTARG" ;;
    u) ENDPOINT="$OPTARG" ;;
    S) USE_SSL=1 ;;
    k) IGNORE_CERT=1 ;;
    v) VERBOSE=1 ;;
    t) TIMEOUT="$OPTARG" ;;
    x) EXCLUDE="&exclude=$OPTARG" ;;
    V) print_version; exit $STATE_OK ;;
    h) print_usage; exit $STATE_OK ;;
    *) print_usage; exit $STATE_UNKNOWN ;;
  esac
done

# Validación
if [[ -z "$HOST" || -z "$PORT" || -z "$TOKEN" ]]; then
  echo "ERROR: Parámetros -H, -p y -a son obligatorios"
  print_usage
  exit $STATE_UNKNOWN
fi

# Protocolo
SCHEME="http"
[[ $USE_SSL -eq 1 ]] && SCHEME="https"

# Construcción de URL
URL="$SCHEME://$HOST:$PORT$ENDPOINT"

# Comando curl
CURL_CMD="curl -s --max-time $TIMEOUT"
[[ $IGNORE_CERT -eq 1 ]] && CURL_CMD+=" -k"

# Ejecución
RESPONSE=$($CURL_CMD "$URL?token=${TOKEN}${EXCLUDE}")
CURL_EXIT=$?

if [[ $CURL_EXIT -ne 0 ]]; then
  echo "CRITICAL - Unable to connect to $URL (curl exit code $CURL_EXIT)"
  exit $STATE_CRITICAL
fi

[[ $VERBOSE -eq 1 ]] && echo "Respuesta: $RESPONSE"

# Extraer status
STATUS=$(echo "$RESPONSE" | jq -r '.status' 2>/dev/null)

if [[ -z "$STATUS" || "$STATUS" == "null" ]]; then
  echo "UNKNOWN - Unable to extract response"
  exit $STATE_UNKNOWN
fi

# --- SALIDA NAGIOS ---
case "$STATUS" in
  OK)
    CHECKS=$(echo "$RESPONSE" | jq -r '.checks | to_entries[] | "[\(.value.status)] \(.key): \(.value.message)"')
    echo -e "OK - All checks completed successfully\n$CHECKS"
    exit $STATE_OK
    ;;
  WARNING)
    CHECKS=$(echo "$RESPONSE" | jq -r '.checks | to_entries[] | "[\(.value.status)] \(.key): \(.value.message )"')
    echo -e "WARNING - At least one check returned a warning\n$CHECKS"
    exit $STATE_WARNING
    ;;
  CRITICAL)
    CHECKS=$(echo "$RESPONSE" | jq -r '.checks | to_entries[] | "[\(.value.status)] \(.key): \(.value.message)"')
    echo -e "CRITICAL - At least one error has occurred\n$CHECKS"
    exit $STATE_CRITICAL
    ;;
  FORBIDDEN)
    echo "UNKNOWN - Access denied"
    exit $STATE_UNKNOWN
    ;;
  *)
    echo "UNKNOWN - Unknown status: $STATUS"
    exit $STATE_UNKNOWN
    ;;
esac
