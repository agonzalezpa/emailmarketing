<?php

/**
 * process_bounces.php
 *
 * Este script se conecta a múltiples buzones de correo (definidos en la tabla `senders`)
 * para procesar correos rebotados (bounces).
 *
 * Lógica:
 * 1. Conecta a la base de datos principal.
 * 2. Obtiene todos los remitentes activos que tienen configuración IMAP.
 * 3. Itera sobre cada remitente.
 * 4. Se conecta al buzón IMAP de cada remitente.
 * 5. Busca correos recibidos desde el inicio del día actual (SINCE "dd-M-yyyy").
 * 6. Extrae el ID del destinatario de la campaña desde la cabecera 'X-Campaign-Recipient-ID'.
 * 7. Si lo encuentra, actualiza el registro correspondiente en `campaign_recipients` a 'bounced'.
 * 8. Mueve el correo procesado a una carpeta de 'Bounces' para no volver a procesarlo.
 *
 * Este script está diseñado para ser ejecutado como un cron job.
 */

// --- CONFIGURACIÓN DE LA BASE DE DATOS ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');

// --- INICIO DEL SCRIPT ---
echo "============================================\n";
echo "Iniciando proceso de rebotes: " . date('Y-m-d H:i:s') . "\n";
echo "============================================\n";

// Conexión a la base de datos
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error CRÍTICO de conexión a la base de datos: " . $e->getMessage() . "\n");
}

// 1. Obtener todos los remitentes activos con configuración IMAP
try {
    $stmt = $pdo->query("SELECT * FROM senders WHERE is_active = 1 AND imap_host IS NOT NULL AND imap_host != ''");
    $senders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error al obtener los remitentes: " . $e->getMessage() . "\n");
}

if (empty($senders)) {
    echo "No hay remitentes activos con configuración IMAP para procesar. Finalizando.\n";
    file_put_contents(__DIR__ . '/process_bounces.log', "No hay remitentes activos con configuración IMAP para procesar. Finalizando.\n", FILE_APPEND);
    exit;
}

echo "Se encontraron " . count($senders) . " remitentes para procesar.\n";

