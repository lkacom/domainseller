-- Import this file after selecting the database created by your hosting provider.
-- The hosting database user may not have CREATE DATABASE privileges.
CREATE TABLE IF NOT EXISTS bids (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    mobile VARCHAR(16) NOT NULL,
    email VARCHAR(190) NULL,
    price DECIMAL(20, 0) UNSIGNED NOT NULL,
    description TEXT NULL,
    sms_admin_status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    sms_user_status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    user_sms_sent TINYINT(1) NOT NULL DEFAULT 0,
    sms_admin_reference VARCHAR(80) NULL,
    sms_user_reference VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_bids_price_created (price DESC, created_at DESC),
    INDEX idx_bids_mobile (mobile),
    INDEX idx_bids_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
