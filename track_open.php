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

// 1. Obtener el User-Agent de la petición
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// 2. Lista negra de firmas de bots y proxies de correo
$bot_signatures = [
    'GoogleImageProxy', // Proxy de imágenes de Gmail
    'Apple Mail',       // Mail Privacy Protection de Apple (a menudo no se identifica claramente, pero se puede filtrar por otros patrones si es necesario)
    'YahooMailProxy',   // Proxy de imágenes de Yahoo
    'Microsoft Office', // Proxy de Outlook (a menudo se identifica como "Microsoft Office" o "Outlook")
    'Outlook-iOS',      // App de Outlook en iOS
    'Outlook-Android',  // App de Outlook en Android
    'AhrefsBot',        // Bot de SEO
    'SemrushBot',       // Bot de SEO
    'Bytespider',       // Otro bot común
    'bot',              // Término genérico para capturar otros bots
    'spider',           // Término genérico para capturar "arañas" web
    'crawler'           // Término genérico para capturar "crawlers"
];

// 3. Verificar si el User-Agent contiene alguna de las firmas de la lista negra
$is_bot = false;
foreach ($bot_signatures as $signature) {
    // usamos stripos para una búsqueda case-insensitive (no distingue mayúsculas/minúsculas)
    if (stripos($user_agent, $signature) !== false) {
        $is_bot = true;
        break; // Si encontramos una coincidencia, no necesitamos seguir buscando
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
            
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

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
// Independientemente de si se registró la apertura o no, siempre servimos la imagen de 1x1 píxel.
// Esto es crucial para que el cliente de correo no muestre una imagen rota.
header('Content-Type: image/gif');
echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICRAEAOw==');
exit;