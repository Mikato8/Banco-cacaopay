# Módulo fiscal SAT — balance de facturación y congruencia de depósitos/retiros

Documento de diseño y **contrato técnico** del módulo fiscal de Banco CacaoPay.
Todo el código (backend, frontend, pruebas, documentación) se construye contra este
documento. Si algo no está aquí, no se inventa: se consulta con el Lead.

> **Aviso.** Los CFDI son un **registro interno simulado** (no se timbran ante un PAC ni
> se envían al SAT). El módulo no sustituye la contabilidad ni la asesoría fiscal de un
> contador. Ver §9.

---

## 1. Objetivo

Permitir que, **desde el propio sitio** (SPA), se pueda:

1. **Balance de facturación** por periodo (mes, trimestre o rango libre): total facturado
   (subtotal, IVA trasladado, total), notas de crédito, desglose por forma de pago y por mes.
2. **Congruencia de depósitos y retiros** (STP) contra el CFDI registrado:
   - depósitos sin factura / parcialmente facturados,
   - CFDI vigentes sin depósito que los respalde (facturado no cobrado),
   - CFDI cancelados que sí tenían depósito,
   - **cuadre contable** del libro mayor (ledger) contra `stp_transfers`.
3. **Cierre fiscal del periodo** ("corte") inmutable con folio/revisión y cadena de
   **hash SHA-256**, para entregar al contador o conservar como evidencia.
4. **Exportación CSV** (compatible con Excel/LibreOffice) de: CFDI, movimientos y
   congruencia, más un resumen JSON para integraciones.
5. **Constancia por usuario**: cada cliente ve su propio balance (facturas emitidas a su
   RFC, sus depósitos y retiros). El ADMIN ve el consolidado global.

Todos los importes son **MXN** (1 cacao = 1 MXN en el prototipo, `CACAO_PER_FIAT`).

---

## 2. Modelo de datos — `db/fiscal.sql`

Archivo nuevo, idempotente (`CREATE TABLE IF NOT EXISTS`), MySQL 5.7+/8.x/MariaDB,
`utf8mb4`, prefijo de tablas `fiscal_` / `cfdi_`.

### 2.1 `fiscal_settings` (identidad fiscal del emisor, una sola fila)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | `TINYINT` PK, default 1 | fila única (id=1) |
| `rfc_emisor` | `VARCHAR(13)` | vacío = sin configurar |
| `razon_social` | `VARCHAR(255)` | |
| `regimen_fiscal` | `VARCHAR(3)` default `612` | clave SAT (601, 606, 612, 626…) |
| `codigo_postal` | `VARCHAR(5)` default `''` | lugar de expedición |
| `serie` | `VARCHAR(10)` default `A` | |
| `iva_rate` | `DECIMAL(6,4)` default `0.1600` | 0.16 = 16 % |
| `moneda` | `CHAR(3)` default `MXN` | |
| `updated_at` | `DATETIME` default/on update `CURRENT_TIMESTAMP` | |

### 2.2 `cfdi_invoices`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | `CHAR(36)` PK | UUID interno |
| `uuid_fiscal` | `CHAR(36)` **UNIQUE** | UUID del CFDI (36 chars) |
| `tipo` | `ENUM('I','E','P','T')` default `I` | I ingreso, E egreso/nota crédito, P pago, T traslado |
| `user_id` | `CHAR(36)` FK `users(id)` | **receptor** del CFDI |
| `stp_transfer_id` | `CHAR(36)` NULL FK `stp_transfers(id)` ON DELETE SET NULL | depósito/retiro relacionado |
| `serie` | `VARCHAR(10)` default `A` | |
| `folio` | `VARCHAR(40)` default `''` | |
| `receptor_rfc` | `VARCHAR(13)` | |
| `receptor_nombre` | `VARCHAR(255)` | |
| `receptor_regimen` | `VARCHAR(3)` default `612` | |
| `uso_cfdi` | `VARCHAR(3)` default `G03` | |
| `metodo_pago` | `ENUM('PUE','PPD')` default `PUE` | |
| `forma_pago` | `VARCHAR(2)` default `03` | clave SAT (`01`,`03`,`04`,`28`…) |
| `moneda` | `CHAR(3)` default `MXN` | |
| `subtotal` | `DECIMAL(20,2)` | |
| `iva` | `DECIMAL(20,2)` default 0 | IVA trasladado |
| `total` | `DECIMAL(20,2)` | = subtotal + iva (tolerancia 0.01) |
| `iva_rate` | `DECIMAL(6,4)` default `0.1600` | tasa aplicada al registrar |
| `estatus` | `ENUM('VIGENTE','CANCELADA')` default `VIGENTE` | nunca se borra |
| `cancel_reason` | `VARCHAR(255)` NULL | |
| `cancelled_at` | `DATETIME` NULL | |
| `uuid_relacionado` | `CHAR(36)` NULL | CFDI sustituido/relacionado (ej. E de una I) |
| `issued_at` | `DATETIME` | fecha de emisión (filtro de periodo) |
| `created_by` | `CHAR(36)` NULL FK `users(id)` ON DELETE SET NULL | |
| `created_at` / `updated_at` | `DATETIME` default `CURRENT_TIMESTAMP` (+ on update en updated_at) | |
| `meta` | `JSON` NULL | reservado |

