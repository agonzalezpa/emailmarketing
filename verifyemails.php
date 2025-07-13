<?php

// --- CONFIGURACIÓN INICIAL ---
//verifyemails.php
define('YOUR_DOMAIN', 'marketing.dom0125.com');

// --- CREDENCIALES DE BASE DE DATOS ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');

// --- CONFIGURACIÓN DEL CRON ---
define('BATCH_SIZE', 50); // Procesar 50 emails por minuto (ajustable según carga del servidor)
define('MAX_EXECUTION_TIME', 50); // Máximo 50 segundos por ejecución
define('VERIFICATION_TIMEOUT', 10); // Timeout para verificaciones DNS

// Función para crear una nueva conexión PDO
function createPdoConnection()
{
    return new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
}

/**
 * Verifica si un email es válido usando múltiples métodos
 */
function verifyEmail($email)
{
    // 1. Validación sintáctica básica
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'reason' => 'invalid_syntax'];
    }

    // 2. Verificar longitud razonable
    if (strlen($email) > 254) {
        return ['valid' => false, 'reason' => 'too_long'];
    }

    // 3. Verificar si es un correo temporal/desechable
    if (isDisposableEmail($email)) {
        return ['valid' => false, 'reason' => 'disposable_email'];
    }

    // 4. Extraer dominio y verificar
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return ['valid' => false, 'reason' => 'invalid_format'];
    }

    $domain = strtolower($parts[1]);

    // 5. Verificar dominio básico
    if (!isValidDomain($domain)) {
        return ['valid' => false, 'reason' => 'invalid_domain'];
    }

    // 6. Verificar registros MX con timeout
    if (!checkMXRecord($domain)) {
        return ['valid' => false, 'reason' => 'no_mx_record'];
    }

    // 7. Verificaciones adicionales para dominios empresariales
    if (isBusinessDomain($domain)) {
        if (!checkDomainReachability($domain)) {
            return ['valid' => false, 'reason' => 'domain_unreachable'];
        }
    }

    return ['valid' => true, 'reason' => 'verified'];
}

/**
 * Verifica si un correo pertenece a un dominio de correo temporal
 */
function isDisposableEmail($email)
{
    $disposableDomains = [
        // Servicios temporales conocidos
        'mailinator.com',
        'tempmail.com',
        'guerrillamail.com',
        '10minutemail.com',
        'yopmail.com',
        'temp-mail.org',
        'dispostable.com',
        'fakeinbox.com',
        'maildrop.cc',
        'mailnesia.com',
        'tempinbox.com',
        'meltmail.com',
        'throwaway.email',
        'getnada.com',
        'mohmal.com',
        'sharklasers.com',
        'grr.la',
        'guerrillamailblock.com',
        'pokemail.net',
        'spam4.me',
        'tempail.com',
        'tempr.email',
        'minuteinbox.com',
        'emailondeck.com',
        'mytrashmail.com',
        'trashmail.com',
        'temporary-email.com',
        'temp-mails.com',
        'inboxkitten.com',
        'trbvm.com',
        'mailcatch.com',
        'mailhog.example',
        'zetmail.com',
        'adguard.com',
        'spambox.us',
        'emailfake.com',
        'fake-mail.ml',
        'fakemail.net',
        'fakermail.com',
        'tempmailo.com',
        'mailtemp.info',
        'temp-email.com',
        'tempemail.com',
        'tempemails.net',
        'safe-mail.net'
    ];

    $domain = explode('@', $email)[1];
    return in_array(strtolower($domain), $disposableDomains);
}

/**
 * Verifica si un dominio es válido
 */
function isValidDomain($domain)
{
    // Verificar formato básico del dominio
    if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain)) {
        return false;
    }

    // Verificar longitud
    if (strlen($domain) > 253) {
        return false;
    }

    // Verificar cada parte del dominio
    $parts = explode('.', $domain);
    foreach ($parts as $part) {
        if (empty($part) || strlen($part) > 63) {
            return false;
        }
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $part)) {
            return false;
        }
        if (substr($part, 0, 1) === '-' || substr($part, -1) === '-') {
            return false;
        }
    }

    return true;
}

/**
 * Verifica registros MX con timeout
 */
