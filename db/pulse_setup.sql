-- Pulse feature setup (imported from Kody 7 Jun)
-- Run once on the target database before using pulse.php.
-- Safe to re-run table creation; column ALTERs may error if already applied.

-- 1. Add showpulse to settings
ALTER TABLE settings ADD COLUMN showpulse INT DEFAULT 1;

-- 2. Add sid_pulse to usr_pwrs (roles)
ALTER TABLE usr_pwrs ADD COLUMN sid_pulse INT DEFAULT 1;

-- 3. Create pulse_types table
CREATE TABLE IF NOT EXISTS pulse_types (
    id INT(11) NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    category ENUM('positive','negative') NOT NULL DEFAULT 'positive',
    icon VARCHAR(50) DEFAULT 'fas fa-star',
    points INT DEFAULT 1,
    isdeleted TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Create pulse_logs table
CREATE TABLE IF NOT EXISTS pulse_logs (
    id INT(11) NOT NULL AUTO_INCREMENT,
    employee_id INT(11) NOT NULL,
    type_id INT(11) NOT NULL,
    category ENUM('positive','negative') NOT NULL,
    rating INT DEFAULT 5,
    notes TEXT,
    recorded_by INT(11) NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_employee (employee_id),
    KEY idx_type (type_id),
    KEY idx_recorded_at (recorded_at),
    KEY idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Insert default pulse types (skip if any rows already exist)
INSERT INTO pulse_types (name, category, icon, points)
SELECT 'الالتزام بالمواعيد', 'positive', 'fas fa-clock', 3 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM pulse_types LIMIT 1);
INSERT INTO pulse_types (name, category, icon, points)
SELECT 'جودة العمل', 'positive', 'fas fa-award', 5 FROM DUAL
WHERE (SELECT COUNT(*) FROM pulse_types) < 2;
INSERT INTO pulse_types (name, category, icon, points)
SELECT 'روح الفريق', 'positive', 'fas fa-users', 4 FROM DUAL
WHERE (SELECT COUNT(*) FROM pulse_types) < 3;
INSERT INTO pulse_types (name, category, icon, points)
SELECT 'المبادرة', 'positive', 'fas fa-lightbulb', 5 FROM DUAL
WHERE (SELECT COUNT(*) FROM pulse_types) < 4;
INSERT INTO pulse_types (name, category, icon, points)
SELECT 'خدمة العملاء', 'positive', 'fas fa-handshake', 4 FROM DUAL
WHERE (SELECT COUNT(*) FROM pulse_types) < 5;
INSERT INTO pulse_types (name, category, icon, points)
SELECT 'النظافة والترتيب', 'positive', 'fas fa-broom', 2 FROM DUAL
WHERE (SELECT COUNT(*) FROM pulse_types) < 6;
INSERT INTO pulse_types (name, category, icon, points)
SELECT 'التأخر', 'negative', 'fas fa-clock', -3 FROM DUAL
WHERE (SELECT COUNT(*) FROM pulse_types) < 7;
INSERT INTO pulse_types (name, category, icon, points)
SELECT 'الإهمال', 'negative', 'fas fa-exclamation-triangle', -5 FROM DUAL
WHERE (SELECT COUNT(*) FROM pulse_types) < 8;
INSERT INTO pulse_types (name, category, icon, points)
SELECT 'عدم التعاون', 'negative', 'fas fa-user-slash', -4 FROM DUAL
WHERE (SELECT COUNT(*) FROM pulse_types) < 9;
INSERT INTO pulse_types (name, category, icon, points)
SELECT 'سوء التعامل', 'negative', 'fas fa-frown', -5 FROM DUAL
WHERE (SELECT COUNT(*) FROM pulse_types) < 10;