Índices: `uq_cfdi_uuid`, `idx_cfdi_issued (issued_at)`, `idx_cfdi_user (user_id)`,
`idx_cfdi_estatus (estatus)`, `idx_cfdi_transfer (stp_transfer_id)`.

### 2.3 `fiscal_periods` (cierres inmutables encadenados)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | `CHAR(36)` PK | |
| `period_key` | `CHAR(7)` | `YYYY-MM` |
| `revision` | `INT` default 1 | 1, 2, 3… si se recierra el mismo mes |
| `from_date` | `DATE` | primer día del mes |
| `to_date` | `DATE` | último día del mes |
| `totals` | `JSON` | snapshot (ver §4.9) |
| `digest` | `CHAR(64)` | SHA-256 hex del snapshot |
| `prev_digest` | `CHAR(64)` NULL | digest del cierre anterior (cadena) |
| `closed_by` | `CHAR(36)` NULL FK `users(id)` ON DELETE SET NULL | |
| `closed_at` | `DATETIME` default `CURRENT_TIMESTAMP` | |

`UNIQUE KEY uq_period_rev (period_key, revision)`, `KEY idx_period_closed (closed_at)`.

### 2.4 Semilla de configuración

`fiscal.sql` **no** inserta filas (no puede leer el `.env`). La fila `id=1` de
`fiscal_settings` la crea `Fiscal::ensureSettings()` con valores por defecto del `.env`
(ver `src/Fiscal.php` y `public/install.php`).

Variables nuevas en `.env` / `.env.example`:

```
FISCAL_RFC=
FISCAL_RAZON_SOCIAL=
FISCAL_REGIMEN=612
FISCAL_CP=
FISCAL_SERIE=A
FISCAL_IVA_RATE=0.16
FISCAL_MONEDA=MXN
```

---

## 3. Reglas de negocio

### 3.1 Alcance (scope) y roles

- `ADMIN` → `scope = GLOBAL`: ve y opera todo (todas las facturas, todos los movimientos).
  Puede crear/cancelar CFDI, cambiar la configuración fiscal y cerrar periodos.
- `SELLER` y `CLIENT` → `scope = OWN`: solo sus propios datos (CFDI emitidos a su
  `user_id`, sus depósitos/retiros). **No** pueden crear/cancelar CFDI, ni cambiar
  configuración, ni cerrar periodos: `403` (`Auth::requireRole($user, ['ADMIN'])`).
- `ADMIN` puede filtrar por usuario con `?user_id=<uuid>` (si el uuid no existe → `404`).
  El parámetro se **ignora** para no-admin (siempre own). El JSON devuelto incluye
  `"scope": "GLOBAL" | "OWN"` y `"user_id": <uuid> | null`.
- Endpoints sin token → `401 No autenticado`.

### 3.2 Periodo

- Query `from` / `to` en formato `YYYY-MM-DD` (fechas inclusivas).
- Default: mes calendario actual en `America/Mexico_City` (from = día 1, to = último día).
- `from > to` → `400 Periodo inválido`; formato inválido → `400 Fecha inválida (YYYY-MM-DD)`;
  rango > 366 días → `400 El periodo no puede exceder 366 días`.
- Filtro de facturas: `issued_at` entre `from 00:00:00` y `to 23:59:59`.
- Filtro de movimientos: `created_at` de `stp_transfers` en el mismo rango.

### 3.3 Creación de CFDI (`POST /api/fiscal/invoices`) — solo ADMIN

Campos JSON del body:

| Campo | Requerido | Regla |
|---|---|---|
| `uuid` | sí | UUID (8-4-4-4-12 hex). Único → `409 El UUID fiscal ya existe` |
| `receptorRfc` | sí | RFC mexicano `^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$` o genérico `XAXX010101000` / `XEXX010101000` |
| `receptorNombre` | sí | 2–255 chars |
| `subtotal` | sí | > 0 |
| `iva` | no | default `round(subtotal * iva_rate, 2)` |
| `ivaRate` | no | default `fiscal_settings.iva_rate` (0–1) |
| `total` | no | default `subtotal + iva`; si se envía debe cuadrar ±0.01 |
| `tipo` | no | `I` (default), `E`, `P`, `T` |
| `receptorUserId` / `receptorEmail` | no | receptor; debe existir. Si se omite: es el dueño de `stpTransferId` cuando se envía transferencia, o el admin autenticado en caso contrario |
| `stpTransferId` | no | transferencia existente; ver reglas abajo |
| `uuidRelacionado` | no | UUID del CFDI relacionado (obligatorio si `tipo=E`) |
| `issuedAt` | no | `Y-m-d` o `Y-m-d H:i:s`; default ahora (`America/Mexico_City`); no futuro (+5 min tol.) |
| `serie`, `folio`, `formaPago`, `metodoPago`, `usoCfdi`, `receptorRegimen`, `moneda` | no | defaults: settings serie, `''`, `03`, `PUE`, `G03`, `612`, `MXN` |

