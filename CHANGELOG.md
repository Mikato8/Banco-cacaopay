# Changelog

Todos los cambios relevantes de este proyecto. Formato basado en
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).

## [No publicado] — 2026-11-05

### Añadido

- **Módulo fiscal SAT** (pestaña **Fiscal · SAT** en la SPA + API `/api/fiscal/*`):
  - Balance de facturación por periodo: CFDI vigentes/cancelados, subtotal, IVA
    trasladado, notas de crédito, facturado neto, desglose por forma de pago y por mes.
  - Congruencia de depósitos/retiros STP contra CFDI: depósitos sin facturar o
    parciales, CFDI sin depósito, CFDI cancelados con depósito y **cuadre del libro
    mayor de doble entrada contra `stp_transfers`** (semáforo CUADRA / REVISAR).
  - Registro y cancelación de CFDI 4.0 interno (simulado): UUID fiscal, RFC/régimen del
    receptor, IVA, forma/método de pago, uso CFDI, relación con depósito/retiro y
    facturación parcial. Cancelar **nunca** borra (deja estatus `CANCELADA` + motivo).
  - Cierre fiscal inmutable por mes con folio por `revision` y cadena de hash
    **SHA-256** (`digest = sha256(prev_digest | JSON canónico)`).
  - Exportación **CSV** de `cfdi`, `movimientos` y `congruencia` con BOM UTF-8,
    comillas escapadas y protección anti-inyección de fórmulas.
  - Constancias por usuario: `CLIENT`/`SELLER` ven solo sus datos; `ADMIN` ve el
    consolidado global y puede filtrar por cliente.
  - Tablas nuevas: `fiscal_settings`, `cfdi_invoices`, `fiscal_periods` (`db/fiscal.sql`,
    idempotente).
  - Backend `src/Fiscal.php` con 10 rutas nuevas en `public/index.php`.
  - Configuración fiscal editable desde la UI o por `POST /api/fiscal/settings`; variables
    `FISCAL_RFC`, `FISCAL_RAZON_SOCIAL`, `FISCAL_REGIMEN`, `FISCAL_CP`, `FISCAL_SERIE`,
    `FISCAL_IVA_RATE`, `FISCAL_MONEDA` en `.env`.
- Suíte de pruebas end-to-end `tests/fiscal-e2e.sh` (16 escenarios, 188 aserciones) con
  MySQL y PHP aislados en `.tmp` (no toca la BD ni el `.env` del repo).
- Documentación:
  - `docs/fiscal-sat.md` — contrato técnico del módulo (modelo, reglas, API, UI).
  - `docs/despliegue-hostinger.md` — guía de despliegue y actualización en Hostinger.
  - `docs/capturas/` — capturas de la pestaña fiscal en vista ADMIN y CLIENTE.

### Cambiado

- `install.php` ejecuta también `db/fiscal.sql` y `Fiscal::ensureSettings()`.
- `src/bootstrap.php` carga `Fiscal.php` y fija la zona horaria de negocio en
  `America/Mexico_City`.
- `.gitignore` ignora `.tmp/` (artefactos de las pruebas e2e).

### Corregido

- La UI de captura de CFDI ahora envía `receptorUserId` al facturar el depósito de otro
  usuario (antes respondía `400 La transferencia no pertenece al receptor`).
- El backend infiere el receptor del CFDI desde el titular de `stpTransferId` cuando se
  omite `receptorUserId`/`receptorEmail` (útil para integraciones).
