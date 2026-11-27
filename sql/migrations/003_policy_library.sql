-- Back up the database, select it in phpMyAdmin, then run this migration once.
SET NAMES utf8mb4;

CREATE TABLE policies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    file_path VARCHAR(500) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    effective_date DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_policies_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE policy_acknowledgements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    policy_id INT UNSIGNED NOT NULL,
    version_acknowledged INT UNSIGNED NOT NULL,
    acknowledged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_policy_ack_version (user_id, policy_id, version_acknowledged),
    KEY idx_policy_ack_report (policy_id, version_acknowledged),
    CONSTRAINT fk_policy_ack_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_policy_ack_policy FOREIGN KEY (policy_id)
        REFERENCES policies (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
