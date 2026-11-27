-- Back up the database, select it in phpMyAdmin, then run this migration once.
SET NAMES utf8mb4;

CREATE TABLE settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
