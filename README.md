# Banco CacaoPay 🍫

Wallet digital **multiusuario** en **PHP 8.1+** (sin framework, PDO) + **MySQL**, donde:

1. Los **depósitos en pesos mexicanos (MXN)** —simulados vía **STP**— se convierten en **cacao** (1 cacao = 1 MXN).
2. El cacao se **transfiere** entre usuarios (P2P) y se **retira** vía STP a una cuenta bancaria (CLABE).
3. El cacao se **canjea** por productos, se **invierte** en fondos con interés y se usa para **préstamos**.

> Proyecto educativo / prototipo. **No** es un banco real ni está afiliado a ninguna entidad financiera.

## Stack

| Capa     | Tecnología                                       |
|----------|--------------------------------------------------|
| API      | PHP 8.1+, PDO (mysql), JWT HS256, bcrypt         |
| DB       | MySQL 5.7+ / 8.x / MariaDB (InnoDB, utf8mb4)     |
| UI       | HTML + CSS + JavaScript (SPA sin build)          |
| Auth     | `Authorization: Bearer <token>`, roles `ADMIN`/`SELLER`/`CLIENT` |

## Arquitectura

```
┌─────────────────────────────┐
│  public_html/ (SPA estática)│
│  index.php · index.html     │
│  app.js · style.css         │
└──────────────┬──────────────┘
               │ HTTP /api/*
               ▼
┌─────────────────────────────┐
│  src/  (PHP, PDO mysql)     │
│  ├ Auth    ├ Wallet         │
│  ├ Products├ Ledger (2E)    │
│  └ Tx (idempotencia)        │
└──────────────┬──────────────┘
               │ SQL
               ▼
┌─────────────────────────────┐
│  MySQL                      │
│  users · accounts           │
│  transactions · ledger_entries │
│  products · redemptions     │
└─────────────────────────────┘
```

### Libro mayor de doble entrada (Ledger)

El corazón del sistema. Nunca se hace `UPDATE balance = balance - 10` suelto:

- Cada operación crea **una fila** en `ledger_entries` con un **débito** (cuenta que pierde) y un **crédito** (cuenta que gana) por el **mismo monto**.
- **Invariante:** la suma de saldos de TODAS las cuentas siempre es `0`. El dinero no se crea de la nada.
- Las cuentas se bloquean con `SELECT … FOR UPDATE` dentro de una transacción ACID (InnoDB).

| Operación | Débito                | Crédito               |
|-----------|-----------------------|-----------------------|
| Depósito  | `SYSTEM` (emisión)    | Usuario               |
| Transfer  | Usuario emisor        | Usuario receptor      |
| Canje     | Usuario comprador     | Vendedor (o `SYSTEM`) |

### Idempotencia

Las operaciones de dinero aceptan el header `Idempotency-Key: <uuid>`. Si el cliente
reenvía la misma petición (doble clic), se devuelve el **resultado original** en lugar
de cobrar dos veces. Implementado con `transactions.idempotency_key` `UNIQUE`.

## Modelo de datos (resumen)

| Entidad           | Campos clave                                                            |
|-------------------|-------------------------------------------------------------------------|
| `users`           | `email`, `name`, `password_hash`, `role`, `kyc_status`                   |
| `accounts`        | `user_id`, `kind` (`USER`/`SYSTEM`), `currency`, `balance`               |
| `transactions`    | `idempotency_key`, `kind`, `result`                                      |
| `ledger_entries`  | `debit_account_id`, `credit_account_id`, `amount`, `entry_type`, `meta`  |
| `products`        | `name`, `description`, `cacao_price`, `stock`, `seller_id`               |
| `redemptions`     | `user_id`, `product_id`, `cacao_paid`, `quantity`, `transaction_id`      |
| `stp_transfers`   | `direction`, `amount_mxn`, `amount_cacao`, `account_number`, `beneficiary` |
| `investments`     | `principal_cacao`, `annual_rate`, `status`                                |
| `loans`           | `principal_cacao`, `annual_rate`, `term_days`, `status`, `due_at`         |
| `fiscal_settings` | fila única `id=1`: `rfc_emisor`, `razon_social`, `regimen_fiscal`, `codigo_postal`, `serie`, `iva_rate`, `moneda` (identidad fiscal del emisor) |
| `cfdi_invoices`   | `uuid_fiscal` UNIQUE, `tipo` (`I`/`E`/`P`/`T`), `user_id` (receptor), `stp_transfer_id`, `subtotal`, `iva`, `total`, `estatus` (`VIGENTE`/`CANCELADA`) — nunca se borra |
| `fiscal_periods`  | cierres inmutables: `period_key` (`YYYY-MM`), `revision`, `totals` JSON, `digest` SHA-256, `prev_digest` (cadena) |

