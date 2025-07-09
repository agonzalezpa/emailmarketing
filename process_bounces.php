<?php

/**
 * process_bounces.php
 *
 * Este script se conecta a múltiples buzones de correo para procesar rebotes (bounces).
 * Es "MIME-aware" y registra cada paso en un archivo de log para depuración.
 *
 * Lógica:
 * 1. Obtiene los remitentes activos desde la base de datos.
 * 2. Para cada remitente, se conecta a su buzón IMAP.
 * 3. Busca correos recibidos desde el inicio del día actual.
 * 4. Analiza la estructura MIME de cada correo para encontrar el ID y la razón del rebote.
 * 5. Si encuentra un ID válido, actualiza el registro correspondiente en `campaign_recipients`.
 * 6. Mueve el correo procesado a una carpeta 'Bounces'.
 * 7. Registra todas las acciones y errores en 'process_bounces.log'.
 */

// --- CONFIGURACIÓN ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');
define('LOG_FILE', __DIR__ . '/process_bounces.log');

// --- FUNCIÓN DE LOGGING ---
function log_message($message)
{
    // Añade la fecha y hora a cada mensaje y lo guarda en el archivo de log.
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] " . $message . "\n", FILE_APPEND);
}

// --- INICIO DEL SCRIPT ---
log_message("============================================");
log_message("Iniciando proceso de rebotes...");

// Conexión a la base de datos
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    log_message("Conexión a la base de datos exitosa.");
} catch (PDOException $e) {
    log_message("Error CRÍTICO de conexión a la base de datos: " . $e->getMessage());
    die(); // Detener el script si no se puede conectar a la BD
}

// 1. Obtener todos los remitentes activos con configuración IMAP
try {
    $stmt = $pdo->query("SELECT * FROM senders WHERE is_active = 1 AND imap_host IS NOT NULL AND imap_host != ''");
    $senders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    log_message("Error al obtener los remitentes: " . $e->getMessage());
    die();
}

if (empty($senders)) {
    log_message("No hay remitentes activos con configuración IMAP para procesar. Finalizando.");
    exit;
}

log_message("Se encontraron " . count($senders) . " remitentes para procesar.");

// 2. Iterar sobre cada remitente
foreach ($senders as $sender) {
    log_message("--- Procesando remitente: {$sender['email']} ---");

    $imap_port = $sender['imap_port'] ?? 993;
    $imap_path = "{{$sender['imap_host']}:{$imap_port}/imap/ssl/novalidate-cert}";
    $processed_mailbox_folder = 'Bounces';

    $inbox = @imap_open($imap_path . 'INBOX', $sender['smtp_username'], $sender['smtp_password'], 0, 1);

    if (!$inbox) {
        log_message("ERROR: No se pudo conectar al buzón de {$sender['email']}. Razón: " . imap_last_error());
        continue; // Saltar al siguiente remitente
    }

    log_message("Conexión IMAP exitosa para {$sender['email']}.");

    if (!mailbox_exists($inbox, $imap_path, $processed_mailbox_folder)) {
        if (@imap_createmailbox($inbox, imap_utf7_encode($imap_path . $processed_mailbox_folder))) {
            log_message("Carpeta '$processed_mailbox_folder' creada exitosamente.");
        } else {
            log_message("ADVERTENCIA: No se pudo crear la carpeta '$processed_mailbox_folder'. Puede que ya exista o sea un problema de permisos.");
        }
    }

    $search_date = date("d-M-Y");
    $search_criteria = "SINCE \"$search_date\"";
    log_message("Buscando correos en INBOX desde el $search_date...");

    $emails = imap_search($inbox, $search_criteria, SE_UID);

    if ($emails) {
        log_message("Se encontraron " . count($emails) . " correos para revisar.");

        $updateStmt = $pdo->prepare(
            "UPDATE campaign_recipients SET status = 'bounced', bounced_at = NOW(), bounce_reason = ? WHERE id = ?"
        );

        foreach ($emails as $uid) {
            $structure = imap_fetchstructure($inbox, $uid, FT_UID);
            $recipient_id = null;
            $bounce_reason = 'Razón no especificada.';

            if (isset($structure->parts) && is_array($structure->parts)) {
                list($recipient_id, $bounce_reason) = parse_mime_parts($inbox, $uid, $structure->parts);
            }

            if ($recipient_id) {
                log_message(" - Rebote detectado para el destinatario ID: $recipient_id");

                try {
                    $updateStmt->execute([$bounce_reason, $recipient_id]);
                    if ($updateStmt->rowCount() > 0) {
                        log_message("   -> Registro actualizado a 'bounced'.");
                    } else {
                        log_message("   -> ADVERTENCIA: El registro con ID $recipient_id no se encontró o ya estaba actualizado.");
                    }
                } catch (PDOException $e) {
                    log_message("   -> ERROR de BD al actualizar el registro ID $recipient_id: " . $e->getMessage());
                }
            } else {
                log_message(" - Correo UID #$uid ignorado (no es un rebote de campaña válido).");
            }

            // Mover el correo procesado
            imap_mail_move($inbox, "$uid", $processed_mailbox_folder, CP_UID);
        }

        imap_expunge($inbox);
    } else {
        log_message("No se encontraron nuevos correos de rebote para este remitente desde $search_date.");
    }

    imap_close($inbox);
}

