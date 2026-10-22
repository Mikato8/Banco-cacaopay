-- Migración para bases de datos existentes (idempotente).
-- Amplía el ENUM de ledger_entries con los nuevos tipos de movimiento.

ALTER TABLE ledger_entries
    MODIFY entry_type ENUM('DEPOSIT','TRANSFER_OUT','TRANSFER_IN','REDEEM','ADJUST','WITHDRAW','INVEST','INVEST_REDEEM','LOAN','LOAN_REPAY') NOT NULL;
