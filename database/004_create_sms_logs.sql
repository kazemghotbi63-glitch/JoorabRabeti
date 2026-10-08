CREATE TABLE IF NOT EXISTS `sms_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    `user_id` INT UNSIGNED DEFAULT NULL,
    `order_id` INT UNSIGNED DEFAULT NULL,

    `mobile` VARCHAR(15) NOT NULL,
    `pattern_id` INT UNSIGNED NOT NULL,
    `event` VARCHAR(50) NOT NULL,

    `variables` JSON DEFAULT NULL,

    `status` ENUM('pending', 'sent', 'failed')
        NOT NULL DEFAULT 'pending',

    `message_id` VARCHAR(100) DEFAULT NULL,

    `response` TEXT DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,

    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at` DATETIME DEFAULT NULL,

    PRIMARY KEY (`id`),

    KEY `idx_sms_user` (`user_id`),
    KEY `idx_sms_order` (`order_id`),
    KEY `idx_sms_pattern` (`pattern_id`),
    KEY `idx_sms_event` (`event`),
    KEY `idx_sms_status` (`status`),
    KEY `idx_sms_created` (`created_at`),

    CONSTRAINT `fk_sms_log_user`
        FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON DELETE SET NULL,

    CONSTRAINT `fk_sms_log_order`
        FOREIGN KEY (`order_id`)
        REFERENCES `orders` (`id`)
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;