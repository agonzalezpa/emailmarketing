<?php

// --- CONFIGURACIÓN INICIAL ---
//date_default_timezone_set('America/Havana');
define('YOUR_DOMAIN', 'marketing.dom0125.com');

// --- CARGA DE DEPENDENCIAS ---
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

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

        $mail->addEmbeddedImage(__DIR__ . '/uploads/header.jpg', 'header_cid');
        $mail->addEmbeddedImage(__DIR__ . '/uploads/about.png', 'about_cid');

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

// --- EJECUCIÓN PRINCIPAL DEL CRON ---
try {
    file_put_contents(__DIR__ . '/email_cron.log', "[" . date('Y-m-d H:i:s') . "] Cron ejecutandose\n", FILE_APPEND);

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
        file_put_contents(__DIR__ . '/email_cron.log', "No hay campañas activas para procesar.\n\n", FILE_APPEND);
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

        file_put_contents(__DIR__ . '/email_cron.log', "Campaña $campaignId (sender $senderId): enviados hoy $sentToday, ideal hasta ahora $idealSentCount, lote $batchLimit, límite diario $dailyLimit\n", FILE_APPEND);

        if ($batchLimit <= 0) {
            file_put_contents(__DIR__ . '/email_cron.log', "Campaña $campaignId tiene ritmo correcto. Saltando.\n", FILE_APPEND);
            continue;
        }

        // Obtener el sender (con cache para evitar consultas repetidas)
        if (!isset($sendersCache[$senderId])) {
            $senderStmt = $pdo->prepare("SELECT * FROM senders WHERE id = ?");
            $senderStmt->execute([$senderId]);
            $sendersCache[$senderId] = $senderStmt->fetch();
        }
        $sender = $sendersCache[$senderId];

        if (!$sender) {
            file_put_contents(__DIR__ . '/email_cron.log', "Remitente con ID $senderId no encontrado para campaña $campaignId.\n", FILE_APPEND);
            continue;
        }

        // 3. Obtén los destinatarios pendientes para esta campaña específica
        $stmt = $pdo->prepare("
            SELECT cr.*, c.email, c.name, cmp.subject, cmp.html_content, cmp.sender_id
            FROM campaign_recipients cr
            JOIN contacts c ON cr.contact_id = c.id
            JOIN campaigns cmp ON cr.campaign_id = cmp.id
            WHERE cr.campaign_id = ?
              AND (cr.status = 'pending' OR (cr.status = 'failed' AND cr.retry_count < 3))
            ORDER BY cr.id ASC
            LIMIT ?
        ");
        $stmt->execute([$campaignId, $batchLimit]);
        $recipients = $stmt->fetchAll();

        file_put_contents(__DIR__ . '/email_cron.log', "Campaña $campaignId: Se encontraron " . count($recipients) . " destinatarios para procesar.\n", FILE_APPEND);
        
        // Cierra la conexión principal antes del envío masivo
        $pdo = null;

        // --- URLs de destino para esta campaña/correo ---
        $url_to_track = 'https://dom0125.com/schedule-meeting.html';
        $encoded_url_to_track = base64_encode($url_to_track);
        $base_tracking_url = 'https://marketing.dom0125.com/track_click.php';

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

                $personalizedSubject = str_replace(array_keys($variables), array_values($variables), $recipient['subject']);
                $personalizedHtml = str_replace(array_keys($variables), array_values($variables), $recipient['html_content']);

                $attachmentPath = __DIR__ . '/precios_base.pdf';
                $trackingPixel = '<img src="https://' . YOUR_DOMAIN . '/track/open/' . $recipient['campaign_id'] . '/' . $recipient['contact_id'] . '" width="1" height="1" style="display:none;"/>';
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
                file_put_contents(__DIR__ . '/email_cron.log', $errorMessage . "\n", FILE_APPEND);
                continue;
            }
        }

        $pdo = createPdoConnection();
        file_put_contents(__DIR__ . '/email_cron.log', "Campaña $campaignId finalizada. Correos procesados en este lote: $totalProcesados.\n", FILE_APPEND);
        echo "\n[OK] Campaña $campaignId: $totalProcesados correos a las " . date('Y-m-d H:i:s') . "\n";
        
        // --- Marcar campaña como completada si corresponde ---
        $check = $pdo->prepare("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND (status = 'pending' OR (status = 'failed' AND retry_count < 3))");
        $check->execute([$campaignId]);
        $remaining = $check->fetchColumn();
        
        if ($remaining == 0) {
            $updateCampaignStmt = $pdo->prepare("UPDATE campaigns SET status = 'sent' WHERE id = ? AND status = 'sending'");
            $updateCampaignStmt->execute([$campaignId]);
            file_put_contents(__DIR__ . '/email_cron.log', "Campaña $campaignId marcada como completada.\n", FILE_APPEND);
        }
        $pdo = null;
    } // FIN DEL FOR

    file_put_contents(__DIR__ . '/email_cron.log', "Cron finalizado completamente.\n\n", FILE_APPEND);

} catch (Throwable $e) {
    // Captura cualquier error fatal que no haya sido manejado antes
    $errorMessage = "ERROR FATAL: " . $e->getMessage() . " en el archivo " . $e->getFile() . " en la línea " . $e->getLine();
    file_put_contents(__DIR__ . '/email_cron.log', $errorMessage . "\n", FILE_APPEND);
    die($errorMessage);
}