Reglas de la transferencia relacionada:
- Debe existir → `404 Transferencia no encontrada`.
- Debe pertenecer al receptor del CFDI → `400 La transferencia no pertenece al receptor`.
- `tipo=I/P/T` exige `direction='IN'`; `tipo=E` exige `direction='OUT'` → `400`.
- El total del CFDI no puede exceder el monto no facturado de la transferencia
  (`transfer.amount_mxn - Σ total CFDI VIGENTES ya ligados`), tolerancia 0.01 →
  `400 El CFDI excede el monto disponible de la transferencia`.
- Se puede facturar por partes (varias facturas por transferencia).
- Respuesta `201`:
  ```json
  { "invoice": { "id": "...", "uuid_fiscal": "...", "tipo": "I", "estatus": "VIGENTE",
      "subtotal": 100.00, "iva": 16.00, "total": 116.00, "receptor_rfc": "...",
      "issued_at": "2026-11-05 10:00:00", "created_at": "..." } }
  ```

### 3.4 Cancelación (`POST /api/fiscal/invoices/cancel`) — solo ADMIN

Body: `{ "invoiceId": "<uuid interno>" }` o `{ "uuid": "<uuid fiscal>" }` + `"reason"` (opcional,
default `"Cancelado desde el sitio"`). Reglas:
- No existe → `404`.
- Ya cancelada → `409 El CFDI ya está cancelado`.
- Setea `estatus='CANCELADA'`, `cancel_reason`, `cancelled_at=NOW()`. **Nunca** borra filas.
- Respuesta `200 { "invoice": {...} }`.

### 3.5 Configuración fiscal (`GET/POST /api/fiscal/settings`)

- `GET` → cualquier autenticado:
  ```json
  { "settings": { "rfc_emisor": "", "razon_social": "", "regimen_fiscal": "612",
      "codigo_postal": "", "serie": "A", "iva_rate": 0.16, "moneda": "MXN" },
    "configured": false }
  ```
- `POST` (ADMIN) body `{ rfcEmisor, razonSocial, regimenFiscal, codigoPostal, serie, ivaRate, moneda }`
  (todos opcionales; se actualizan los enviados). Validaciones:
  `rfcEmisor` vacío o RFC válido (mismo regex), `razonSocial` ≤255, `regimenFiscal` 3 dígitos,
  `codigoPostal` vacío o 5 dígitos, `serie` ≤10, `ivaRate` numérico 0–1, `moneda` 3 letras.
  Respuesta `200 { "settings": {...}, "configured": true }`.

### 3.6 Cierre de periodo (`POST /api/fiscal/periods/close`) — solo ADMIN

Body `{ "period": "YYYY-MM" }`. Reglas:
- Formato inválido → `400 Periodo inválido (YYYY-MM)`; mes futuro → `400 No se puede cerrar un periodo futuro`.
- Calcula el snapshot §4.9 para `from`/`to` del mes (scope GLOBAL siempre).
- `revision` = última revisión del mes + 1 (nunca se actualiza un cierre existente).
- `prev_digest` = `digest` del último cierre global (`ORDER BY closed_at DESC, id DESC LIMIT 1`),
  `NULL` si es el primero. Cadena: `digest = sha256(prev_digest . "|" . json_canonico)` donde
  `json_canonico` es `json_encode(totals)` con claves ordenadas recursivamente
  (`ksort` recursivo) y `JSON_UNESCAPED_UNICODE`.
- Inserta fila en `fiscal_periods` dentro de una transacción (`SELECT … FOR UPDATE` del último
  cierre para evitar cadenas partidas).
- Respuesta `201 { "period": { id, period_key, revision, from_date, to_date, digest,
  prev_digest, closed_at, closed_by, totals } }`.

### 3.7 Listado de cierres (`GET /api/fiscal/periods`)

```json
{ "periods": [ { "id": "...", "period_key": "2026-11", "revision": 1,
    "from_date": "2026-11-01", "to_date": "2026-11-30", "digest": "...",
    "prev_digest": null, "closed_at": "...", "closed_by": { "id": "...", "name": "..." },
    "totals": { ... } } ] }
```
Orden: `closed_at DESC`. Todo autenticado (los cierres son globales; no contienen datos
personales más allá de los totales).

