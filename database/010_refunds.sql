CREATE TABLE IF NOT EXISTS refund_requests (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id    INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  amount        INT UNSIGNED NOT NULL,
  sheba         VARCHAR(26) NOT NULL,
  account_holder VARCHAR(100) NOT NULL,
  status        ENUM('pending','paid','rejected') NOT NULL DEFAULT 'pending',
  admin_note    VARCHAR(500) NULL,
  paid_at       DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rr (status, created_at),
  FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;