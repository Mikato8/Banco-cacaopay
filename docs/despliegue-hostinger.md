# Despliegue en Hostinger — Banco CacaoPay + módulo fiscal (SAT)

Guía paso a paso para publicar esta versión (con el **módulo fiscal SAT**) en Hostinger
shared hosting, y para **actualizar** una instalación de CacaoPay que ya está en línea
sin perder datos.

> Probado con la estructura de este repo (PHP 8.1+, PDO/MySQL, SPA estática).
> Requiere **`pdo_mysql`** habilitada (viene por defecto en Hostinger) y **PHP 8.1 o superior**
> (recomendado 8.2/8.3 en hPanel → *PHP Configuration*).

---

## 0. Resumen de lo que hay que subir

```
/home/uXXXXXXX/                 ← raíz de tu cuenta (NO accesible por web)
├── .env                        ← credenciales MySQL + JWT_SECRET + FISCAL_*
├── src/                        ← código PHP (nunca dentro de public_html)
├── db/                         ← esquema y migraciones SQL
└── public_html/                ← SOLO el contenido de public/
    ├── index.php
    ├── install.php             ← borrar después de instalar
    ├── index.html
    ├── app.js
    ├── style.css
    ├── assets/
    └── .htaccess
```

⚠️ **Nunca** subas `.env`, `src/` ni `db/` dentro de `public_html/`.
`src/bootstrap.php` busca el `.env` un nivel arriba de la carpeta del proyecto, así que
mantener la estructura de arriba es obligatorio.

`README.md`, `docs/`, `tests/` **no** son necesarios en producción (son documentación y
pruebas; si los subes, que sea fuera de `public_html`).

---

## 1. Base de datos

1. hPanel → **Bases de datos → MySQL Databases**: crea la BD y el usuario, anota:
   - **Host:** `localhost`
   - **BD:** p. ej. `u123456_cacaopay`
   - **Usuario:** p. ej. `u123456_cacaopay`
   - **Contraseña:** la que definas
2. Haz un **respaldo** antes de tocar nada si la instalación ya existe:
   - hPanel → *Bases de datos → phpMyAdmin → Exportar*, o por SSH:
     ```bash
     mysqldump -h localhost -u USUARIO -p BD > ~/backup-cacaopay-$(date +%F).sql
     ```

## 2. Subir los archivos

Tienes dos caminos: **File Manager** (hPanel) o **FTP/SFTP** (FileZilla, `ssh`/`rsync` si tu
plan tiene SSH).

**Opción FTP/File Manager:**

1. Sube el **contenido de `public/`** a `public_html/` (sobrescribe `index.php`, `app.js`,
   `style.css`, `index.html`, `assets/` y conserva el `.htaccess`).
2. Sube **`src/`** y **`db/`** a la **raíz de tu cuenta** (`/home/uXXXXXXX/`), no dentro de
   `public_html/`.
3. Crea `.env` en la raíz de la cuenta (ver §3).

**Opción SSH (si tu plan lo incluye):** desde la raíz del proyecto local:

```bash
# Ajusta usuario@host y la ruta de tu cuenta
rsync -av --delete public/   uXXXXXXX@HOST:~/public_html/
rsync -av         src/       uXXXXXXX@HOST:~/src/
rsync -av         db/        uXXXXXXX@HOST:~/db/
scp .env uXXXXXXX@HOST:~/.env
```

> Ojo con `--delete` en `public_html`: si tienes ahí el `.env` de una instalación vieja,
> muévelo antes a la raíz de la cuenta (ver §6, seguridad).

## 3. El archivo `.env` (fuera de `public_html`)

En la raíz de la cuenta (`/home/uXXXXXXX/.env`) con permisos `600`:

```ini
DB_HOST=localhost
DB_PORT=3306
DB_NAME=u123456_cacaopay
DB_USER=u123456_cacaopay
DB_PASSWORD=TU_PASSWORD

JWT_SECRET=una-cadena-larga-y-aleatoria-de-64-caracteres   # openssl rand -hex 32
CLIENT_ORIGIN=https://tudominio.com
FIAT_CURRENCY=MXN
CACAO_PER_FIAT=1
INVEST_ANNUAL_RATE=0.05
LOAN_ANNUAL_RATE=0.12
LOAN_TERM_DAYS=30

APP_DEBUG=false
APP_INSTALL=false        # ponlo en true SOLO durante la instalación (§4)

# --- Módulo fiscal (SAT) ---
FISCAL_RFC=TU_RFC
FISCAL_RAZON_SOCIAL=Tu Razón Social
FISCAL_REGIMEN=612
FISCAL_CP=01000
FISCAL_SERIE=A
FISCAL_IVA_RATE=0.16
FISCAL_MONEDA=MXN
```

- `JWT_SECRET`: **obsérvalo** (no uses el valor de ejemplo). Si faltaba, la app usaba un
  secreto público por defecto.
- `FISCAL_*` son opcionales: si las dejas vacías, la fila inicial de `fiscal_settings` se
  crea con defaults y puedes configurarlas luego desde la UI como ADMIN.

## 4. Instalar o migrar la base de datos

### A) Instalación nueva (BD vacía)

1. `.env` con `APP_INSTALL=true`.
2. Visita `https://tudominio.com/install.php` **una sola vez**: crea tablas, datos demo y
   las 3 tablas fiscales (`fiscal_settings`, `cfdi_invoices`, `fiscal_periods`).
3. **Borra `install.php`** del servidor y pon `APP_INSTALL=false`.

### B) Actualizar una instalación existente (recomendado si ya está en línea)

**No** ejecutes `install.php` sobre una BD en uso (crearía/renombraría datos demo y puede
duplicar seed). Migra solo el módulo fiscal, que es **idempotente**:

**phpMyAdmin:** importa `db/fiscal.sql` (Importar → archivo).
**SSH/terminal:**

```bash
cd ~
mysql -h localhost -u USUARIO -p BD < db/fiscal.sql
```

`db/fiscal.sql` solo hace `CREATE TABLE IF NOT EXISTS` de las 3 tablas fiscales; no toca
`users`, `accounts`, `ledger_entries`, `stp_transfers` ni ningún dato existente.

Si vienes de una versión previa del repo (antes de STP/inversiones/préstamos), ejecuta
también `db/migrate.sql` (también idempotente).

Después reemplaza el código (`public/`, `src/`) por esta versión, actualiza `.env` con
`FISCAL_*` y limpia la caché del navegador (los assets van con `?v=N`).

## 5. Permisos y `.htaccess`

- Archivos `644`, carpetas `755` (`public/` → `public_html/`).
- `public_html/.htaccess` debe existir (reescribe todas las rutas a `index.php`):

  ```apache
  DirectoryIndex index.php index.html
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.php [QSA,L]
  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
  ```

  La última línea es **importante**: sin ella, la cabecera `Authorization: Bearer` no
  llega a PHP en Apache/CGI y la API responderá `401 No autenticado` en todas las rutas.
- No se necesitan extensiones PHP adicionales más allá de `pdo_mysql` (y `json`, incluida
  por defecto).

## 6. Seguridad (checklist)

- [ ] `.env` **fuera** de `public_html` y con permisos `600`.
- [ ] `APP_DEBUG=false` en producción.
- [ ] `install.php` **borrado** y `APP_INSTALL=false`.
- [ ] `JWT_SECRET` aleatorio y largo (nunca el de ejemplo).
- [ ] HTTPS activo (hPanel → SSL).
- [ ] No subir `tests/`, `docs/`, `README.md` ni `db/` dentro de `public_html`.
- [ ] En una instalación anterior, mover el `.env` que estuviera dentro de `public_html`
      a la raíz de la cuenta; si no, sigue siendo accesible por error de configuración.
