<?php
// --- MODO DE DEPURACIÓN (Activar solo para debug) ---
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// --- CONEXIÓN A LA BASE DE DATOS ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');

// --- LÓGICA DE SEGUIMIENTO DE APERTURAS MEJORADA ---

// 1. Obtener datos de la petición
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
$referer = $_SERVER['HTTP_REFERER'] ?? '';

// 2. Lista de bots REALES que debemos filtrar (más conservadora)
$definite_bots = [
    'GoogleImageProxy',           // Proxy de imágenes de Gmail
    'YahooMailProxy',            // Proxy de Yahoo
    'FeedFetcher-Google',        // Bot de Google para feeds
    'Gmail-content-scanning',     // Scanner de contenido de Gmail
    'ProtonMail-image-proxy',    // Proxy de ProtonMail
    'Thunderbird/0.0.0.0',      // Algunos scrapers usan esta firma
];

// 3. Patrones de bots más específicos
$bot_patterns = [
    '/HeadlessChrome/i',                    // Chrome sin interfaz
    '/PhantomJS/i',                         // PhantomJS
    '/\bbot\b/i',                          // Palabra "bot"
    '/\bspider\b/i',                       // Palabra "spider"
    '/\bcrawler\b/i',                      // Palabra "crawler"
    '/\bscanner\b/i',                      // Palabra "scanner"
    '/AhrefsBot|SemrushBot|Bytespider|bingbot|msnbot/i',  // Bots específicos
    '/WhatsApp\/[\d\.]+\s*$/i',            // WhatsApp preview (muy básico)
    '/Slackbot/i',                         // Slack preview
    '/facebookexternalhit/i',              // Facebook preview
    '/LinkedInBot/i',                      // LinkedIn preview
    '/Twitterbot/i',                       // Twitter preview
];

// 4. Función para verificar si es un bot
function isBot($user_agent, $ip_address) {
    global $definite_bots, $bot_patterns;
    
    // Si no hay User-Agent, es muy sospechoso
    if (empty($user_agent)) {
        return true;
    }
    
    // User-Agent demasiado corto o genérico
    if (strlen($user_agent) < 10 || $user_agent === 'Mozilla/5.0') {
        return true;
    }
    
    // Verificar bots definidos
    foreach ($definite_bots as $bot) {
        if (stripos($user_agent, $bot) !== false) {
            return true;
        }
    }
    
    // Verificar patrones de bots
    foreach ($bot_patterns as $pattern) {
        if (preg_match($pattern, $user_agent)) {
            return true;
        }
    }
    
    return false;
}

// 5. Función para verificar si es una apertura duplicada reciente
function isDuplicateRecent($pdo, $campaign_id, $contact_id, $ip_address) {
    try {
        // Buscar aperturas de la misma campaña y contacto en los últimos 30 minutos
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM email_events 
             WHERE campaign_id = ? AND contact_id = ? AND event_type = 'opened' 
             AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
        $stmt->execute([$campaign_id, $contact_id, $ip_address]);
        
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false; // En caso de error, permitir el registro
    }
}

// 6. Función para log de debug (opcional)
function logDebug($message) {
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] $message\n";
        file_put_contents(__DIR__ . '/debug_tracking.log', $log_message, FILE_APPEND);
    }
}

// 7. LÓGICA PRINCIPAL DE TRACKING
$campaign_id = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$contact_id = isset($_GET['contact_id']) ? (int)$_GET['contact_id'] : 0;

// Verificar que tenemos los parámetros necesarios
if ($campaign_id > 0 && $contact_id > 0) {
    
    // Verificar si es un bot
    $is_bot = isBot($user_agent, $ip_address);
    
    // Log de debug (descomenta para activar)
    // define('DEBUG_MODE', true);
    // logDebug("UA: $user_agent | IP: $ip_address | Bot: " . ($is_bot ? 'YES' : 'NO'));
    
    if (!$is_bot) {
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Verificar si no es una apertura duplicada reciente
            if (!isDuplicateRecent($pdo, $campaign_id, $contact_id, $ip_address)) {
                
                // Registrar la apertura
                $stmt = $pdo->prepare(
                    "INSERT INTO email_events (campaign_id, contact_id, event_type, ip_address, user_agent, created_at) 
                     VALUES (?, ?, 'opened', ?, ?, NOW())"
                );
                $stmt->execute([$campaign_id, $contact_id, $ip_address, $user_agent]);
                
                // Log de éxito (opcional)
                // logDebug("APERTURA REGISTRADA - Campaign: $campaign_id, Contact: $contact_id");
            }
            
        } catch (PDOException $e) {
            // Registrar error
            $errorMessage = date('Y-m-d H:i:s') . " - ERROR DE APERTURA: " . $e->getMessage();
            file_put_contents(__DIR__ . '/error_tracking.log', $errorMessage . "\n", FILE_APPEND);
        }
    }
}

// --- RESPUESTA FINAL ---
// Siempre servir la imagen de 1x1 píxel transparente
header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Imagen GIF transparente de 1x1 píxel
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;
?>