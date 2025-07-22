<?php
/**
 * process_bounces.php - VERSIÓN CORREGIDA
 * 
 * Modificado para procesar SOLO correos que son rebotes legítimos,
 * no todos los correos en la bandeja de entrada.
 */

// --- CONFIGURACIÓN ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');
define('LOG_FILE', __DIR__ . '/logs/process_bounces.log');

// --- FUNCIÓN DE LOGGING ---
function log_message($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] " . $message . "\n", FILE_APPEND);
} 

// --- FUNCIÓN PARA DETECTAR SI UN CORREO ES REBOTE ---
function is_bounce_email($inbox, $uid) {
    // CRITERIO PRINCIPAL: Si el correo tiene nuestra cabecera X-Campaign-Recipient-ID,
    // entonces es un rebote porque todos nuestros correos enviados la tienen
    
    // 1. Buscar en las cabeceras principales
    $header_text = imap_fetchheader($inbox, $uid, FT_UID);
    $recipient_id = extract_custom_header($header_text, 'X-Campaign-Recipient-ID');
    
    if ($recipient_id) {
        log_message("  -> Rebote detectado: encontrada cabecera X-Campaign-Recipient-ID: $recipient_id");
        return true;
    }
    
    // 2. Buscar en las partes MIME si no se encontró en las cabeceras principales
    $structure = imap_fetchstructure($inbox, $uid, FT_UID);
    if (isset($structure->parts) && is_array($structure->parts)) {
        list($found_id, $dummy) = parse_mime_parts($inbox, $uid, $structure->parts);
        if ($found_id) {
            log_message("  -> Rebote detectado: encontrada cabecera X-Campaign-Recipient-ID en partes MIME: $found_id");
            return true;
        }
    }
    
    // 3. Buscar en todo el contenido del correo como último recurso
    $full_message = imap_fetchbody($inbox, $uid, "", FT_UID);
    $recipient_id = extract_custom_header($full_message, 'X-Campaign-Recipient-ID');
    if ($recipient_id) {
        log_message("  -> Rebote detectado: encontrada cabecera X-Campaign-Recipient-ID en contenido completo: $recipient_id");
        return true;
    }
    
    log_message("  -> NO es rebote: no se encontró cabecera X-Campaign-Recipient-ID");
    return false;
}

// --- FUNCIONES MEJORADAS PARA MANEJO DE CARPETAS ---
function mailbox_exists($stream, $imap_path, $mailbox) {
    $mailboxes = imap_list($stream, $imap_path, '*');
    if ($mailboxes === false) {
        log_message("ERROR: No se pudieron listar las carpetas. " . imap_last_error());
        return false;
    }
    
    // Verificar con diferentes variantes del nombre
    $possible_names = [
        $imap_path . $mailbox,
        imap_utf7_encode($imap_path . $mailbox),
        $imap_path . 'INBOX.' . $mailbox,
        $imap_path . 'INBOX/' . $mailbox,
        $imap_path . 'Folders.' . $mailbox,
        $imap_path . 'Folders/' . $mailbox
    ];
    
    foreach ($possible_names as $name) {
        if (in_array($name, $mailboxes)) {
            log_message("Carpeta encontrada con nombre: $name");
            return $name;
        }
    }
    
    log_message("Carpeta '$mailbox' no encontrada en: " . implode(', ', array_slice($mailboxes, 0, 10)));
    return false;
}

function create_bounce_folder($inbox, $imap_path, $folder_name) {
    $possible_paths = [
        $imap_path . $folder_name,
        $imap_path . 'INBOX.' . $folder_name,
        $imap_path . 'INBOX/' . $folder_name,
        $imap_path . 'Folders.' . $folder_name,
        $imap_path . 'Folders/' . $folder_name
    ];
    
    foreach ($possible_paths as $path) {
        $encoded_path = imap_utf7_encode($path);
        if (@imap_createmailbox($inbox, $encoded_path)) {
            log_message("Carpeta creada exitosamente: $path");
            return $path;
        }
    }
    
    return false;
}

function move_email_to_folder($inbox, $uid, $folder_name) {
    $possible_folders = [
        $folder_name,
        'INBOX.' . $folder_name,
        'INBOX/' . $folder_name,
        'Folders.' . $folder_name,
        'Folders/' . $folder_name,
        'Bounces',
        'INBOX.Bounces',
        'INBOX/Bounces'
    ];
    
    foreach ($possible_folders as $folder) {
        imap_errors();
        
        if (@imap_mail_move($inbox, "$uid", $folder, CP_UID)) {
            log_message("Correo UID #$uid movido exitosamente a: $folder");
            return true;
        }
    }
    
    return false;
}