### 3.8 Importes

- Cálculos monetarios en SQL `DECIMAL` y `round($v, 2)` en PHP.
- JSON: números (`float`) con 2 decimales. Comparaciones con tolerancia `0.01`.
- `facturacion`:
  - `ingresos_subtotal/iva/total` = Σ de CFDI **VIGENTES** con `tipo='I'`.
  - `notas_credito_total` = Σ `total` de CFDI VIGENTES con `tipo='E'`.
  - `facturado_neto` = `ingresos_total - notas_credito_total`.
  - `iva_neto` = `ingresos_iva - Σ iva` de E.
  - `cfdi_vigentes` = nº de CFDI VIGENTES (todas las clases); `cfdi_cancelados` = nº cancelados
    del periodo; `otros_count` = nº VIGENTES de tipo `P`/`T` (no suman a ingresos).
- `movimientos` (de `stp_transfers`):
  - `depositos_mxn` = Σ `amount_mxn` `direction='IN'`; `depositos_count`.
  - `retiros_mxn` = Σ `amount_mxn` `direction='OUT'`; `retiros_count`.
  - `neto_mxn` = `depositos_mxn - retiros_mxn`.
- `congruencia` (definiciones exactas):
  - `facturado_en_depositos` = Σ, por depósito (`IN`), de `min(Σtotal CFDI VIGENTES ligados, amount_mxn)`.
  - `depositos_sin_facturar_total` = `depositos_mxn - facturado_en_depositos`.
  - `cfdi_sin_deposito_total` = Σ `total` de CFDI VIGENTES `tipo='I'` **sin** `stp_transfer_id`.
  - `diferencia_total` = `depositos_sin_facturar_total - cfdi_sin_deposito_total`.
  - `cobertura_pct` = `depositos_mxn > 0 ? round(facturado_en_depositos / depositos_mxn * 100, 2) : 100.0`.
  - `estado` = `"CUADRA"` si `depositos_sin_facturar_total <= 0.01` **y**
    `cfdi_sin_deposito_total <= 0.01` **y** `cuadre_ledger.ok`; si no `"REVISAR"`.
  - `cuadre_ledger` (integridad del libro mayor contra STP):
    - `depositos_ledger` = Σ `ledger_entries.amount` con `entry_type='DEPOSIT'`, período y
      `JSON_UNQUOTE(JSON_EXTRACT(meta,'$.via'))='STP'` (filtrado por usuario en scope OWN).
    - `depositos_stp` = Σ `stp_transfers.amount_cacao` `IN` del periodo (mismo filtro).
    - `retiros_ledger` = Σ `amount` con `entry_type='WITHDRAW'` + `meta.via='STP'`.
    - `retiros_stp` = Σ `amount_cacao` `OUT`.
    - `ok` = `|depositos_ledger-depositos_stp| <= 0.01 && |retiros_ledger-retiros_stp| <= 0.01`.
- `por_forma_pago`: `[{ "forma_pago":"03", "label":"Transferencia electrónica de fondos",
  "count": n, "total": x }]` de CFDI VIGENTES del periodo (todas las clases), orden desc por total.
- `por_mes`: meses del rango (máx. 24): `[{ "period":"2026-11", "facturado": x, "iva": y,
  "depositos": z, "retiros": w }]`, orden ascendente.
- `alertas`: `[{ "tipo": "...", "severidad": "ALTA|MEDIA|INFO", "mensaje": "...", "monto": x }]`
  (máx. 50). Tipos: `CONFIGURACION_FISCAL` (sin RFC emisor, MEDIA),
  `DEPOSITOS_SIN_FACTURA` (ALTA, monto = `depositos_sin_facturar_total`),
  `CFDI_SIN_DEPOSITO` (ALTA, monto = `cfdi_sin_deposito_total`),
  `CFDI_CANCELADO_CON_DEPOSITO` (ALTA, monto = Σ total de cancelados ligados a transferencia),
  `LEDGER_DESCUADRE` (ALTA).

---

## 4. API (base `/api`) — todas las rutas requieren `Authorization: Bearer <jwt>`

| Método | Ruta | Rol | Respuesta |
|---|---|---|---|
| GET | `/fiscal/settings` | todos | §3.5 |
| POST | `/fiscal/settings` | ADMIN | §3.5 |
| GET | `/fiscal/summary?from=&to=&user_id=` | todos | §4.1 |
| GET | `/fiscal/congruence?from=&to=&user_id=` | todos | §4.2 |
| GET | `/fiscal/invoices?from=&to=&estatus=&user_id=` | todos (ADMIN puede filtrar) | §4.3 |
| POST | `/fiscal/invoices` | ADMIN | §3.3 |
| POST | `/fiscal/invoices/cancel` | ADMIN | §3.4 |
| GET | `/fiscal/export?from=&to=&type=cfdi\|movimientos\|congruencia&format=csv` | todos | §4.4 |
| GET | `/fiscal/periods` | todos | §3.7 |
| POST | `/fiscal/periods/close` | ADMIN | §3.6 |

