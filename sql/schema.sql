-- Select your hosting provider's MySQL database in phpMyAdmin before importing.
-- The first admin is seeded inactive. Set its password hash and activate it
-- only after following the deployment instructions in README.md.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(254) NOT NULL,
    emp_code VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    department VARCHAR(120) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_emp_code (emp_code),
    KEY idx_users_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(170) NOT NULL,
    description TEXT NULL,
    icon VARCHAR(80) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name),
    UNIQUE KEY uq_categories_slug (slug),
    KEY idx_categories_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lessons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    video_type ENUM('youtube', 'drive') NOT NULL,
    video_url VARCHAR(1000) NOT NULL,
    duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_mandatory TINYINT(1) NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lessons_category_published_sort (category_id, is_published, sort_order),
    CONSTRAINT fk_lessons_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_attachments_lesson (lesson_id),
    CONSTRAINT fk_attachments_lesson FOREIGN KEY (lesson_id)
        REFERENCES lessons (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS progress (
    user_id INT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED NOT NULL,
    status ENUM('started', 'completed') NOT NULL DEFAULT 'started',
    completed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (user_id, lesson_id),
    KEY idx_progress_lesson_status (lesson_id, status),
    CONSTRAINT fk_progress_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_progress_lesson FOREIGN KEY (lesson_id)
        REFERENCES lessons (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS universities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    logo_path VARCHAR(500) NULL,
    fee_structure_pdf VARCHAR(500) NULL,
    notes TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_universities_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS university_fees (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    university_id INT UNSIGNED NOT NULL,
    program VARCHAR(200) NOT NULL,
    duration VARCHAR(100) NOT NULL,
    total_fee DECIMAL(12,2) NULL,
    per_semester_fee DECIMAL(12,2) NULL,
    notes TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_university_fees_university (university_id),
    CONSTRAINT fk_university_fees_university FOREIGN KEY (university_id)
        REFERENCES universities (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categories (name, slug, description, sort_order) VALUES
    ('POSH Training', 'posh-training', 'Prevention of Sexual Harassment training and workplace guidance.', 1),
    ('Fresher Onboarding', 'fresher-onboarding', 'Orientation and essential resources for new team members.', 2),
    ('University Fees', 'university-fees', 'University and program fee information for student advisors.', 3),
    ('HR Portal How-tos', 'hr-portal-how-tos', 'Guides for leave requests and other HR portal tasks.', 4),
    ('Sales How-tos', 'sales-how-tos', 'Sales workflows, records, and communication guidance.', 5);

INSERT IGNORE INTO users (name, email, emp_code, password_hash, role, department, is_active)
VALUES (
    'Learning Hub Admin',
    'admin@degreedrishti.com',
    'DD-ADMIN-001',
    '!SET_A_REAL_PASSWORD_HASH_BEFORE_ACTIVATING!',
    'admin',
    'Administration',
    0
);

SET FOREIGN_KEY_CHECKS = 1;
