<?php
/**
 * process_bounces.php - VERSIÓN MEJORADA COMPLETA
 *
 * Este script se conecta a múltiples buzones de correo para procesar rebotes (bounces).
 * Es "MIME-aware" y registra cada paso y un resumen final en un archivo de log.
 *
 * MEJORAS IMPLEMENTADAS:
 * - Búsqueda más exhaustiva de la cabecera X-Campaign-Recipient-ID
 * - Mejor manejo de las partes MIME anidadas
 * - Análisis mejorado de mensajes RFC822 adjuntos
 * - Logging más detallado para debugging
 * - Manejo mejorado de carpetas IMAP con múltiples intentos
 * - Funciones de diagnóstico para troubleshooting
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
        $imap_path . 'Folders/' . $folder_name,
        $imap_path . 'Entrada.' . $folder_name,
        $imap_path . 'Entrada/' . $folder_name,
        $imap_path . 'Recibidos.' . $folder_name,
        $imap_path . 'Recibidos/' . $folder_name
    ];
    
    foreach ($possible_paths as $path) {
        $encoded_path = imap_utf7_encode($path);
        if (@imap_createmailbox($inbox, $encoded_path)) {
            log_message("Carpeta creada exitosamente: $path");
            return $path;
        } else {
            log_message("Falló crear carpeta con path: $path - Error: " . imap_last_error());
        }
    }
    
    return false;
}

function move_email_to_folder($inbox, $uid, $folder_name) {
    // Intentar mover con diferentes nombres de carpeta
    $possible_folders = [
        $folder_name,
        'INBOX.' . $folder_name,
        'INBOX/' . $folder_name,
        'Folders.' . $folder_name,
        'Folders/' . $folder_name,
        'Entrada' . $folder_name,
        'Entrada/' . $folder_name,
        'Recibidos/' . $folder_name,
        'Bounces',
        'INBOX.Bounces',
        'INBOX/Bounces',
        'Folders.Bounces',
        'Folders/Bounces',
        'Entrada.Bounces',
        'Entrada/Bounces',
        'Recibidos.Bounces',
        'Bounces/Bounces'
    ];
    
    foreach ($possible_folders as $folder) {
        // Limpiar errores anteriores
        imap_errors();
        
        if (@imap_mail_move($inbox, "$uid", $folder, CP_UID)) {
            log_message("Correo UID #$uid movido exitosamente a: $folder");
            return true;
        } else {
            $error = imap_last_error();
            log_message("Falló mover correo UID #$uid a '$folder': $error");
        }
    }
    
    return false;
}

function debug_imap_folders($inbox, $imap_path) {
    log_message("=== DEBUG: Listando todas las carpetas disponibles ===");
    $folders = imap_list($inbox, $imap_path, '*');
    if ($folders) {
        foreach ($folders as $folder) {
            log_message("Carpeta encontrada: $folder");
        }
    } else {
        log_message("No se pudieron listar las carpetas: " . imap_last_error());
    }
    log_message("=== FIN DEBUG ===");
}

function test_folder_creation($inbox, $imap_path) {
    $test_folder = 'TestBounces';
    $full_path = $imap_path . $test_folder;
    
    if (@imap_createmailbox($inbox, imap_utf7_encode($full_path))) {
        log_message("TEST OK: Carpeta de prueba creada exitosamente");
        // Eliminar carpeta de prueba
        @imap_deletemailbox($inbox, imap_utf7_encode($full_path));
        return true;
    } else {
        log_message("TEST FAIL: No se pudo crear carpeta de prueba: " . imap_last_error());
        return false;
    }
}

// --- FUNCIONES ORIGINALES MEJORADAS ---
function parse_mime_parts($inbox, $uid, $parts, $parent_part_number = '') {
    $recipient_id = null;
    $bounce_reason = 'Razón no especificada.';

    foreach ($parts as $index => $part) {
        $current_part_number = ($parent_part_number ? $parent_part_number . '.' : '') . ($index + 1);
        
        // Obtener el contenido de esta parte
        $body = imap_fetchbody($inbox, $uid, $current_part_number, FT_UID);
        
        // Decodificar según el tipo de encoding
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

        // Verificar si esta parte contiene partes anidadas
        if (isset($part->parts) && is_array($part->parts)) {
            list($sub_id, $sub_reason) = parse_mime_parts($inbox, $uid, $part->parts, $current_part_number);
            if ($sub_id) {
                $recipient_id = $sub_id;
            }
            if ($sub_reason !== 'Razón no especificada.') {
                $bounce_reason = $sub_reason;
            }
        }

        // Buscar la cabecera X-Campaign-Recipient-ID en el contenido de esta parte
        $found_id = extract_custom_header($body, 'X-Campaign-Recipient-ID');
        if ($found_id) {
            $recipient_id = $found_id;
        }

        // Si es una parte de tipo message/delivery-status, extraer la razón del rebote
        if (isset($part->type) && $part->type == 2 && isset($part->subtype) && $part->subtype == 'DELIVERY-STATUS') {
            $reason = extract_bounce_reason($body);
            if ($reason !== 'Razón no especificada.') {
                $bounce_reason = $reason;
            }
        }

        // Si es una parte de tipo message/rfc822, buscar en las cabeceras
        if (isset($part->type) && $part->type == 2 && isset($part->subtype) && $part->subtype == 'RFC822') {
            $id = extract_custom_header($body, 'X-Campaign-Recipient-ID');
            if ($id) {
                $recipient_id = $id;
            }
        }

        // También buscar en partes de texto plano que puedan contener cabeceras
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
    // Patrón más flexible para encontrar la cabecera
    $patterns = [
        "/^" . preg_quote($header_name, '/') . ":\s*(.*)$/im",
        "/" . preg_quote($header_name, '/') . ":\s*([^\r\n]+)/i",
        "/\b" . preg_quote($header_name, '/') . ":\s*([^\r\n\s]+)/i"
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $value = trim($matches[1]);
            // Verificar que el valor sea numérico (ID válido)
            if (is_numeric($value)) {
                return $value;
            }
        }
    }
    
    return null;
}

function extract_bounce_reason($body) {
    // Patrón mejorado para capturar mensajes multilínea
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
log_message("Iniciando proceso de rebotes...");

// Inicializar contadores para el resumen final
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
    $total_senders_processed++;
    log_message("--- Procesando remitente: {$sender['email']} ---");

    $imap_port = $sender['imap_port'] ?? 993;
    $imap_path = "{{$sender['imap_host']}:{$imap_port}/imap/ssl/novalidate-cert}";
    $processed_mailbox_folder = 'Bounces';

    // Limpiar errores anteriores
    imap_errors();
    
    $inbox = @imap_open($imap_path . 'INBOX', $sender['smtp_username'], $sender['smtp_password'], 0, 1);

    if (!$inbox) {
        log_message("ERROR: No se pudo conectar al buzón de {$sender['email']}. Razón: " . imap_last_error());
        continue;
    }

    log_message("Conexión IMAP exitosa para {$sender['email']}.");

    // DEBUG: Mostrar carpetas disponibles (comentar después de troubleshooting)
    // debug_imap_folders($inbox, $imap_path);
    // test_folder_creation($inbox, $imap_path);

    // Verificar si existe la carpeta Bounces
    $bounce_folder = mailbox_exists($inbox, $imap_path, $processed_mailbox_folder);
    
    if (!$bounce_folder) {
        log_message("Carpeta 'Bounces' no existe. Intentando crear...");
        $bounce_folder = create_bounce_folder($inbox, $imap_path, $processed_mailbox_folder);
        
        if (!$bounce_folder) {
            log_message("ADVERTENCIA: No se pudo crear la carpeta 'Bounces'. Los correos se marcarán como procesados.");
            $bounce_folder = false;
        }
    } else {
        log_message("Carpeta 'Bounces' encontrada: $bounce_folder");
    }

    $search_date = date("d-M-Y");
    $search_criteria = "SINCE \"$search_date\"";
    log_message("Buscando correos en INBOX desde el $search_date...");
    
    $emails = imap_search($inbox, $search_criteria, SE_UID);

    if ($emails) {
        $total_emails_checked += count($emails);
        log_message("Se encontraron " . count($emails) . " correos para revisar.");
        
        $updateStmt = $pdo->prepare(
            "UPDATE campaign_recipients SET status = 'bounced', bounced_at = NOW(), bounce_reason = ? WHERE id = ?"
        );

        foreach ($emails as $uid) {
            log_message("Procesando correo UID #$uid...");
            
            // Obtener la estructura MIME del correo
            $structure = imap_fetchstructure($inbox, $uid, FT_UID);
            $recipient_id = null;
            $bounce_reason = 'Razón no especificada.';

            // MÉTODO 1: Buscar en las cabeceras del correo principal
            $header_text = imap_fetchheader($inbox, $uid, FT_UID);
            $recipient_id = extract_custom_header($header_text, 'X-Campaign-Recipient-ID');
            
            if ($recipient_id) {
                log_message("  -> ID encontrado en cabeceras principales: $recipient_id");
                $body = imap_body($inbox, $uid, FT_UID);
                $bounce_reason = extract_bounce_reason($body);
            } else {
                log_message("  -> ID no encontrado en cabeceras principales. Analizando estructura MIME...");
                
                // MÉTODO 2: Analizar partes MIME
                if (isset($structure->parts) && is_array($structure->parts)) {
                    list($recipient_id, $bounce_reason) = parse_mime_parts($inbox, $uid, $structure->parts);
                }
                
                // MÉTODO 3: Fallback - buscar en todo el contenido del correo
                if (!$recipient_id) {
                    log_message("  -> ID no encontrado en partes MIME. Buscando en todo el contenido...");
                    $full_message = imap_fetchbody($inbox, $uid, "", FT_UID);
                    $recipient_id = extract_custom_header($full_message, 'X-Campaign-Recipient-ID');
                    if ($recipient_id) {
                        log_message("  -> ID encontrado en contenido completo: $recipient_id");
                        $bounce_reason = extract_bounce_reason($full_message);
                    }
                }
            }

            if ($recipient_id) {
                $total_bounces_detected++;
                log_message("  -> Rebote detectado para el destinatario ID: $recipient_id");
                log_message("  -> Razón del rebote: $bounce_reason");
                
                try {
                    $updateStmt->execute([$bounce_reason, $recipient_id]);
                    if ($updateStmt->rowCount() > 0) {
                        $total_db_updates++;
                        log_message("     -> Registro actualizado a 'bounced'.");
                    } else {
                        log_message("     -> ADVERTENCIA: El registro con ID $recipient_id no se encontró o ya estaba actualizado.");
                    }
                } catch (PDOException $e) {
                    log_message("     -> ERROR de BD al actualizar el registro ID $recipient_id: " . $e->getMessage());
                }
            } else {
                log_message("  -> Correo UID #$uid ignorado (no se encontró X-Campaign-Recipient-ID).");
            }

            // Intentar mover el correo a la carpeta de rebotes
            if ($bounce_folder && move_email_to_folder($inbox, $uid, $processed_mailbox_folder)) {
                $total_emails_moved++;
            } else {
                log_message("  -> No se pudo mover el correo. Intentando marcarlo como procesado...");
                
                // Alternativa: marcar como leído y procesado
                if (@imap_setflag_full($inbox, $uid, "\\Seen \\Flagged", ST_UID)) {
                    log_message("  -> Correo UID #$uid marcado como procesado (leído + marcado).");
                    $total_emails_moved++;
                } else {
                    log_message("  -> ERROR: No se pudo procesar el correo UID #$uid de ninguna manera.");
                }
            }
        }
        
        // Expunge solo si se procesaron correos
        if ($total_emails_moved > 0) {
            imap_expunge($inbox);
            log_message("Expunge ejecutado - cambios aplicados permanentemente.");
        }
    } else {
        log_message("No se encontraron nuevos correos para procesar desde $search_date.");
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
log_message("Proceso de rebotes finalizado.");
log_message("============================================");

?>