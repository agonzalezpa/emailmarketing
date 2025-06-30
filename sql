-- Crear la base de datos
CREATE DATABASE email_marketing;

-- Importar la estructura
mysql -u root -p email_marketing < database.sql

INSERT INTO templates (name, subject, html_content) 
VALUES ('Newsletter', 'Newsletter Mensual', '<html>...</html>');

