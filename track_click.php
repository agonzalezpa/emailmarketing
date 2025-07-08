<?php
//<!-- (ejemplo de como tiene que ser el ennlace dentro del correo para por ser rastreado) -->
//<a href="https://marketing.dom0125.com/track/click/123/456?url=https%3A%2F%2Ftudestino.com%2Foferta">Ver oferta</a>


// --- CREDENCIALES DE BASE DE DATOS (centralizadas para reutilización) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');

// Función para crear una nueva conexión PDO
function createPdoConnection()
{
    return new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
}
// track/click.php
$campaignId = $_GET['campaign_id'];
$contactId = $_GET['contact_id'];
$url = $_GET['url'];

// Registrar el clic
$pdo = createPdoConnection();
$stmt = $pdo->prepare("INSERT INTO email_events (campaign_id, contact_id, event_type, event_time) VALUES (?, ?, 'clicked', NOW())");
$stmt->execute([$campaignId, $contactId]);

// Redirigir al destino real
header("Location: $url");
exit;

