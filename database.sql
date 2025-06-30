-- Base de datos para el sistema de Email Marketing
-- Este archivo contiene la estructura de la base de datos MySQL

CREATE DATABASE IF NOT EXISTS email_marketing;
USE email_marketing;

-- Tabla de remitentes (senders)
CREATE TABLE senders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port INT NOT NULL DEFAULT 587,
    smtp_username VARCHAR(255) NOT NULL,
    smtp_password VARCHAR(255) NOT NULL,
    smtp_encryption ENUM('tls', 'ssl', 'none') DEFAULT 'tls',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de contactos
CREATE TABLE contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    status ENUM('active', 'inactive', 'bounced', 'unsubscribed') DEFAULT 'active',
    tags JSON,
    custom_fields JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_status (status)
);

-- Tabla de plantillas de email
CREATE TABLE templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    html_content TEXT NOT NULL,
    text_content TEXT,
    thumbnail_url VARCHAR(500),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de campañas
CREATE TABLE campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    html_content TEXT NOT NULL,
    text_content TEXT,
    sender_id INT NOT NULL,
    template_id INT NULL,
    status ENUM('draft', 'scheduled', 'sending', 'sent', 'paused', 'cancelled') DEFAULT 'draft',
    total_recipients INT DEFAULT 0,
    sent_count INT DEFAULT 0,
    delivered_count INT DEFAULT 0,
    opened_count INT DEFAULT 0,
    clicked_count INT DEFAULT 0,
    bounced_count INT DEFAULT 0,
    unsubscribed_count INT DEFAULT 0,
    scheduled_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES senders(id) ON DELETE RESTRICT,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL
);

-- Tabla de recipients (destinatarios por campaña)
CREATE TABLE campaign_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    contact_id INT NOT NULL,
    status ENUM('pending', 'sent', 'delivered', 'opened', 'clicked', 'bounced', 'unsubscribed') DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    opened_at TIMESTAMP NULL,
    clicked_at TIMESTAMP NULL,
    bounced_at TIMESTAMP NULL,
    bounce_reason TEXT NULL,
    unsubscribed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_campaign_contact (campaign_id, contact_id),
    INDEX idx_campaign_status (campaign_id, status),
    INDEX idx_contact_status (contact_id, status)
);

-- Tabla de eventos de email (tracking)
CREATE TABLE email_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    contact_id INT NOT NULL,
    event_type ENUM('sent', 'delivered', 'opened', 'clicked', 'bounced', 'unsubscribed', 'complained') NOT NULL,
    event_data JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    INDEX idx_campaign_event (campaign_id, event_type),
    INDEX idx_contact_event (contact_id, event_type),
    INDEX idx_event_time (created_at)
);

-- Tabla de listas de contactos
CREATE TABLE contact_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de relación entre contactos y listas
CREATE TABLE contact_list_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contact_id INT NOT NULL,
    list_id INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (list_id) REFERENCES contact_lists(id) ON DELETE CASCADE,
    UNIQUE KEY unique_contact_list (contact_id, list_id)
);

-- Tabla de configuración global
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insertar configuraciones por defecto
INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('app_name', 'EmailPro Marketing Suite', 'string', 'Nombre de la aplicación'),
('max_recipients_per_campaign', '10000', 'number', 'Máximo número de destinatarios por campaña'),
('default_from_name', 'EmailPro', 'string', 'Nombre por defecto del remitente'),
('enable_click_tracking', 'true', 'boolean', 'Habilitar seguimiento de clicks'),
('enable_open_tracking', 'true', 'boolean', 'Habilitar seguimiento de aperturas'),
('bounce_handling_enabled', 'true', 'boolean', 'Habilitar manejo de rebotes'),
('unsubscribe_link_required', 'true', 'boolean', 'Requerir link de desuscripción');

-- Crear índices adicionales para optimización
CREATE INDEX idx_campaigns_status_sent ON campaigns(status, sent_at);
CREATE INDEX idx_contacts_status_created ON contacts(status, created_at);
CREATE INDEX idx_events_created ON email_events(created_at);

-- Crear vistas útiles
CREATE VIEW campaign_stats AS
SELECT 
    c.id,
    c.name,
    c.subject,
    c.status,
    c.total_recipients,
    c.sent_count,
    c.delivered_count,
    c.opened_count,
    c.clicked_count,
    c.bounced_count,
    c.unsubscribed_count,
    ROUND((c.opened_count / NULLIF(c.delivered_count, 0)) * 100, 2) as open_rate,
    ROUND((c.clicked_count / NULLIF(c.delivered_count, 0)) * 100, 2) as click_rate,
    ROUND((c.bounced_count / NULLIF(c.sent_count, 0)) * 100, 2) as bounce_rate,
    s.name as sender_name,
    s.email as sender_email,
    c.sent_at,
    c.created_at
FROM campaigns c
LEFT JOIN senders s ON c.sender_id = s.id;

CREATE VIEW contact_activity AS
SELECT 
    c.id,
    c.name,
    c.email,
    c.status,
    COUNT(DISTINCT cr.campaign_id) as campaigns_received,
    COUNT(CASE WHEN cr.status = 'opened' THEN 1 END) as emails_opened,
    COUNT(CASE WHEN cr.status = 'clicked' THEN 1 END) as emails_clicked,
    MAX(cr.opened_at) as last_opened,
    MAX(cr.clicked_at) as last_clicked,
    c.created_at
FROM contacts c
LEFT JOIN campaign_recipients cr ON c.id = cr.contact_id
GROUP BY c.id, c.name, c.email, c.status, c.created_at;

-- Procedimientos almacenados útiles

-- Procedimiento para actualizar estadísticas de campaña
DELIMITER //
CREATE PROCEDURE UpdateCampaignStats(IN campaign_id INT)
BEGIN
    UPDATE campaigns 
    SET 
        sent_count = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = campaign_id AND status IN ('sent', 'delivered', 'opened', 'clicked')),
        delivered_count = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = campaign_id AND status IN ('delivered', 'opened', 'clicked')),
        opened_count = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = campaign_id AND status IN ('opened', 'clicked')),
        clicked_count = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = campaign_id AND status = 'clicked'),
        bounced_count = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = campaign_id AND status = 'bounced'),
        unsubscribed_count = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = campaign_id AND status = 'unsubscribed')
    WHERE id = campaign_id;
END //
DELIMITER ;

-- Procedimiento para limpiar datos antiguos
DELIMITER //
CREATE PROCEDURE CleanupOldData()
BEGIN
    -- Eliminar eventos de email más antiguos de 1 año
    DELETE FROM email_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
    
    -- Archivar campañas más antiguas de 6 meses (cambiar status)
    UPDATE campaigns 
    SET status = 'archived' 
    WHERE status = 'sent' 
    AND sent_at < DATE_SUB(NOW(), INTERVAL 6 MONTH);
END //
DELIMITER ;

