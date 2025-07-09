<?php
// --- MODO DE DEPURACIÓN (Desactivado por defecto) ---
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// --- CONEXIÓN A LA BASE DE DATOS ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');

// --- LÓGICA DE SEGUIMIENTO DE APERTURAS ---

// 1. Obtener datos de la petición
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

// 2. Lista negra de firmas y patrones de bots/proxies
$specific_signatures = [
    'GoogleImageProxy',
    'YahooMailProxy',
    'Microsoft Office',
    'Outlook-iOS',
    'Outlook-Android',
    'FeedFetcher-Google',
    'Gmail-content-sampling'
];

$bot_patterns = [
    '/Apple Mail/i',
    '/HeadlessChrome/i',
    '/\b(AhrefsBot|SemrushBot|Bytespider|bingbot|msnbot|bot|spider|crawler|preview|scanner)\b/i'
];

// 3. Verificar si el User-Agent o la IP indican que es un bot
$is_bot = false;

// Si el User-Agent está vacío o es demasiado genérico, es un bot.
if (empty($user_agent) || $user_agent === 'Mozilla/5.0') {
    $is_bot = true;
}

// Comprobar firmas específicas
if (!$is_bot) {
    foreach ($specific_signatures as $signature) {
        if (stripos($user_agent, $signature) !== false) {
            $is_bot = true;
            break;
        }
    }
}

// Comprobar patrones de regex
if (!$is_bot) {
    foreach ($bot_patterns as $pattern) {
        if (preg_match($pattern, $user_agent)) {
            $is_bot = true;
            break;
        }
    }
}

// Comprobación adicional: si la IP es de un rango conocido de Google y el User-Agent es sospechoso
if (!$is_bot && $ip_address) {
    // Rangos de IP comunes de Google. Esto se puede ampliar.
    $google_ip_ranges = ['66.249.', '66.102.', '72.14.', '74.125.', '173.194.', '209.85.'];
    foreach ($google_ip_ranges as $range) {
        if (strpos($ip_address, $range) === 0) {
            // Si la IP es de Google y el User-Agent es el genérico de Chrome/Edge que vimos, lo marcamos como bot.
            if (strpos($user_agent, 'Chrome/42.0.2311.135') !== false) {
                $is_bot = true;
                break;
            }
        }
    }
}


// 4. Solo registrar la apertura si NO es un bot y si tenemos los parámetros correctos
if (!$is_bot && !empty($_GET['campaign_id']) && !empty($_GET['contact_id'])) {
    
    $campaign_id = (int)$_GET['campaign_id'];
    $contact_id = (int)$_GET['contact_id'];

    if ($campaign_id > 0 && $contact_id > 0) {
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Preparamos la inserción en tu tabla email_events
            $stmt = $pdo->prepare(
                "INSERT INTO email_events (campaign_id, contact_id, event_type, ip_address, user_agent) 
                 VALUES (?, ?, 'opened', ?, ?)"
            );
            $stmt->execute([$campaign_id, $contact_id, $ip_address, $user_agent]);

        } catch (PDOException $e) {
            // Si hay un error de base de datos, lo registramos
            $errorMessage = "ERROR DE APERTURA: " . $e->getMessage();
            file_put_contents(__DIR__ . '/error_log.txt', $errorMessage . "\n", FILE_APPEND);
        }
    }
}

// --- RESPUESTA FINAL ---
// Siempre servimos la imagen de 1x1 píxel.
header('Content-Type: image/gif');
echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICRAEAOw==');
exit;
