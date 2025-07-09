<?php
/**
 * process_bounces.php - VERSIÓN FINAL IDEMPOTENTE
 *
 * Este script se conecta a múltiples buzones de correo para procesar rebotes (bounces).
 * Comprueba el estado del destinatario en la base de datos antes de actualizarlo
 * para evitar el reprocesamiento, incluso si el correo no se puede mover.
 *
 * MEJORAS IMPLEMENTADAS:
 * - Idempotencia: Cada rebote se procesa una sola vez gracias a la comprobación de estado.
 * - Manejo de carpetas mejorado para evitar errores de creación.
 * - Búsqueda más exhaustiva de la cabecera X-Campaign-Recipient-ID.
 * - Análisis mejorado de mensajes RFC822 adjuntos.
 * - Logging detallado para depuración.
 */

// --- CONFIGURACIÓN ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');
define('LOG_FILE', __DIR__ . '/process_bounces.log');

// --- FUNCIÓN DE LOGGING ---
function log_message($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] " . $message . "\n", FILE_APPEND);
}

// --- INICIO DEL SCRIPT ---
log_message("============================================");
log_message("Iniciando proceso de rebotes...");

$total_senders_processed = 0;
$total_emails_checked = 0;
$total_bounces_detected = 0;
$total_db_updates = 0;
$total_emails_moved = 0;

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    log_message("Conexión a la base de datos exitosa.");
} catch (PDOException $e) {
    log_message("Error CRÍTICO de conexión a la base de datos: " . $e->getMessage());
    die();
}

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

foreach ($senders as $sender) {
    $total_senders_processed++;
    log_message("--- Procesando remitente: {$sender['email']} ---");

    $imap_port = $sender['imap_port'] ?? 993;
    $imap_path = "{{$sender['imap_host']}:{$imap_port}/imap/ssl/novalidate-cert}";
    $processed_mailbox_folder = 'Bounces';

    $inbox = @imap_open($imap_path . 'INBOX', $sender['smtp_username'], $sender['smtp_password'], 0, 1);

    if (!$inbox) {
        log_message("ERROR: No se pudo conectar al buzón de {$sender['email']}. Razón: " . imap_last_error());
        continue;
    }

    log_message("Conexión IMAP exitosa para {$sender['email']}.");

    if (!mailbox_exists($inbox, $imap_path, $processed_mailbox_folder)) {
        if (!create_bounce_folder($inbox, $imap_path, $processed_mailbox_folder)) {
             log_message("ADVERTENCIA: No se pudo crear la carpeta '$processed_mailbox_folder'.");
        }
    }

    $search_date = date("d-M-Y");
    $search_criteria = "SINCE \"$search_date\"";
    log_message("Buscando correos en INBOX desde el $search_date...");
    
    $emails = imap_search($inbox, $search_criteria, SE_UID);

    if ($emails) {
        $total_emails_checked += count($emails);
        log_message("Se encontraron " . count($emails) . " correos para revisar.");
        
        $checkStmt = $pdo->prepare("SELECT status FROM campaign_recipients WHERE id = ?");
        $updateStmt = $pdo->prepare("UPDATE campaign_recipients SET status = 'bounced', bounced_at = NOW(), bounce_reason = ? WHERE id = ?");

        foreach ($emails as $uid) {
            log_message("Procesando correo UID #$uid...");
            
            $structure = imap_fetchstructure($inbox, $uid, FT_UID);
            $recipient_id = null;
            $bounce_reason = 'Razón no especificada.';

            list($recipient_id, $bounce_reason) = find_bounce_details($inbox, $uid, $structure);

            if ($recipient_id) {
                $total_bounces_detected++;
                log_message("  -> Rebote detectado para el destinatario ID: $recipient_id");
                
                try {
                    $checkStmt->execute([$recipient_id]);
                    $current_status = $checkStmt->fetchColumn();

                    if ($current_status === 'bounced') {
                        log_message("    -> El registro ya está marcado como 'bounced'. Ignorando actualización.");
                    } else if ($current_status) {
                        $updateStmt->execute([$bounce_reason, $recipient_id]);
                        if ($updateStmt->rowCount() > 0) {
                            $total_db_updates++;
                            log_message("    -> Registro actualizado a 'bounced'.");
                        }
                    } else {
                        log_message("    -> ADVERTENCIA: No se encontró el registro con ID $recipient_id en la tabla campaign_recipients.");
                    }
                } catch (PDOException $e) {
                    log_message("    -> ERROR de BD al procesar el registro ID $recipient_id: " . $e->getMessage());
                }
            } else {
                log_message("  -> Correo UID #$uid ignorado (no se encontró X-Campaign-Recipient-ID).");
            }

            if (imap_mail_move($inbox, "$uid", $processed_mailbox_folder, CP_UID)) {
                $total_emails_moved++;
            } else {
                log_message("  -> ADVERTENCIA: No se pudo mover el correo UID #$uid a la carpeta 'Bounces'. Se omitirá en futuras ejecuciones gracias a la comprobación de estado.");
            }
        }
        
        imap_expunge($inbox);
    } else {
        log_message("No se encontraron nuevos correos de rebote para este remitente desde $search_date.");
    }

    imap_close($inbox);
}