Errores: JSON `{ "error": "mensaje en español" }` con el status correspondiente (400, 401,
403, 404, 409, 500). Sin `Access-Control-Allow-Methods` nuevos: solo GET/POST/OPTIONS.

### 4.1 `GET /api/fiscal/summary`

```json
{
  "scope": "GLOBAL",
  "user_id": null,
  "period": { "from": "2026-11-01", "to": "2026-11-30", "label": "noviembre 2026", "days": 30 },
  "settings": { "rfc_emisor": "", "razon_social": "", "regimen_fiscal": "612",
                "codigo_postal": "", "serie": "A", "iva_rate": 0.16, "moneda": "MXN",
                "configured": false },
  "facturacion": {
    "cfdi_vigentes": 3, "cfdi_cancelados": 1, "otros_count": 0,
    "ingresos_subtotal": 1000.00, "ingresos_iva": 160.00, "ingresos_total": 1160.00,
    "notas_credito_total": 0.00, "facturado_neto": 1160.00, "iva_neto": 160.00
  },
  "movimientos": { "depositos_mxn": 1000.00, "depositos_count": 2,
                   "retiros_mxn": 300.00, "retiros_count": 1, "neto_mxn": 700.00 },
  "congruencia": {
    "facturado_en_depositos": 1000.00, "depositos_sin_facturar_total": 0.00,
    "cfdi_sin_deposito_total": 0.00, "diferencia_total": 0.00,
    "cobertura_pct": 100.0, "estado": "CUADRA",
    "cuadre_ledger": { "depositos_ledger": 1000.00, "depositos_stp": 1000.00,
                       "retiros_ledger": 300.00, "retiros_stp": 300.00, "ok": true }
  },
  "por_forma_pago": [ { "forma_pago": "03", "label": "Transferencia electrónica de fondos",
                        "count": 2, "total": 1160.00 } ],
  "por_mes": [ { "period": "2026-11", "facturado": 1160.00, "iva": 160.00,
                 "depositos": 1000.00, "retiros": 300.00 } ],
  "alertas": [ { "tipo": "CONFIGURACION_FISCAL", "severidad": "MEDIA",
                 "mensaje": "Configura el RFC del emisor para emitir CFDI congruentes.",
                 "monto": 0 } ]
}
```

### 4.2 `GET /api/fiscal/congruence`

```json
{
  "scope": "GLOBAL", "user_id": null,
  "period": { ... },
  "estado": "REVISAR",
  "resumen": { ...mismo objeto que summary.congruencia... },
  "depositos": [
    { "id": "<stp_transfer id>", "fecha": "2026-11-02 10:00:00", "monto_mxn": 500.00,
      "amount_cacao": 500.00, "referencia": "REF-1",
      "receptor": { "id": "...", "email": "...", "name": "...", "rfc": null },
      "facturado": 500.00, "cfdi_uuids": ["..."],
      "estado": "FACTURADO|PARCIAL|SIN_FACTURA|EXCEDE", "diferencia": 0.00 }
  ],
  "retiros": [
    { "id": "...", "fecha": "...", "monto_mxn": 300.00,
      "clabe": "012345678901234567", "beneficiario": "Ana", "banco": "Banco X",
      "facturado": 0.00, "cfdi_uuids": [],
      "estado": "FACTURADO|PARCIAL|SIN_FACTURA|EXCEDE", "diferencia": 300.00 }
  ],
  "cfdi_sin_deposito": [ { "id": "...", "uuid_fiscal": "...", "tipo": "I",
                           "receptor_rfc": "...", "issued_at": "...", "total": 0.00 } ],
  "cfdi_cancelados_con_deposito": [ { "id": "...", "uuid_fiscal": "...",
                                      "stp_transfer_id": "...", "total": 0.00 } ],
  "cuadre_ledger": { ... },
  "alertas": [ ... ]
}
```
Límite: 500 filas por lista (más recientes primero, `ORDER BY created_at DESC`).

### 4.3 `GET /api/fiscal/invoices`

```json
{ "scope": "GLOBAL", "user_id": null, "period": { ... },
  "totals": { "vigentes": 3, "cancelados": 1, "subtotal": 1000.00, "iva": 160.00,
              "total": 1160.00, "cancelado_total": 100.00 },
  "invoices": [ { "id": "...", "uuid_fiscal": "...", "tipo": "I", "estatus": "VIGENTE",
      "fecha": "2026-11-02 10:00:00", "serie": "A", "folio": "1",
      "receptor_rfc": "...", "receptor_nombre": "...", "uso_cfdi": "G03",
      "metodo_pago": "PUE", "forma_pago": "03", "moneda": "MXN",
      "subtotal": 500.00, "iva": 80.00, "total": 580.00,
      "stp_transfer_id": "...", "referencia": "REF-1",
      "cancelled_at": null, "cancel_reason": null, "created_at": "..." } ] }
```
`estatus` opcional: `VIGENTE` o `CANCELADA` (otro valor → 400). `totals` siempre calculado
sobre el periodo **sin** el filtro `estatus` (vigentes y cancelados por separado).

