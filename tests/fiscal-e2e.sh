#!/usr/bin/env bash
# =============================================================================
# tests/fiscal-e2e.sh — Pruebas end-to-end del módulo fiscal SAT (CacaoPay)
# =============================================================================
# Verifica el contrato de docs/fiscal-sat.md (secciones 3, 4 y 7) contra el
# backend real, usando un entorno TOTALMENTE AISLADO:
#
#   - Copia el proyecto a .tmp/fiscal-e2e/app (excluye .git, .tmp y .env).
#   - Genera su propio .env dentro de la copia (nunca toca el .env del repo).
#   - Inicializa y levanta un MySQL privado (datadir .tmp/fiscal-e2e/mysql,
#     puerto MYSQL_PORT=3411, socket propio en /tmp, --mysqlx=OFF).
#   - Levanta `php -S 127.0.0.1:HTTP_PORT` (default 8111).
#   - Instala con `php public/install.php` (CLI) y ejecuta los escenarios.
#   - Al terminar apaga los dos servidores y borra los temporales (salvo KEEP=1).
#
# NUNCA usa el puerto 3399, ni el .env del repositorio, ni la base de datos del
# usuario. Cada aserción imprime [PASS]/[FAIL]; el resultado final es
# `RESULTADO: PASS` (código 0) o `RESULTADO: FAIL n/m` (código 1).
#
# Uso:
#   tests/fiscal-e2e.sh              # ejecución completa
#   tests/fiscal-e2e.sh --keep       # no borra artefactos (equivale a KEEP=1)
#   tests/fiscal-e2e.sh --skip-db    # solo comprobaciones php -l (sin MySQL)
#   tests/fiscal-e2e.sh --check      # solo verifica herramientas (SKIP, código 0)
#   tests/fiscal-e2e.sh --help
#
# Variables: MYSQL_PORT (3411), HTTP_PORT (8111), MYSQL_BASEDIR, PHP, MYSQLD,
#            MYSQLADMIN, CURL, PYTHON, KEEP.
#
# Requisitos: php 8.1+, mysqld/mysqladmin, curl, python3 (aserciones JSON).
#
# Nota: el script se ejecuta como usuario normal; no requiere root ni sudo.
# =============================================================================
set -uo pipefail

# ---------------------------------------------------------------------------
# Rutas, valores por defecto y banderas
# ---------------------------------------------------------------------------
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT_DIR=$(cd "$SCRIPT_DIR/.." && pwd)

MYSQL_PORT="${MYSQL_PORT:-3411}"
HTTP_PORT="${HTTP_PORT:-8111}"
KEEP="${KEEP:-0}"
SKIP_DB=0
CHECK_ONLY=0

TMP_DIR="$ROOT_DIR/.tmp/fiscal-e2e"
APP_DIR="$TMP_DIR/app"
DATADIR="$TMP_DIR/mysql"
WORK="$TMP_DIR/work"
SOCKET="/tmp/cacaopay-fiscal-e2e.$$.sock"
E2E_DB_NAME="cacaopay_e2e"

WORK_BODY="$WORK/body.out"
WORK_HEADERS="$WORK/headers.out"
WORK_CURL="$WORK/curl.err"

PHP_BIN="${PHP:-$(command -v php || true)}"
MYSQLD_BIN="${MYSQLD:-$(command -v mysqld || true)}"
MYSQLADMIN_BIN="${MYSQLADMIN:-$(command -v mysqladmin || true)}"
CURL_BIN="${CURL:-$(command -v curl || true)}"
PYTHON_BIN="${PYTHON:-$(command -v python3 || true)}"
MYSQL_BASEDIR="${MYSQL_BASEDIR:-}"

PHP_PID=""
PHP_STARTED=0
MYSQL_PID=""
MYSQL_STARTED=0

# Estado de aserciones
TOTAL=0
PASS=0
FAIL=0
SCEN_NAME=""
SCEN_PASS=0
SCEN_FAIL=0
SCEN_SUMMARY_TEXT=""

# Última respuesta HTTP
HTTP_CODE=""
BODY=""
HEADERS=""

# Ids/UUIDs usados por los escenarios
ADMIN_TOKEN=""
CLIENT_TOKEN=""
ADMIN_ID=""
CLIENT_ID=""

DEP500=""
DEP300=""
DEP200=""
WITHDRAW_ID=""

INV1="11111111-1111-4111-8111-111111111111"
INV2="22222222-2222-4222-8222-222222222222"
INV3="33333333-3333-4333-8333-333333333333"
INV4="44444444-4444-4444-8444-444444444444"
INV5="55555555-5555-4555-8555-555555555555"
INV6="66666666-6666-4666-8666-666666666666"
INV7="77777777-7777-4777-8777-777777777777"
BOGUS_USER="99999999-9999-4999-8999-999999999999"

INV1_ID=""
INV2_ID=""
INV3_ID=""
INV2_CANCELLED_AT=""
NAME3='Cliente Ñandú "El Bueno", S.A. de C.V.'

CFDI_HEADER="UUID_FISCAL,TIPO,ESTATUS,FECHA_EMISION,SERIE,FOLIO,RFC_EMISOR,RAZON_SOCIAL_EMISOR,RFC_RECEPTOR,NOMBRE_RECEPTOR,REGIMEN_RECEPTOR,USO_CFDI,METODO_PAGO,FORMA_PAGO,MONEDA,SUBTOTAL,IVA,TOTAL,UUID_RELACIONADO,REFERENCIA_DEPOSITO,MOTIVO_CANCELACION"
MOV_HEADER="FECHA,DIRECCION,MONTO_MXN,MONTO_CACAO,CLABE,BANCO,BENEFICIARIO,REFERENCIA,ID_TRANSFERENCIA,ESTATUS_FISCAL,UUID_CFDI"
CONG_HEADER="FECHA,CONCEPTO,TIPO,MONTO,FACTURADO,DIFERENCIA,CFDI_UUID,ESTADO,OBSERVACION"

# Periodo (mes actual en America/Mexico_City, igual que el backend)
MONTH=""
FROM=""
TO=""
FUTURE_MONTH=""

# ---------------------------------------------------------------------------
# Salida con colores
# ---------------------------------------------------------------------------
if [[ -t 1 ]]; then
    BOLD=$'\033[1m'
    GREEN=$'\033[32m'
    RED=$'\033[31m'
    YELLOW=$'\033[33m'
    RESET=$'\033[0m'
else
    BOLD=""
    GREEN=""
    RED=""
    YELLOW=""
    RESET=""
fi

log()  { printf '%s\n' "$*"; }
info() { printf '  · %s\n' "$*"; }
warn() { printf '%s  ! %s%s\n' "$YELLOW" "$*" "$RESET"; }

usage() {
    cat <<'EOF'
tests/fiscal-e2e.sh — Pruebas end-to-end del módulo fiscal SAT de CacaoPay.

Uso:
  tests/fiscal-e2e.sh [opciones]

Opciones:
  --keep        Conserva artefactos y deja los servidores en marcha (equivale a KEEP=1).
  --skip-db     Solo valida la sintaxis (php -l); no levanta MySQL ni servidor HTTP.
  --check       Solo comprueba herramientas/requisitos; termina con "RESULTADO: SKIP".
  --help, -h    Muestra esta ayuda.

Entorno aislado:
  Copia el repo a .tmp/fiscal-e2e/app, genera su propio .env y levanta un MySQL
  privado (puerto MYSQL_PORT, default 3411) y un `php -S` (HTTP_PORT, default 8111).
  No toca el .env del repo, ni el puerto 3399, ni las bases de datos del usuario.

Variables de entorno:
  MYSQL_PORT=3411       Puerto del MySQL temporal.
  HTTP_PORT=8111        Puerto del servidor PHP temporal.
  MYSQL_BASEDIR=...     basedir de MySQL (por defecto se deduce del mysqld instalado).
  PHP=...               Binario de PHP (default: php del PATH).
  MYSQLD=...            Binario mysqld (default: mysqld del PATH).
  MYSQLADMIN=...        Binario mysqladmin (default: mysqladmin del PATH).
  CURL=... PYTHON=...   Binarios de curl y python3.
  KEEP=1                No borra .tmp/fiscal-e2e al terminar.

Requisitos: php (8.1+), mysqld/mysqladmin, curl y python3.

Salida: resumen por escenario y, al final, `RESULTADO: PASS` (exit 0) o
`RESULTADO: FAIL n/m` (exit 1).
EOF
}

# ---------------------------------------------------------------------------
# Aserciones
# ---------------------------------------------------------------------------
snippet() {
    printf '%s' "${BODY:-}" | tr '\n' ' ' | cut -c1-600
}

pass_assert() {
    PASS=$((PASS + 1))
    TOTAL=$((TOTAL + 1))
    SCEN_PASS=$((SCEN_PASS + 1))
    printf '  %s[PASS]%s %s\n' "$GREEN" "$RESET" "$1"
}

fail_assert() {
    FAIL=$((FAIL + 1))
    TOTAL=$((TOTAL + 1))
    SCEN_FAIL=$((SCEN_FAIL + 1))
    printf '  %s[FAIL]%s %s\n' "$RED" "$RESET" "$1"
    if [[ -n "${2:-}" ]]; then
        printf '         %s\n' "$2"
    fi
}

scenario() {
    scenario_end
    SCEN_NAME="$1"
    SCEN_PASS=0
    SCEN_FAIL=0
    printf '\n%s── %s ──%s\n' "$BOLD" "$1" "$RESET"
}

