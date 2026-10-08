CREATE TABLE IF NOT EXISTS payment_verification_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    payment_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,

    old_amount INT UNSIGNED DEFAULT NULL,
    new_amount INT UNSIGNED DEFAULT NULL,

    old_status ENUM('pending','confirmed','rejected') DEFAULT NULL,
    new_status ENUM('pending','confirmed','rejected') DEFAULT NULL,

    reason VARCHAR(500) DEFAULT NULL,

    changed_by INT UNSIGNED NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    ip VARCHAR(45) DEFAULT NULL,

    KEY idx_pvh_payment (payment_id),
    KEY idx_pvh_invoice (invoice_id),
    KEY idx_pvh_changed_by (changed_by),
    KEY idx_pvh_changed_at (changed_at),

    CONSTRAINT fk_pvh_payment
        FOREIGN KEY (payment_id)
        REFERENCES payments(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_pvh_invoice
        FOREIGN KEY (invoice_id)
        REFERENCES invoices(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_pvh_changed_by
        FOREIGN KEY (changed_by)
        REFERENCES users(id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;