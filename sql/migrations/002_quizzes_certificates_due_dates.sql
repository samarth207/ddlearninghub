-- Back up the database first. Select the application database before importing.
-- Run this migration once, after importing schema.sql and any earlier migrations.
SET NAMES utf8mb4;

ALTER TABLE users ADD COLUMN joining_date DATE NULL;
ALTER TABLE lessons ADD COLUMN due_in_days INT UNSIGNED NULL;

CREATE TABLE quizzes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_id INT UNSIGNED NOT NULL,
    pass_mark_percent TINYINT UNSIGNED NOT NULL,
    max_attempts INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_quizzes_lesson (lesson_id),
    CONSTRAINT fk_quizzes_lesson FOREIGN KEY (lesson_id)
        REFERENCES lessons (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_questions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    quiz_id INT UNSIGNED NOT NULL,
    question TEXT NOT NULL,
    option_a TEXT NOT NULL,
    option_b TEXT NOT NULL,
    option_c TEXT NOT NULL,
    option_d TEXT NOT NULL,
    correct_option ENUM('a', 'b', 'c', 'd') NOT NULL,
    explanation TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_quiz_questions_order (quiz_id, sort_order, id),
    CONSTRAINT fk_quiz_questions_quiz FOREIGN KEY (quiz_id)
        REFERENCES quizzes (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    quiz_id INT UNSIGNED NOT NULL,
    score_percent DECIMAL(5,2) NOT NULL,
    passed TINYINT(1) NOT NULL,
    answers_json LONGTEXT NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_quiz_attempts_user_quiz (user_id, quiz_id),
    KEY idx_quiz_attempts_quiz (quiz_id),
    CONSTRAINT fk_quiz_attempts_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_quiz_attempts_quiz FOREIGN KEY (quiz_id)
        REFERENCES quizzes (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certificates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED NOT NULL,
    certificate_no VARCHAR(80) NOT NULL,
    employee_name VARCHAR(150) NOT NULL,
    lesson_title VARCHAR(200) NOT NULL,
    issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certificates_number (certificate_no),
    UNIQUE KEY uq_certificates_user_lesson (user_id, lesson_id),
    KEY idx_certificates_lesson (lesson_id),
    CONSTRAINT fk_certificates_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_certificates_lesson FOREIGN KEY (lesson_id)
        REFERENCES lessons (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
