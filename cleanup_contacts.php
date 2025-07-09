<?php
/**
 * cleanup_contacts.php
 *
 * Este script se ejecuta como un cron job para limpiar la base de datos.
 * 1. Busca contactos que han rebotado (bounced) o fallado permanentemente.
 * 2. Elimina a esos contactos de todas las listas de `contact_list_members`.
 * 3. Actualiza el estado de esos contactos a 'inactive' en la tabla `contacts`.
 * Esta versión es eficiente y solo procesa contactos que aún no han sido desactivados.
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
log_message("Iniciando script de limpieza de contactos y listas...");

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    //log_message("Conexión a la base de datos exitosa.");
} catch (PDOException $e) {
    log_message("Error CRÍTICO de conexión a la base de datos: " . $e->getMessage());
    die();
}

try {
    // 1. Encontrar todos los IDs de contacto únicos que han rebotado o fallado
    //    Y QUE NO ESTÉN YA MARCADOS COMO 'inactive'.
    log_message("Buscando IDs de contactos para desactivar...");
    
    // --- CONSULTA OPTIMIZADA ---
    $stmt = $pdo->query("
        SELECT DISTINCT cr.contact_id 
        FROM campaign_recipients cr
        JOIN contacts c ON cr.contact_id = c.id
        WHERE (cr.status = 'bounced' OR cr.retry_count >= 3)
          AND c.status != 'inactive'
    ");
    
    $contact_ids_to_disable = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($contact_ids_to_disable)) {
        log_message("No se encontraron nuevos contactos para limpiar. Proceso finalizado.");
        log_message("============================================");
        exit;
    }

    log_message("Se encontraron " . count($contact_ids_to_disable) . " nuevos contactos para procesar.");

    // Crear una cadena de marcadores de posición (?, ?, ?) para las consultas IN
    $placeholders = implode(',', array_fill(0, count($contact_ids_to_disable), '?'));

    // 2. ELIMINAR a estos contactos de todas las listas de miembros
    log_message("Eliminando contactos de las listas de miembros...");
    $deleteStmt = $pdo->prepare(
        "DELETE FROM contact_list_members WHERE contact_id IN ($placeholders)"
    );
    $deleteStmt->execute($contact_ids_to_disable);
    $deleted_memberships = $deleteStmt->rowCount();
    log_message("Se eliminaron " . $deleted_memberships . " membresías de las listas.");


    // 3. ACTUALIZAR el estado de esos contactos en la tabla `contacts` a 'inactive'
    log_message("Actualizando estado de los contactos a 'inactive'...");
    $updateStmt = $pdo->prepare(
        "UPDATE contacts 
         SET status = 'inactive' 
         WHERE id IN ($placeholders)"
    );
    $updateStmt->execute($contact_ids_to_disable);
    $affected_rows = $updateStmt->rowCount();
    log_message("Se actualizaron exitosamente " . $affected_rows . " registros en la tabla 'contacts'.");

} catch (PDOException $e) {
    log_message("Ha ocurrido un error durante el proceso de limpieza: " . $e->getMessage());
}

log_message("--- RESUMEN DE LA EJECUCIÓN ---");
log_message("Contactos encontrados para limpiar: " . count($contact_ids_to_disable));
log_message("Membresías a listas eliminadas: " . ($deleted_memberships ?? 0));
log_message("Contactos marcados como inactivos: " . ($affected_rows ?? 0));
log_message("Proceso de limpieza finalizado.");
log_message("============================================");

?>