### 4.4 `GET /api/fiscal/export` (CSV)

- `type` ∈ `cfdi` (default), `movimientos`, `congruencia`; otro → `400 Tipo de exportación inválido`.
- `format` = `csv` (default); otro → `400 Formato no soportado`.
- Headers: `Content-Type: text/csv; charset=utf-8`,
  `Content-Disposition: attachment; filename="<tipo>_<from>_<to>.csv"`,
  `Access-Control-Expose-Headers: Content-Disposition`.
- Contenido: **BOM UTF-8** (`\xEF\xBB\xBF`), separador `,`, campos entre comillas dobles con
  comillas escapadas (`""`), salto `\r\n`. **Anti CSV-injection**: si una celda empieza con
  `=`, `+`, `-`, `@`, `\t` o `\r`, se prefija con `'`.
- Columnas (encabezado exacto, mayúsculas, sin acentos):
  - `cfdi`: `UUID_FISCAL,TIPO,ESTATUS,FECHA_EMISION,SERIE,FOLIO,RFC_EMISOR,RAZON_SOCIAL_EMISOR,RFC_RECEPTOR,NOMBRE_RECEPTOR,REGIMEN_RECEPTOR,USO_CFDI,METODO_PAGO,FORMA_PAGO,MONEDA,SUBTOTAL,IVA,TOTAL,UUID_RELACIONADO,REFERENCIA_DEPOSITO,MOTIVO_CANCELACION`
  - `movimientos`: `FECHA,DIRECCION,MONTO_MXN,MONTO_CACAO,CLABE,BANCO,BENEFICIARIO,REFERENCIA,ID_TRANSFERENCIA,ESTATUS_FISCAL,UUID_CFDI`
  - `congruencia`: `FECHA,CONCEPTO,TIPO,MONTO,FACTURADO,DIFERENCIA,CFDI_UUID,ESTADO,OBSERVACION`
- Los importes en formato `0.00` (punto decimal). Fechas `Y-m-d H:i:s`.

### 4.5 Implementación de referencia (backend)

- `src/Fiscal.php`: `final class Fiscal` con métodos estáticos públicos:
  `settings`, `updateSettings`, `summary`, `congruence`, `invoices`, `createInvoice`,
  `cancelInvoice`, `export`, `periods`, `closePeriod`, `ensureSettings` (usado por
  `install.php`). Helpers privados: periodo, scope, totales, validaciones, CSV.
- `src/bootstrap.php`: añadir `require __DIR__ . '/Fiscal.php';`.
- `public/index.php`: registrar las 10 rutas **antes** del `Response::error('Ruta no
  encontrada', 404);`.
- `public/install.php`: tras ejecutar `schema.sql`, `seed.sql`, `migrate.sql`, ejecutar
  también `db/fiscal.sql` y `Fiscal::ensureSettings()`.
- Código compatible PHP 8.1+ y MySQL 5.7+ (sin CTE, sin `RETURNING`, sin `JSON_TABLE`).
  PDO con parámetros posicionales (el proyecto usa `ATTR_EMULATE_PREPARES => false`).
  Errores con `AppException(status, 'mensaje en español')`.
- El CSV se emite directamente (`header()`, `echo`, `exit`) porque `Response::json` fuerza
  JSON; método `never`.

---

## 5. Frontend (SPA sin build) — `public/index.html`, `public/app.js`, `public/style.css`

### 5.1 Navegación

Dentro de `<header class="topbar">`, después de `.brand` y antes del botón de logout:

```html
<nav id="mainNav" class="nav-tabs hidden">
  <button id="navWallet" class="nav-tab active" type="button">Billetera</button>
  <button id="navFiscal" class="nav-tab" type="button">Fiscal · SAT</button>
</nav>
```

`#mainNav` se muestra solo con sesión iniciada (`showView('app')`). La vista existente
`#appView` (dashboard) se conserva; se añade `<section id="fiscalView" class="hidden">`
con todo el módulo. `showView(view)` admite `'auth' | 'app' | 'fiscal'` y alterna
`#appView`/`#fiscalView` + clases `active` en los tabs. Al entrar por primera vez a la
vista fiscal se carga `loadFiscal()`.

### 5.2 Ids obligatorios (los usa `app.js`)