// --- FUNCIONES ORIGINALES MEJORADAS ---
function parse_mime_parts($inbox, $uid, $parts, $parent_part_number = '') {
    $recipient_id = null;
    $bounce_reason = 'Razón no especificada.';

    foreach ($parts as $index => $part) {
        $current_part_number = ($parent_part_number ? $parent_part_number . '.' : '') . ($index + 1);
        
        $body = imap_fetchbody($inbox, $uid, $current_part_number, FT_UID);
        
        if (isset($part->encoding)) {
            switch ($part->encoding) {
                case 3: // BASE64
                    $body = base64_decode($body);
                    break;
                case 4: // QUOTED-PRINTABLE
                    $body = quoted_printable_decode($body);
                    break;
            }
        }

        if (isset($part->parts) && is_array($part->parts)) {
            list($sub_id, $sub_reason) = parse_mime_parts($inbox, $uid, $part->parts, $current_part_number);
            if ($sub_id) {
                $recipient_id = $sub_id;
            }
            if ($sub_reason !== 'Razón no especificada.') {
                $bounce_reason = $sub_reason;
            }
        }

        $found_id = extract_custom_header($body, 'X-Campaign-Recipient-ID');
        if ($found_id) {
            $recipient_id = $found_id;
        }

        if (isset($part->type) && $part->type == 2 && isset($part->subtype) && $part->subtype == 'DELIVERY-STATUS') {
            $reason = extract_bounce_reason($body);
            if ($reason !== 'Razón no especificada.') {
                $bounce_reason = $reason;
            }
        }

        if (isset($part->type) && $part->type == 2 && isset($part->subtype) && $part->subtype == 'RFC822') {
            $id = extract_custom_header($body, 'X-Campaign-Recipient-ID');
            if ($id) {
                $recipient_id = $id;
            }
        }

        if (isset($part->type) && $part->type == 0 && isset($part->subtype) && $part->subtype == 'PLAIN') {
            $id = extract_custom_header($body, 'X-Campaign-Recipient-ID');
            if ($id) {
                $recipient_id = $id;
            }
        }
    }
    
    return [$recipient_id, $bounce_reason];
}

