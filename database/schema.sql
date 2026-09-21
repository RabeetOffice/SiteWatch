-- ============================================================================
-- SiteWatch database schema (latest version — see App\Core\Migrator::VERSION)
-- MySQL 5.7+ / MariaDB 10.3+.  All DATETIME values are stored in UTC.
-- Existing installations are upgraded automatically by App\Core\Migrator.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ----------------------------------------------------------------------------
-- Roles (permission sets). Default roles are seeded by the migrator.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(60)  NOT NULL,
  `slug`        VARCHAR(60)  NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `permissions` TEXT NULL COMMENT 'JSON array of permission keys, ["*"] = everything',
  `is_system`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'built-in role that cannot be edited or deleted',
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_slug` (`slug`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Users
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(100)  NOT NULL,
  `email`           VARCHAR(190)  NOT NULL,
  `password_hash`   VARCHAR(255)  NOT NULL,
  `role_id`         INT UNSIGNED  NOT NULL,
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `session_version` INT UNSIGNED  NOT NULL DEFAULT 1 COMMENT 'incremented to sign the user out everywhere',
  `last_login_at`   DATETIME      NULL,
  `last_login_ip`   VARCHAR(45)   NULL,
  `created_at`      DATETIME      NOT NULL,
  `updated_at`      DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `fk_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login rate limiting
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`         VARCHAR(45)  NOT NULL,
  `email`      VARCHAR(190) NOT NULL,
  `success`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_ip_time` (`ip`, `created_at`),
  KEY `idx_login_email_time` (`email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Remember me" tokens (selector + hashed validator)
CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `selector`       CHAR(24)  NOT NULL,
  `validator_hash` CHAR(64)  NOT NULL,
  `expires_at`     DATETIME  NOT NULL,
  `created_at`     DATETIME  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_id`),
  KEY `idx_remember_expires` (`expires_at`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Monitored websites
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `websites` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(150)  NOT NULL,
  `client_name`         VARCHAR(150)  NOT NULL DEFAULT '',
  `url`                 VARCHAR(2048) NOT NULL,
  `url_hash`            CHAR(64)      NOT NULL COMMENT 'sha256 of normalised URL, enforces uniqueness',
  `domain`              VARCHAR(253)  NOT NULL,
  `type`                VARCHAR(20)   NOT NULL DEFAULT 'wordpress' COMMENT 'wordpress | woocommerce | other',
  `status`              VARCHAR(30)   NOT NULL DEFAULT 'PENDING',
  `previous_status`     VARCHAR(30)   NULL,
  `monitoring_enabled`  TINYINT(1)    NOT NULL DEFAULT 1,
  `check_interval`      SMALLINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'minutes',
  `failure_threshold`   TINYINT UNSIGNED NULL COMMENT 'per-site override, NULL = global setting',
  `recovery_threshold`  TINYINT UNSIGNED NULL COMMENT 'per-site override, NULL = global setting',
  `failure_count`       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'consecutive failures',
  `success_count`       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'consecutive successes',
  `checks_json`         TEXT NULL COMMENT 'enabled check types',
  `alerts_json`         TEXT NULL COMMENT 'enabled alert types (per-site overrides)',
  `last_http_status`    SMALLINT UNSIGNED NULL,
  `last_response_time`  INT UNSIGNED NULL COMMENT 'milliseconds',
  `last_error_type`     VARCHAR(40)  NULL,
  `last_error_message`  VARCHAR(500) NULL,
  `last_final_url`      VARCHAR(2048) NULL,
  `last_redirect_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_checked_at`     DATETIME NULL,
  `last_online_at`      DATETIME NULL,
  `last_down_at`        DATETIME NULL,
  `last_status_change_at` DATETIME NULL,
  `next_check_at`       DATETIME NULL,
  `ssl_valid`           TINYINT(1)   NULL,
  `ssl_expires_at`      DATETIME     NULL,
  `ssl_issuer`          VARCHAR(190) NULL,
  `ssl_error`           VARCHAR(255) NULL,
  `ssl_checked_at`      DATETIME     NULL,
  `ssl_alert_level`     SMALLINT     NULL COMMENT 'last SSL expiry alert threshold sent (30/14/7/0)',
  `favicon_url`         VARCHAR(500) NULL,
  `notes`               TEXT NULL,
  `created_at`          DATETIME NOT NULL,
  `updated_at`          DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_websites_url_hash` (`url_hash`),
  KEY `idx_websites_status` (`status`),
  KEY `idx_websites_due` (`monitoring_enabled`, `next_check_at`),
  KEY `idx_websites_client` (`client_name`),
  KEY `idx_websites_domain` (`domain`),
  KEY `idx_websites_ssl_expires` (`ssl_expires_at`),
  KEY `idx_websites_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Individual check results (raw, retained for a configurable number of days)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `website_checks` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id`         INT UNSIGNED NOT NULL,
  `status`             VARCHAR(30)  NOT NULL,
  `is_failure`         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'raw request outcome was a failure',
  `is_up`              TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'counts as up for uptime (only confirmed downtime is 0)',
  `http_status`        SMALLINT UNSIGNED NULL,
  `response_time`      INT UNSIGNED NULL COMMENT 'milliseconds',
  `error_type`         VARCHAR(40)  NULL,
  `error_message`      VARCHAR(500) NULL,
  `redirect_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `final_url`          VARCHAR(2048) NULL,
  `ssl_days_remaining` SMALLINT NULL,
  `source`             VARCHAR(10)  NOT NULL DEFAULT 'cron' COMMENT 'cron | manual',
  `checked_at`         DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_checks_website_time` (`website_id`, `checked_at`),
  KEY `idx_checks_time` (`checked_at`),
  CONSTRAINT `fk_checks_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Incidents (confirmed failures). Retained indefinitely.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incidents` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id`             INT UNSIGNED NOT NULL,
  `type`                   VARCHAR(30)   NOT NULL,
  `title`                  VARCHAR(190)  NOT NULL,
  `error_message`          VARCHAR(1000) NULL,
  `http_status`            SMALLINT UNSIGNED NULL,
  `response_time`          INT UNSIGNED NULL,
  `diagnostics`            TEXT NULL COMMENT 'JSON supporting diagnostics',
  `started_at`             DATETIME NOT NULL COMMENT 'time of the first failed check',
  `confirmed_at`           DATETIME NOT NULL COMMENT 'time the failure threshold was reached',
  `resolved_at`            DATETIME NULL,
  `duration_seconds`       INT UNSIGNED NULL,
  `status`                 VARCHAR(10) NOT NULL DEFAULT 'OPEN' COMMENT 'OPEN | RESOLVED',
  `notified_at`            DATETIME NULL,
  `recovery_notified_at`   DATETIME NULL,
  `resolved_http_status`   SMALLINT UNSIGNED NULL,
  `resolved_response_time` INT UNSIGNED NULL,
  `created_at`             DATETIME NOT NULL,
  `updated_at`             DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_incidents_website_started` (`website_id`, `started_at`),
  KEY `idx_incidents_status` (`status`),
  KEY `idx_incidents_started` (`started_at`),
  KEY `idx_incidents_type` (`type`),
  CONSTRAINT `fk_incidents_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Daily aggregated statistics (one row per website per day, kept indefinitely)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `daily_stats` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id`            INT UNSIGNED NOT NULL,
  `stat_date`             DATE NOT NULL COMMENT 'calendar day in the application timezone',
  `total_checks`          INT UNSIGNED NOT NULL DEFAULT 0,
  `successful_checks`     INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_checks`         INT UNSIGNED NOT NULL DEFAULT 0,
  `uptime_percentage`     DECIMAL(6,3) NULL,
  `average_response_time` INT UNSIGNED NULL,
  `min_response_time`     INT UNSIGNED NULL,
  `max_response_time`     INT UNSIGNED NULL,
  `incident_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`            DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_daily_site_date` (`website_id`, `stat_date`),
  KEY `idx_daily_date` (`stat_date`),
  CONSTRAINT `fk_daily_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Key/value application settings (thresholds, SMTP, Telegram, WhatsApp, Discord, ...)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `key`        VARCHAR(100) NOT NULL,
  `value`      TEXT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Notification attempts (every alert that was sent, failed or skipped)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id`    INT UNSIGNED NULL,
  `incident_id`   INT UNSIGNED NULL,
  `channel`       VARCHAR(20)  NOT NULL COMMENT 'email | telegram | whatsapp | discord',
  `event`         VARCHAR(40)  NOT NULL COMMENT 'down | recovery | ssl_expiry | slow | test',
  `recipient`     VARCHAR(255) NULL,
  `subject`       VARCHAR(255) NULL,
  `status`        VARCHAR(10)  NOT NULL COMMENT 'sent | failed | skipped',
  `error_message` VARCHAR(500) NULL,
  `created_at`    DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_website` (`website_id`, `created_at`),
  KEY `idx_notifications_created` (`created_at`),
  KEY `idx_notifications_incident` (`incident_id`),
  CONSTRAINT `fk_notifications_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Activity log (meaningful admin/system events only)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NULL,
  `website_id`  INT UNSIGNED NULL,
  `action`      VARCHAR(50)  NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `context`     TEXT NULL,
  `ip`          VARCHAR(45)  NULL,
  `created_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_activity_created` (`created_at`),
  KEY `idx_activity_website` (`website_id`),
  KEY `idx_activity_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Monitoring engine heartbeats (one row per cron run)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `monitor_heartbeats` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `process`          VARCHAR(30) NOT NULL COMMENT 'monitor | cleanup | ssl-check | domain-check',
  `started_at`       DATETIME NOT NULL,
  `finished_at`      DATETIME NULL,
  `websites_checked` INT UNSIGNED NOT NULL DEFAULT 0,
  `failures`         INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms`      INT UNSIGNED NULL,
  `status`           VARCHAR(10) NOT NULL DEFAULT 'running' COMMENT 'running | completed | failed | skipped',
  `message`          VARCHAR(500) NULL,
  `hostname`         VARCHAR(190) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_heartbeats_process_time` (`process`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Domain registration (RDAP / WHOIS) and hosting details, one row per website
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `domain_info` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id`       INT UNSIGNED NOT NULL,
  `host`             VARCHAR(253) NOT NULL COMMENT 'website host that was inspected',
  `domain`           VARCHAR(253) NOT NULL COMMENT 'registrable domain, e.g. example.co.uk',
  `registrar`        VARCHAR(190) NULL,
  `registered_at`    DATETIME     NULL,
  `expires_at`       DATETIME     NULL,
  `whois_source`     VARCHAR(10)  NULL COMMENT 'rdap | whois',
  `whois_error`      VARCHAR(255) NULL,
  `ip_address`       VARCHAR(45)  NULL COMMENT 'primary address the host resolves to',
  `asn`              INT UNSIGNED NULL,
  `hosting_provider` VARCHAR(120) NULL,
  `cdn`              VARCHAR(60)  NULL,
  `country_code`     CHAR(2)      NULL,
  `hosting_error`    VARCHAR(255) NULL,
  `details`          MEDIUMTEXT   NULL COMMENT 'JSON: full registration and hosting details incl. raw RDAP/WHOIS',
  `checked_at`       DATETIME     NOT NULL,
  `created_at`       DATETIME     NOT NULL,
  `updated_at`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_domain_info_website` (`website_id`),
  KEY `idx_domain_info_domain` (`domain`),
  KEY `idx_domain_info_expires` (`expires_at`),
  KEY `idx_domain_info_checked` (`checked_at`),
  CONSTRAINT `fk_domain_info_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
