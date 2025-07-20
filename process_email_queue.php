<?php

// --- CONFIGURACIÓN INICIAL ---
//date_default_timezone_set('America/Havana');
define('YOUR_DOMAIN', 'marketing.dom0125.com');

// --- CARGA DE DEPENDENCIAS ---
require_once 'vendor/autoload.php';
require_once 'WebsiteChecker.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use WebsiteChecker;

// --- CREDENCIALES DE BASE DE DATOS (centralizadas para reutilización) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');

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
 * Envía un correo electrónico usando PHPMailer con configuración SMTP.
 */
function sendEmail($sender, $toEmail, $toName, $subject, $htmlContent, $id_recipient, $attachmentPath = null)
{
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $sender['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $sender['smtp_username'];
        $mail->Password = $sender['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        // $mail->addEmbeddedImage(__DIR__ . '/uploads/header.jpg', 'header_cid');
        // $mail->addEmbeddedImage(__DIR__ . '/uploads/about.png', 'about_cid');

        $mail->CharSet = 'UTF-8';
        $mail->addCustomHeader('X-Campaign-Recipient-ID', $id_recipient);
        $mail->setFrom($sender['email'], $sender['name']);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo($sender['email'], $sender['name']);
        $mail->isHTML(true);
        $mail->Subject = $subject;

        $mail->Body = $htmlContent;
        $mail->AltBody = strip_tags($htmlContent);

        if ($attachmentPath && file_exists($attachmentPath)) {
            $mail->addAttachment($attachmentPath, basename($attachmentPath));
        }

        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        error_log("Error al enviar correo: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Función para reemplazar variables con manejo de variables inexistentes
function replaceVariables($content, $variables)
{
    // Primero reemplazar las variables que existen
    $content = str_replace(array_keys($variables), array_values($variables), $content);

    // Luego eliminar/reemplazar variables que no existen (formato {{variable}})
    $content = preg_replace('/\{\{[^}]+\}\}/', '', $content);

    return $content;
}

/**
 * Procesa una plantilla con lógica condicional y reemplaza variables.
 * Soporta: {{variable}}, [SI variable EXISTE], [SI variable NO EXISTE], [SI variable=valor], [SI SEXO=FEMENINO/MASCULINO]
 * Es recursiva para manejar bloques anidados.
 *
 * @param string $content El contenido de la plantilla con la lógica.
 * @param array $variables Un array asociativo con todas las variables disponibles (ej: '{{name}}' => 'Juan').
 * @return string El contenido procesado.
 */
function parseDynamicTemplate($content, $variables)
{
    // --- ETAPA 1: PROCESAR LA LÓGICA CONDICIONAL ---
    $maxIterations = 10;
    $iteration = 0;

    while (preg_match('/\[SI\s+[^]]+\]/', $content) && $iteration < $maxIterations) {
        $content = processConditionalBlocks($content, $variables);
        $iteration++;
    }

    // --- ETAPA 2: PROCESAR GÉNERO ---
    $genderKey = '{{sexo}}';
    $gender = isset($variables[$genderKey]) ? strtolower(trim($variables[$genderKey])) : 'masculino';

    $content = preg_replace_callback('/\[GENDER:([^|]+)\|([^]]+)\]/', function ($matches) use ($gender) {
        $masculine = $matches[1];
        $feminine = $matches[2];
        return ($gender == 'femenino') ? $feminine : $masculine;
    }, $content);

    // --- ETAPA 3: REEMPLAZAR VARIABLES SIMPLES ---
    $content = str_replace(array_keys($variables), array_values($variables), $content);

    // --- ETAPA 4: LIMPIEZA FINAL ---
    $content = preg_replace('/\{\{[^}]+\}\}/', '', $content);
    $content = preg_replace('/\s+/', ' ', $content);
    $content = trim($content);

    return $content;
}

function processConditionalBlocks($content, $variables)
{
    // Patrón mejorado para manejar estructura [SINO] [SI...] [FIN SI] [FIN SI]
    $pattern = '/\[SI\s+(.*?)\s*\](.*?)(?:\[SINO\]\s*(.*?))?\s*\[FIN\s+SI\]/s';

    return preg_replace_callback($pattern, function ($matches) use ($variables) {
        $condition = trim($matches[1]);
        $ifContent = $matches[2];
        $elseContent = isset($matches[3]) ? $matches[3] : '';

        $isConditionMet = evaluateCondition($condition, $variables);

        if ($isConditionMet) {
            return $ifContent;
        } else {
            // Procesar el contenido del ELSE, que puede contener más condiciones
            return processNestedElseContent($elseContent, $variables);
        }
    }, $content);
}

function processNestedElseContent($elseContent, $variables)
{
    // Si el contenido del ELSE tiene condiciones anidadas, procesarlas
    if (preg_match('/\[SI\s+[^]]+\]/', $elseContent)) {
        return processConditionalBlocks($elseContent, $variables);
    }
    return $elseContent;
}

function evaluateCondition($condition, $variables)
{
    $parts = explode(' ', $condition, 3);
    $key = '{{' . $parts[0] . '}}';
    $operator = isset($parts[1]) ? strtoupper($parts[1]) : 'EXISTE';
    $value = isset($parts[2]) ? $parts[2] : null;

    switch ($operator) {
        case 'EXISTE':
            return isset($variables[$key]) && !empty(trim($variables[$key]));
        case 'NO':
            if (isset($parts[2]) && strtoupper($parts[2]) === 'EXISTE') {
                return !isset($variables[$key]) || empty(trim($variables[$key]));
            }
            break;
        case '=':
        case '==':
            return isset($variables[$key]) && strtolower(trim($variables[$key])) == strtolower($value);
        case '!=':
            return !isset($variables[$key]) || strtolower(trim($variables[$key])) != strtolower($value);
        case 'CONTIENE':
            return isset($variables[$key]) && stripos($variables[$key], $value) !== false;
    }

    return false;
}

function verificarSitioWeb(string $url): bool
{
    // 1. Validar el formato de la URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $curl = curl_init($url);

    // 2. Configurar opciones de cURL para máxima compatibilidad y eficiencia
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,      // No imprimir la respuesta, devolverla como string
        CURLOPT_NOBODY         => true,      // Pedir solo las cabeceras, no el cuerpo (más rápido)
        CURLOPT_FOLLOWLOCATION => true,      // Seguir cualquier redirección (ej. http a https)
        CURLOPT_CONNECTTIMEOUT => 10,        // Tiempo de espera para la conexión en segundos
        CURLOPT_TIMEOUT        => 15,        // Tiempo total de la operación en segundos
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.127 Safari/537.36', // Simular un navegador
        CURLOPT_SSL_VERIFYPEER => true,       // Verificar el certificado SSL del sitio
        CURLOPT_SSL_VERIFYHOST => 2,         // Verificar que el nombre del certificado coincida con el host
    ]);

    // Ejecutar la petición
    curl_exec($curl);

    // 3. Verificar si hubo un error a nivel de cURL (ej. no se puede resolver el dominio)
    if (curl_errno($curl)) {
        curl_close($curl);
        return false;
    }

    // 4. Obtener el código de estado HTTP de la respuesta
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    // 5. Un sitio se considera "existente" si responde con un código 2xx (Éxito) o 3xx (Redirección)
    return ($http_code >= 200 && $http_code < 400);
}

// --- EJECUCIÓN PRINCIPAL DEL CRON ---
try {
    file_put_contents(__DIR__ . '/logs/email_cron.log', "[" . date('Y-m-d H:i:s') . "] Cron ejecutandose\n", FILE_APPEND);

    $pdo = createPdoConnection();

    // --- OBTENER TODAS LAS CAMPAÑAS ACTIVAS (sin agrupar por sender) ---
    $campaignsStmt = $pdo->query("
        SELECT id as campaign_id, sender_id, daily_limit 
        FROM campaigns 
        WHERE status = 'sending'
        ORDER BY id ASC
    ");
    $campaigns = $campaignsStmt->fetchAll();
    $pdo = null;

    if (empty($campaigns)) {
        file_put_contents(__DIR__ . '/logs/email_cron.log', "No hay campañas activas para procesar.\n\n", FILE_APPEND);
        exit;
    }

    $secondsInDay = 86400;
    $secondsElapsed = time() - strtotime('today midnight');

    // Cache para evitar consultas repetidas de senders
    $sendersCache = [];

    foreach ($campaigns as $camp) {
        $pdo = createPdoConnection();
        $totalProcesados = 0;
        $campaignId = $camp['campaign_id'];
        $senderId = $camp['sender_id'];
        $dailyLimit = $camp['daily_limit']; // Ahora se obtiene de la BD

        // 1. Correos enviados hoy por esta campaña específica
        $currentDay = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND DATE(sent_at) = ? AND status = 'sent'");
        $stmt->execute([$campaignId, $currentDay]);
        $sentToday = (int)$stmt->fetchColumn();

        // 2. Calcula el ritmo ideal y lote para esta campaña
        $idealSentCount = floor(($secondsElapsed / $secondsInDay) * $dailyLimit);
        $batchLimit = $idealSentCount - $sentToday;
        $remainingForDay = $dailyLimit - $sentToday;
        $batchLimit = min($batchLimit, $remainingForDay);

        file_put_contents(__DIR__ . '/logs/email_cron.log', "Campaña $campaignId (sender $senderId): enviados hoy $sentToday, ideal hasta ahora $idealSentCount, lote $batchLimit, límite diario $dailyLimit\n", FILE_APPEND);

        if ($batchLimit <= 0) {
            file_put_contents(__DIR__ . '/logs/email_cron.log', "Campaña $campaignId tiene ritmo correcto. Saltando.\n", FILE_APPEND);
            continue;
        }
        if ($batchLimit > 100) {
            $batchLimit = 100; // Limitar a 100 envíos por lote para evitar sobrecarga
        }

        // Obtener el sender (con cache para evitar consultas repetidas)
        if (!isset($sendersCache[$senderId])) {
            $senderStmt = $pdo->prepare("SELECT * FROM senders WHERE id = ?");
            $senderStmt->execute([$senderId]);
            $sendersCache[$senderId] = $senderStmt->fetch();
        }
        $sender = $sendersCache[$senderId];

        if (!$sender) {
            file_put_contents(__DIR__ . '/logs/email_cron.log', "Remitente con ID $senderId no encontrado para campaña $campaignId.\n", FILE_APPEND);
            continue;
        }



        // 3. Obtén los destinatarios pendientes priorizando contactos verificados
        /* $stmt = $pdo->prepare("
    SELECT cr.*, c.email, c.name, c.custom_fields, cmp.subject, cmp.html_content, cmp.sender_id, cmp.file_attached
    FROM campaign_recipients cr
    JOIN contacts c ON cr.contact_id = c.id
    JOIN campaigns cmp ON cr.campaign_id = cmp.id
    WHERE cr.campaign_id = ?
      AND (cr.status = 'pending' OR (cr.status = 'failed' AND cr.retry_count < 3))
      AND c.status = 'active'
    ORDER BY 
        -- Priorizar contactos verificados recientemente
        CASE 
            WHEN JSON_EXTRACT(c.custom_fields, '$.last_verification_result') = 'verified' THEN 0
            WHEN JSON_EXTRACT(c.custom_fields, '$.last_verified') IS NOT NULL THEN 1
            ELSE 2
        END,
        cr.id ASC
    LIMIT ?
");*/

        $stmt = $pdo->prepare("
        SELECT cr.*, c.email, c.name, c.custom_fields, cmp.subject, cmp.html_content, cmp.sender_id, cmp.file_attached
        FROM campaign_recipients cr
        JOIN contacts c ON cr.contact_id = c.id
        JOIN campaigns cmp ON cr.campaign_id = cmp.id
        WHERE cr.campaign_id = ?
          AND (cr.status = 'pending' OR (cr.status = 'failed' AND cr.retry_count < 3))
          AND c.status = 'active'
        ORDER BY cr.id ASC
        LIMIT ?
    ");
        $stmt->execute([$campaignId, $batchLimit]);
        $recipients = $stmt->fetchAll();

        file_put_contents(__DIR__ . '/logs/email_cron.log', "Campaña $campaignId: Se encontraron " . count($recipients) . " destinatarios para procesar.\n", FILE_APPEND);

        // Cierra la conexión principal antes del envío masivo
        $pdo = null;

        // --- URLs de destino para esta campaña/correo ---
        $url_to_track = 'https://dom0125.com/schedule-meeting.html';
        $encoded_url_to_track = base64_encode($url_to_track);
        $base_tracking_url = 'https://marketing.dom0125.com/track_click.php';
        // $checker = new WebsiteChecker(); //comprobar si las paginas webs de los clientes existen o no

        // --- BUCLE DE ENVÍO POR CAMPAÑA ---
        foreach ($recipients as $recipient) {
            try {
                $loopPdo = createPdoConnection();

                $params = '?campaign_id=' . $campaignId . '&contact_id=' . $recipient['contact_id'];
                $tracking_link = $base_tracking_url . $params . '&redirect_url=' . urlencode($encoded_url_to_track);


                // Reemplazo de variables personalizadas en asunto y cuerpo
                $variables = [
                    '{{name}}'  => $recipient['name'],
                    '{{email}}' => $recipient['email'],
                    '{{campaign_id}}' => $campaignId,
                    '{{contact_id}}' =>  $recipient['contact_id'],
                    '{{TRACK_LINK}}' => $tracking_link,

                ];



                // Decodificar custom_fields del contacto actual
                if (!empty($recipient['custom_fields'])) {
                    $customFields = json_decode($recipient['custom_fields'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($customFields)) {
                        foreach ($customFields as $key => $value) {
                            // Agregar cada campo personalizado como variable
                            $variables['{{' . $key . '}}'] = $value;
                        }
                    }
                }

                if (!empty($variables['sitio_web'])) {
                    if (verificarSitioWeb($variables['sitio_web'])) { // Esto incluye 2xx y 3xx
                        $variables['{{sitio_web_valido}}'] = "SI";
                    }
                }


                $personalizedSubject = parseDynamicTemplate($recipient['subject'], $variables);
                $personalizedHtml = parseDynamicTemplate($recipient['html_content'], $variables);

                // Determinar archivo adjunto de la campaña
                $attachmentPath = null;
                if (!empty($recipient['file_attached'])) {
                    $campaignAttachment = __DIR__ . '/uploads/' . $recipient['file_attached'];
                    if (file_exists($campaignAttachment)) {
                        $attachmentPath = $campaignAttachment;
                    } else {
                        // Log si el archivo no existe
                        file_put_contents(__DIR__ . '/logs/email_cron.log', "Archivo adjunto no encontrado para campaña $campaignId: {$recipient['file_attached']}\n", FILE_APPEND);
                    }
                }

                $timestamp = time();
                $trackingPixel = '<img src="https://' . YOUR_DOMAIN . '/track/open/' . $recipient['campaign_id'] . '/' . $recipient['contact_id'] . '?t=' . $timestamp . '" width="1" height="1" style="display:none;"/>';
                $finalHtmlContent = $personalizedHtml . $trackingPixel;
                $emailSent = sendEmail($sender, $recipient['email'], $recipient['name'], $personalizedSubject, $finalHtmlContent, $recipient['id'], file_exists($attachmentPath) ? $attachmentPath : null);

                if ($emailSent['success']) {
                    $updateStmt = $loopPdo->prepare("UPDATE campaign_recipients SET status = 'sent', sent_at = CURRENT_TIMESTAMP, error_message = NULL WHERE id = ?");
                    $updateStmt->execute([$recipient['id']]);
                } else {
                    $updateStmt = $loopPdo->prepare("UPDATE campaign_recipients SET status = 'failed', retry_count = retry_count + 1, error_message = ? WHERE id = ?");
                    $updateStmt->execute([$emailSent['error'], $recipient['id']]);
                }
                $totalProcesados++;
                $loopPdo = null;
            } catch (PDOException $e) {
                $errorMessage = "Error de BD procesando destinatario ID {$recipient['id']}: " . $e->getMessage();
                file_put_contents(__DIR__ . '/logs/email_cron.log', $errorMessage . "\n", FILE_APPEND);
                continue;
            }
        }

        $pdo = createPdoConnection();
        file_put_contents(__DIR__ . '/logs/email_cron.log', "Campaña $campaignId finalizada. Correos procesados en este lote: $totalProcesados.\n", FILE_APPEND);
        echo "\n[OK] Campaña $campaignId: $totalProcesados correos a las " . date('Y-m-d H:i:s') . "\n";

        // --- Marcar campaña como completada si corresponde ---
        $check = $pdo->prepare("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND (status = 'pending' OR (status = 'failed' AND retry_count < 3))");
        $check->execute([$campaignId]);
        $remaining = $check->fetchColumn();

        if ($remaining == 0) {
            $updateCampaignStmt = $pdo->prepare("UPDATE campaigns SET status = 'sent' WHERE id = ? AND status = 'sending'");
            $updateCampaignStmt->execute([$campaignId]);
            file_put_contents(__DIR__ . '/logs/email_cron.log', "Campaña $campaignId marcada como completada.\n", FILE_APPEND);
        }
        $pdo = null;
    } // FIN DEL FOR

    file_put_contents(__DIR__ . '/logs/email_cron.log', "Cron finalizado completamente.\n\n", FILE_APPEND);
} catch (Throwable $e) {
    // Captura cualquier error fatal que no haya sido manejado antes
    $errorMessage = "ERROR FATAL: " . $e->getMessage() . " en el archivo " . $e->getFile() . " en la línea " . $e->getLine();
    file_put_contents(__DIR__ . '/logs/email_cron.log', $errorMessage . "\n", FILE_APPEND);
    die($errorMessage);
}
