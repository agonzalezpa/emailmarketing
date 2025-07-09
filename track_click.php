<?php
// --- CONEXIÓN A LA BASE DE DATOS ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');

// URL de redirección por defecto si algo falla.
define('FALLBACK_URL', 'https://dom0125.com/');

// --- LÓGICA DE SEGUIMIENTO ---

$destination_url = FALLBACK_URL;

// Verificamos que tenemos todos los parámetros necesarios
if (!empty($_GET['campaign_id']) && !empty($_GET['contact_id']) && !empty($_GET['redirect_url'])) {
    
    // Decodificamos la URL de destino
    $decoded_url = base64_decode($_GET['redirect_url']);

    // --- Medida de Seguridad: Validar que la URL es válida ---
    // Esto previene vulnerabilidades de "Open Redirect".
    if (filter_var($decoded_url, FILTER_VALIDATE_URL) && (strpos($decoded_url, 'http://') === 0 || strpos($decoded_url, 'https://') === 0)) {
        $destination_url = $decoded_url; // La URL es válida, la usamos como destino.
    } else {
        // Si la URL no es válida, registramos el intento y usamos la URL de fallback.
        file_put_contents(__DIR__ . '/error_log', "Intento de redirección inválida: " . $decoded_url . "\n", FILE_APPEND);
    }
    
    // --- Registrar el evento en la base de datos ---
    $campaign_id = (int)$_GET['campaign_id'];
    $contact_id = (int)$_GET['contact_id'];

    if ($campaign_id > 0 && $contact_id > 0) {
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $stmt = $pdo->prepare(
                "INSERT INTO email_events (campaign_id, contact_id, event_type, ip_address, user_agent) 
                 VALUES (?, ?, 'clicked', ?, ?)"
            );
            // Guardamos la URL de destino (ya decodificada) en 'event_data'.
            $stmt->execute([$campaign_id, $contact_id, $destination_url, $ip_address, $user_agent]);

        } catch (PDOException $e) {
            // Si hay un error, lo registramos pero no detenemos la redirección.
            $errorMessage = "ERROR DE CLIC: " . $e->getMessage();
            file_put_contents(__DIR__ . '/error_log', $errorMessage . "\n", FILE_APPEND);
        }
    }
}

// --- REDIRECCIÓN FINAL ---
// Redirigimos al usuario a la URL de destino (sea la decodificada o la de fallback).
header('Location: ' . $destination_url, true, 302);
exit;