# Banco CacaoPay 🍫

Wallet digital **multiusuario** en **PHP 8.1+** (sin framework, PDO) + **MySQL**, donde:

1. Los **depósitos en soles (PEN)** —simulados— se convierten en **cacao** 🍫.
2. El cacao se **transfiere** entre usuarios (P2P).
3. El cacao se **canjea** por productos del catálogo.

Inspirado en la moneda de 1 Sol de la serie *Recursos Naturales del Perú*.

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
| `FIAT_CURRENCY`   | `PEN`              | Moneda fiat base                           |
| `CACAO_PER_FIAT`  | `2`                | Tasa: 1 fiat → 2 cacao                     |
| `APP_DEBUG`       | `false`            | Mostrar errores en pantalla                |

Alternativa: `DATABASE_URL=mysql://usuario:contraseña@localhost:3306/cacaopay`.

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
