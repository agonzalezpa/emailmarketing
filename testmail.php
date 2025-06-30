<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$mail = new PHPMailer(true);



    try {
        // Configuración SMTP
        $mail->isSMTP();
        $mail->Host = 'smtp.hostinger.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'info@dom0125.com';
        $mail->Password = 'Olivera19%';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // o STARTTLS si usas 587
        $mail->Port = 465; // o 587 si usas STARTTLS

        // Info del remitente y receptor
        $mail->setFrom('info@dom0125.com', 'DOM LLC');
        $mail->addAddress("agonzalezpa0191@gmail.com");

        // Contenido
        $mail->isHTML(true);
        $mail->Subject = "EL de prueba";
        $mail->Body    = "<h1>Dimelo mi hermano como va la cosa</h1>";

        $mail->send();
        echo "PHPMailer funciona Correo enviado correctamente ✅";
        
    } catch (Exception $e) {
        echo "Dio error al enviar" ;
    }