log_message("Proceso de rebotes finalizado.");
log_message("============================================");


/**
 * Funciones de ayuda
 */
function parse_mime_parts($inbox, $uid, $parts, $parent_part_number = '')
{
    $recipient_id = null;
    $bounce_reason = 'Razón no especificada.';

    foreach ($parts as $index => $part) {
        $current_part_number = ($parent_part_number ? $parent_part_number . '.' : '') . ($index + 1);

        if (isset($part->parts) && is_array($part->parts)) {
            list($sub_id, $sub_reason) = parse_mime_parts($inbox, $uid, $part->parts, $current_part_number);
            if ($sub_id) $recipient_id = $sub_id;
            if ($sub_reason !== 'Razón no especificada.') $bounce_reason = $sub_reason;
        }

        if (isset($part->type) && $part->type == 2 && isset($part->subtype) && $part->subtype == 'DELIVERY-STATUS') {
            $body = imap_fetchbody($inbox, $uid, $current_part_number, FT_UID);
            $reason = extract_bounce_reason($body);
            if ($reason) $bounce_reason = $reason;
        }

        if (isset($part->type) && $part->type == 1 && isset($part->subtype) && $part->subtype == 'RFC822') {
            $body = imap_fetchbody($inbox, $uid, $current_part_number, FT_UID);
            $id = extract_custom_header($body, 'X-Campaign-Recipient-ID');
            if ($id) $recipient_id = $id;
        }
    }
    return [$recipient_id, $bounce_reason];
}

function extract_custom_header($header_text, $header_name)
{
    $pattern = "/^" . preg_quote($header_name, '/') . ":\s*(.*)$/im";
    if (preg_match($pattern, $header_text, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

function extract_bounce_reason($body)
{
    $pattern = '/Diagnostic-Code: (.*?)(?:\r\n\r\n|\r\n[A-Z]|$)/is';
    if (preg_match($pattern, $body, $matches)) {
        return trim(preg_replace('/\s+/', ' ', $matches[1]));
    }

    $pattern = '/Status: (.*)/i';
    if (preg_match($pattern, $body, $matches)) {
        return trim($matches[1]);
    }

    return 'Razón no especificada.';
}

function mailbox_exists($stream, $imap_path, $mailbox)
{
    $mailboxes = imap_list($stream, $imap_path, '*');
    if ($mailboxes === false) return false;
    $encoded_mailbox_name = imap_utf7_encode($imap_path . $mailbox);
    return in_array($encoded_mailbox_name, $mailboxes);
}