function checkMXRecord($domain)
{
    $originalTimeout = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', VERIFICATION_TIMEOUT);

    try {
        $result = checkdnsrr($domain, 'MX');
        ini_set('default_socket_timeout', $originalTimeout);
        return $result;
    } catch (Exception $e) {
        ini_set('default_socket_timeout', $originalTimeout);
        return false;
    }
}

/**
 * Verifica si es un dominio empresarial (no proveedor de email masivo)
 */
function isBusinessDomain($domain)
{
    $massProviders = [
        'gmail.com',
        'outlook.com',
        'hotmail.com',
        'yahoo.com',
        'icloud.com',
        'aol.com',
        'protonmail.com',
        'mail.com',
        'zoho.com',
        'gmx.com',
        'live.com',
        'msn.com',
        'yahoo.es',
        'yahoo.co.uk',
        'yahoo.fr',
        'outlook.es',
        'hotmail.es',
        'terra.com',
        'narod.ru',
        'mail.ru',
        'yandex.com',
        'qq.com',
        '163.com',
        '126.com',
        'sina.com'
    ];

    return !in_array(strtolower($domain), $massProviders);
}

/**
 * Verifica si el dominio es alcanzable (para dominios empresariales)
 */
function checkDomainReachability($domain)
{
    $originalTimeout = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', VERIFICATION_TIMEOUT);

    try {
        // Verificar si el dominio responde
        $result = @dns_get_record($domain, DNS_A);
        // Si no tiene registros A, verificar con www
        if (empty($result)) {
            $result = @dns_get_record('www.' . $domain, DNS_A);
        }
        ini_set('default_socket_timeout', $originalTimeout);
        return !empty($result);
    } catch (Exception $e) {
        ini_set('default_socket_timeout', $originalTimeout);
        return true; // En caso de error, asumimos que es válido para evitar falsos positivos
    }
}

/**
 * Desactiva un contacto y lo remueve de todas las listas
 */