scenario_end() {
    if [[ -z "$SCEN_NAME" ]]; then
        return 0
    fi
    local scen_total=$((SCEN_PASS + SCEN_FAIL))
    local scen_line=""
    local status="PASS"
    if [[ $SCEN_FAIL -gt 0 ]]; then
        status="FAIL"
    fi
    if [[ $status == "PASS" ]]; then
        printf -v scen_line '  %s[PASS]%s %-52s %d/%d\n' "$GREEN" "$RESET" "$SCEN_NAME" "$SCEN_PASS" "$scen_total"
    else
        printf -v scen_line '  %s[FAIL]%s %-52s %d/%d\n' "$RED" "$RESET" "$SCEN_NAME" "$SCEN_PASS" "$scen_total"
    fi
    SCEN_SUMMARY_TEXT="${SCEN_SUMMARY_TEXT}${scen_line}"
    SCEN_NAME=""
}

assert_status() {
    local name="$1" expected="$2"
    if [[ "$HTTP_CODE" == "$expected" ]]; then
        pass_assert "$name (HTTP $HTTP_CODE)"
    else
        fail_assert "$name (HTTP $HTTP_CODE, esperado $expected)" "$(snippet)"
    fi
}

assert_eq() {
    local name="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        pass_assert "$name"
    else
        fail_assert "$name" "esperado '$expected', obtenido '$actual'"
    fi
}

assert_contains() {
    local name="$1" haystack="$2" needle="$3"
    if [[ "$haystack" == *"$needle"* ]]; then
        pass_assert "$name"
    else
        fail_assert "$name" "no contiene '$needle' (valor: '$(printf '%s' "$haystack" | cut -c1-300)')"
    fi
}

assert_regex() {
    local name="$1" value="$2" re="$3"
    if [[ "$value" =~ $re ]]; then
        pass_assert "$name"
    else
        fail_assert "$name" "valor '$value' no coincide con /$re/"
    fi
}

# Evalúa una expresión Python con `d` = JSON de la última respuesta.
# Ejemplo: assert_py "depósitos 1000" "abs(d['movimientos']['depositos_mxn']-1000)<=0.011"
assert_py() {
    local name="$1" expr="$2" out rc
    out=$(printf '%s' "${BODY:-}" | "$PYTHON_BIN" -c '
import sys, json
try:
    d = json.load(sys.stdin)
except Exception as exc:
    print("JSON_INVALID: %s" % exc)
    sys.exit(2)
try:
    print("OK" if eval(sys.argv[1]) else "MISMATCH")
except Exception as exc:
    print("EVAL_ERROR: %s" % exc)
    sys.exit(3)
' "$expr" 2>&1)
    rc=$?
    if [[ $rc -eq 0 && "$out" == "OK" ]]; then
        pass_assert "$name"
    else
        fail_assert "$name" "expr: $expr | $out | body: $(snippet)"
    fi
}

# Compara dos números con tolerancia 0.011 (contrato: 0.01).
assert_num() {
    local name="$1" expected="$2" actual="$3" out rc
    out=$("$PYTHON_BIN" -c '
import sys
try:
    a = float(sys.argv[1]); b = float(sys.argv[2])
    print("OK" if abs(a - b) <= 0.011 else "MISMATCH a=%s b=%s" % (a, b))
except Exception as exc:
    print("ERROR: %s" % exc)
' "$expected" "$actual" 2>&1)
    rc=$?
    if [[ $rc -eq 0 && "$out" == "OK" ]]; then
        pass_assert "$name"
    else
        fail_assert "$name" "esperado $expected, obtenido '$actual' | $out"
    fi
}

# Comprueba que una cabecera HTTP contenga un texto dado (sin distinguir mayúsculas).
assert_header() {
    local name="$1" header="$2" needle="$3" val lower_val lower_needle
    val=$(printf '%s' "${HEADERS:-}" | grep -i "^${header}:" 2>/dev/null | tail -n 1 | sed -e 's/^[^:]*:[[:space:]]*//' -e 's/\r$//')
    lower_val=$(printf '%s' "$val" | tr '[:upper:]' '[:lower:]')
    lower_needle=$(printf '%s' "$needle" | tr '[:upper:]' '[:lower:]')
    if [[ -n "$val" && "$lower_val" == *"$lower_needle"* ]]; then
        pass_assert "$name"
    else
        fail_assert "$name" "cabecera ${header}: '$val' (se esperaba contener '$needle')"
    fi
}

# Ejecuta código Python sobre un archivo (p. ej. CSV). El código dispone de
# `raw` (bytes), `csv`, `io`, `sys` y `extra` (4º argumento). sys.exit("msg")
# marca el fallo con el mensaje.
py_check() {
    local name="$1" file="$2" code="$3" extra="${4:-}" out rc
    out=$("$PYTHON_BIN" - "$file" "$code" "$extra" 2>&1 <<'PY'
import sys, csv, io
path, code, extra = sys.argv[1], sys.argv[2], sys.argv[3]
raw = open(path, "rb").read()
ns = {"sys": sys, "raw": raw, "csv": csv, "io": io, "extra": extra}
try:
    exec(compile(code, "<check>", "exec"), ns)
except SystemExit:
    raise
PY
)
    rc=$?
    if [[ $rc -eq 0 ]]; then
        pass_assert "$name"
    else
        fail_assert "$name" "$out"
    fi
}

# ---------------------------------------------------------------------------
# Peticiones HTTP
# ---------------------------------------------------------------------------
request() {
    local method="$1" path="$2" data="${3:-}" token="${4:-}" code
    local -a args=(-sS -o "$WORK_BODY" -D "$WORK_HEADERS" -w '%{http_code}' -X "$method")
    if [[ -n "$data" ]]; then
        args+=(-H 'Content-Type: application/json' --data-binary "$data")
    fi
    if [[ -n "$token" ]]; then
        args+=(-H "Authorization: Bearer $token")
    fi
    if ! code=$("$CURL_BIN" "${args[@]}" "http://127.0.0.1:$HTTP_PORT$path" 2>"$WORK_CURL"); then
        code="000"
    fi
    HTTP_CODE="$code"
    BODY=$(cat "$WORK_BODY" 2>/dev/null || true)
    HEADERS=$(cat "$WORK_HEADERS" 2>/dev/null || true)
}

request_file() {
    local path="$1" token="$2" out="$3" code
    if ! code=$("$CURL_BIN" -sS -o "$out" -D "$WORK_HEADERS" -w '%{http_code}' \
        "http://127.0.0.1:$HTTP_PORT$path" \
        -H "Authorization: Bearer $token" 2>"$WORK_CURL"); then
        code="000"
    fi
    HTTP_CODE="$code"
    HEADERS=$(cat "$WORK_HEADERS" 2>/dev/null || true)
    BODY=""
}

# Extrae un valor del JSON de la última respuesta.
json_get() {
    printf '%s' "${BODY:-}" | "$PYTHON_BIN" -c '
import sys, json
try:
    d = json.load(sys.stdin)
    v = eval(sys.argv[1], {"d": d})
except Exception:
    sys.exit(1)
print("" if v is None else v)
' "$1" 2>/dev/null || true
}

require_value() {
    # require_value <nombre> <valor>
    if [[ -z "${2:-}" ]]; then
        fatal "no se pudo obtener '$1' de la respuesta: $(snippet)"
    fi
}

fatal() {
    printf '\n%sERROR FATAL:%s %s\n' "$RED" "$RESET" "$*" >&2
    printf '\nRESULTADO: FAIL %d/%d\n' "$FAIL" "$TOTAL"
    exit 1
}

# ---------------------------------------------------------------------------
# Limpieza (trap)
# ---------------------------------------------------------------------------
cleanup() {
    local rc=$?
    trap - EXIT INT TERM
    scenario_end

    if [[ "$PHP_STARTED" -eq 1 && -n "$PHP_PID" ]]; then
        kill "$PHP_PID" 2>/dev/null || true
        wait "$PHP_PID" 2>/dev/null || true
        PHP_STARTED=0
    fi

    if [[ "$MYSQL_STARTED" -eq 1 ]]; then
        if [[ -n "$MYSQLADMIN_BIN" && -S "$SOCKET" ]]; then
            "$MYSQLADMIN_BIN" --no-defaults --protocol=SOCKET --socket="$SOCKET" \
                -uroot shutdown >/dev/null 2>&1 || true
        fi
        if [[ -n "$MYSQL_PID" ]] && kill -0 "$MYSQL_PID" 2>/dev/null; then
            kill "$MYSQL_PID" 2>/dev/null || true
        fi
        wait "$MYSQL_PID" 2>/dev/null || true
        MYSQL_STARTED=0
    fi

    rm -f "$SOCKET" "$SOCKET.lock" 2>/dev/null || true

    if [[ "$KEEP" == "1" ]]; then
        printf '\n%sArtefactos conservados (KEEP=1):%s %s\n' "$BOLD" "$RESET" "$TMP_DIR"
        if [[ -n "$PHP_PID" ]]; then
            printf '  php -S 127.0.0.1:%s (pid %s)\n' "$HTTP_PORT" "$PHP_PID"
        fi
        if [[ -n "$MYSQL_PID" ]]; then
            printf '  mysqld 127.0.0.1:%s (pid %s, socket %s)\n' "$MYSQL_PORT" "$MYSQL_PID" "$SOCKET"
        fi
    else
        case "$TMP_DIR" in
            "$ROOT_DIR"/.tmp/fiscal-e2e)
                rm -rf "$TMP_DIR"
                ;;
            *)
                printf '%sAVISO:%s no se borra la ruta inesperada %s\n' "$YELLOW" "$RESET" "$TMP_DIR"
                ;;
        esac
    fi

    exit "$rc"
}
trap cleanup EXIT INT TERM

# ---------------------------------------------------------------------------
# Preflight: herramientas, versiones y puertos
# ---------------------------------------------------------------------------
port_in_use() {
    "$PYTHON_BIN" - "$1" 2>/dev/null <<'PY'
import socket, sys
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
s.settimeout(0.3)
try:
    rc = s.connect_ex(("127.0.0.1", int(sys.argv[1])))
finally:
    s.close()
sys.exit(0 if rc == 0 else 1)
PY
}