| Elemento | id |
|---|---|
| Contenedor vista fiscal | `fiscalView` |
| Fecha desde / hasta | `fiscalFrom`, `fiscalTo` |
| Botones periodo | `fiscalQuickMonth`, `fiscalQuickYear`, `fiscalRefresh` |
| Nota de alcance / estado | `fiscalScopeNote`, `fiscalEstado` |
| KPIs (valor) | `kpiFacturado`, `kpiIva`, `kpiDepositos`, `kpiRetiros`, `kpiCobertura`, `kpiDiferencia` |
| Sub-labels KPI | `kpiFacturadoSub`, `kpiDepositosSub`, `kpiRetirosSub`, `kpiMesesSub` |
| Alertas | `fiscalAlerts` |
| Tabla congruencia | `fiscalCongruencia` |
| Cuadre ledger | `fiscalCuadreLedger` |
| Formulario CFDI (card) | `cfdiFormCard`, `cfdiForm` |
| Campos CFDI | `cfdiUuid`, `cfdiFecha`, `cfdiTipo`, `cfdiRfc`, `cfdiNombre`, `cfdiSubtotal`, `cfdiIva`, `cfdiTotal`, `cfdiFormaPago`, `cfdiMetodoPago`, `cfdiUso`, `cfdiTransfer`, `cfdiSubmit` |
| Tabla CFDI | `cfdiList` |
| Exportar CSV | `exportCfdi`, `exportMovimientos`, `exportCongruencia` |
| Configuración fiscal | `fiscalSettingsCard`, `fiscalSettingsForm`, `fsRfc`, `fsRazonSocial`, `fsRegimen`, `fsCp`, `fsSerie`, `fsIvaRate` |
| Cierres | `fiscalPeriods`, `closePeriodBtn`, `closePeriodNote` |

Los `.hidden` se usan para ocultar cards solo-admin cuando `state.user.role !== 'ADMIN'`.

### 5.3 Comportamiento

- Periodo por defecto: mes actual local; botones rápidos "Mes actual" / "Año actual".
  `from`/`to` en `YYYY-MM-DD`; al cambiar y pulsar `fiscalRefresh` → `loadFiscal()`.
- `loadFiscal()` (paralelo): `GET /fiscal/summary`, `/fiscal/congruence`,
  `/fiscal/invoices` con `?from&to`; pinta KPIs, badge `#fiscalEstado`
  (`CUADRA` verde / `REVISAR` ámbar), alertas, tabla de congruencia (depósitos y retiros),
  cuadre ledger, tabla de CFDI y `#cfdiTransfer` (depósitos del periodo: `IN`,
  `SIN_FACTURA`/`PARCIAL`, con `value=id` y texto `fecha · $monto · receptor`).
- Formulario CFDI: `subtotal` recalcula `iva` (tasa de settings) y `total` en vivo;
  al enviar `POST /fiscal/invoices` con `{ uuid, receptorRfc, receptorNombre, subtotal, iva,
  total, tipo, formaPago, metodoPago, usoCfdi, issuedAt, stpTransferId? }`; toast +
  `loadFiscal()`.
- Cancelar CFDI: confirmación (`confirm()`) + `POST /fiscal/invoices/cancel` + recarga.
- Exportar: `fetch('/api/fiscal/export?...', {headers:{Authorization}})`, `blob`,
  `<a download>` con el nombre del `Content-Disposition` (o fallback
  `<tipo>_<from>_<to>.csv`). Muestra toast de error si falla.
- Guardar configuración: `POST /fiscal/settings` (tasa como porcentaje /100) + recarga.
- Cerrar periodo: `POST /fiscal/periods/close` con el mes de `fiscalFrom`; recarga cierres.
- Todos los textos visibles en **español**; números con `toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2})`;
  montos con prefijo `$` y sufijo `MXN` donde aplique.
- Sin dependencias externas (no CDN, no build). Escapar HTML con `escapeHtml()` (ya existe).

### 5.4 Estilo

Añadir a `style.css`: `.nav-tabs`/`.nav-tab` (como `.tab` pero en el header), `.kpi-grid`,
`.kpi`, `.kpi-value`, `.kpi-label`, `.badge.ok/.warn/.bad`, `.table` (th/td, scroll horizontal
en móvil), `.table-wrap { overflow-x: auto }`, `.note`, `.period-bar`. Mantener la paleta y
`@media (max-width: 480px)`.

---

## 6. Instalación / migración

- BD nueva: `install.php` ejecuta `db/schema.sql`, `db/seed.sql`, `db/migrate.sql`,
  `db/fiscal.sql` y `Fiscal::ensureSettings()`.
- BD existente: ejecutar `db/fiscal.sql` y añadir las variables `FISCAL_*` al `.env`
  (o guardar la configuración desde la UI como ADMIN). `fiscal.sql` es idempotente.
