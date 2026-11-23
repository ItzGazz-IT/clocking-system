CREATE TABLE productivity_classification_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    match_target ENUM('app', 'website') NOT NULL,
    pattern VARCHAR(190) NOT NULL,
    category ENUM('productive', 'neutral', 'distracting') NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_productivity_rules_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_productivity_rules_user FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_productivity_rule (company_id, match_target, pattern),
    INDEX idx_productivity_rule_lookup (company_id, match_target, category)
) ENGINE=InnoDB;

INSERT IGNORE INTO productivity_classification_rules (company_id, match_target, pattern, category)
SELECT c.id, defaults.match_target, defaults.pattern, defaults.category
FROM companies c
CROSS JOIN (
    SELECT 'website' AS match_target, 'youtube' AS pattern, 'distracting' AS category UNION ALL
    SELECT 'website', 'facebook', 'distracting' UNION ALL
    SELECT 'website', 'instagram', 'distracting' UNION ALL
    SELECT 'website', 'tiktok', 'distracting' UNION ALL
    SELECT 'website', 'netflix', 'distracting' UNION ALL
    SELECT 'website', 'twitter', 'distracting' UNION ALL
    SELECT 'website', 'reddit', 'distracting' UNION ALL
    SELECT 'website', 'pinterest', 'distracting' UNION ALL
    SELECT 'website', 'twitch', 'distracting' UNION ALL
    SELECT 'app', 'steam', 'distracting' UNION ALL
    SELECT 'app', 'xbox', 'distracting' UNION ALL
    SELECT 'app', 'spotify', 'distracting' UNION ALL
    SELECT 'app', 'discord', 'distracting' UNION ALL
    SELECT 'app', 'whatsapp', 'distracting' UNION ALL
    SELECT 'app', 'telegram', 'distracting' UNION ALL
    SELECT 'app', 'microsoft word', 'productive' UNION ALL
    SELECT 'app', 'microsoft excel', 'productive' UNION ALL
    SELECT 'app', 'microsoft outlook', 'productive' UNION ALL
    SELECT 'app', 'microsoft teams', 'productive' UNION ALL
    SELECT 'app', 'visual studio', 'productive' UNION ALL
    SELECT 'app', 'adobe acrobat', 'productive' UNION ALL
    SELECT 'app', 'file explorer', 'neutral' UNION ALL
    SELECT 'app', 'google chrome', 'neutral' UNION ALL
    SELECT 'app', 'microsoft edge', 'neutral'
) defaults;
