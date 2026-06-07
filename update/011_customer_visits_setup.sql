-- Customer foot-traffic analytics (imported from Kody 7 Jun)
-- Keeps the existing clinic `visits` table untouched.

CREATE TABLE IF NOT EXISTS customer_visits (
    id INT(11) NOT NULL AUTO_INCREMENT,
    gender ENUM('male','female') NOT NULL,
    age_group ENUM('under18','18_25','25_40','over40') NOT NULL,
    mode ENUM('solo','group') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NULL DEFAULT NULL,
    order_value ENUM('under60','over60') NOT NULL,
    visit_type ENUM('new','returning','regular') NOT NULL,
    created_by INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    isdeleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_created_at (created_at),
    KEY idx_isdeleted (isdeleted),
    KEY idx_visit_type (visit_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE settings ADD COLUMN show_customer_visits INT DEFAULT 1;