log_message("--- RESUMEN DE LA EJECUCIÓN ---");
log_message("Remitentes procesados: " . $total_senders_processed);
log_message("Correos totales revisados: " . $total_emails_checked);
log_message("Rebotes válidos detectados: " . $total_bounces_detected);
log_message("Registros actualizados en la BD: " . $total_db_updates);
log_message("Correos movidos a la carpeta 'Bounces': " . $total_emails_moved);
log_message("Proceso de rebotes finalizado.");
log_message("============================================");


/**
 * FUNCIONES DE AYUDA
 */
function find_bounce_details($inbox, $uid, $structure) {
    if (isset($structure->parts) && is_array($structure->parts)) {
        return parse_mime_parts($inbox, $uid, $structure->parts);
    } else {
        $header_text = imap_fetchheader($inbox, $uid, FT_UID);
        $body = imap_body($inbox, $uid, FT_UID);
        $recipient_id = extract_custom_header($body, 'X-Campaign-Recipient-ID');
        if (!$recipient_id) {
             $recipient_id = extract_custom_header($header_text, 'X-Campaign-Recipient-ID');
        }
        $bounce_reason = extract_bounce_reason($body);
        return [$recipient_id, $bounce_reason];
    }
}

function parse_mime_parts($inbox, $uid, $parts, $parent_part_number = '') {
    $recipient_id = null;
    $bounce_reason = 'Razón no especificada.';

    foreach ($parts as $index => $part) {
        $current_part_number = ($parent_part_number ? $parent_part_number . '.' : '') . ($index + 1);
        
        $body = imap_fetchbody($inbox, $uid, $current_part_number, FT_UID);
        if (isset($part->encoding)) {
            switch ($part->encoding) {
                case 3: $body = base64_decode($body); break;
                case 4: $body = quoted_printable_decode($body); break;
            }
        }

        if (isset($part->parts) && is_array($part->parts)) {
            list($sub_id, $sub_reason) = parse_mime_parts($inbox, $uid, $part->parts, $current_part_number);
            if ($sub_id) $recipient_id = $sub_id;
            if ($sub_reason !== 'Razón no especificada.') $bounce_reason = $sub_reason;
        }

        if (isset($part->type) && $part->type == 2 && isset($part->subtype) && $part->subtype == 'DELIVERY-STATUS') {
            $reason = extract_bounce_reason($body);
            if ($reason) $bounce_reason = $reason;
        }

        if (isset($part->type) && $part->type == 2 && isset($part->subtype) && $part->subtype == 'RFC822') {
            $id = extract_custom_header($body, 'X-Campaign-Recipient-ID');
            if ($id) $recipient_id = $id;
        }
    }
    return [$recipient_id, $bounce_reason];
}

function extract_custom_header($text, $header_name) {
    $patterns = [
        "/^" . preg_quote($header_name, '/') . ":\s*(.*)$/im",
        "/" . preg_quote($header_name, '/') . ":\s*([^\r\n]+)/i"
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $value = trim($matches[1]);
            if (is_numeric($value)) {
                return $value;
            }
        }
    }
    return null;
}

function extract_bounce_reason($body) {
    $patterns = [
        '/Diagnostic-Code:\s*smtp;\s*(.*?)(?:\r\n\r\n|\r\n[A-Z]|$)/is',
        '/Status:\s*([45]\.[0-9]\.[0-9].*)/i'
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $body, $matches)) {
            $reason = trim(preg_replace('/\s+/', ' ', $matches[1]));
            if (!empty($reason)) {
                return $reason;
            }
        }
    }
    return 'Razón no especificada.';
}

function mailbox_exists($stream, $imap_path, $mailbox) {
    $mailboxes = imap_list($stream, $imap_path, '*');
    if ($mailboxes === false) {
        log_message("ERROR: No se pudieron listar las carpetas. " . imap_last_error());
        return false;
    }
    $encoded_mailbox_name = imap_utf7_encode($imap_path . $mailbox);
    return in_array($encoded_mailbox_name, $mailboxes);
}

function create_bounce_folder($inbox, $imap_path, $folder_name) {
    $encoded_path = imap_utf7_encode($imap_path . $folder_name);
    if (@imap_createmailbox($inbox, $encoded_path)) {
        log_message("Carpeta creada exitosamente: $folder_name");
        return true;
    }
    return false;
}

?>