## Variables de entorno

Copia `.env.example` a `.env` (este archivo **no** se sube al repo):

| Variable          | Default            | Uso                                        |
|-------------------|--------------------|--------------------------------------------|
| `DB_HOST`         | `localhost`        | Host de MySQL                              |
| `DB_PORT`         | `3306`             | Puerto de MySQL                            |
| `DB_NAME`         | `cacaopay`         | Nombre de la base de datos                 |
| `DB_USER`         | `root`             | Usuario de MySQL                           |
| `DB_PASSWORD`     | —                  | Contraseña de MySQL                        |
| `JWT_SECRET`      | dev fallback       | Firma de tokens JWT (cadena larga)         |
| `CLIENT_ORIGIN`   | `*`                | Origen permitido en CORS                   |
| `FIAT_CURRENCY`   | `MXN`              | Moneda fiat base                           |
| `CACAO_PER_FIAT`  | `1`                | Tasa: 1 MXN = 1 cacao                      |
| `INVEST_ANNUAL_RATE` | `0.05`          | Tasa anual de inversión (0.05 = 5%)        |
| `LOAN_ANNUAL_RATE`   | `0.12`          | Tasa anual de préstamos (0.12 = 12%)       |
| `LOAN_TERM_DAYS`     | `30`            | Plazo por defecto de los préstamos         |
| `APP_DEBUG`       | `false`            | Mostrar errores en pantalla                |
| `FISCAL_RFC`      | — (vacío)          | RFC del emisor de CFDI (vacío = sin configurar) |
| `FISCAL_RAZON_SOCIAL` | — (vacío)      | Razón social del emisor                    |
| `FISCAL_REGIMEN`  | `612`              | Clave de régimen fiscal SAT del emisor     |
| `FISCAL_CP`       | — (vacío)          | Código postal del lugar de expedición      |
| `FISCAL_SERIE`    | `A`                | Serie por defecto de los CFDI              |
| `FISCAL_IVA_RATE` | `0.16`             | Tasa de IVA trasladado (0.16 = 16 %)       |
| `FISCAL_MONEDA`   | `MXN`              | Moneda de los CFDI (MXN por defecto)       |

Alternativa: `DATABASE_URL=mysql://usuario:contraseña@localhost:3306/cacaopay`.

