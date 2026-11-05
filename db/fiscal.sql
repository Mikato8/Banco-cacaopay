-- ============================================================
-- Módulo fiscal SAT (docs/fiscal-sat.md §2)
-- Banco CacaoPay — tablas del módulo fiscal.
-- Idempotente: CREATE TABLE IF NOT EXISTS. Compatible MySQL 5.7+/8.x/MariaDB.
-- No inserta filas: fiscal_settings id=1 lo crea Fiscal::ensureSettings().
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS fiscal_settings (
    id             TINYINT       NOT NULL DEFAULT 1,
    rfc_emisor     VARCHAR(13)   NOT NULL DEFAULT '',
    razon_social   VARCHAR(255)  NOT NULL DEFAULT '',
    regimen_fiscal VARCHAR(3)    NOT NULL DEFAULT '612',
    codigo_postal  VARCHAR(5)    NOT NULL DEFAULT '',
    serie          VARCHAR(10)   NOT NULL DEFAULT 'A',
    iva_rate       DECIMAL(6,4)  NOT NULL DEFAULT 0.1600,
    moneda         CHAR(3)       NOT NULL DEFAULT 'MXN',
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cfdi_invoices (
    id               CHAR(36)      NOT NULL,
    uuid_fiscal      CHAR(36)      NOT NULL,
    tipo             ENUM('I','E','P','T') NOT NULL DEFAULT 'I',
    user_id          CHAR(36)      NOT NULL,
    stp_transfer_id  CHAR(36)      NULL,
    serie            VARCHAR(10)   NOT NULL DEFAULT 'A',
    folio            VARCHAR(40)   NOT NULL DEFAULT '',
    receptor_rfc     VARCHAR(13)   NOT NULL,
    receptor_nombre  VARCHAR(255)  NOT NULL,
    receptor_regimen VARCHAR(3)    NOT NULL DEFAULT '612',
    uso_cfdi         VARCHAR(3)    NOT NULL DEFAULT 'G03',
    metodo_pago      ENUM('PUE','PPD') NOT NULL DEFAULT 'PUE',
    forma_pago       VARCHAR(2)    NOT NULL DEFAULT '03',
    moneda           CHAR(3)       NOT NULL DEFAULT 'MXN',
    subtotal         DECIMAL(20,2) NOT NULL,
    iva              DECIMAL(20,2) NOT NULL DEFAULT 0,
    total            DECIMAL(20,2) NOT NULL,
    iva_rate         DECIMAL(6,4)  NOT NULL DEFAULT 0.1600,
    estatus          ENUM('VIGENTE','CANCELADA') NOT NULL DEFAULT 'VIGENTE',
    cancel_reason    VARCHAR(255)  NULL,
    cancelled_at     DATETIME      NULL,
    uuid_relacionado CHAR(36)      NULL,
    issued_at        DATETIME      NOT NULL,
    created_by       CHAR(36)      NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    meta             JSON          NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cfdi_uuid (uuid_fiscal),
    KEY idx_cfdi_issued (issued_at),
    KEY idx_cfdi_user (user_id),
    KEY idx_cfdi_estatus (estatus),
    KEY idx_cfdi_transfer (stp_transfer_id),
    CONSTRAINT fk_cfdi_user       FOREIGN KEY (user_id)         REFERENCES users(id),
    CONSTRAINT fk_cfdi_created_by FOREIGN KEY (created_by)      REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_cfdi_transfer   FOREIGN KEY (stp_transfer_id) REFERENCES stp_transfers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_periods (
    id          CHAR(36)  NOT NULL,
    period_key  CHAR(7)   NOT NULL,
    revision    INT       NOT NULL DEFAULT 1,
    from_date   DATE      NOT NULL,
    to_date     DATE      NOT NULL,
    totals      JSON      NOT NULL,
    digest      CHAR(64)  NOT NULL,
    prev_digest CHAR(64)  NULL,
    closed_by   CHAR(36)  NULL,
    closed_at   DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_period_rev (period_key, revision),
    KEY idx_period_closed (closed_at),
    CONSTRAINT fk_period_closed_by FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
