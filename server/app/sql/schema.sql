-- SignalLab backend schema (MySQL 5.7+ / MariaDB 10.3+)
-- Import once in cPanel → phpMyAdmin → Import.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email            VARCHAR(190) NOT NULL UNIQUE,
  password_hash    VARCHAR(255) NULL,
  name             VARCHAR(120) NOT NULL DEFAULT '',
  google_sub       VARCHAR(64)  NULL UNIQUE,
  email_verified_at DATETIME    NULL,
  created_at       DATETIME     NOT NULL,
  last_login_at    DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_settings (
  user_id        INT UNSIGNED PRIMARY KEY,
  account_size   DECIMAL(14,2) NOT NULL DEFAULT 1000,
  risk_pct       DECIMAL(5,2)  NOT NULL DEFAULT 1.00,
  style          ENUM('1d','1h') NOT NULL DEFAULT '1d',
  allow_short    TINYINT(1) NOT NULL DEFAULT 0,
  fractional     TINYINT(1) NOT NULL DEFAULT 1,
  all_signals    TINYINT(1) NOT NULL DEFAULT 0,
  push_signals   TINYINT(1) NOT NULL DEFAULT 1,
  push_results   TINYINT(1) NOT NULL DEFAULT 1,
  email_signals  TINYINT(1) NOT NULL DEFAULT 1,
  email_results  TINYINT(1) NOT NULL DEFAULT 1,
  report_daily   TINYINT(1) NOT NULL DEFAULT 0,
  report_weekly  TINYINT(1) NOT NULL DEFAULT 1,
  report_monthly TINYINT(1) NOT NULL DEFAULT 1,
  updated_at     DATETIME NOT NULL,
  CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS watchlist (
  user_id  INT UNSIGNED NOT NULL,
  symbol   VARCHAR(20)  NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, symbol),
  KEY idx_watch_symbol (symbol),
  CONSTRAINT fk_watch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refresh_tokens (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL UNIQUE,
  expires_at  DATETIME NOT NULL,
  created_at  DATETIME NOT NULL,
  revoked_at  DATETIME NULL,
  KEY idx_rt_user (user_id),
  CONSTRAINT fk_rt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_tokens (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  purpose     ENUM('verify','reset') NOT NULL,
  token_hash  CHAR(64) NOT NULL UNIQUE,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL,
  CONSTRAINT fk_et_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devices (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  fcm_token   VARCHAR(255) NOT NULL UNIQUE,
  platform    VARCHAR(16) NOT NULL DEFAULT '',
  updated_at  DATETIME NOT NULL,
  KEY idx_dev_user (user_id),
  CONSTRAINT fk_dev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip           VARCHAR(45) NOT NULL,
  email        VARCHAR(190) NOT NULL,
  attempted_at DATETIME NOT NULL,
  KEY idx_la (ip, attempted_at),
  KEY idx_la_email (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Symbols the scanner follows and the last bar it has processed.
CREATE TABLE IF NOT EXISTS tracked_series (
  symbol        VARCHAR(20) NOT NULL,
  tf            ENUM('1d','1h') NOT NULL,
  name          VARCHAR(190) NOT NULL DEFAULT '',
  tracked_since DATETIME NOT NULL,
  last_bar_time DATETIME NULL,
  last_price    DECIMAL(16,6) NULL,
  last_scan_at  DATETIME NULL,
  last_error    VARCHAR(255) NULL,
  PRIMARY KEY (symbol, tf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every signal the server has ever issued, and what happened next.
CREATE TABLE IF NOT EXISTS signals (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  symbol          VARCHAR(20) NOT NULL,
  tf              ENUM('1d','1h') NOT NULL,
  direction       ENUM('buy','sell') NOT NULL,
  kind            ENUM('trade','warning') NOT NULL,
  status          ENUM('pending','open','won','lost','warning') NOT NULL,
  signal_time     DATETIME NOT NULL,
  signal_price    DECIMAL(16,6) NOT NULL,
  stop_loss       DECIMAL(16,6) NOT NULL,
  target1         DECIMAL(16,6) NOT NULL,
  target2         DECIMAL(16,6) NOT NULL,
  atr             DECIMAL(16,6) NOT NULL,
  strength        TINYINT UNSIGNED NOT NULL,
  reasons         TEXT NOT NULL,
  entry_time      DATETIME NULL,
  entry_price     DECIMAL(16,6) NULL,
  live_stop       DECIMAL(16,6) NULL,
  live_target1    DECIMAL(16,6) NULL,
  live_target2    DECIMAL(16,6) NULL,
  tp1_hit_time    DATETIME NULL,
  exit_time       DATETIME NULL,
  exit_price      DECIMAL(16,6) NULL,
  exit_reason     ENUM('target','stop','time') NULL,
  return_pct      DECIMAL(10,4) NULL,
  r_multiple      DECIMAL(10,4) NULL,
  bars_held       SMALLINT UNSIGNED NULL,
  last_price      DECIMAL(16,6) NULL,
  last_checked_time DATETIME NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  UNIQUE KEY uq_signal (symbol, tf, direction, signal_time),
  KEY idx_sig_status (status, tf),
  KEY idx_sig_exit (exit_time),
  KEY idx_sig_time (signal_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- In-app inbox + delivery log.
CREATE TABLE IF NOT EXISTS notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  signal_id   INT UNSIGNED NULL,
  kind        ENUM('new_buy','bearish','target1','target','stop','time','report','system') NOT NULL,
  symbol      VARCHAR(20) NOT NULL DEFAULT '',
  title       VARCHAR(190) NOT NULL,
  body        VARCHAR(500) NOT NULL,
  created_at  DATETIME NOT NULL,
  read_at     DATETIME NULL,
  pushed      TINYINT(1) NOT NULL DEFAULT 0,
  emailed     TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_notif (user_id, signal_id, kind),
  KEY idx_notif_user (user_id, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_queue (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_email    VARCHAR(190) NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  html        MEDIUMTEXT NOT NULL,
  text_body   MEDIUMTEXT NOT NULL,
  created_at  DATETIME NOT NULL,
  sent_at     DATETIME NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(255) NULL,
  KEY idx_eq_pending (sent_at, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_log (
  user_id    INT UNSIGNED NOT NULL,
  period     ENUM('daily','weekly','monthly') NOT NULL,
  period_key VARCHAR(20) NOT NULL,
  sent_at    DATETIME NOT NULL,
  PRIMARY KEY (user_id, period, period_key),
  CONSTRAINT fk_rl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scan_log (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at   DATETIME NOT NULL,
  finished_at  DATETIME NULL,
  series       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  new_signals  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  closed       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  errors       TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
