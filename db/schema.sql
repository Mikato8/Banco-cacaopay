BEGIN;

CREATE TABLE IF NOT EXISTS users (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    email         TEXT NOT NULL UNIQUE,
    name          TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'CLIENT' CHECK (role IN ('ADMIN', 'SELLER', 'CLIENT')),
    kyc_status    TEXT NOT NULL DEFAULT 'PENDING' CHECK (kyc_status IN ('PENDING', 'VERIFIED', 'REJECTED')),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS accounts (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id    UUID REFERENCES users(id) ON DELETE CASCADE,
    kind       TEXT NOT NULL CHECK (kind IN ('USER', 'SYSTEM')),
    currency   TEXT NOT NULL CHECK (currency IN ('CACAO', 'PEN', 'USD')),
    balance    NUMERIC(20, 2) NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (user_id, currency)
);

CREATE TABLE IF NOT EXISTS transactions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    idempotency_key UUID UNIQUE,
    kind            TEXT NOT NULL,
    meta            JSONB NOT NULL DEFAULT '{}'::jsonb,
    result          JSONB,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS ledger_entries (
    id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    transaction_id   UUID NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
    entry_type       TEXT NOT NULL CHECK (entry_type IN ('DEPOSIT', 'TRANSFER_OUT', 'TRANSFER_IN', 'REDEEM', 'ADJUST')),
    debit_account_id  UUID NOT NULL REFERENCES accounts(id),
    credit_account_id UUID NOT NULL REFERENCES accounts(id),
    amount           NUMERIC(20, 2) NOT NULL CHECK (amount > 0),
    meta             JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_ledger_debit  ON ledger_entries (debit_account_id);
CREATE INDEX IF NOT EXISTS idx_ledger_credit ON ledger_entries (credit_account_id);
CREATE INDEX IF NOT EXISTS idx_ledger_txn    ON ledger_entries (transaction_id);

CREATE TABLE IF NOT EXISTS products (
    id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name         TEXT NOT NULL,
    description  TEXT NOT NULL DEFAULT '',
    cacao_price  NUMERIC(20, 2) NOT NULL CHECK (cacao_price > 0),
    stock        INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
    active       BOOLEAN NOT NULL DEFAULT TRUE,
    seller_id    UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS redemptions (
    id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id        UUID NOT NULL REFERENCES users(id),
    product_id     UUID NOT NULL REFERENCES products(id),
    cacao_paid     NUMERIC(20, 2) NOT NULL,
    quantity       INTEGER NOT NULL CHECK (quantity > 0),
    transaction_id UUID NOT NULL REFERENCES transactions(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO accounts (kind, currency, balance)
SELECT 'SYSTEM', 'CACAO', 0
WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE kind = 'SYSTEM' AND currency = 'CACAO');

COMMIT;