Las variables `FISCAL_*` son **opcionales**: si no las defines, el módulo usa los valores
por defecto y la configuración se puede guardar después desde la UI (ADMIN) o con
`POST /api/fiscal/settings`. Ver [Módulo fiscal (SAT)](#módulo-fiscal-sat).

## Despliegue en Hostinger (shared hosting)

1. **Crea la base de datos y el usuario** en hPanel → *MySQL Databases* (host = `localhost`).
   Anota el nombre de la BD, el usuario y la contraseña.
2. **Sube los archivos por FTP** (estructura esperada):

   ```
   public_html/            ← contenido de public/ (raíz web, incluye install.php)
   src/                    ← carpeta src/ (fuera de la raíz web)
   db/                     ← scripts SQL
   .env                    ← tus credenciales de MySQL + JWT_SECRET
   ```

3. **Edita `.env`** con los datos de MySQL de Hostinger y un `JWT_SECRET` fuerte,
   y `APP_INSTALL=true` para permitir el instalador.
4. **Visita `https://tudominio.com/install.php`** una vez: crea las tablas y los
   usuarios/productos demo. Luego **borra `install.php`** y pon `APP_INSTALL=false`.
5. Pon `APP_DEBUG=false`.

> Requiere la extensión **`pdo_mysql`** (viene habilitada por defecto en Hostinger).

📘 **Guía detallada (recomendada): [`docs/despliegue-hostinger.md`](docs/despliegue-hostinger.md)**
— estructura de carpetas, `.env` fuera de `public_html`, instalación nueva vs **actualizar
un sitio ya en línea** (solo `db/fiscal.sql`), checklist de seguridad, verificación en
producción y rollback.

### Usuarios demo (contraseña `cacao123`)

| Correo                    | Rol     |
|---------------------------|---------|
| `admin@bancocacao.pe`     | ADMIN   |
| `vendedor@bancocacao.pe`  | SELLER  |
| `cliente@bancocacao.pe`   | CLIENT  |

## Inicio rápido (local)

```bash
cp .env.example .env        # edita las credenciales de MySQL
mysql -uroot -p -e "CREATE DATABASE cacaopay CHARACTER SET utf8mb4"
mysql -uroot -p cacaopay < db/schema.sql
mysql -uroot -p cacaopay < db/seed.sql
php -S 127.0.0.1:8000 -t public public/index.php
```

- UI: http://127.0.0.1:8000
- API: http://127.0.0.1:8000/api/health

## API endpoints

Base: `/api` · `Content-Type: application/json` · Auth: `Authorization: Bearer <jwt>`

| Método | Ruta                   | Auth           | Descripción                      |
|--------|------------------------|----------------|----------------------------------|
| GET    | `/health`              | No             | Healthcheck y tasa cacao         |
| POST   | `/auth/register`       | No             | Alta de usuario + JWT            |
| POST   | `/auth/login`          | No             | Login + JWT                      |
| GET    | `/auth/me`             | Sí             | Perfil actual                    |
| GET    | `/wallet/balance`      | Sí             | Saldo cacao + tasa               |
| GET    | `/wallet/ledger`       | Sí             | Últimos 100 movimientos          |
| POST   | `/wallet/deposit`      | Sí             | Fiat → cacao (mock)              |
| POST   | `/wallet/transfer`     | Sí             | Transferir cacao por email       |
| GET    | `/products`            | No             | Catálogo activo                  |
| POST   | `/products`            | Sí (SELLER/ADMIN) | Crear producto               |
| POST   | `/products/redeem`     | Sí             | Canjear producto con cacao       |
| POST   | `/stp/deposit`         | Sí             | Entrada STP (MXN → cacao)        |
| POST   | `/stp/withdraw`        | Sí             | Salida STP (cacao → MXN a CLABE) |
| GET    | `/stp/transfers`       | Sí             | Historial de transferencias STP  |
| POST   | `/investments`         | Sí             | Invertir cacao (genera interés)  |
| GET    | `/investments`         | Sí             | Inversiones activas + interés    |
| POST   | `/investments/redeem`  | Sí             | Redimir inversión + interés      |
| POST   | `/loans`               | Sí             | Solicitar préstamo en cacao      |
| GET    | `/loans`               | Sí             | Préstamos activos + interés      |
| POST   | `/loans/repay`         | Sí             | Pagar préstamo + interés         |
| GET    | `/fiscal/settings`     | Sí             | Configuración fiscal (identidad del emisor) |
| POST   | `/fiscal/settings`     | Sí (ADMIN)     | Guardar configuración fiscal     |
| GET    | `/fiscal/summary`      | Sí             | Balance de facturación + congruencia + alertas del periodo |
| GET    | `/fiscal/congruence`   | Sí             | Detalle depósitos/retiros y CFDI sin depósito |
| GET    | `/fiscal/invoices`     | Sí             | CFDI del periodo (filtro `estatus`) |
| POST   | `/fiscal/invoices`     | Sí (ADMIN)     | Registrar CFDI (simulado)        |
| POST   | `/fiscal/invoices/cancel` | Sí (ADMIN)  | Cancelar CFDI (nunca se borra)   |
| GET    | `/fiscal/export`       | Sí             | Export CSV (`cfdi`/`movimientos`/`congruencia`) |
| GET    | `/fiscal/periods`      | Sí             | Cierres fiscales con hash        |
| POST   | `/fiscal/periods/close` | Sí (ADMIN)    | Cerrar periodo (inmutable)       |

Ejemplo (depósito con idempotencia):

```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"cliente@bancocacao.pe","password":"cacao123"}' | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])")

curl -s -X POST http://127.0.0.1:8000/api/wallet/deposit \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen | tr A-Z a-z)" \
  -d '{"amountFiat":10}'
```

## Módulo fiscal (SAT)

Registro y conciliación fiscal **interno** sobre los movimientos de la billetera. El
contrato técnico completo (modelo de datos, reglas de negocio, API y UI) está en
[`docs/fiscal-sat.md`](docs/fiscal-sat.md).

Qué hace:

- **Balance de facturación** por periodo (mes/trimestre/rango libre): subtotal, IVA
  trasladado y total facturado, notas de crédito (`tipo=E`), desglose por forma de pago y
  por mes.
- **Congruencia de depósitos y retiros (STP) contra CFDI**: depósitos sin facturar o
  parcialmente facturados, CFDI vigentes sin depósito que los respalde, CFDI cancelados con
  depósito y **cuadre del libro mayor** (`ledger` vs `stp_transfers`) → estado
  `CUADRA` / `REVISAR` + alertas.
- **Cierre fiscal del periodo** inmutable: folio por `revision` y cadena de **hash SHA-256**
  (`digest = sha256(prev_digest . "|" . json_canonico)`), para entregar al contador o
  conservar como evidencia.
- **Exportación CSV** (Excel/LibreOffice) de CFDI, movimientos y congruencia, más resumen
  JSON para integraciones.
- **Constancia por usuario**: `CLIENT`/`SELLER` ven solo sus CFDI y movimientos (`scope
  OWN`); `ADMIN` ve el consolidado global y puede filtrar por `?user_id=<uuid>`.

En la UI aparece como pestaña **“Fiscal · SAT”** en el header (con sesión iniciada). Los
roles `CLIENT` y `SELLER` ven su constancia; solo `ADMIN` ve el formulario de CFDI, la
configuración fiscal y el botón de cierre de periodo.

### Instalación del módulo

El módulo vive en `db/fiscal.sql` (idempotente, `CREATE TABLE IF NOT EXISTS`) y
`src/Fiscal.php`; las rutas se registran en `public/index.php`.

- **Base de datos nueva:** `php public/install.php` ejecuta `db/schema.sql`,
  `db/seed.sql`, `db/migrate.sql`, `db/fiscal.sql` y `Fiscal::ensureSettings()` (crea la
  fila `fiscal_settings` id=1 con los valores `FISCAL_*` del `.env`).
- **Base de datos existente:** ejecuta solo el script fiscal y añade las variables
  `FISCAL_*` al `.env` (o guarda la configuración desde la UI como ADMIN):

  ```bash
  mysql -u root -p cacaopay < db/fiscal.sql   # idempotente: se puede repetir
  ```

  No crea ni modifica datos de la billetera; solo añade las tres tablas `fiscal_settings`,
  `cfdi_invoices` y `fiscal_periods`.

### Variables de entorno del módulo

| Variable              | Default | Uso                                             |
|-----------------------|---------|-------------------------------------------------|
| `FISCAL_RFC`          | vacío   | RFC del emisor (vacío = módulo sin configurar; genera alerta `CONFIGURACION_FISCAL`) |
| `FISCAL_RAZON_SOCIAL` | vacío   | Razón social del emisor                         |
| `FISCAL_REGIMEN`      | `612`   | Clave de régimen fiscal SAT (601, 606, 612, 626…) |
| `FISCAL_CP`           | vacío   | Código postal del lugar de expedición           |
| `FISCAL_SERIE`        | `A`     | Serie por defecto de los CFDI                   |
| `FISCAL_IVA_RATE`     | `0.16`  | Tasa de IVA trasladado (0.16 = 16 %)            |
| `FISCAL_MONEDA`       | `MXN`   | Moneda de los CFDI (MXN por defecto)            |

Se pueden cambiar después con `POST /api/fiscal/settings` (ADMIN); la BD manda sobre el
`.env` una vez que existe la fila de configuración.

### Endpoints (base `/api`, `Authorization: Bearer <jwt>`)

| Método | Ruta | Rol | Descripción |
|---|---|---|---|
| GET  | `/api/fiscal/settings` | todos | Configuración fiscal + `configured` |
| POST | `/api/fiscal/settings` | ADMIN | Actualizar RFC, razón social, régimen, CP, serie, tasa de IVA |
| GET  | `/api/fiscal/summary?from=&to=&user_id=` | todos | Balance de facturación, movimientos, congruencia, alertas |
| GET  | `/api/fiscal/congruence?from=&to=&user_id=` | todos | Depósitos/retiros conciliados, CFDI sin depósito, cuadre ledger |
| GET  | `/api/fiscal/invoices?from=&to=&estatus=&user_id=` | todos | CFDI del periodo (`estatus` = `VIGENTE`/`CANCELADA`) |
| POST | `/api/fiscal/invoices` | ADMIN | Registrar CFDI simulado (201) |
| POST | `/api/fiscal/invoices/cancel` | ADMIN | Cancelar CFDI (409 si ya estaba cancelado) |
| GET  | `/api/fiscal/export?from=&to=&type=cfdi\|movimientos\|congruencia&format=csv` | todos | CSV con BOM, comillas escapadas y anti-inyección |
| GET  | `/api/fiscal/periods` | todos | Listado de cierres (global) |
| POST | `/api/fiscal/periods/close` | ADMIN | Cerrar periodo `{"period":"YYYY-MM"}` (201) |

Errores: JSON `{ "error": "mensaje en español" }` con `400`, `401`, `403`, `404`, `409` o
`500`. Sin token → `401 No autenticado`; roles no autorizados → `403`.

### Ejemplos con curl

```bash
BASE=http://127.0.0.1:8000

# 1) Login ADMIN
ADMIN_TOKEN=$(curl -s -X POST $BASE/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@bancocacao.pe","password":"cacao123"}' \
  | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])")

# 2) Registrar un depósito y localizar su id (POST /api/stp/deposit)
DEPOSIT_ID=$(curl -s -X POST $BASE/api/stp/deposit \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"amountMxn":500,"reference":"REF-1"}' \
  | python3 -c "import sys,json;print(json.load(sys.stdin)['transfer']['id'])")

# 3) Crear CFDI ligado a ese depósito (subtotal 431.03 + IVA 68.97 = 500.00)
curl -s -X POST $BASE/api/fiscal/invoices \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H 'Content-Type: application/json' \
  -d "{\"uuid\":\"11111111-2222-3333-4444-555555555555\",
       \"receptorRfc\":\"XAXX010101000\",\"receptorNombre\":\"Público en general\",
       \"subtotal\":431.03,\"iva\":68.97,\"total\":500.00,
       \"stpTransferId\":\"$DEPOSIT_ID\",\"formaPago\":\"03\"}"

# 4) Consultar balance y congruencia del periodo
curl -s "$BASE/api/fiscal/summary?from=2026-11-01&to=2026-11-30" \
  -H "Authorization: Bearer $ADMIN_TOKEN"
curl -s "$BASE/api/fiscal/congruence?from=2026-11-01&to=2026-11-30" \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# 5) Exportar CSV (BOM UTF-8; nombre de archivo en Content-Disposition)
curl -s -D- -o cfdi_2026-11.csv \
  "$BASE/api/fiscal/export?from=2026-11-01&to=2026-11-30&type=cfdi&format=csv" \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# 6) Cerrar el periodo (revision 1; recerrar crea revision 2 encadenada)
curl -s -X POST $BASE/api/fiscal/periods/close \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"period":"2026-11"}'

# 7) Ver cierres existentes
curl -s $BASE/api/fiscal/periods -H "Authorization: Bearer $ADMIN_TOKEN"
```

### Pruebas end-to-end

`tests/fiscal-e2e.sh` verifica el módulo completo **sin tocar tu base de datos ni el
`.env` del repo**: copia el proyecto a `.tmp/fiscal-e2e/app`, genera allí un `.env`
temporal, levanta un **MySQL aislado** (puerto `3411`) y un `php -S` (puerto `8111`),
instala con `php public/install.php` y ejecuta los escenarios del contrato §7. Al terminar
mata ambos procesos y borra los temporales (salvo `KEEP=1`).

```bash
tests/fiscal-e2e.sh                     # puertos por defecto: 3411 / 8111
MYSQL_PORT=3412 HTTP_PORT=8112 tests/fiscal-e2e.sh
tests/fiscal-e2e.sh --skip-db           # solo comprobaciones de sintaxis (php -l)
tests/fiscal-e2e.sh --keep              # deja artefactos y servidores para depurar
tests/fiscal-e2e.sh --help
```

Imprime el resultado por escenario y termina con `RESULTADO: PASS` (código 0) o
`RESULTADO: FAIL n/m` (código 1). Requiere `php`, `mysqld`/`mysqladmin`, `curl` y
`python3` (para las aserciones JSON; no necesita `jq`).

### Capturas

- Vista **ADMIN**: [`docs/capturas/fiscal-admin.png`](docs/capturas/fiscal-admin.png) —
  balance, alertas, congruencia, cuadre, captura de CFDI, configuración y cierres.
- Vista **CLIENTE**: [`docs/capturas/fiscal-cliente.png`](docs/capturas/fiscal-cliente.png)
  — constancia propia, sin acciones de administración.

Historial de cambios: [`CHANGELOG.md`](CHANGELOG.md).

> **Aviso legal del módulo fiscal.** Es un módulo de **registro y conciliación fiscal
> interno**, con datos capturados manualmente. Los CFDI son un **registro interno
> simulado**: **no se timbran ni validan ante el SAT**, no se envían a un PAC y **no
> sustituyen la asesoría contable ni fiscal** de un contador. Para deducciones,
> acreditamiento de IVA y obligaciones formales, consulta a tu contador y usa un PAC
> certificado. Ver §9 de [`docs/fiscal-sat.md`](docs/fiscal-sat.md).

## Seguridad

- Contraseñas con **bcrypt** (cost 10).
- JWT firmado con `JWT_SECRET`, expira en **7 días**.
- Rutas de wallet y canje requieren token; crear productos requiere `SELLER`/`ADMIN`.
- Idempotencia para evitar cobros dobles.
- Doble entrada contable para que el dinero se conserve.

## Aviso legal

Crear un banco digital real requiere licencias financieras (CNBV / Banco de México),
dinero electrónico (EMI) y políticas AML. Este es un prototipo educativo.

El **módulo fiscal (SAT)** incluido es solo un registro y conciliación interna con datos
capturados manualmente: **no timbra CFDI ante el SAT** ni sustituye la asesoría de un
contador (ver [Aviso legal del módulo fiscal](#módulo-fiscal-sat)).

## Licencia

Uso educativo / prototipo.
