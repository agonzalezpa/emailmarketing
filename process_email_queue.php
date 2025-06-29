<?php

// --- CONFIGURACIÓN INICIAL ---
date_default_timezone_set('America/Havana');
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

// Función de envío de correo (sin cambios)
function sendEmail($sender, $toEmail, $toName, $subject, $htmlContent, $attachmentPath = null)
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
        $mail->setFrom($sender['email'], $sender['name']);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo($sender['email'], $sender['name']);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $htmlContent;
        $mail->AltBody = strip_tags($htmlContent);
        if ($attachmentPath && file_exists($attachmentPath)) {
            $mail->addAttachment($attachmentPath, basename($attachmentPath));
        }
        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


// --- EJECUCIÓN PRINCIPAL DEL CRON ---
try {
    file_put_contents(__DIR__ . '/email_cron.log', "[" . date('Y-m-d H:i:s') . "] Cron ejecutandose\n", FILE_APPEND);

    $pdo = createPdoConnection();

    // --- LÓGICA DE LÍMITE DIARIO ---
    $dailyLimit = 200;
    $currentDay = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaign_recipients WHERE DATE(sent_at) = ? AND status = 'sent'");
    $stmt->execute([$currentDay]);
    $sentToday = (int)$stmt->fetchColumn();
    file_put_contents(__DIR__ . '/email_cron.log', "Correos enviados hoy ($currentDay): $sentToday\n", FILE_APPEND);

    if ($sentToday >= $dailyLimit) {
        file_put_contents(__DIR__ . '/email_cron.log', "Límite diario ($dailyLimit) alcanzado. Deteniendo.\n\n", FILE_APPEND);
        exit;
    }

    // --- LÓGICA DE RITMO Y CÁLCULO DE LOTE ---
    $secondsInDay = 86400;
    $secondsElapsed = time() - strtotime('today midnight');
    $idealSentCount = floor(($secondsElapsed / $secondsInDay) * $dailyLimit);
    $batchLimit = $idealSentCount - $sentToday;
    $remainingForDay = $dailyLimit - $sentToday;
    $batchLimit = min($batchLimit, $remainingForDay);
    file_put_contents(__DIR__ . '/email_cron.log', "Ideal a enviar: $idealSentCount. Lote calculado: $batchLimit.\n", FILE_APPEND);

    if ($batchLimit <= 0) {
        file_put_contents(__DIR__ . '/email_cron.log', "No es necesario enviar correos ahora (ritmo correcto).\n\n", FILE_APPEND);
        exit;
    }

    // --- OBTENCIÓN DE DESTINATARIOS ---
    file_put_contents(__DIR__ . '/email_cron.log', "Buscando destinatarios en la base de datos...\n", FILE_APPEND);
    $stmt = $pdo->prepare("
        SELECT cr.*, c.email, c.name, cmp.subject, cmp.html_content, cmp.sender_id
        FROM campaign_recipients cr
        JOIN contacts c ON cr.contact_id = c.id
        JOIN campaigns cmp ON cr.campaign_id = cmp.id
        WHERE (cr.status = 'pending' OR (cr.status = 'failed' AND cr.retry_count < 3))
        ORDER BY cr.id ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $batchLimit, PDO::PARAM_INT);
    $stmt->execute();
    $recipients = $stmt->fetchAll();
    file_put_contents(__DIR__ . '/email_cron.log', "Se encontraron " . count($recipients) . " destinatarios para procesar.\n", FILE_APPEND);

    if (empty($recipients)) {
        file_put_contents(__DIR__ . '/email_cron.log', "No hay correos pendientes en la cola. Finalizando.\n\n", FILE_APPEND);
        exit;
    }
    
    // Cerramos la conexión inicial, ya que no la usaremos más antes del bucle
    $pdo = null;

    // --- BUCLE DE ENVÍO ---
    $processedCount = 0;
    foreach ($recipients as $recipient) {
        try {
            // **LA CORRECCIÓN CLAVE: RECONECTAR EN CADA ITERACIÓN**
            $loopPdo = createPdoConnection();

            $senderStmt = $loopPdo->prepare("SELECT * FROM senders WHERE id = ?");
            $senderStmt->execute([$recipient['sender_id']]);
            $sender = $senderStmt->fetch();

            if (!$sender) {
                $errorMessage = "Remitente con ID {$recipient['sender_id']} no encontrado.";
                $updateStmt = $loopPdo->prepare("UPDATE campaign_recipients SET status = 'failed', retry_count = retry_count + 1, error_message = ? WHERE id = ?");
                $updateStmt->execute([$errorMessage, $recipient['id']]);
                continue;
            }

            $attachmentPath = __DIR__ . '/catalogo.pdf';
             // 1. Crear el pixel de seguimiento con el ID del destinatario
            $trackingPixel = '<img src="https://' . YOUR_DOMAIN . '/track/open/' . $recipient['campaign_id'] . '/' . $recipient['contact_id'] . '" width="1" height="1" style="display:none;"/>';
            $finalHtmlContent =  $recipient['html_content']. $trackingPixel;
           
            $emailSent = sendEmail($sender, $recipient['email'], $recipient['name'], $recipient['subject'], $finalHtmlContent, file_exists($attachmentPath) ? $attachmentPath : null);

            if ($emailSent['success']) {
                $updateStmt = $loopPdo->prepare("UPDATE campaign_recipients SET status = 'sent', sent_at = CURRENT_TIMESTAMP, error_message = NULL WHERE id = ?");
                $updateStmt->execute([$recipient['id']]);
            } else {
                $updateStmt = $loopPdo->prepare("UPDATE campaign_recipients SET status = 'failed', retry_count = retry_count + 1, error_message = ? WHERE id = ?");
                $updateStmt->execute([$emailSent['error'], $recipient['id']]);
            }
            $processedCount++;
            
            // Cerramos la conexión de este ciclo
            $loopPdo = null;

        } catch (PDOException $e) {
            // Si hay un error de DB en esta iteración, lo registramos y continuamos con el siguiente correo
            $errorMessage = "Error de BD procesando destinatario ID {$recipient['id']}: " . $e->getMessage();
            file_put_contents(__DIR__ . '/email_cron.log', $errorMessage . "\n", FILE_APPEND);
            continue;
        }
    }

    // --- Marcar campañas como completadas ---
    // **RECONECTAR UNA ÚLTIMA VEZ para asegurar la conexión**
    $finalPdo = createPdoConnection();
    $campaignIds = array_unique(array_column($recipients, 'campaign_id'));
    foreach ($campaignIds as $campaignId) {
        $check = $finalPdo->prepare("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND (status = 'pending' OR (status = 'failed' AND retry_count < 3))");
        $check->execute([$campaignId]);
        $remaining = $check->fetchColumn();

        if ($remaining == 0) {
            $updateCampaignStmt = $finalPdo->prepare("UPDATE campaigns SET status = 'sent' WHERE id = ? AND status = 'sending'");
            $updateCampaignStmt->execute([$campaignId]);
        }
    }

    file_put_contents(__DIR__ . '/email_cron.log', "Cron finalizado. Correos procesados en este lote: $processedCount.\n\n", FILE_APPEND);
    echo "\n[OK] Procesado: $processedCount correos a las " . date('Y-m-d H:i:s') . "\n";

} catch (Throwable $e) {
    // Captura cualquier error fatal que no haya sido manejado antes
    $errorMessage = "ERROR FATAL: " . $e->getMessage() . " en el archivo " . $e->getFile() . " en la línea " . $e->getLine();
    file_put_contents(__DIR__ . '/email_cron.log', $errorMessage . "\n", FILE_APPEND);
    die($errorMessage); // Detiene la ejecución mostrando el error
}