// 2. Iterar sobre cada remitente para procesar su buzón
foreach ($senders as $sender) {
    echo "\n--- Procesando remitente: {$sender['email']} ---\n";
    file_put_contents(__DIR__ . '/process_bounces.log', "Procesando remitente: {$sender['email']}\n", FILE_APPEND);
    // Construir la cadena de conexión IMAP
    $imap_port = $sender['imap_port'] ?? 993; // Puerto 993 por defecto para IMAP SSL
    $imap_path = "{{$sender['imap_host']}:{$imap_port}/imap/ssl}";
    $processed_mailbox_folder = 'Bounces';

    // Conexión al buzón de correo del remitente actual
    $inbox = @imap_open($imap_path . 'INBOX', $sender['smtp_username'], $sender['smtp_password']);

    if (!$inbox) {
        echo "ERROR: No se pudo conectar al buzón de {$sender['email']}. Razón: " . imap_last_error() . "\n";
        file_put_contents(__DIR__ . '/process_bounces.log', "ERROR: No se pudo conectar al buzón de {$sender['email']}. Razón: " . imap_last_error() . "\n", FILE_APPEND);
        continue; // Saltar al siguiente remitente
    }

    // Crear la carpeta para los correos procesados si no existe
    if (!mailbox_exists($inbox, $imap_path, $processed_mailbox_folder)) {
        if (@imap_createmailbox($inbox, imap_utf7_encode($imap_path . $processed_mailbox_folder))) {
            echo "Carpeta '$processed_mailbox_folder' creada exitosamente.\n";
        } else {
            echo "ADVERTENCIA: No se pudo crear la carpeta '$processed_mailbox_folder'.\n";
            file_put_contents(__DIR__ . '/process_bounces.log', "ADVERTENCIA: No se pudo crear la carpeta '$processed_mailbox_folder'.\n", FILE_APPEND);
        }
    }

    // --- MODIFICACIÓN CLAVE: Buscar por fecha en lugar de "UNSEEN" ---
    $search_date = date("d-M-Y");
    $search_criteria = "SINCE \"$search_date\"";
    echo "Buscando correos en INBOX desde el $search_date...\n";

    $emails = imap_search($inbox, $search_criteria, SE_UID);

    if ($emails) {
        echo "Se encontraron " . count($emails) . " correos para revisar.\n";

        $updateStmt = $pdo->prepare(
            "UPDATE campaign_recipients 
             SET status = 'bounced', bounced_at = NOW(), bounce_reason = ? 
             WHERE id = ?"
        );

        foreach ($emails as $uid) {
            // Usamos el UID para obtener el número de secuencia del mensaje
            $msg_number = imap_msgno($inbox, $uid);

            $header_text = imap_fetchheader($inbox, $uid, FT_UID);

            // Intentar extraer el ID personalizado de la cabecera
            $recipient_id = extract_custom_header($header_text, 'X-Campaign-Recipient-ID');

            if ($recipient_id) {
                $body = imap_body($inbox, $uid, FT_UID);
                $bounce_reason = extract_bounce_reason($body);
                echo " - Rebote detectado para el destinatario ID: $recipient_id\n";

                $updateStmt->execute([$bounce_reason, $recipient_id]);

                if ($updateStmt->rowCount() > 0) {
                    echo "   -> Registro actualizado a 'bounced'.\n";
                } else {
                    echo "   -> ADVERTENCIA: El registro con ID $recipient_id no se encontró o ya estaba actualizado.\n";
                    file_put_contents(__DIR__ . '/process_bounces.log', "ADVERTENCIA: El registro con ID $recipient_id no se encontró o ya estaba actualizado.\n", FILE_APPEND);
                }
            } else {
                echo " - Correo UID #$uid ignorado (no es un rebote de campaña o no tiene la cabecera).\n";
                file_put_contents(__DIR__ . '/process_bounces.log', "Correo UID #$uid ignorado (no es un rebote de campaña o no tiene la cabecera).\n", FILE_APPEND);
            }

            // Mover el correo procesado para no volver a leerlo
            imap_mail_move($inbox, "$uid", $processed_mailbox_folder, CP_UID);
        }

        imap_expunge($inbox);
    } else {
        echo "No se encontraron nuevos correos de rebote para este remitente desde $search_date.\n";
    }

    // Cerrar la conexión para este remitente
    imap_close($inbox);
}

echo "\n============================================\n";
echo "Proceso de rebotes finalizado: " . date('Y-m-d H:i:s') . "\n";
echo "============================================\n";
file_put_contents(__DIR__ . '/process_bounces.log', "Proceso de rebotes finalizado: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

/**
 * Función para extraer el valor de una cabecera personalizada.
 */
function extract_custom_header($header_text, $header_name)
{
    $pattern = "/^" . preg_quote($header_name, '/') . ":\s*(.*)$/im";
    if (preg_match($pattern, $header_text, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

/**
 * Función para extraer la razón del rebote desde el cuerpo del mensaje.
 */
function extract_bounce_reason($body)
{
    $pattern = '/Diagnostic-Code: (.*)/i';
    if (preg_match($pattern, $body, $matches)) {
        return trim($matches[1]);
    }

    $pattern = '/Status: (.*)/i';
    if (preg_match($pattern, $body, $matches)) {
        return trim($matches[1]);
    }

    return 'Razón no especificada.';
}

/**
 * Verifica si un buzón (carpeta) existe.
 */
function mailbox_exists($stream, $imap_path, $mailbox)
{
    $mailboxes = imap_list($stream, $imap_path, '*');
    $encoded_mailbox_name = imap_utf7_encode($imap_path . $mailbox);
    return in_array($encoded_mailbox_name, $mailboxes);
}
