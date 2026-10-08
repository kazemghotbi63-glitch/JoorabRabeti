CREATE TABLE IF NOT EXISTS financial_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  invoice_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,

  type ENUM('creditor_settlement') NOT NULL,

  amount INT UNSIGNED NOT NULL,

  method ENUM('sheba','card_to_card','cash') NOT NULL,

  document_no VARCHAR(100) DEFAULT NULL,
  reference_no VARCHAR(100) DEFAULT NULL,
  description VARCHAR(500) DEFAULT NULL,

  status ENUM('pending','completed','cancelled')
    NOT NULL DEFAULT 'completed',

  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME DEFAULT NULL,

  KEY idx_financial_invoice (invoice_id),
  KEY idx_financial_user (user_id),
  KEY idx_financial_type (type),
  KEY idx_financial_status (status),
  KEY idx_financial_created (created_at),

  CONSTRAINT fk_financial_invoice
    FOREIGN KEY (invoice_id)
    REFERENCES invoices(id),

  CONSTRAINT fk_financial_user
    FOREIGN KEY (user_id)
    REFERENCES users(id),

  CONSTRAINT fk_financial_creator
    FOREIGN KEY (created_by)
    REFERENCES users(id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
