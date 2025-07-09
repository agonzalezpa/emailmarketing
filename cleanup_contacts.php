<?php
/**
 * cleanup_contacts.php
 *
 * Este script se ejecuta como un cron job para limpiar la base de datos de contactos.
 * Busca todos los contactos que han rebotado (bounced) o han fallado permanentemente
 * en cualquier campaña y actualiza su estado general en la tabla 'contacts' a 'inactive'.
 * Esto previene que se les intente enviar correos en futuras campañas.
 */

// --- CONFIGURACIÓN ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');
define('LOG_FILE', __DIR__ . '/cleanup_contacts.log');

// --- FUNCIÓN DE LOGGING ---
function log_message($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] " . $message . "\n", FILE_APPEND);
}

// --- INICIO DEL SCRIPT ---
log_message("============================================");
log_message("Iniciando script de limpieza de contactos...");

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    //log_message("Conexión a la base de datos exitosa.");
} catch (PDOException $e) {
    log_message("Error CRÍTICO de conexión a la base de datos: " . $e->getMessage());
    die();
}

try {
    // 1. Encontrar todos los IDs de contacto únicos que han rebotado o fallado permanentemente
    log_message("Buscando IDs de contactos para desactivar...");
    $stmt = $pdo->query("
        SELECT DISTINCT contact_id 
        FROM campaign_recipients 
        WHERE status = 'bounced' OR retry_count >= 3
    ");
    
    $contact_ids_to_disable = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($contact_ids_to_disable)) {
        log_message("No se encontraron contactos para desactivar. Proceso finalizado.");
        log_message("============================================");
        exit;
    }

    log_message("Se encontraron " . count($contact_ids_to_disable) . " contactos para marcar como inactivos.");

    // 2. Actualizar el estado de esos contactos en la tabla `contacts`
    // Usamos 'inactive' para mantener la integridad referencial pero evitar futuros envíos.
    
    // Crear una cadena de marcadores de posición (?, ?, ?) para la consulta IN
    $placeholders = implode(',', array_fill(0, count($contact_ids_to_disable), '?'));

    $updateStmt = $pdo->prepare(
        "UPDATE contacts 
         SET status = 'inactive' 
         WHERE id IN ($placeholders) AND status != 'inactive'"
    );

    $updateStmt->execute($contact_ids_to_disable);

    $affected_rows = $updateStmt->rowCount();
    log_message("Se actualizaron exitosamente " . $affected_rows . " registros en la tabla 'contacts'.");

} catch (PDOException $e) {
    log_message("Ha ocurrido un error durante el proceso de limpieza: " . $e->getMessage());
}

log_message("Proceso de limpieza finalizado.");
log_message("============================================");

?>