- `README.md`: nueva sección "Módulo fiscal (SAT)" con tabla de endpoints, variables de
  entorno, migración y aviso legal; actualizar tabla de endpoints y modelo de datos.

---

## 7. Pruebas — `tests/fiscal-e2e.sh`

Script bash autónomo (sin tocar la BD del usuario), ejecutable desde la raíz del repo:

- Levanta un **MySQL aislado** (`mysqld --no-defaults --initialize-insecure` con
  `--datadir` en `.tmp/...`, puerto `MYSQL_PORT` default **3411**, socket propio) y un
  `php -S` en `HTTP_PORT` default **8111** con `.env` temporal (generado desde `.env.example`,
  `APP_INSTALL=true`, `APP_DEBUG=false`, `DB_PORT=$MYSQL_PORT`, `JWT_SECRET` fijo,
  `FISCAL_*` completos). Usa `trap` para apagar todo y borrar temporales.
- Escenarios mínimos (todos con aserciones y `exit 1` si falla alguna; resumen PASS/FAIL):
  1. `GET /api/health` 200; `GET /api/fiscal/summary` sin token → 401.
  2. Instalación: `php public/install.php` (CLI) crea tablas; `fiscal_settings` id=1 existe.
  3. Login admin y cliente (usuarios demo `cacao123`).
  4. Cliente: `POST /api/stp/deposit` x3 (p. ej. 500, 300, 200) → 201.
  5. Cliente: `POST /api/stp/withdraw` 300 a CLABE válida → 201.
  6. Admin: `GET /fiscal/summary` → depósitos 1000, retiros 300, facturado 0,
     cobertura 0, estado REVISAR, `cuadre_ledger.ok === true`, alerta
     `DEPOSITOS_SIN_FACTURA`.
  7. Admin: crear CFDI 500 (subtotal 431.03, iva 68.97, total 500) ligado al depósito 500 →
     201; `GET /fiscal/invoices` totals correctos.
  8. Admin: crear CFDI parcial 100 ligado al depósito 300; CFDI sin depósito 200
     (subtotal 172.41, iva 27.59) → `depositos_sin_facturar_total` = 400,
     `cfdi_sin_deposito_total` = 200, `diferencia_total` = 200, cobertura 60 %,
     estado REVISAR.
  9. Admin: crear CFDI que excede el depósito (p. ej. 500 sobre el de 200) → 400.
  10. Duplicar UUID → 409. RFC inválido → 400. `tipo=E` sin `uuidRelacionado` → 400.
  11. Cliente intenta `POST /fiscal/invoices` → 403. Cliente `GET /fiscal/summary` → scope OWN
      y totales solo suyos (depósitos 1000, retiros 300).
  12. Cliente intenta `GET /fiscal/summary?user_id=<otro>` → se ignora (scope OWN).
  13. `POST /fiscal/settings` admin con RFC válido → `configured=true`; cliente → 403;
      RFC inválido → 400.
  14. Cancelar CFDI → `estatus CANCELADA`; sumatorias lo excluyen; congruencia lo reporta.
      409 al recancelar.
  15. Export CSV: 3 tipos → `Content-Type: text/csv`, `Content-Disposition: attachment`,
      BOM UTF-8, encabezados exactos, nº de líneas > 1. `type` inválido → 400.
  16. Cierre: `POST /fiscal/periods/close {"period":"<mes>"}` → 201 con `digest` (64 hex),
      `revision=1`, `prev_digest=null`; `GET /fiscal/periods` lo lista; recerrar → `revision=2`
      con `prev_digest == digest` anterior; mes inválido/futuro → 400; cliente → 403.
  17. `GET /fiscal/congruence`: depósitos con `estado` FACTURADO/PARCIAL/SIN_FACTURA,
      `cfdi_sin_deposito` no vacío; comillas/acentos en campos sobreviven al CSV.
- Requisitos: `php`, `mysqld/mysql`, `curl`, `python3` (para aserciones JSON) disponibles.
  Si falta algo, el script lo reporta y sale con código 1 (o `SKIP` con código 0 solo para
  `--check`). Debe imprimir al final `RESULTADO: PASS` o `RESULTADO: FAIL n/m`.

---

## 8. Fuera de alcance (explícito)

- Timbrado real / PAC / sellos digitales / e.firma / envío al SAT.
- Cálculo de ISR/IVA declaraciones y determinación de impuestos.
- SAT "opinión de cumplimiento", contabilidad electrónica (XML SAT), nómina.
- Multi-tenant / multi-empresa (una sola identidad emisora).

## 9. Aviso legal (debe aparecer en README y UI)

Módulo de **registro y conciliación fiscal interno**, con datos capturados manualmente.
No timbra ni valida CFDI ante el SAT; no sustituye asesoría contable ni fiscal. Para
deducciones, acreditamiento de IVA y obligaciones formales, consulta a tu contador y usa
un PAC certificado.
