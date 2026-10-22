# Banco CacaoPay 🍫

Wallet digital **multiusuario** en **PHP 8.1+** (sin framework, PDO) + **PostgreSQL (Neon)**, donde:

1. Los **depósitos en soles (PEN)** —simulados— se convierten en **cacao** 🍫.
2. El cacao se **transfiere** entre usuarios (P2P).
3. El cacao se **canjea** por productos del catálogo.

Inspirado en la moneda de 1 Sol de la serie *Recursos Naturales del Perú*.

> Proyecto educativo / prototipo. **No** es un banco real ni está afiliado a ninguna entidad financiera.

## Stack

| Capa     | Tecnología                                              |
|----------|---------------------------------------------------------|
| API      | PHP 8.1+, PDO (pgsql), JWT HS256, bcrypt                |
| DB       | PostgreSQL 18 (Neon, vía connection string del pooler)  |
| UI       | HTML + CSS + JavaScript (SPA sin build)                 |
| Auth     | `Authorization: Bearer <token>`, roles `ADMIN`/`SELLER`/`CLIENT` |

## Arquitectura

```
┌─────────────────────────────┐
│  public/  (SPA estática)    │
│  index.html · app.js        │
│  style.css                  │
└──────────────┬──────────────┘
               │ HTTP /api/*
               ▼
┌─────────────────────────────┐
│  public/index.php (router)  │
│  src/  (PHP, PDO)           │
│  ├ Auth    ├ Wallet         │
│  ├ Products├ Ledger (2E)    │
│  └ Tx (idempotencia)        │
└──────────────┬──────────────┘
               │ SQL (TLS)
               ▼
┌─────────────────────────────┐
│  Neon PostgreSQL            │
│  users · accounts           │
│  transactions · ledger_entries │
│  products · redemptions     │
└─────────────────────────────┘
```

### Libro mayor de doble entrada (Ledger)

El corazón del sistema. Nunca se hace `UPDATE balance = balance - 10` suelto:

- Cada operación crea **una fila** en `ledger_entries` con un **débito** (cuenta que pierde) y un **crédito** (cuenta que gana) por el **mismo monto**.
- **Invariante:** la suma de saldos de TODAS las cuentas siempre es `0`. El dinero no se crea de la nada.
- Las cuentas se bloquean con `SELECT … FOR UPDATE` dentro de una transacción ACID.

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

## Variables de entorno

Copia `.env.example` a `.env` (este archivo **no** se sube al repo):

| Variable          | Default      | Uso                                             |
|-------------------|--------------|-------------------------------------------------|
| `DATABASE_URL`    | —            | Connection string de Neon (pooler, `sslmode=require`) |
| `JWT_SECRET`      | dev fallback | Firma de tokens JWT (usa una cadena larga)      |
| `CLIENT_ORIGIN`   | `*`          | Origen permitido en CORS                        |
| `FIAT_CURRENCY`   | `PEN`        | Moneda fiat base                                |
| `CACAO_PER_FIAT`  | `2`          | Tasa: 1 fiat → 2 cacao                          |
| `APP_DEBUG`       | `false`      | Mostrar errores en pantalla                     |

## Base de datos (Neon)

1. Crea (o reutiliza) un proyecto Neon y copia la connection string del **pooler**.
2. Aplícala en `.env` como `DATABASE_URL`.
3. Aplica schema y datos demo:

```bash
psql "$DATABASE_URL" -f db/schema.sql
psql "$DATABASE_URL" -f db/seed.sql
```

> Nunca subas la connection string a git. Si se filtra, rota la contraseña en el dashboard de Neon.

## Inicio rápido (local)

```bash
cp .env.example .env   # edita DATABASE_URL y JWT_SECRET
php -S 127.0.0.1:8000 -t public public/index.php
```

- UI: http://127.0.0.1:8000
- API: http://127.0.0.1:8000/api/health

### Usuarios demo (contraseña `cacao123`)

| Correo                    | Rol     |
|---------------------------|---------|
| `admin@bancocacao.pe`     | ADMIN   |
| `vendedor@bancocacao.pe`  | SELLER  |
| `cliente@bancocacao.pe`   | CLIENT  |

## Despliegue en Hostinger (shared hosting)

1. Sube el contenido de `public/` a la raíz (`public_html`) y `src/` a `src/` (fuera de `public_html`, si es posible).
2. Asegúrate de que la extensión **`pdo_pgsql`** esté habilitada en el panel (PHP 8.1+).
3. Sube tu `.env` (con la connection string de Neon) a la raíz del proyecto.
4. El `.htaccess` incluido ya reescribe todas las rutas a `index.php` y reenvía el header `Authorization`.
5. Pon `APP_DEBUG=false` y un `JWT_SECRET` fuerte.

> **Nota:** Hostinger shared hosting suele traer MySQL, no PostgreSQL. Para usar Neon
> necesitas `pdo_pgsql` habilitado. Si tu plan no lo permite, usa un VPS/Cloud de
> Hostinger o cambia el driver a MySQL en `src/Database.php`.

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

## Seguridad

- Contraseñas con **bcrypt** (cost 10).
- JWT firmado con `JWT_SECRET`, expira en **7 días**.
- Rutas de wallet y canje requieren token; crear productos requiere `SELLER`/`ADMIN`.
- Idempotencia para evitar cobros dobles.
- Doble entrada contable para que el dinero se conserve.

## Aviso legal

Crear un banco digital real requiere licencias financieras (SBS en Perú), dinero
electrónico (EMI) y políticas AML. Este es un prototipo educativo.

## Licencia

Uso educativo / prototipo.