preflight() {
    local missing=""
    add_missing() { missing="${missing:+$missing, }$1"; }

    if [[ "$SKIP_DB" -eq 1 ]]; then
        # --skip-db solo necesita php.
        if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
            add_missing "php"
        fi
    else
        if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then add_missing "php"; fi
        if [[ -z "$MYSQLD_BIN" || ! -x "$MYSQLD_BIN" ]]; then add_missing "mysqld"; fi
        if [[ -z "$MYSQLADMIN_BIN" || ! -x "$MYSQLADMIN_BIN" ]]; then add_missing "mysqladmin"; fi
        if [[ -z "$CURL_BIN" || ! -x "$CURL_BIN" ]]; then add_missing "curl"; fi
        if [[ -z "$PYTHON_BIN" || ! -x "$PYTHON_BIN" ]]; then add_missing "python3"; fi
    fi

    if [[ -n "$missing" ]]; then
        printf '\n%sFaltan herramientas requeridas:%s %s\n' "$RED" "$RESET" "$missing" >&2
        printf 'Instálalas o pásalas por variable de entorno (PHP=, MYSQLD=, ...).\n' >&2
        if [[ "$CHECK_ONLY" -eq 1 ]]; then
            printf '\nRESULTADO: SKIP\n'
            exit 0
        fi
        printf '\nRESULTADO: FAIL 0/0\n'
        exit 1
    fi

    info "PHP: $("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo '?') ($PHP_BIN)"
    if [[ "$SKIP_DB" -eq 0 ]]; then
        info "MySQL: $("$MYSQLD_BIN" --version 2>/dev/null | head -n 1) ($MYSQLD_BIN)"
        info "curl: $("$CURL_BIN" --version 2>/dev/null | head -n 1)"
        info "python3: $("$PYTHON_BIN" --version 2>&1)"
        info "Repo: $ROOT_DIR"
    fi

    # Protección explícita: nunca usar el puerto de la instancia de otros procesos.
    if [[ "$MYSQL_PORT" == "3399" || "$HTTP_PORT" == "3399" ]]; then
        fatal "el puerto 3399 está reservado por otro proceso; usa MYSQL_PORT/HTTP_PORT distintos (default 3411/8111)"
    fi
    if [[ "$MYSQL_PORT" == "$HTTP_PORT" ]]; then
        fatal "MYSQL_PORT y HTTP_PORT deben ser distintos (ambos valen $MYSQL_PORT)"
    fi
    if ! [[ "$MYSQL_PORT" =~ ^[0-9]+$ ]] || ! [[ "$HTTP_PORT" =~ ^[0-9]+$ ]]; then
        fatal "MYSQL_PORT y HTTP_PORT deben ser numéricos (MYSQL_PORT=$MYSQL_PORT HTTP_PORT=$HTTP_PORT)"
    fi

    if [[ "$SKIP_DB" -eq 0 ]]; then
        if port_in_use "$MYSQL_PORT"; then
            fatal "el puerto $MYSQL_PORT ya está ocupado; usa otro (p. ej. MYSQL_PORT=3412)"
        fi
        if port_in_use "$HTTP_PORT"; then
            fatal "el puerto $HTTP_PORT ya está ocupado; usa otro (p. ej. HTTP_PORT=8112)"
        fi
    fi
}