- [ ] Revisar `error_log` en hPanel tras el despliegue.

## 7. Verificación en producción (checklist)

```bash
BASE=https://tudominio.com

# 1) API viva
curl -s $BASE/api/health
#    → {"ok":true,"service":"banco-cacaopay","cacao_per_fiat":1,"fiat_currency":"MXN"}

# 2) El módulo fiscal está desplegado (401 = existe y pide token; 404 = falta)
curl -s -o /dev/null -w "%{http_code}\n" $BASE/api/fiscal/summary
#    → 401  (si 404: faltan src/Fiscal.php o las rutas en public/index.php)

# 3) Login de admin y balance fiscal
TOKEN=$(curl -s -X POST $BASE/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@TU_DOMINIO","password":"..."}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["token"])')
curl -s "$BASE/api/fiscal/summary?from=$(date +%Y-%m-01)&to=$(date +%Y-%m-t)" \
  -H "Authorization: Bearer $TOKEN"
#    → JSON con scope, period, facturacion, movimientos, congruencia, alertas
```

En la UI: inicia sesión como ADMIN → pestaña **Fiscal · SAT** → comprueba KPIs, badge
CUADRA/REVISAR, tablas de congruencia, captura de un CFDI de prueba (puedes cancelarlo
después), exportación CSV y cierre de periodo. Como CLIENT comprueba que **no** aparecen
los botones de captura/cierre.

## 8. Rollback

1. Restaura los archivos: guarda una copia de `public_html/` y `src/` anteriores antes de
   sobrescribir (o usa el *File Manager → Historial* / tu backup FTP).
2. Restaura la BD desde el respaldo del §1.
3. Si solo quieres revertir el módulo fiscal (no hay datos fiscales que conservar):
   ```sql
   DROP TABLE IF EXISTS fiscal_periods, cfdi_invoices, fiscal_settings;
   ```
   y quita `src/Fiscal.php`, las rutas `/api/fiscal/*` y las variables `FISCAL_*`.
   El resto de la billetera no depende del módulo fiscal.

## 9. Problemas típicos

| Síntoma | Causa probable | Solución |
|---|---|---|
| `404 Ruta no encontrada` en `/api/fiscal/*` | `public/index.php` viejo o falta `src/Fiscal.php` | Sube el `public/index.php` nuevo y `src/Fiscal.php` |
| `500 Error interno` en cualquier ruta | Error de PHP / permisos / versión PHP | hPanel → *PHP Configuration* 8.2+, revisa `error_log` |
| `401 No autenticado` siempre | El `.htaccess` no pasa `Authorization`, o JWT_SECRET cambió | Añade `SetEnvIf Authorization ...` al `.htaccess`; el token viejo deja de valer si cambias el secreto |
| `Configuración fiscal no disponible` | No se ejecutó `db/fiscal.sql` | Impórtalo/ejecútalo (§4) |
| `install.php` responde 403 | `APP_INSTALL=false` | Ponlo `true` temporalmente, instala y vuelve a `false` |
| 403 en `POST /fiscal/*` | Rol distinto de ADMIN | Entra con un usuario ADMIN |
| Los cambios de `app.js`/`style.css` no se ven | Caché del navegador | Los assets usan `?v=N`; sube la versión en `index.html` si cambias los archivos |

## 10. Nota sobre la instancia ya publicada en Hostinger

Al 2026-11-05 la instancia conocida `rosybrown-wallaby-603868.hostingersite.com` corre una
versión **anterior** de CacaoPay (la API responde `/api/health` y `/api/stp/*`, pero
`/api/fiscal/*` devuelve `404`). Para habilitar el módulo fiscal en esa instancia:

1. Correr `db/fiscal.sql` sobre su BD (§4-B).
2. Reemplazar `public/` y `src/` por esta versión.
3. Añadir las variables `FISCAL_*` a su `.env`.
4. Verificar con el checklist del §7.
