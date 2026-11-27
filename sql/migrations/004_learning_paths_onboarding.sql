-- Back up the database, select it in phpMyAdmin, then run this migration once.
SET NAMES utf8mb4;

CREATE TABLE paths (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    department VARCHAR(120) NULL,
    description TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE path_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    path_id INT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    suggested_day INT UNSIGNED NULL,
    UNIQUE KEY uq_path_lesson (path_id, lesson_id),
    KEY idx_path_order (path_id, sort_order, id),
    CONSTRAINT fk_path_item_path FOREIGN KEY (path_id) REFERENCES paths (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_path_item_lesson FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_paths (
    user_id INT UNSIGNED NOT NULL,
    path_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, path_id),
    KEY idx_user_paths_path (path_id),
    CONSTRAINT fk_user_path_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_path_path FOREIGN KEY (path_id) REFERENCES paths (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE checklist_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    department VARCHAR(120) NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_checklist (
    user_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    done_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, item_id),
    KEY idx_user_checklist_item (item_id),
    CONSTRAINT fk_user_checklist_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_checklist_item FOREIGN KEY (item_id) REFERENCES checklist_items (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contacts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    role VARCHAR(150) NOT NULL,
    department VARCHAR(120) NULL,
    email VARCHAR(254) NULL,
    phone VARCHAR(50) NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE holidays (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    name VARCHAR(200) NOT NULL,
    KEY idx_holidays_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
