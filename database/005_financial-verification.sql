

ALTER TABLE payments
    ADD COLUMN claimed_amount INT UNSIGNED NULL AFTER amount,
    ADD COLUMN verified_amount INT UNSIGNED NULL AFTER claimed_amount,
    ADD COLUMN verification_status ENUM(
        'unverified',
        'verified',
        'rejected',
        'adjusted'
    ) NOT NULL DEFAULT 'unverified' AFTER verified_amount,
    ADD COLUMN verification_note VARCHAR(500) NULL AFTER verification_status,
    ADD COLUMN verified_by INT UNSIGNED NULL AFTER verification_note,
    ADD COLUMN verified_at DATETIME NULL AFTER verified_by,
    ADD COLUMN transaction_date DATETIME NULL AFTER verified_at;

UPDATE payments
SET claimed_amount = amount
WHERE claimed_amount IS NULL;



CREATE TABLE IF NOT EXISTS payment_verification_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    payment_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,

    previous_verified_amount INT UNSIGNED NULL,
    new_verified_amount INT UNSIGNED NULL,

    action ENUM(
        'verify',
        'adjust',
        'reject',
        'reopen'
    ) NOT NULL,

    note VARCHAR(500) NULL,

    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_pvh_payment (payment_id),
    KEY idx_pvh_invoice (invoice_id),
    KEY idx_pvh_created (created_at),

    CONSTRAINT fk_pvh_payment
        FOREIGN KEY (payment_id)
        REFERENCES payments(id),

    CONSTRAINT fk_pvh_invoice
        FOREIGN KEY (invoice_id)
        REFERENCES invoices(id),

    CONSTRAINT fk_pvh_creator
        FOREIGN KEY (created_by)
        REFERENCES users(id)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;




ALTER TABLE financial_transactions
    ADD COLUMN transaction_date DATETIME NULL AFTER description;

UPDATE financial_transactions
SET transaction_date = created_at
WHERE transaction_date IS NULL;