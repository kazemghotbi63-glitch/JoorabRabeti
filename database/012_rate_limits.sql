-- محدودیت تعداد درخواست برای ورود مشتری و فراموشی رمز
-- rl_key = SHA-256 از کلید (موبایل/ایمیل/IP) تا داده شخصی خام ذخیره نشود
CREATE TABLE IF NOT EXISTS rate_limits (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket      VARCHAR(40) NOT NULL,
  rl_key      CHAR(64) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rl_lookup (bucket, rl_key, created_at),
  INDEX idx_rl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
