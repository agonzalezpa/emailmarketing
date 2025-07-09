
<?php
// Conexión a la base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');

// Recibimos campaign_id y contact_id
if (!empty($_GET['campaign_id']) && !empty($_GET['contact_id'])) {
    $campaign_id = (int)$_GET['campaign_id'];
    $contact_id = (int)$_GET['contact_id'];

    if ($campaign_id > 0 && $contact_id > 0) {

        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);

            // Capturamos la información adicional
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            // Preparamos la inserción en tu tabla email_events
            $stmt = $pdo->prepare(
                "INSERT INTO email_events (campaign_id, contact_id, event_type, ip_address, user_agent) 
                 VALUES (?, ?, 'opened', ?, ? )"
            );
            $stmt->execute([$campaign_id, $contact_id, $ip_address, $user_agent]);
        } catch (PDOException $e) {
            // Silencio
            $errorMessage = "ERROR FATAL: " . $e->getMessage() . " en el archivo " . $e->getFile() . " en la línea " . $e->getLine();
            file_put_contents(__DIR__ . '/error_log', $errorMessage . "\n", FILE_APPEND);
        }
    }
} else {
    $queryString = $_SERVER['QUERY_STRING'] ?? 'No query string';
    file_put_contents(__DIR__ . '/error_log', "Petición sin parámetros recibida: " . $queryString . "\n", FILE_APPEND);
}

// Servir la imagen no cambia
header('Content-Type: image/gif');
echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICRAEAOw==');
exit;
