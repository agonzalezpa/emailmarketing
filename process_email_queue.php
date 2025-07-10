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
function limpiarAsunto($asunto)
{
    $cadena = "Subject";
    $longitud = strlen($cadena) + 2;
    return substr(
        iconv_mime_encode(
            $cadena,
            $asunto,
            [
                "input-charset" => "UTF-8",
                "output-charset" => "UTF-8",
            ]
        ),
        $longitud
    );
}
/**
 * Envía un correo electrónico usando PHPMailer con configuración SMTP.
 *
 * @param array $sender Un array con los datos del remitente (smtp_host, smtp_username, etc.).
 * @param string $toEmail La dirección de correo del destinatario.
 * @param string $toName El nombre del destinatario.
 * @param string $subject El asunto del correo.
 * @param string $htmlContent El cuerpo del correo en formato HTML.
 * @param string|null $attachmentPath La ruta opcional a un archivo adjunto.
 * @return array Un array indicando el éxito o fracaso de la operación.
 */
function sendEmail($sender, $toEmail, $toName, $subject, $htmlContent, $id_recipient, $attachmentPath = null)
{
    try {
        $mail = new PHPMailer(true);
        // ... (toda tu configuración SMTP existente va aquí)
        $mail->isSMTP();
        $mail->Host = $sender['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $sender['smtp_username'];
        $mail->Password = $sender['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        // --- INICIO DE AJUSTES ---

        // 1. Incrusta las imágenes y dales un CID único
        //    Asegúrate de que la ruta a tus imágenes sea correcta en el servidor
        $mail->addEmbeddedImage(__DIR__ . '/uploads/header.jpg', 'header_cid');
        $mail->addEmbeddedImage(__DIR__ . '/uploads/about.png', 'about_cid');
        //$mail->addEmbeddedImage(__DIR__ . '/uploads/bg_1.jpg', 'counter_cid');

        // 2. El resto de la configuración
        $mail->CharSet = 'UTF-8';
        $mail->addCustomHeader('X-Campaign-Recipient-ID', $id_recipient);
        $mail->setFrom($sender['email'], $sender['name']);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo($sender['email'], $sender['name']);
        $mail->isHTML(true);
        $mail->Subject = $subject; // PHPMailer lo maneja

        // 3. El $htmlContent ya debe tener los CIDs en lugar de las URLs
        $mail->Body = $htmlContent;
        $mail->AltBody = strip_tags($htmlContent);

        if ($attachmentPath && file_exists($attachmentPath)) {
            $mail->addAttachment($attachmentPath, basename($attachmentPath));
        }

        // --- FIN DE AJUSTES ---

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

    // --- OBTENER CAMPAÑAS ACTIVAS (una por sender) ---

    $campaignsStmt = $pdo->query("
    SELECT MIN(id) as campaign_id, sender_id
    FROM campaigns
    WHERE status = 'sending'
    GROUP BY sender_id
");
    $campaigns = $campaignsStmt->fetchAll();
    $pdo = null;
    if (empty($campaigns)) {
        file_put_contents(__DIR__ . '/email_cron.log', "No hay campañas activas para procesar.\n\n", FILE_APPEND);
        exit;
    }

    $secondsInDay = 86400;
    $secondsElapsed = time() - strtotime('today midnight');
    $dailyLimit = 1000; // Por campaña

    foreach ($campaigns as $camp) {
        $pdo = createPdoConnection();
        $totalProcesados = 0; // Reiniciar contador por campaña
        $campaignId = $camp['campaign_id'];
        $senderId = $camp['sender_id'];

        // 1. Correos enviados hoy por esta campaña
        $currentDay = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND DATE(sent_at) = ? AND status = 'sent'");
        $stmt->execute([$campaignId, $currentDay]);
        $sentToday = (int)$stmt->fetchColumn();

        // 2. Calcula el ritmo ideal y lote para esta campaña
        $idealSentCount = floor(($secondsElapsed / $secondsInDay) * $dailyLimit);
        $batchLimit = $idealSentCount - $sentToday;
        $remainingForDay = $dailyLimit - $sentToday;
        $batchLimit = min($batchLimit, $remainingForDay);

        file_put_contents(__DIR__ . '/email_cron.log', "Campaña $campaignId (sender $senderId): enviados hoy $sentToday, ideal hasta ahora $idealSentCount, lote $batchLimit\n", FILE_APPEND);

        if ($batchLimit <= 0) {
            file_put_contents(__DIR__ . '/email_cron.log', "Campaña $campaignId  tiene ritmo correcto. Saltando.\n", FILE_APPEND);
            continue;
        }
        // Obtener el sender UNA SOLA VEZ por campaña
        $senderStmt = $pdo->prepare("SELECT * FROM senders WHERE id = ?");
        $senderStmt->execute([$senderId]);
        $sender = $senderStmt->fetch();

        if (!$sender) {
            file_put_contents(__DIR__ . '/email_cron.log', "Remitente con ID $senderId no encontrado para campaña $campaignId.\n", FILE_APPEND);
            continue;
        }

        // 3. Obtén los destinatarios pendientes para esta campaña
        $stmt = $pdo->prepare("
        SELECT cr.*, c.email, c.name, cmp.subject, cmp.html_content, cmp.sender_id
        FROM campaign_recipients cr
        JOIN contacts c ON cr.contact_id = c.id
        JOIN campaigns cmp ON cr.campaign_id = cmp.id
        WHERE cr.campaign_id = ?
          AND (cr.status = 'pending' OR  cr.retry_count < 3)
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
        // --- Codificar las URLs en base64 ---
        $encoded_url_to_track = base64_encode($url_to_track);

        // --- Generar los enlaces de seguimiento completos ---
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
                //$finalHtmlContent =  $recipient['html_content'] . $trackingPixel;
                $finalHtmlContent = $personalizedHtml . $trackingPixel;
                $emailSent = sendEmail($sender, $recipient['email'], $recipient['name'], $personalizedSubject, $finalHtmlContent,$recipient['id'], file_exists($attachmentPath) ? $attachmentPath : null);

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
        file_put_contents(__DIR__ . '/email_cron.log', "Cron finalizado. Correos procesados en este lote: $totalProcesados.\n\n", FILE_APPEND);
        echo "\n[OK] Procesado: $totalProcesados correos a las " . date('Y-m-d H:i:s') . "\n";
        // --- Marcar campaña como completada si corresponde ---
        $check = $pdo->prepare("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND (status = 'pending' OR (status = 'failed' AND retry_count < 3))");
        $check->execute([$campaignId]);
        $remaining = $check->fetchColumn();
        if ($remaining == 0) {
            $updateCampaignStmt = $pdo->prepare("UPDATE campaigns SET status = 'sent' WHERE id = ? AND status = 'sending'");
            $updateCampaignStmt->execute([$campaignId]);
        }
        $pdo = null;
    } //FIN DEL 1ER FOR

} catch (Throwable $e) {
    // Captura cualquier error fatal que no haya sido manejado antes
    $errorMessage = "ERROR FATAL: " . $e->getMessage() . " en el archivo " . $e->getFile() . " en la línea " . $e->getLine();
    file_put_contents(__DIR__ . '/email_cron.log', $errorMessage . "\n", FILE_APPEND);
    die($errorMessage); // Detiene la ejecución mostrando el error
}