function extract_custom_header($text, $header_name) {
    $patterns = [
        "/^" . preg_quote($header_name, '/') . ":\s*(.*)$/im",
        "/" . preg_quote($header_name, '/') . ":\s*([^\r\n]+)/i",
        "/\b" . preg_quote($header_name, '/') . ":\s*([^\r\n\s]+)/i"
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
        '/Diagnostic-Code:\s*(.*?)(?:\r\n\r\n|\r\n[A-Z]|$)/is',
        '/Status:\s*([45]\.[0-9]\.[0-9].*)/i',
        '/Action:\s*failed.*?Status:\s*([45]\.[0-9]\.[0-9].*)/is',
        '/550[- ](.+)/i',
        '/5[0-9][0-9][- ](.+)/i'
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

// --- INICIO DEL SCRIPT ---
log_message("============================================");
log_message("Iniciando proceso de rebotes (VERSIÓN CORREGIDA)...");

$total_senders_processed = 0;
$total_emails_checked = 0;
$total_bounces_detected = 0;
$total_db_updates = 0;
$total_emails_moved = 0;

// Conexión a la base de datos
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    log_message("Conexión a la base de datos exitosa.");
} catch (PDOException $e) {
    log_message("Error CRÍTICO de conexión a la base de datos: " . $e->getMessage());
    die();
}

// Obtener remitentes activos
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

// Procesar cada remitente
foreach ($senders as $sender) {
    $total_senders_processed++;
    log_message("--- Procesando remitente: {$sender['email']} ---");

    $imap_port = $sender['imap_port'] ?? 993;
    $imap_path = "{{$sender['imap_host']}:{$imap_port}/imap/ssl/novalidate-cert}";
    $processed_mailbox_folder = 'Bounces';

    imap_errors();
    
    $inbox = @imap_open($imap_path . 'INBOX', $sender['smtp_username'], $sender['smtp_password'], 0, 1);

    if (!$inbox) {
        log_message("ERROR: No se pudo conectar al buzón de {$sender['email']}. Razón: " . imap_last_error());
        continue;
    }

    log_message("Conexión IMAP exitosa para {$sender['email']}.");

    // Verificar carpeta Bounces
    $bounce_folder = mailbox_exists($inbox, $imap_path, $processed_mailbox_folder);
    
    if (!$bounce_folder) {
        log_message("Carpeta 'Bounces' no existe. Intentando crear...");
        $bounce_folder = create_bounce_folder($inbox, $imap_path, $processed_mailbox_folder);
        
        if (!$bounce_folder) {
            log_message("ADVERTENCIA: No se pudo crear la carpeta 'Bounces'. Los correos se marcarán como procesados.");
            $bounce_folder = false;
        }
    }

    // MODIFICACIÓN PRINCIPAL: Buscar todos los correos recientes y filtrar por cabecera
    $search_date = date("d-M-Y", strtotime('-3 days')); // Últimos 3 días
    $search_criteria = "SINCE \"$search_date\"";
    
    log_message("Buscando correos en INBOX desde $search_date para verificar rebotes...");
    $all_emails = imap_search($inbox, $search_criteria, SE_UID);

    if ($all_emails) {
        $total_emails_checked += count($all_emails);
        log_message("Se encontraron " . count($all_emails) . " correos para revisar desde $search_date.");
        
        $updateStmt = $pdo->prepare(
            "UPDATE campaign_recipients SET status = 'bounced', bounced_at = NOW(), bounce_reason = ? WHERE id = ?"
        );

        foreach ($all_emails as $uid) {
            log_message("Revisando correo UID #$uid...");
            
            // FILTRO PRINCIPAL: Verificar si es realmente un rebote
            if (!is_bounce_email($inbox, $uid)) {
                log_message("  -> Correo UID #$uid NO es un rebote. Ignorando.");
                continue;
            }
            
            log_message("  -> Confirmado como rebote. Procesando...");
            
            // Procesar el rebote (código original)
            $structure = imap_fetchstructure($inbox, $uid, FT_UID);
            $recipient_id = null;
            $bounce_reason = 'Razón no especificada.';

            $header_text = imap_fetchheader($inbox, $uid, FT_UID);
            $recipient_id = extract_custom_header($header_text, 'X-Campaign-Recipient-ID');
            
            if ($recipient_id) {
                log_message("  -> ID encontrado en cabeceras principales: $recipient_id");
                $body = imap_body($inbox, $uid, FT_UID);
                $bounce_reason = extract_bounce_reason($body);
            } else {
                if (isset($structure->parts) && is_array($structure->parts)) {
                    list($recipient_id, $bounce_reason) = parse_mime_parts($inbox, $uid, $structure->parts);
                }
                
                if (!$recipient_id) {
                    $full_message = imap_fetchbody($inbox, $uid, "", FT_UID);
                    $recipient_id = extract_custom_header($full_message, 'X-Campaign-Recipient-ID');
                    if ($recipient_id) {
                        $bounce_reason = extract_bounce_reason($full_message);
                    }
                }
            }

            if ($recipient_id) {
                $total_bounces_detected++;
                log_message("  -> Rebote procesado para destinatario ID: $recipient_id");
                log_message("  -> Razón: $bounce_reason");
                
                try {
                    $updateStmt->execute([$bounce_reason, $recipient_id]);
                    if ($updateStmt->rowCount() > 0) {
                        $total_db_updates++;
                        log_message("     -> Registro actualizado a 'bounced'.");
                    }
                } catch (PDOException $e) {
                    log_message("     -> ERROR de BD: " . $e->getMessage());
                }
            } else {
                log_message("  -> Rebote sin ID de campaña válido. No se actualiza BD.");
            }

            // Mover correo procesado
            if ($bounce_folder && move_email_to_folder($inbox, $uid, $processed_mailbox_folder)) {
                $total_emails_moved++;
            } else {
                if (@imap_setflag_full($inbox, $uid, "\\Seen \\Flagged", ST_UID)) {
                    $total_emails_moved++;
                }
            }
        }
        
        if ($total_emails_moved > 0) {
            imap_expunge($inbox);
        }
    } else {
        log_message("No se encontraron correos para procesar desde $search_date.");
    }

    imap_close($inbox);
}

// --- RESUMEN FINAL ---
log_message("--- RESUMEN DE LA EJECUCIÓN ---");
log_message("Remitentes procesados: " . $total_senders_processed);
log_message("Correos totales revisados: " . $total_emails_checked);
log_message("Rebotes válidos detectados: " . $total_bounces_detected);
log_message("Registros actualizados en la BD: " . $total_db_updates);
log_message("Correos procesados (movidos/marcados): " . $total_emails_moved);
log_message("Proceso finalizado correctamente.");
log_message("============================================");

?>