function deactivateContact($pdo, $contactId, $reason)
{
    try {
        $pdo->beginTransaction();

        // 1. Cambiar estado a inactive y registrar motivo en una sola consulta
        $stmt = $pdo->prepare("
            UPDATE contacts 
            SET status = 'inactive', 
                updated_at = CURRENT_TIMESTAMP,
                custom_fields = JSON_SET(COALESCE(custom_fields, '{}'), '$.verification_failed_reason', ?)
            WHERE id = ?
        ");
        $stmt->execute([$reason, $contactId]);

        // 2. Remover de todas las listas
        $stmt = $pdo->prepare("DELETE FROM contact_list_members WHERE contact_id = ?");
        $stmt->execute([$contactId]);

        // 3. ELIMINAR campaign_recipients pendientes y failed
        $stmt = $pdo->prepare("
            DELETE FROM campaign_recipients 
            WHERE contact_id = ? 
            AND (status = 'pending')
        ");
        $stmt->execute([$contactId]);
        $deletedRecipients = $stmt->rowCount();

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Registra el resultado de verificación
 */
function logVerificationResult($contactId, $email, $result, $reason)
{
    $status = $result ? 'VALID' : 'INVALID';
    $logEntry = "[" . date('Y-m-d H:i:s') . "] Contact ID: $contactId, Email: $email, Status: $status, Reason: $reason\n";
    file_put_contents(__DIR__ . '/logs/email_verification.log', $logEntry, FILE_APPEND);
}

// --- EJECUCIÓN PRINCIPAL DEL CRON ---
try {
    $startTime = time();
    file_put_contents(__DIR__ . '/logs/email_verification.log', "[" . date('Y-m-d H:i:s') . "] Cron de verificación de emails iniciado\n", FILE_APPEND);
    $queryStart = microtime(true);
    $pdo = createPdoConnection();

    //-- Versión optimizada con LEFT JOIN (recomendada para mejor rendimiento)
    //-- Y aprovechando el índice idx_contact_status existente
    $stmt = $pdo->prepare("
    SELECT c.id, c.email, c.name, c.custom_fields,
           CASE WHEN cr.contact_id IS NOT NULL THEN 1 ELSE 0 END as has_pending_campaign
    FROM contacts c
    LEFT JOIN (
        SELECT DISTINCT contact_id 
        FROM campaign_recipients 
        WHERE status = 'pending'
    ) cr ON c.id = cr.contact_id
    WHERE c.status = 'active' 
    AND (
        c.custom_fields IS NULL 
        OR JSON_EXTRACT(c.custom_fields, '$.last_verified') IS NULL 
        OR JSON_EXTRACT(c.custom_fields, '$.last_verified') < DATE_SUB(NOW(), INTERVAL 30 DAY)
    )
    ORDER BY 
        -- Prioridad 1: Contactos en campañas pendientes (usa idx_contact_status)
        has_pending_campaign DESC,
        -- Prioridad 2: Contactos nunca verificados
        CASE 
            WHEN JSON_EXTRACT(c.custom_fields, '$.last_verified') IS NULL THEN 0 
            ELSE 1 
        END,
        -- Prioridad 3: Contactos más antiguos primero
        c.created_at ASC
    LIMIT ?
");
    $stmt->execute([BATCH_SIZE]);
    $contacts = $stmt->fetchAll();
    $queryTime = microtime(true) - $queryStart;
    file_put_contents(
        __DIR__ . '/logs/email_verification.log',
        "[" . date('Y-m-d H:i:s') . "] Consulta ejecutada en: " . round($queryTime, 3) . "s\n",
        FILE_APPEND
    );

    if (empty($contacts)) {
        file_put_contents(__DIR__ . '/logs/email_verification.log', "[" . date('Y-m-d H:i:s') . "] No hay contactos para verificar\n", FILE_APPEND);
        exit;
    }

    $totalProcessed = 0;
    $totalInvalid = 0;
    $totalValid = 0;

    foreach ($contacts as $contact) {
        // Verificar límite de tiempo de ejecución
        if ((time() - $startTime) >= MAX_EXECUTION_TIME) {
            file_put_contents(__DIR__ . '/logs/email_verification.log', "[" . date('Y-m-d H:i:s') . "] Límite de tiempo alcanzado, deteniendo procesamiento\n", FILE_APPEND);
            break;
        }

        try {
            $verificationResult = verifyEmail($contact['email']);

            // Actualizar timestamp de última verificación
            $customFields = json_decode($contact['custom_fields'] ?? '{}', true);
            $customFields['last_verified'] = date('Y-m-d H:i:s');
            $customFields['last_verification_result'] = $verificationResult['reason'];

            if ($verificationResult['valid']) {
                // Email válido - actualizar campos de verificación
                $stmt = $pdo->prepare("UPDATE contacts SET custom_fields = ? WHERE id = ?");
                $stmt->execute([json_encode($customFields), $contact['id']]);
                $totalValid++;

               // logVerificationResult($contact['id'], $contact['email'], true, $verificationResult['reason']);
            } else {
                // Email inválido - desactivar contacto
                $customFields['verification_failed_reason'] = $verificationResult['reason'];
                deactivateContact($pdo, $contact['id'], $verificationResult['reason']);
                $totalInvalid++;

                logVerificationResult($contact['id'], $contact['email'], false, $verificationResult['reason']);

                file_put_contents(
                    __DIR__ . '/logs/email_verification.log',
                    "[" . date('Y-m-d H:i:s') . "] DESACTIVADO - ID: {$contact['id']}, Email: {$contact['email']}, Razón: {$verificationResult['reason']}\n",
                    FILE_APPEND
                );
            }

            $totalProcessed++;

            // Pequeña pausa para no saturar el servidor
            usleep(100000); // 0.1 segundos

        } catch (Exception $e) {
            file_put_contents(
                __DIR__ . '/logs/email_verification.log',
                "[" . date('Y-m-d H:i:s') . "] ERROR procesando contacto {$contact['id']}: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            continue;
        }
    }

    // Estadísticas finales
    $executionTime = time() - $startTime;
    file_put_contents(
        __DIR__ . '/logs/email_verification.log',
        "[" . date('Y-m-d H:i:s') . "] Verificación completada - Procesados: $totalProcessed, Válidos: $totalValid, Inválidos: $totalInvalid, Tiempo: {$executionTime}s\n\n",
        FILE_APPEND
    );

    echo "[OK] Verificación completada: $totalProcessed emails procesados, $totalValid válidos, $totalInvalid inválidos\n";
} catch (Throwable $e) {
    $errorMessage = "ERROR FATAL: " . $e->getMessage() . " en " . $e->getFile() . " línea " . $e->getLine();
    file_put_contents(__DIR__ . '/logs/email_verification.log', "[" . date('Y-m-d H:i:s') . "] $errorMessage\n", FILE_APPEND);
    die($errorMessage);
}