# ---------------------------------------------------------------------------
# Validación de sintaxis PHP
# ---------------------------------------------------------------------------
lint_php() {
    local dir f out rc=0 bad=""
    for dir in "$ROOT_DIR/src" "$ROOT_DIR/public"; do
        [[ -d "$dir" ]] || continue
        for f in "$dir"/*.php; do
            [[ -e "$f" ]] || continue
            out=$("$PHP_BIN" -l "$f" 2>&1)
            if [[ $? -ne 0 ]]; then
                rc=1
                bad="${bad}${f}: ${out}; "
            fi
        done
    done
    if [[ $rc -eq 0 ]]; then
        pass_assert "Sintaxis PHP válida (src/*.php y public/*.php, php -l)"
    else
        fail_assert "Sintaxis PHP inválida" "$bad"
    fi
}

# ---------------------------------------------------------------------------
# Arranque del entorno aislado
# ---------------------------------------------------------------------------
setup_workdir() {
    scenario "1. Entorno aislado (copiar repo, .env propio, MySQL y PHP)"

    # Copia limpia del repo (sin .git, .tmp ni .env del usuario).
    case "$TMP_DIR" in
        "$ROOT_DIR"/.tmp/fiscal-e2e) rm -rf "$TMP_DIR" ;;
        *) fatal "ruta temporal inesperada: $TMP_DIR" ;;
    esac
    mkdir -p "$TMP_DIR" "$WORK"
    if command -v rsync >/dev/null 2>&1; then
        if ! rsync -a --exclude='.git' --exclude='.tmp' --exclude='.env' --exclude='.DS_Store' \
            "$ROOT_DIR"/ "$APP_DIR"/ >>"$WORK/setup.log" 2>&1; then
            fatal "no se pudo copiar el proyecto a $APP_DIR (ver $WORK/setup.log)"
        fi
    else
        mkdir -p "$APP_DIR"
        if ! ( cd "$ROOT_DIR" && tar --exclude='.git' --exclude='.tmp' --exclude='.env' --exclude='.DS_Store' -cf - . ) \
            | ( cd "$APP_DIR" && tar -xf - ) >>"$WORK/setup.log" 2>&1; then
            fatal "no se pudo copiar el proyecto a $APP_DIR (ver $WORK/setup.log)"
        fi
    fi
    if [[ ! -f "$APP_DIR/public/index.php" || ! -d "$APP_DIR/src" ]]; then
        fatal "la copia del proyecto está incompleta en $APP_DIR"
    fi
    info "Copia aislada del repo: $APP_DIR"

    # .env propio de la copia (jamás se toca el .env del repo).
    cat > "$APP_DIR/.env" <<EOF
DB_HOST=127.0.0.1
DB_PORT=$MYSQL_PORT
DB_NAME=$E2E_DB_NAME
DB_USER=root
DB_PASSWORD=
JWT_SECRET=fiscal-e2e-secret-${MYSQL_PORT}-${HTTP_PORT}-no-usar-en-produccion
CLIENT_ORIGIN=*
FIAT_CURRENCY=MXN
CACAO_PER_FIAT=1
INVEST_ANNUAL_RATE=0.05
LOAN_ANNUAL_RATE=0.12
LOAN_TERM_DAYS=30
APP_DEBUG=false
APP_INSTALL=true
FISCAL_RFC=
FISCAL_RAZON_SOCIAL=
FISCAL_REGIMEN=612
FISCAL_CP=
FISCAL_SERIE=A
FISCAL_IVA_RATE=0.16
EOF
    info ".env temporal generado en $APP_DIR/.env (DB_PORT=$MYSQL_PORT)"

    # basedir de MySQL (se deduce del binario si no se indica).
    if [[ -z "$MYSQL_BASEDIR" ]]; then
        MYSQL_BASEDIR=$("$PYTHON_BIN" -c '
import os, sys
real = os.path.realpath(sys.argv[1])
print(os.path.dirname(os.path.dirname(real)))
' "$MYSQLD_BIN" 2>/dev/null || true)
    fi
    if [[ -z "$MYSQL_BASEDIR" || ! -d "$MYSQL_BASEDIR" ]]; then
        fatal "no se pudo determinar MYSQL_BASEDIR (pásalo por variable de entorno)"
    fi
    info "MySQL basedir: $MYSQL_BASEDIR"

    # Fechas del periodo actual (misma zona horaria que el contrato).
    MONTH=$("$PHP_BIN" -r 'date_default_timezone_set("America/Mexico_City"); echo date("Y-m");')
    FROM="$MONTH-01"
    TO=$("$PHP_BIN" -r 'date_default_timezone_set("America/Mexico_City"); echo date("Y-m-t");')
    FUTURE_MONTH=$("$PHP_BIN" -r 'date_default_timezone_set("America/Mexico_City"); echo (new DateTimeImmutable("first day of next month"))->format("Y-m");')
    info "Periodo de prueba: $FROM .. $TO (mes $MONTH)"

    # --- MySQL aislado ------------------------------------------------------
    rm -f "$SOCKET" "$SOCKET.lock"
    mkdir -p "$DATADIR"
    info "Inicializando MySQL en $DATADIR ..."
    if ! "$MYSQLD_BIN" --no-defaults --initialize-insecure \
        --basedir="$MYSQL_BASEDIR" --datadir="$DATADIR" \
        --log-error="$WORK/mysql-init.log" >/dev/null 2>&1; then
        printf '%s--- mysql-init.log ---%s\n' "$BOLD" "$RESET" >&2
        tail -n 40 "$WORK/mysql-init.log" >&2 2>/dev/null || true
        fatal "no se pudo inicializar MySQL (revisa $WORK/mysql-init.log)"
    fi

    "$MYSQLD_BIN" --no-defaults \
        --basedir="$MYSQL_BASEDIR" \
        --datadir="$DATADIR" \
        --port="$MYSQL_PORT" \
        --bind-address=127.0.0.1 \
        --socket="$SOCKET" \
        --pid-file="$TMP_DIR/mysqld.pid" \
        --log-error="$WORK/mysqld.log" \
        --mysqlx=OFF \
        --skip-networking=OFF >/dev/null 2>&1 &
    MYSQL_PID=$!
    MYSQL_STARTED=1

    local tries=0
    while [[ $tries -lt 120 ]]; do
        if "$MYSQLADMIN_BIN" --no-defaults --protocol=TCP -h127.0.0.1 -P"$MYSQL_PORT" -uroot ping >/dev/null 2>&1; then
            break
        fi
        if ! kill -0 "$MYSQL_PID" 2>/dev/null; then
            printf '%s--- mysqld.log ---%s\n' "$BOLD" "$RESET" >&2
            tail -n 40 "$WORK/mysqld.log" >&2 2>/dev/null || true
            fatal "mysqld terminó inesperadamente (revisa $WORK/mysqld.log)"
        fi
        tries=$((tries + 1))
        sleep 0.5
    done
    if [[ $tries -ge 120 ]]; then
        tail -n 40 "$WORK/mysqld.log" >&2 2>/dev/null || true
        fatal "MySQL no respondió en 127.0.0.1:$MYSQL_PORT"
    fi
    pass_assert "MySQL aislado arriba en 127.0.0.1:$MYSQL_PORT (datadir propio)"

    # Crear la base de datos de la copia (nunca la del usuario).
    local create_out create_rc
    create_out=$("$PHP_BIN" -d display_errors=0 -r '
$pdo = new PDO("mysql:host=127.0.0.1;port=" . $argv[1] . ";charset=utf8mb4", "root", "");
$pdo->exec("CREATE DATABASE IF NOT EXISTS `" . $argv[2] . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "DB_OK";
' "$MYSQL_PORT" "$E2E_DB_NAME" 2>&1)
    create_rc=$?
    if [[ $create_rc -ne 0 || "$create_out" != *"DB_OK"* ]]; then
        fatal "no se pudo crear la base $E2E_DB_NAME: $create_out"
    fi

    # --- Instalación --------------------------------------------------------
    local install_out install_rc
    install_out=$( cd "$APP_DIR" && "$PHP_BIN" -d display_errors=1 public/install.php 2>&1 )
    install_rc=$?
    if [[ $install_rc -ne 0 ]]; then
        fail_assert "php public/install.php (CLI)" "$install_out"
        fatal "la instalación falló (código $install_rc)"
    fi
    pass_assert "php public/install.php (CLI) termina con código 0"
    if [[ "$install_out" == *"OK"* ]]; then
        pass_assert "la salida de install.php contiene OK"
    else
        fail_assert "la salida de install.php contiene OK" "salida: $(printf '%s' "$install_out" | tr '\n' ' ' | cut -c1-400)"
    fi

    # Comprobar las tablas fiscales y la fila de configuración id=1.
    local dbinfo
    dbinfo=$( cd "$APP_DIR" && "$PHP_BIN" -d display_errors=0 -r '
require "src/bootstrap.php";
$pdo = Database::pdo();
$tables = implode(",", $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
$settings = (int) $pdo->query("SELECT COUNT(*) FROM fiscal_settings WHERE id = 1")->fetchColumn();
echo json_encode(["tables" => $tables, "settings_row" => $settings]);
' 2>&1 )
    BODY="$dbinfo"
    assert_py "existe la tabla fiscal_settings" "'fiscal_settings' in d['tables'].split(',')"
    assert_py "existe la tabla cfdi_invoices" "'cfdi_invoices' in d['tables'].split(',')"
    assert_py "existe la tabla fiscal_periods" "'fiscal_periods' in d['tables'].split(',')"
    assert_py "fiscal_settings tiene la fila id=1" "d['settings_row'] == 1"
    BODY=""
    if [[ $SCEN_FAIL -gt 0 ]]; then
        fatal "el esquema fiscal no quedó instalado; revisa install.php y db/fiscal.sql"
    fi

    # --- Servidor PHP -------------------------------------------------------
    pushd "$APP_DIR" >/dev/null
    "$PHP_BIN" -S "127.0.0.1:$HTTP_PORT" -t public public/index.php \
        >"$WORK/php-server.log" 2>&1 &
    PHP_PID=$!
    PHP_STARTED=1
    popd >/dev/null

    tries=0
    local code=""
    while [[ $tries -lt 120 ]]; do
        code=$("$CURL_BIN" -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$HTTP_PORT/api/health" 2>/dev/null || true)
        if [[ "$code" == "200" ]]; then
            break
        fi
        if ! kill -0 "$PHP_PID" 2>/dev/null; then
            tail -n 40 "$WORK/php-server.log" >&2 2>/dev/null || true
            fatal "el servidor PHP terminó inesperadamente (revisa $WORK/php-server.log)"
        fi
        tries=$((tries + 1))
        sleep 0.25
    done
    if [[ "$code" != "200" ]]; then
        tail -n 40 "$WORK/php-server.log" >&2 2>/dev/null || true
        fatal "el servidor PHP no respondió en 127.0.0.1:$HTTP_PORT/api/health"
    fi
    pass_assert "php -S arriba en 127.0.0.1:$HTTP_PORT (health 200)"
}

# ---------------------------------------------------------------------------
# Escenarios
# ---------------------------------------------------------------------------
scenario_health_auth() {
    scenario "2. Healthcheck y autenticación (401 sin token)"

    request GET "/api/health"
    assert_status "GET /api/health" 200
    assert_py "GET /api/health devuelve ok=true" "d.get('ok') is True"

    request GET "/api/fiscal/summary"
    assert_status "GET /api/fiscal/summary sin token" 401
    assert_py "401 sin token devuelve 'No autenticado'" "'No autenticado' in str(d.get('error', ''))"

    request GET "/api/fiscal/periods"
    assert_status "GET /api/fiscal/periods sin token" 401
}

scenario_login() {
    scenario "3. Login de usuarios demo (contraseña cacao123)"

    request POST "/api/auth/login" '{"email":"admin@bancocacao.pe","password":"cacao123"}'
    assert_status "login admin" 200
    assert_py "admin tiene rol ADMIN" "d.get('user', {}).get('role') == 'ADMIN'"
    ADMIN_TOKEN=$(json_get "d['token']")
    ADMIN_ID=$(json_get "d['user']['id']")
    require_value "ADMIN_TOKEN" "$ADMIN_TOKEN"
    require_value "ADMIN_ID" "$ADMIN_ID"
    if [[ -n "$ADMIN_TOKEN" && -n "$ADMIN_ID" ]]; then
        pass_assert "token e id de admin obtenidos"
    fi

    request POST "/api/auth/login" '{"email":"cliente@bancocacao.pe","password":"cacao123"}'
    assert_status "login cliente" 200
    assert_py "cliente tiene rol CLIENT" "d.get('user', {}).get('role') == 'CLIENT'"
    CLIENT_TOKEN=$(json_get "d['token']")
    CLIENT_ID=$(json_get "d['user']['id']")
    require_value "CLIENT_TOKEN" "$CLIENT_TOKEN"
    require_value "CLIENT_ID" "$CLIENT_ID"
    if [[ -n "$CLIENT_TOKEN" && -n "$CLIENT_ID" ]]; then
        pass_assert "token e id de cliente obtenidos"
    fi

    request POST "/api/auth/login" '{"email":"cliente@bancocacao.pe","password":"incorrecta"}'
    assert_status "login con contraseña incorrecta" 401
}

scenario_stp_movements() {
    scenario "4. Movimientos STP (3 depósitos y 1 retiro)"

    request POST "/api/stp/deposit" '{"amountMxn":500,"reference":"REF-500"}' "$CLIENT_TOKEN"
    assert_status "depósito cliente 500 MXN" 201
    DEP500=$(json_get "d['transfer']['id']")
    require_value "DEP500" "$DEP500"

    request POST "/api/stp/deposit" '{"amountMxn":300,"reference":"REF-300"}' "$CLIENT_TOKEN"
    assert_status "depósito cliente 300 MXN" 201
    DEP300=$(json_get "d['transfer']['id']")
    require_value "DEP300" "$DEP300"

    request POST "/api/stp/deposit" '{"amountMxn":200,"reference":"REF-200"}' "$CLIENT_TOKEN"
    assert_status "depósito cliente 200 MXN" 201
    DEP200=$(json_get "d['transfer']['id']")
    require_value "DEP200" "$DEP200"
    if [[ -n "$DEP500" && -n "$DEP300" && -n "$DEP200" ]]; then
        pass_assert "ids de los tres depósitos obtenidos"
    fi

    request POST "/api/stp/withdraw" \
        '{"amountCacao":300,"clabe":"012345678901234567","bankName":"Banco Prueba","beneficiary":"Ana Pérez"}' \
        "$CLIENT_TOKEN"
    assert_status "retiro cliente 300 cacao" 201
    assert_py "retiro con direction OUT" "d.get('transfer', {}).get('direction') == 'OUT'"
    WITHDRAW_ID=$(json_get "d['transfer']['id']")
    require_value "WITHDRAW_ID" "$WITHDRAW_ID"
}

scenario_summary_initial() {
    scenario "5. Summary inicial (sin CFDI): depósitos 1000, retiros 300, REVISAR"

    request GET "/api/fiscal/summary?from=$FROM&to=$TO" "" "$ADMIN_TOKEN"
    assert_status "GET /api/fiscal/summary admin" 200
    assert_py "scope GLOBAL y user_id null" "d.get('scope') == 'GLOBAL' and d.get('user_id') is None"
    assert_py "periodo del summary" "d['period']['from'] == '$FROM' and d['period']['to'] == '$TO'"
    assert_py "depósitos 1000.00 (3 movimientos)" "abs(d['movimientos']['depositos_mxn'] - 1000) <= 0.011 and d['movimientos']['depositos_count'] == 3"
    assert_py "retiros 300.00 (1 movimiento)" "abs(d['movimientos']['retiros_mxn'] - 300) <= 0.011 and d['movimientos']['retiros_count'] == 1"
    assert_py "neto 700.00" "abs(d['movimientos']['neto_mxn'] - 700) <= 0.011"
    assert_py "facturado_neto 0 e cfdi_vigentes 0" "abs(d['facturacion']['facturado_neto']) <= 0.011 and d['facturacion']['cfdi_vigentes'] == 0"
    assert_py "cobertura 0 %" "abs(d['congruencia']['cobertura_pct']) <= 0.011"
    assert_py "estado REVISAR" "d['congruencia']['estado'] == 'REVISAR'"
    assert_py "cuadre ledger ok" "d['congruencia']['cuadre_ledger']['ok'] is True"
    assert_py "cuadre ledger: depósitos ledger == STP" "abs(d['congruencia']['cuadre_ledger']['depositos_ledger'] - 1000) <= 0.011 and abs(d['congruencia']['cuadre_ledger']['depositos_stp'] - 1000) <= 0.011"
    assert_py "cuadre ledger: retiros ledger == STP" "abs(d['congruencia']['cuadre_ledger']['retiros_ledger'] - 300) <= 0.011 and abs(d['congruencia']['cuadre_ledger']['retiros_stp'] - 300) <= 0.011"
    assert_py "alerta DEPOSITOS_SIN_FACTURA" "any(a.get('tipo') == 'DEPOSITOS_SIN_FACTURA' for a in d['alertas'])"
    assert_py "alerta CONFIGURACION_FISCAL (sin RFC emisor)" "any(a.get('tipo') == 'CONFIGURACION_FISCAL' for a in d['alertas'])"
    assert_py "settings.configured es false" "d['settings'].get('configured') is False"
    assert_py "IVA por defecto 0.16" "abs(float(d['settings']['iva_rate']) - 0.16) <= 0.0001"
}

scenario_create_invoices() {
    scenario "6. Creación de CFDI (completo 500, parcial 100 y sin depósito 200)"

    local body
    body=$(cat <<JSON
{"uuid":"$INV1","receptorUserId":"$CLIENT_ID","receptorRfc":"CACO010101ABC","receptorNombre":"Cliente Cacao","subtotal":431.03,"iva":68.97,"total":500.00,"tipo":"I","stpTransferId":"$DEP500","formaPago":"03"}
JSON
)
    request POST "/api/fiscal/invoices" "$body" "$ADMIN_TOKEN"
    assert_status "CFDI completo 500 ligado al depósito de 500" 201
    assert_py "CFDI ligado: uuid, estatus y montos" "d['invoice']['uuid_fiscal'] == '$INV1' and d['invoice']['estatus'] == 'VIGENTE' and abs(d['invoice']['subtotal'] - 431.03) <= 0.011 and abs(d['invoice']['iva'] - 68.97) <= 0.011 and abs(d['invoice']['total'] - 500) <= 0.011"
    INV1_ID=$(json_get "d['invoice']['id']")
    require_value "INV1_ID" "$INV1_ID"

    body=$(cat <<JSON
{"uuid":"$INV2","receptorUserId":"$CLIENT_ID","receptorRfc":"CACO010101ABC","receptorNombre":"Cliente Cacao","subtotal":86.21,"iva":13.79,"total":100.00,"tipo":"I","stpTransferId":"$DEP300","formaPago":"03"}
JSON
)
    request POST "/api/fiscal/invoices" "$body" "$ADMIN_TOKEN"
    assert_status "CFDI parcial 100 ligado al depósito de 300" 201
    INV2_ID=$(json_get "d['invoice']['id']")
    require_value "INV2_ID" "$INV2_ID"

    body=$("$PYTHON_BIN" -c 'import json, sys
print(json.dumps({"uuid": sys.argv[1], "receptorUserId": sys.argv[2], "receptorRfc": "CACO010101ABC", "receptorNombre": sys.argv[3], "subtotal": 172.41, "iva": 27.59, "total": 200.00, "tipo": "I", "formaPago": "03"}, ensure_ascii=False))' "$INV3" "$CLIENT_ID" "$NAME3")
    request POST "/api/fiscal/invoices" "$body" "$ADMIN_TOKEN"
    assert_status "CFDI sin depósito 200 (con acentos y comillas)" 201
    INV3_ID=$(json_get "d['invoice']['id']")
    require_value "INV3_ID" "$INV3_ID"

    request GET "/api/fiscal/invoices?from=$FROM&to=$TO" "" "$ADMIN_TOKEN"
    assert_status "GET /api/fiscal/invoices" 200
    assert_py "invoices.totals: 3 vigentes y 0 cancelados" "d['totals']['vigentes'] == 3 and d['totals']['cancelados'] == 0"
    assert_py "invoices.totals: subtotal 689.65, IVA 110.35, total 800" "abs(d['totals']['subtotal'] - 689.65) <= 0.011 and abs(d['totals']['iva'] - 110.35) <= 0.011 and abs(d['totals']['total'] - 800) <= 0.011"
    assert_py "lista con 3 CFDI" "len(d['invoices']) == 3"
    assert_py "el CFDI completo trae stp_transfer_id y referencia" "any(i['uuid_fiscal'] == '$INV1' and i['stp_transfer_id'] == '$DEP500' and i['referencia'] == 'REF-500' for i in d['invoices'])"
}

scenario_summary_congruence() {
    scenario "7. Summary de congruencia: sin facturar 400, cfdi sin depósito 200, diferencia 200, cobertura 60 %"

    request GET "/api/fiscal/summary?from=$FROM&to=$TO" "" "$ADMIN_TOKEN"
    assert_status "GET /api/fiscal/summary (con CFDI)" 200
    assert_py "facturación: ingresos total 800" "d['facturacion']['cfdi_vigentes'] == 3 and abs(d['facturacion']['ingresos_total'] - 800) <= 0.011"
    assert_py "facturación: subtotal 689.65 e IVA 110.35" "abs(d['facturacion']['ingresos_subtotal'] - 689.65) <= 0.011 and abs(d['facturacion']['ingresos_iva'] - 110.35) <= 0.011"
    assert_py "facturado_en_depositos 600" "abs(d['congruencia']['facturado_en_depositos'] - 600) <= 0.011"
    assert_py "depositos_sin_facturar_total 400" "abs(d['congruencia']['depositos_sin_facturar_total'] - 400) <= 0.011"
    assert_py "cfdi_sin_deposito_total 200" "abs(d['congruencia']['cfdi_sin_deposito_total'] - 200) <= 0.011"
    assert_py "diferencia_total 200" "abs(d['congruencia']['diferencia_total'] - 200) <= 0.011"
    assert_py "cobertura 60 %" "abs(d['congruencia']['cobertura_pct'] - 60) <= 0.011"
    assert_py "estado REVISAR" "d['congruencia']['estado'] == 'REVISAR'"
    assert_py "cuadre ledger ok" "d['congruencia']['cuadre_ledger']['ok'] is True"
    assert_py "alerta DEPOSITOS_SIN_FACTURA con monto 400 (ALTA)" "any(a.get('tipo') == 'DEPOSITOS_SIN_FACTURA' and abs(a.get('monto', 0) - 400) <= 0.011 and a.get('severidad') == 'ALTA' for a in d['alertas'])"
    assert_py "alerta CFDI_SIN_DEPOSITO con monto 200" "any(a.get('tipo') == 'CFDI_SIN_DEPOSITO' and abs(a.get('monto', 0) - 200) <= 0.011 for a in d['alertas'])"
    assert_py "por_forma_pago: 03 con 3 CFDI y total 800" "len(d['por_forma_pago']) == 1 and d['por_forma_pago'][0]['forma_pago'] == '03' and d['por_forma_pago'][0]['count'] == 3 and abs(d['por_forma_pago'][0]['total'] - 800) <= 0.011"
    assert_py "por_mes incluye el mes con facturado 800" "any(m['period'] == '$MONTH' and abs(m['facturado'] - 800) <= 0.011 and abs(m['depositos'] - 1000) <= 0.011 and abs(m['retiros'] - 300) <= 0.011 for m in d['por_mes'])"
}

scenario_congruence_detail() {
    scenario "8. Detalle de congruencia (facturado/parcial/sin factura)"

    request GET "/api/fiscal/congruence?from=$FROM&to=$TO" "" "$ADMIN_TOKEN"
    assert_status "GET /api/fiscal/congruence" 200
    assert_py "scope GLOBAL" "d.get('scope') == 'GLOBAL'"
    assert_py "resumen congruencia: 400/200/200" "abs(d['resumen']['depositos_sin_facturar_total'] - 400) <= 0.011 and abs(d['resumen']['cfdi_sin_deposito_total'] - 200) <= 0.011 and abs(d['resumen']['diferencia_total'] - 200) <= 0.011"
    assert_py "3 depósitos en la congruencia" "len(d['depositos']) == 3"
    assert_py "depósito 500: FACTURADO y facturado 500" "next(x for x in d['depositos'] if x['id'] == '$DEP500')['estado'] == 'FACTURADO' and abs(next(x for x in d['depositos'] if x['id'] == '$DEP500')['facturado'] - 500) <= 0.011"
    assert_py "depósito 300: PARCIAL y facturado 100" "next(x for x in d['depositos'] if x['id'] == '$DEP300')['estado'] == 'PARCIAL' and abs(next(x for x in d['depositos'] if x['id'] == '$DEP300')['facturado'] - 100) <= 0.011"
    assert_py "depósito 200: SIN_FACTURA y facturado 0" "next(x for x in d['depositos'] if x['id'] == '$DEP200')['estado'] == 'SIN_FACTURA' and abs(next(x for x in d['depositos'] if x['id'] == '$DEP200')['facturado']) <= 0.011"
    assert_py "retiro 300: SIN_FACTURA con diferencia 300" "len(d['retiros']) == 1 and d['retiros'][0]['estado'] == 'SIN_FACTURA' and abs(d['retiros'][0]['diferencia'] - 300) <= 0.011"
    assert_py "cfdi_sin_deposito contiene el CFDI de 200" "len(d['cfdi_sin_deposito']) == 1 and d['cfdi_sin_deposito'][0]['uuid_fiscal'] == '$INV3'"
    assert_py "cfdi_cancelados_con_deposito vacío" "isinstance(d['cfdi_cancelados_con_deposito'], list) and len(d['cfdi_cancelados_con_deposito']) == 0"
    assert_py "cuadre_ledger ok en congruencia" "d['cuadre_ledger']['ok'] is True"
}

scenario_rejections() {
    scenario "9. Rechazos de CFDI y periodos inválidos"

    local body
    body=$(cat <<JSON
{"uuid":"$INV4","receptorUserId":"$CLIENT_ID","receptorRfc":"CACO010101ABC","receptorNombre":"Cliente Cacao","subtotal":431.03,"iva":68.97,"total":500.00,"tipo":"I","stpTransferId":"$DEP200"}
JSON
)
    request POST "/api/fiscal/invoices" "$body" "$ADMIN_TOKEN"
    assert_status "CFDI que excede el depósito de 200" 400
    assert_py "error de exceso menciona la transferencia" "'excede' in str(d.get('error', '')).lower()"

    body=$(cat <<JSON
{"uuid":"$INV1","receptorUserId":"$CLIENT_ID","receptorRfc":"CACO010101ABC","receptorNombre":"Cliente Cacao","subtotal":86.21,"iva":13.79,"total":100.00,"tipo":"I"}
JSON
)
    request POST "/api/fiscal/invoices" "$body" "$ADMIN_TOKEN"
    assert_status "UUID fiscal duplicado" 409
    assert_py "error de UUID duplicado" "'ya existe' in str(d.get('error', '')).lower()"

    body=$(cat <<JSON
{"uuid":"$INV5","receptorUserId":"$CLIENT_ID","receptorRfc":"ABC123","receptorNombre":"Cliente Cacao","subtotal":86.21,"iva":13.79,"total":100.00,"tipo":"I"}
JSON
)
    request POST "/api/fiscal/invoices" "$body" "$ADMIN_TOKEN"
    assert_status "RFC de receptor inválido" 400

    body=$(cat <<JSON
{"uuid":"$INV6","receptorUserId":"$CLIENT_ID","receptorRfc":"CACO010101ABC","receptorNombre":"Cliente Cacao","subtotal":86.21,"iva":13.79,"total":100.00,"tipo":"E"}
JSON
)
    request POST "/api/fiscal/invoices" "$body" "$ADMIN_TOKEN"
    assert_status "CFDI tipo E sin uuidRelacionado" 400

    request GET "/api/fiscal/summary?from=$TO&to=$FROM" "" "$ADMIN_TOKEN"
    assert_status "periodo con from > to" 400
    assert_py "error de periodo inválido" "'Periodo' in str(d.get('error', '')) or 'inválido' in str(d.get('error', ''))"

    request GET "/api/fiscal/invoices?from=2026-13-01&to=2026-13-31" "" "$ADMIN_TOKEN"
    assert_status "fecha con formato inválido" 400

    request GET "/api/fiscal/invoices?from=$FROM&to=$TO&estatus=OTRO" "" "$ADMIN_TOKEN"
    assert_status "filtro estatus inválido" 400
}

scenario_roles_scope() {
    scenario "10. Roles y alcance (403 cliente, scope OWN)"

    local body
    body=$(cat <<JSON
{"uuid":"$INV7","receptorUserId":"$CLIENT_ID","receptorRfc":"CACO010101ABC","receptorNombre":"Cliente Cacao","subtotal":86.21,"iva":13.79,"total":100.00,"tipo":"I"}
JSON
)
    request POST "/api/fiscal/invoices" "$body" "$CLIENT_TOKEN"
    assert_status "cliente intenta crear CFDI" 403

    request POST "/api/fiscal/periods/close" "{\"period\":\"$MONTH\"}" "$CLIENT_TOKEN"
    assert_status "cliente intenta cerrar periodo" 403

    request GET "/api/fiscal/settings" "" "$CLIENT_TOKEN"
    assert_status "cliente puede leer settings" 200

    request GET "/api/fiscal/summary?from=$FROM&to=$TO" "" "$CLIENT_TOKEN"
    assert_status "cliente consulta su summary" 200
    assert_py "scope OWN para el cliente" "d.get('scope') == 'OWN'"
    assert_py "user_id del cliente en scope OWN" "d.get('user_id') == '$CLIENT_ID'"
    assert_py "cliente ve sus depósitos 1000 y retiros 300" "abs(d['movimientos']['depositos_mxn'] - 1000) <= 0.011 and abs(d['movimientos']['retiros_mxn'] - 300) <= 0.011"
    assert_py "cliente ve sus CFDI (800 facturado)" "abs(d['facturacion']['ingresos_total'] - 800) <= 0.011"

    request GET "/api/fiscal/summary?from=$FROM&to=$TO&user_id=$ADMIN_ID" "" "$CLIENT_TOKEN"
    assert_status "cliente intenta filtrar por user_id de otro" 200
    assert_py "el filtro user_id se ignora para no-admin" "d.get('scope') == 'OWN' and d.get('user_id') == '$CLIENT_ID'"
    assert_py "totales del cliente siguen siendo los suyos" "abs(d['movimientos']['depositos_mxn'] - 1000) <= 0.011"

    request GET "/api/fiscal/summary?from=$FROM&to=$TO&user_id=$CLIENT_ID" "" "$ADMIN_TOKEN"
    assert_status "admin filtra por user_id de un cliente" 200
    assert_py "admin conserva scope GLOBAL con filtro" "d.get('scope') == 'GLOBAL' and d.get('user_id') == '$CLIENT_ID'"
    assert_py "totales filtrados del cliente" "abs(d['movimientos']['depositos_mxn'] - 1000) <= 0.011"

    request GET "/api/fiscal/summary?from=$FROM&to=$TO&user_id=$BOGUS_USER" "" "$ADMIN_TOKEN"
    assert_status "admin filtra por user_id inexistente" 404
}

scenario_settings() {
    scenario "11. Configuración fiscal (GET/POST, configured, 403 y RFC inválido)"

    request GET "/api/fiscal/settings" "" "$ADMIN_TOKEN"
    assert_status "GET /api/fiscal/settings admin" 200
    assert_py "settings sin configurar (configured=false)" "d.get('configured') is False and d['settings'].get('rfc_emisor') == ''"
    assert_py "settings por defecto: régimen 612, serie A, IVA 0.16" "d['settings']['regimen_fiscal'] == '612' and d['settings']['serie'] == 'A' and abs(float(d['settings']['iva_rate']) - 0.16) <= 0.0001"

    local body
    body=$(cat <<JSON
{"rfcEmisor":"CACO010101ABC","razonSocial":"CacaoPay de México, S.A. de C.V.","regimenFiscal":"612","codigoPostal":"01000","serie":"A","ivaRate":0.16,"moneda":"MXN"}
JSON
)
    request POST "/api/fiscal/settings" "$body" "$ADMIN_TOKEN"
    assert_status "POST /api/fiscal/settings admin" 200
    assert_py "POST settings devuelve configured=true y RFC" "d.get('configured') is True and d['settings']['rfc_emisor'] == 'CACO010101ABC' and d['settings']['razon_social'].startswith('CacaoPay')"

    request GET "/api/fiscal/settings" "" "$ADMIN_TOKEN"
    assert_status "GET settings tras guardar" 200
    assert_py "la configuración persiste" "d.get('configured') is True and d['settings']['rfc_emisor'] == 'CACO010101ABC' and d['settings']['codigo_postal'] == '01000'"

    request POST "/api/fiscal/settings" '{"rfcEmisor":"CACO010101ABC"}' "$CLIENT_TOKEN"
    assert_status "cliente intenta guardar settings" 403

    request POST "/api/fiscal/settings" '{"rfcEmisor":"INVALIDO"}' "$ADMIN_TOKEN"
    assert_status "admin guarda RFC inválido" 400
}

scenario_cancel() {
    scenario "12. Cancelación de CFDI (excluye de sumas y 409 al recancelar)"

    request POST "/api/fiscal/invoices/cancel" "{\"uuid\":\"$INV2\",\"reason\":\"Prueba e2e\"}" "$ADMIN_TOKEN"
    assert_status "cancelar el CFDI parcial de 100" 200
    assert_py "el CFDI cancelado responde con estatus CANCELADA" "d['invoice']['estatus'] == 'CANCELADA'"

    request GET "/api/fiscal/invoices?from=$FROM&to=$TO&estatus=CANCELADA" "" "$ADMIN_TOKEN"
    assert_status "listar CFDI cancelados" 200
    assert_py "totals del periodo sin filtro estatus: 2 vigentes, 1 cancelado, total 700, cancelado_total 100" "d['totals']['vigentes'] == 2 and d['totals']['cancelados'] == 1 and abs(d['totals']['subtotal'] - 603.44) <= 0.011 and abs(d['totals']['iva'] - 96.56) <= 0.011 and abs(d['totals']['total'] - 700) <= 0.011 and abs(d['totals']['cancelado_total'] - 100) <= 0.011"
    assert_py "el cancelado aparece con motivo, fecha y estatus" "len(d['invoices']) == 1 and d['invoices'][0]['uuid_fiscal'] == '$INV2' and d['invoices'][0]['estatus'] == 'CANCELADA' and d['invoices'][0].get('cancel_reason') == 'Prueba e2e' and bool(d['invoices'][0].get('cancelled_at'))"

    request GET "/api/fiscal/summary?from=$FROM&to=$TO" "" "$ADMIN_TOKEN"
    assert_status "summary tras cancelar" 200
    assert_py "facturación: ingresos 700 y 2 vigentes, 1 cancelado" "abs(d['facturacion']['ingresos_total'] - 700) <= 0.011 and d['facturacion']['cfdi_vigentes'] == 2 and d['facturacion']['cfdi_cancelados'] == 1"
    assert_py "congruencia: facturado 500, sin facturar 500, cfdi sin depósito 200" "abs(d['congruencia']['facturado_en_depositos'] - 500) <= 0.011 and abs(d['congruencia']['depositos_sin_facturar_total'] - 500) <= 0.011 and abs(d['congruencia']['cfdi_sin_deposito_total'] - 200) <= 0.011"
    assert_py "congruencia: diferencia 300 y cobertura 50 %" "abs(d['congruencia']['diferencia_total'] - 300) <= 0.011 and abs(d['congruencia']['cobertura_pct'] - 50) <= 0.011"
    assert_py "alerta CFDI_CANCELADO_CON_DEPOSITO con monto 100" "any(a.get('tipo') == 'CFDI_CANCELADO_CON_DEPOSITO' and abs(a.get('monto', 0) - 100) <= 0.011 for a in d['alertas'])"

    request GET "/api/fiscal/congruence?from=$FROM&to=$TO" "" "$ADMIN_TOKEN"
    assert_status "congruence tras cancelar" 200
    assert_py "cfdi_cancelados_con_deposito reporta el CFDI cancelado" "len(d['cfdi_cancelados_con_deposito']) == 1 and d['cfdi_cancelados_con_deposito'][0]['uuid_fiscal'] == '$INV2' and d['cfdi_cancelados_con_deposito'][0]['stp_transfer_id'] == '$DEP300'"

    request POST "/api/fiscal/invoices/cancel" "{\"uuid\":\"$INV2\"}" "$ADMIN_TOKEN"
    assert_status "recancelar el mismo CFDI" 409
    assert_py "error de CFDI ya cancelado" "'ya está cancelado' in str(d.get('error', '')).lower()"
}

scenario_export() {
    scenario "13. Exportación CSV (cfdi, movimientos y congruencia)"

    local f="$WORK/export_cfdi.csv"
    request_file "/api/fiscal/export?from=$FROM&to=$TO&type=cfdi&format=csv" "$ADMIN_TOKEN" "$f"
    assert_status "export cfdi" 200
    assert_header "export cfdi: Content-Type text/csv" "Content-Type" "text/csv"
    assert_header "export cfdi: Content-Disposition attachment" "Content-Disposition" "attachment"
    assert_header "export cfdi: nombre de archivo" "Content-Disposition" "filename=\"cfdi_${FROM}_${TO}.csv\""
    assert_header "export cfdi: expone Content-Disposition" "Access-Control-Expose-Headers" "Content-Disposition"

    py_check "CSV cfdi: BOM UTF-8" "$f" '
if not raw.startswith(b"\xef\xbb\xbf"):
    sys.exit("no empieza con BOM EF BB BF")'

    py_check "CSV cfdi: encabezado exacto" "$f" '
text = raw[3:].decode("utf-8")
lines = text.split("\r\n")
cols = next(csv.reader([lines[0]]))
exp = extra.split(",")
if cols != exp:
    sys.exit("encabezado %r != esperado %r" % (cols, exp))' "$CFDI_HEADER"

    py_check "CSV cfdi: > 1 línea" "$f" '
text = raw[3:].decode("utf-8")
rows = [line for line in text.split("\r\n") if line.strip()]
if len(rows) <= 1:
    sys.exit("solo %d línea(s)" % len(rows))'

    py_check "CSV cfdi: acentos y comillas sobreviven" "$f" '
text = raw[3:].decode("utf-8")
rows = list(csv.reader(io.StringIO(text)))
hdr = rows[0]
if "NOMBRE_RECEPTOR" not in hdr:
    sys.exit("no existe la columna NOMBRE_RECEPTOR")
idx = hdr.index("NOMBRE_RECEPTOR")
vals = [r[idx] for r in rows[1:] if len(r) > idx]
if extra not in vals:
    sys.exit("no aparece %r; valores=%r" % (extra, vals))' "$NAME3"

    py_check "CSV cfdi: separador CRLF y comillas dobles" "$f" '
text = raw[3:].decode("utf-8")
if "\r\n" not in text:
    sys.exit("no usa CRLF")
if not text.startswith("\"") and "," not in text.split("\r\n")[0]:
    sys.exit("formato inesperado")'

    f="$WORK/export_movimientos.csv"
    request_file "/api/fiscal/export?from=$FROM&to=$TO&type=movimientos&format=csv" "$ADMIN_TOKEN" "$f"
    assert_status "export movimientos" 200
    assert_header "export movimientos: Content-Type text/csv" "Content-Type" "text/csv"
    assert_header "export movimientos: nombre de archivo" "Content-Disposition" "filename=\"movimientos_${FROM}_${TO}.csv\""
    py_check "CSV movimientos: BOM UTF-8" "$f" '
if not raw.startswith(b"\xef\xbb\xbf"):
    sys.exit("no empieza con BOM EF BB BF")'
    py_check "CSV movimientos: encabezado exacto" "$f" '
text = raw[3:].decode("utf-8")
cols = next(csv.reader([text.split("\r\n")[0]]))
exp = extra.split(",")
if cols != exp:
    sys.exit("encabezado %r != esperado %r" % (cols, exp))' "$MOV_HEADER"
    py_check "CSV movimientos: incluye los 4 movimientos" "$f" '
text = raw[3:].decode("utf-8")
rows = [line for line in text.split("\r\n") if line.strip()]
if len(rows) < 5:
    sys.exit("se esperaban >= 5 líneas (encabezado + 4 movimientos), hay %d" % len(rows))'

    f="$WORK/export_congruencia.csv"
    request_file "/api/fiscal/export?from=$FROM&to=$TO&type=congruencia&format=csv" "$ADMIN_TOKEN" "$f"
    assert_status "export congruencia" 200
    assert_header "export congruencia: Content-Type text/csv" "Content-Type" "text/csv"
    assert_header "export congruencia: nombre de archivo" "Content-Disposition" "filename=\"congruencia_${FROM}_${TO}.csv\""
    py_check "CSV congruencia: BOM UTF-8" "$f" '
if not raw.startswith(b"\xef\xbb\xbf"):
    sys.exit("no empieza con BOM EF BB BF")'
    py_check "CSV congruencia: encabezado exacto" "$f" '
text = raw[3:].decode("utf-8")
cols = next(csv.reader([text.split("\r\n")[0]]))
exp = extra.split(",")
if cols != exp:
    sys.exit("encabezado %r != esperado %r" % (cols, exp))' "$CONG_HEADER"
    py_check "CSV congruencia: > 1 línea" "$f" '
text = raw[3:].decode("utf-8")
rows = [line for line in text.split("\r\n") if line.strip()]
if len(rows) <= 1:
    sys.exit("solo %d línea(s)" % len(rows))'

    request GET "/api/fiscal/export?from=$FROM&to=$TO&type=otro&format=csv" "" "$ADMIN_TOKEN"
    assert_status "export con type inválido" 400
    assert_py "error de tipo de exportación" "'Tipo de exportación inválido' in str(d.get('error', ''))"

    request GET "/api/fiscal/export?from=$FROM&to=$TO&type=cfdi&format=xml" "" "$ADMIN_TOKEN"
    assert_status "export con format inválido" 400
    assert_py "error de formato no soportado" "'Formato no soportado' in str(d.get('error', ''))"

    request GET "/api/fiscal/export?from=$FROM&to=$TO&type=cfdi" ""
    assert_status "export sin token" 401
}

scenario_close_period() {
    scenario "14. Cierre de periodo (hash SHA-256, revisiones encadenadas)"

    request POST "/api/fiscal/periods/close" '{"period":"2026-13"}' "$ADMIN_TOKEN"
    assert_status "cierre con mes inválido" 400
    assert_py "error de periodo inválido (YYYY-MM)" "'Periodo inválido' in str(d.get('error', ''))"

    request POST "/api/fiscal/periods/close" "{\"period\":\"$FUTURE_MONTH\"}" "$ADMIN_TOKEN"
    assert_status "cierre de un mes futuro" 400
    assert_py "error de periodo futuro" "'futuro' in str(d.get('error', '')).lower()"

    request POST "/api/fiscal/periods/close" "{\"period\":\"$MONTH\"}" "$CLIENT_TOKEN"
    assert_status "cliente intenta cerrar periodo" 403

    request POST "/api/fiscal/periods/close" "{\"period\":\"$MONTH\"}" "$ADMIN_TOKEN"
    assert_status "admin cierra el periodo actual" 201
    assert_py "cierre: period_key, revision 1 y prev_digest null" "d['period']['period_key'] == '$MONTH' and d['period']['revision'] == 1 and d['period']['prev_digest'] is None"
    assert_py "cierre: from_date y to_date del mes" "d['period']['from_date'] == '$FROM' and d['period']['to_date'] == '$TO'"
    assert_py "cierre: totals es un snapshot no vacío" "isinstance(d['period']['totals'], dict) and len(d['period']['totals']) > 0"
    local digest1 digest2
    digest1=$(json_get "d['period']['digest']")
    require_value "digest1" "$digest1"
    assert_regex "cierre: digest SHA-256 de 64 hex" "$digest1" '^[0-9a-f]{64}$'

    request GET "/api/fiscal/periods" "" "$ADMIN_TOKEN"
    assert_status "GET /api/fiscal/periods" 200
    assert_py "el cierre revision 1 aparece en el listado" "any(p['period_key'] == '$MONTH' and p['revision'] == 1 and p['digest'] == '$digest1' for p in d['periods'])"
    assert_py "el listado incluye closed_by" "next((p['closed_by'] for p in d['periods'] if p['period_key'] == '$MONTH' and p['revision'] == 1), None) is not None"

    request POST "/api/fiscal/periods/close" "{\"period\":\"$MONTH\"}" "$ADMIN_TOKEN"
    assert_status "recerrar el periodo crea revisión 2" 201
    assert_py "revisión 2 encadenada con el digest anterior" "d['period']['revision'] == 2 and d['period']['prev_digest'] == '$digest1'"
    digest2=$(json_get "d['period']['digest']")
    require_value "digest2" "$digest2"
    assert_regex "revisión 2: digest SHA-256 de 64 hex" "$digest2" '^[0-9a-f]{64}$'
    if [[ "$digest1" != "$digest2" ]]; then
        pass_assert "los digests de las revisiones 1 y 2 son distintos"
    else
        fail_assert "los digests de las revisiones 1 y 2 son distintos" "ambos son $digest1"
    fi

    request GET "/api/fiscal/periods" "" "$ADMIN_TOKEN"
    assert_status "GET /fiscal/periods tras recerrar" 200
    assert_py "el listado tiene las 2 revisiones del mes" "len([p for p in d['periods'] if p['period_key'] == '$MONTH']) == 2"
    assert_py "revisión 2 en el listado con su prev_digest" "any(p['period_key'] == '$MONTH' and p['revision'] == 2 and p['prev_digest'] == '$digest1' and p['digest'] == '$digest2' for p in d['periods'])"

    request GET "/api/fiscal/periods" ""
    assert_status "GET /fiscal/periods sin token" 401
}

scenario_receptor_inference() {
    scenario "15. Regresión: inferencia de receptor desde stpTransferId"

    # (1) CFDI con depósito del CLIENTE y SIN receptor explícito → receptor inferido.
    local new_uuid body
    new_uuid=$("$PYTHON_BIN" -c 'import uuid; print(uuid.uuid4())')
    body=$("$PYTHON_BIN" -c 'import json, sys
print(json.dumps({"uuid": sys.argv[1], "receptorRfc": "CACO010101ABC", "receptorNombre": "Cliente Cacao", "subtotal": 172.41, "iva": 27.59, "total": 200.00, "tipo": "I", "stpTransferId": sys.argv[2]}))' "$new_uuid" "$DEP200")
    request POST "/api/fiscal/invoices" "$body" "$ADMIN_TOKEN"
    assert_status "CFDI con depósito del cliente y sin receptor explícito" 201
    assert_py "el CFDI infiere el receptor y queda VIGENTE" "d['invoice']['uuid_fiscal'] == '$new_uuid' and d['invoice']['estatus'] == 'VIGENTE'"

    request GET "/api/fiscal/invoices?from=$FROM&to=$TO&user_id=$CLIENT_ID" "" "$ADMIN_TOKEN"
    assert_status "listado de CFDI filtrado por el cliente" 200
    assert_py "el CFDI inferido aparece en el scope del cliente" "any(i['uuid_fiscal'] == '$new_uuid' for i in d['invoices'])"

    request GET "/api/fiscal/invoices?from=$FROM&to=$TO&user_id=$ADMIN_ID" "" "$ADMIN_TOKEN"
    assert_py "el CFDI inferido NO aparece en el scope del admin" "all(i['uuid_fiscal'] != '$new_uuid' for i in d['invoices'])"

    request GET "/api/fiscal/congruence?from=$FROM&to=$TO" "" "$ADMIN_TOKEN"
    assert_status "congruence tras facturar el depósito de 200" 200
    assert_py "el depósito de 200 queda FACTURADO por 200" "next(x for x in d['depositos'] if x['id'] == '$DEP200')['estado'] == 'FACTURADO' and abs(next(x for x in d['depositos'] if x['id'] == '$DEP200')['facturado'] - 200) <= 0.011"
    assert_py "el depósito de 200 sale de depósitos sin facturar (quedan 300)" "abs(d['resumen']['depositos_sin_facturar_total'] - 300) <= 0.011"

    # (2) Sin transferencia y sin receptor: el receptor sigue siendo el admin autenticado.
    local admin_uuid admin_body
    admin_uuid=$("$PYTHON_BIN" -c 'import uuid; print(uuid.uuid4())')
    admin_body=$("$PYTHON_BIN" -c 'import json, sys
print(json.dumps({"uuid": sys.argv[1], "receptorRfc": "CACO010101ABC", "receptorNombre": "Administrador", "subtotal": 86.21, "iva": 13.79, "total": 100.00, "tipo": "I"}))' "$admin_uuid")
    request POST "/api/fiscal/invoices" "$admin_body" "$ADMIN_TOKEN"
    assert_status "CFDI sin depósito y sin receptor (default admin)" 201
    request GET "/api/fiscal/invoices?from=$FROM&to=$TO&user_id=$ADMIN_ID" "" "$ADMIN_TOKEN"
    assert_py "sin transferencia el receptor por defecto es el admin" "any(i['uuid_fiscal'] == '$admin_uuid' for i in d['invoices'])"
    request GET "/api/fiscal/invoices?from=$FROM&to=$TO&user_id=$CLIENT_ID" "" "$ADMIN_TOKEN"
    assert_py "el CFDI del admin no aparece en el scope del cliente" "all(i['uuid_fiscal'] != '$admin_uuid' for i in d['invoices'])"

    # (3) Regresión: transferencia del cliente con receptorEmail de otro usuario → 400.
    local email_uuid email_body
    email_uuid=$("$PYTHON_BIN" -c 'import uuid; print(uuid.uuid4())')
    email_body=$("$PYTHON_BIN" -c 'import json, sys
print(json.dumps({"uuid": sys.argv[1], "receptorEmail": "admin@bancocacao.pe", "receptorRfc": "CACO010101ABC", "receptorNombre": "Administrador", "subtotal": 86.21, "iva": 13.79, "total": 100.00, "tipo": "I", "stpTransferId": sys.argv[2]}))' "$email_uuid" "$DEP300")
    request POST "/api/fiscal/invoices" "$email_body" "$ADMIN_TOKEN"
    assert_status "transferencia del cliente con receptorEmail ajeno" 400
    assert_py "error 'La transferencia no pertenece al receptor'" "'no pertenece al receptor' in str(d.get('error', ''))"

    # (4) stpTransferId inexistente → 404.
    local missing_uuid missing_body bogus_transfer
    missing_uuid=$("$PYTHON_BIN" -c 'import uuid; print(uuid.uuid4())')
    bogus_transfer=$("$PYTHON_BIN" -c 'import uuid; print(uuid.uuid4())')
    missing_body=$("$PYTHON_BIN" -c 'import json, sys
print(json.dumps({"uuid": sys.argv[1], "receptorRfc": "CACO010101ABC", "receptorNombre": "Cliente Cacao", "subtotal": 86.21, "iva": 13.79, "total": 100.00, "tipo": "I", "stpTransferId": sys.argv[2]}))' "$missing_uuid" "$bogus_transfer")
    request POST "/api/fiscal/invoices" "$missing_body" "$ADMIN_TOKEN"
    assert_status "stpTransferId inexistente" 404
    assert_py "error 'Transferencia no encontrada'" "'Transferencia no encontrada' in str(d.get('error', ''))"
}

# ---------------------------------------------------------------------------
# Resumen final
# ---------------------------------------------------------------------------
finish() {
    scenario_end

    printf '\n%s================ RESUMEN DE ESCENARIOS ================%s\n' "$BOLD" "$RESET"
    if [[ -n "$SCEN_SUMMARY_TEXT" ]]; then
        printf '%s' "$SCEN_SUMMARY_TEXT"
    fi
    printf '%s=======================================================%s\n' "$BOLD" "$RESET"
    printf 'Asercciones: %d/%d PASS, %d FAIL\n' "$PASS" "$TOTAL" "$FAIL"

    if [[ "$FAIL" -eq 0 && "$TOTAL" -gt 0 ]]; then
        printf '\n%sRESULTADO: PASS%s\n' "$GREEN" "$RESET"
        exit 0
    fi
    printf '\n%sRESULTADO: FAIL %d/%d%s\n' "$RED" "$FAIL" "$TOTAL" "$RESET"
    exit 1
}

# ---------------------------------------------------------------------------
# main
# ---------------------------------------------------------------------------
main() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --keep)
                KEEP=1
                ;;
            --skip-db)
                SKIP_DB=1
                ;;
            --check)
                CHECK_ONLY=1
                ;;
            --help|-h)
                usage
                exit 0
                ;;
            *)
                printf 'Opción desconocida: %s\n\n' "$1" >&2
                usage >&2
                exit 2
                ;;
        esac
        shift
    done

    if [[ "$KEEP" != "1" ]]; then
        KEEP=0
    fi

    # Evitar que variables del entorno del usuario pisen el .env temporal.
    unset DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD DATABASE_URL \
        JWT_SECRET CLIENT_ORIGIN FIAT_CURRENCY CACAO_PER_FIAT \
        INVEST_ANNUAL_RATE LOAN_ANNUAL_RATE LOAN_TERM_DAYS \
        APP_DEBUG APP_INSTALL FISCAL_RFC FISCAL_RAZON_SOCIAL FISCAL_REGIMEN \
        FISCAL_CP FISCAL_SERIE FISCAL_IVA_RATE 2>/dev/null || true

    printf '%sCacaoPay · pruebas e2e del módulo fiscal SAT%s\n' "$BOLD" "$RESET"
    printf '  Repo: %s\n' "$ROOT_DIR"
    printf '  MySQL aislado: 127.0.0.1:%s · PHP: 127.0.0.1:%s\n' "$MYSQL_PORT" "$HTTP_PORT"
    printf '  Temporal: %s\n' "$TMP_DIR"

    preflight

    if [[ "$CHECK_ONLY" -eq 1 ]]; then
        printf '\nPreflight OK. No se ejecutan pruebas (--check).\n'
        printf '\nRESULTADO: SKIP\n'
        exit 0
    fi

    scenario "0. Sintaxis PHP (php -l)"
    lint_php
    scenario_end

    if [[ "$SKIP_DB" -eq 1 ]]; then
        warn "--skip-db: solo se valida sintaxis; no se ejecutan escenarios de base de datos."
        finish
    fi

    if [[ ! -f "$ROOT_DIR/db/fiscal.sql" || ! -f "$ROOT_DIR/src/Fiscal.php" ]]; then
        fatal "el backend fiscal no está completo (faltan db/fiscal.sql y/o src/Fiscal.php)"
    fi

    setup_workdir
    scenario_end

    scenario_health_auth
    scenario_login
    scenario_stp_movements
    scenario_summary_initial
    scenario_create_invoices
    scenario_summary_congruence
    scenario_congruence_detail
    scenario_rejections
    scenario_roles_scope
    scenario_settings
    scenario_cancel
    scenario_export
    scenario_close_period
    scenario_receptor_inference

    finish
}

main "$@"
