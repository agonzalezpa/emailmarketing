<?php

/**
 * API Backend para el Sistema de Email Marketing
 * Este archivo maneja todas las operaciones de base de datos y envío de emails
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'u750684196_email_marketin');
define('DB_USER', 'u750684196_info');
define('DB_PASS', 'Olivera19%');

// PHPMailer configuration (install via Composer: composer require phpmailer/phpmailer)
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;

class EmailMarketingAPI
{
    private $pdo;

    public function __construct()
    {
        // Constructor will not initialize database connection automatically
        // Connection will be tested on demand
        $this->getConnection();
    }

    private function getConnection()
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $e) {
                throw new Exception('Database connection failed: ' . $e->getMessage());
            }
        }
        return $this->pdo;
    }

    public function handleRequest()
    {

        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $pathParts = explode('/', trim($path, '/'));

        // Remove 'api.php' from path if present
        if ($pathParts[0] === 'api.php') {
            array_shift($pathParts);
        }

        $endpoint = $pathParts[0] ?? '';
        $id = $pathParts[1] ?? null;

        try {
            switch ($endpoint) {
                case 'test-connection':
                    $this->handleTestConnection();
                    break;
                case 'senders':
                    $this->handleSenders($method, $id);
                    break;
                case 'contacts':
                    // Soporta /contacts/count
                    if (isset($pathParts[1]) && $pathParts[1] === 'count') {
                        $this->countContacts();
                    } else {
                        $this->handleContacts($method, $id);
                    }
                    break;
                case 'campaigns':
                    $this->handleCampaigns($method, $id);
                    break;
               /* case 'templates':
                    $this->handleTemplates($method, $id);
                    break;*/
                case 'send-test':
                    $this->handleSendTest();
                    break;
                case 'send-campaign':
                    $this->handleSendCampaign($method);
                    break;
                case 'stats':
                    $this->handleStats();
                    break;
                case 'contact-lists':
                    $this->handleContactLists($method, $id);
                    break;
                case 'contact-list-members':
                    $this->handleContactListMembers($method, $id);
                    break;
                default:
                    $this->sendError(404, 'Endpoint not found');
            }
        } catch (Exception $e) {
            $this->sendError(500, 'Server error: ' . $e->getMessage());
        }
    }

    public function countContacts()
    {
        $pdo = $this->getConnection();
        $listIds = isset($_GET['list_ids']) ? explode(',', $_GET['list_ids']) : [];

        if (empty($listIds) || (count($listIds) === 1 && $listIds[0] === '')) {
            // Todos los contactos activos
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM contacts WHERE status = 'active'");
            $stmt->execute();
        } elseif (count($listIds) === 1) {
            // Contactos activos en una lista
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id) as total
            FROM contacts c
            JOIN contact_list_members clm ON c.id = clm.contact_id
            WHERE clm.list_id = ? AND c.status = 'active'");
            $stmt->execute([$listIds[0]]);
        } else {
            // Contactos activos en varias listas (distintos)
            $in = str_repeat('?,', count($listIds) - 1) . '?';
            $sql = "SELECT COUNT(DISTINCT c.id) as total
            FROM contacts c
            JOIN contact_list_members clm ON c.id = clm.contact_id
            WHERE clm.list_id IN ($in) AND c.status = 'active'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($listIds);
        }
        $total = $stmt->fetchColumn();
        $this->sendResponse(['total' => (int)$total]);
    }

    private function handleTestConnection()
    {
        try {
            $pdo = $this->getConnection();
            // Test basic query
            $stmt = $pdo->query("SELECT* FROM settings");
            $result = $stmt->fetch();

            if ($result) {
                $this->sendResponse([
                    'status' => 'connected',
                    'message' => 'Database connection successful',
                    'database' => DB_NAME,
                    'host' => DB_HOST
                ]);
            } else {
                $this->sendError(500, 'Database test query failed');
            }
        } catch (Exception $e) {
            $this->sendError(500, 'Database connection failed: ' . $e->getMessage());
        }
    }

    private function handleSenders($method, $id)
    {
        switch ($method) {
            case 'GET':
                if ($id) {
                    $this->getSender($id);
                } else {
                    $this->getSenders();
                }
                break;
            case 'POST':
                $this->createSender();
                break;
            case 'PUT':
                $this->updateSender($id);
                break;
            case 'DELETE':
                $this->deleteSender($id);
                break;
            default:
                $this->sendError(405, 'Method not allowed');
        }
    }

    private function handleContacts($method, $id)
    {
        switch ($method) {
            case 'GET':
                if ($id) {
                    $this->getContact($id);
                } else {
                    $this->getContacts();
                }
                break;
            case 'POST':
                if (isset($_GET['import'])) {
                    $this->importContacts();
                    // $this->handleImportExcel();
                } else {
                    $this->createContact();
                }
                break;
            case 'PUT':
                $this->updateContact($id);
                break;
            case 'DELETE':
                $this->deleteContact($id);
                break;
            default:
                $this->sendError(405, 'Method not allowed');
        }
    }

    private function handleCampaigns($method, $id)
    {
        // Verificar si hay un parámetro action en la URL
        $action = $_GET['action'] ?? null;

        if ($action) {
            // Manejar acciones específicas
            switch ($action) {
                case 'pauseCampaign':
                    $this->pauseCampaign($id);
                    break;
                case 'resumeCampaign':
                    $this->resumeCampaign($id);
                    break;
                case 'cancelCampaign':
                    $this->cancelCampaign($id);
                    break;
                default:
                    $this->sendError(400, 'Invalid action');
            }
            return;
        }

        // Manejar métodos HTTP estándar
        switch ($method) {
            case 'GET':
                if ($id) {
                    $this->getCampaign($id);
                } else {
                    $this->getCampaigns();
                }
                break;
            case 'POST':
                $this->createCampaign();
                break;
            case 'PUT':
                $this->updateCampaign($id);
                break;
            case 'DELETE':
                //  $this->deleteCampaign($id);
                break;
            default:
                $this->sendError(405, 'Method not allowed');
        }
    }

   /* private function handleTemplates($method, $id)
    {
        switch ($method) {
            case 'GET':
                if ($id) {
                    $this->getTemplate($id);
                } else {
                    $this->getTemplates();
                }
                break;
            case 'POST':
                $this->createTemplate();
                break;
            case 'PUT':
                $this->updateTemplate($id);
                break;
            case 'DELETE':
                $this->deleteTemplate($id);
                break;
            default:
                $this->sendError(405, 'Method not allowed');
        }
    }*/

    // Sender methods
    private function getSenders()
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->query("SELECT * FROM senders WHERE is_active = 1 ORDER BY created_at DESC");
        $senders = $stmt->fetchAll();

        // Hide sensitive information
        foreach ($senders as &$sender) {
            $sender['smtp_password'] = '****';
        }

        $this->sendResponse($senders);
    }

    private function getSender($id)
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM senders WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        $sender = $stmt->fetch();

        if (!$sender) {
            $this->sendError(404, 'Sender not found');
        }

        $sender['smtp_password'] = '****';
        $this->sendResponse($sender);
    }

    private function createSender()
    {
        $data = $this->getJsonInput();

        $required = ['name', 'email', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password'];
        $this->validateRequiredFields($data, $required);

        // Validate email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->sendError(400, 'Invalid email address');
        }

        $pdo = $this->getConnection();

        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM senders WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            $this->sendError(400, 'Email already exists');
        }

        // Test SMTP connection
        /* if (!$this->testSmtpConnection($data)) {
            $this->sendError(400, 'SMTP connection failed. Please check your settings.');
        }*/

        $stmt = $pdo->prepare("
            INSERT INTO senders (name, email, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['name'],
            $data['email'],
            $data['smtp_host'],
            $data['smtp_port'],
            $data['smtp_username'],
            $this->encryptPassword($data['smtp_password']),
            $data['smtp_encryption'] ?? 'tls'
        ]);

        $senderId = $pdo->lastInsertId();
        $this->sendResponse(['id' => $senderId, 'message' => 'Sender created successfully']);
    }

    private function updateSender($id)
    {
        if (!$id) {
            $this->sendError(400, 'Sender ID required');
        }

        $data = $this->getJsonInput();

        $pdo = $this->getConnection();

        $stmt = $pdo->prepare("
            UPDATE senders SET name = ?, email = ?, smtp_host = ?, smtp_port = ?, 
                   smtp_username = ?, smtp_encryption = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmt->execute([
            $data['name'],
            $data['email'],
            $data['smtp_host'],
            $data['smtp_port'],
            $data['smtp_username'],
            $data['smtp_encryption'] ?? 'tls',
            $id
        ]);

        // Update password if provided
        if (!empty($data['smtp_password']) && $data['smtp_password'] !== '****') {
            $stmt = $pdo->prepare("UPDATE senders SET smtp_password = ? WHERE id = ?");
            $stmt->execute([$this->encryptPassword($data['smtp_password']), $id]);
        }

        $this->sendResponse(['message' => 'Sender updated successfully']);
    }

    private function deleteSender($id)
    {
        if (!$id) {
            $this->sendError(400, 'Sender ID required');
        }

        $pdo = $this->getConnection();

        // Check if sender is used in campaigns
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaigns WHERE sender_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            $this->sendError(400, 'Cannot delete sender: it is used in campaigns');
        }

        $stmt = $pdo->prepare("UPDATE senders SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);

        $this->sendResponse(['message' => 'Sender deleted successfully']);
    }

    // Contact methods


    private function getContacts()
    {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 15;
        $search = $_GET['search'] ?? '';
        $listId = $_GET['list_id'] ?? null;
        $offset = ($page - 1) * $limit;

        $pdo = $this->getConnection();

        $whereClause = "1=1";
        $params = [];

        if (!empty($search)) {
            $whereClause .= " AND (name LIKE :search OR email LIKE :search)";
            $params[':search'] = "%$search%";
        }

        if (!empty($listId) && $listId !== 'all') {
            $whereClause .= " AND id IN (SELECT contact_id FROM contact_list_members WHERE list_id = :list_id)";
            $params[':list_id'] = $listId;
        }

        // Total count
        $totalSql = "SELECT COUNT(*) FROM contacts WHERE $whereClause";
        $totalStmt = $pdo->prepare($totalSql);
        $totalStmt->execute($params);
        $total = $totalStmt->fetchColumn();

        // Data
        $dataSql = "SELECT * FROM contacts WHERE $whereClause ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $dataStmt = $pdo->prepare($dataSql);
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $contacts = $dataStmt->fetchAll();

        $this->sendResponse([
            'total' => $total,
            'limit' => $limit,
            'data' => $contacts
        ]);
    }

    private function getContact($id)
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE id = ?");
        $stmt->execute([$id]);
        $contact = $stmt->fetch();

        if (!$contact) {
            $this->sendError(404, 'Contact not found');
        }

        $this->sendResponse($contact);
    }

    private function createContact()
    {
        $data = $this->getJsonInput();
        error_log(print_r($data, true));
        $required = ['name', 'email'];
        $this->validateRequiredFields($data, $required);

        // Validate email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->sendError(400, 'Invalid email address');
        }

        $pdo = $this->getConnection();

        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM contacts WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            $this->sendError(400, 'Email already exists');
        }

        $stmt = $pdo->prepare("
        INSERT INTO contacts (name, email, status, tags, custom_fields) 
        VALUES (?, ?, ?, ?, ?)
    ");

        $stmt->execute([
            $data['name'],
            $data['email'],
            $data['status'] ?? 'active',
            json_encode($data['tags'] ?? []),
            json_encode($data['custom_fields'] ?? [])
        ]);

        $contactId = $pdo->lastInsertId();

        // Si se recibieron listas, agregar el contacto a cada una
        if (!empty($data['list_ids']) && is_array($data['list_ids'])) {
            $stmtList = $pdo->prepare("INSERT INTO contact_list_members (list_id, contact_id) VALUES (?, ?)");
            foreach ($data['list_ids'] as $listId) {
                // Verifica que la lista exista (opcional)
                $stmtCheck = $pdo->prepare("SELECT id FROM contact_lists WHERE id = ?");
                $stmtCheck->execute([$listId]);
                if ($stmtCheck->fetch()) {
                    try {
                        $stmtList->execute([$listId, $contactId]);
                    } catch (Exception $e) {
                        // Si ya existe la relación, la ignoramos
                    }
                }
            }
        }

        $this->sendResponse(['id' => $contactId, 'message' => 'Contact created successfully']);
    }

    private function updateContact($id)
    {
        if (!$id) {
            $this->sendError(400, 'Contact ID required');
        }

        $data = $this->getJsonInput();

        $pdo = $this->getConnection();

        $stmt = $pdo->prepare("
            UPDATE contacts SET name = ?, email = ?, status = ?, tags = ?, 
                   custom_fields = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");

        $stmt->execute([
            $data['name'],
            $data['email'],
            $data['status'] ?? 'active',
            json_encode($data['tags'] ?? []),
            json_encode($data['custom_fields'] ?? []),
            $id
        ]);

        $this->sendResponse(['message' => 'Contact updated successfully']);
    }

    private function deleteContact($id)
    {
        if (!$id) {
            $this->sendError(400, 'Contact ID required');
        }

        $pdo = $this->getConnection();

        // Eliminar de las listas primero
        $stmt = $pdo->prepare("DELETE FROM contact_list_members WHERE contact_id = ?");
        $stmt->execute([$id]);

        // Luego eliminar el contacto
        $stmt = $pdo->prepare("DELETE FROM contacts WHERE id = ?");
        $stmt->execute([$id]);

        $this->sendResponse(['message' => 'Contact deleted successfully']);
    }


    /**
     * Importar contactos desde un csv
     * @return void
     */
    private function importContacts()
    {
        // Configuración inicial para archivos grandes
        ini_set('max_execution_time', 0); // Sin límite de tiempo
        ini_set('memory_limit', '512M');  // Aumentar memoria disponible

        if (!isset($_FILES['csv_file'])) {
            $this->sendError(400, 'Archivo CSV requerido.');
        }

        $file = $_FILES['csv_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->sendError(400, 'Error al subir el archivo.');
        }

        // Verificar tipo MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedTypes = ['text/csv', 'text/plain', 'application/vnd.ms-excel'];
        if (!in_array($mimeType, $allowedTypes)) {
            $this->sendError(400, 'Tipo de archivo inválido. Sube un archivo CSV.');
        }

        // Obtener listas seleccionadas
        $listIds = [];
        if (isset($_POST['list_ids'])) {
            $listIds = is_array($_POST['list_ids']) ? $_POST['list_ids'] : json_decode($_POST['list_ids'], true);
            if (!is_array($listIds)) $listIds = [];
        }

        // Limpiar archivo CSV antes de procesarlo
        $cleanedFile = $this->cleanCSVFile($file['tmp_name']);

        // Abrir archivo limpio para lectura
        if (($handle = fopen($cleanedFile, 'r')) === false) {
            $this->sendError(400, 'No se puede leer el archivo CSV.');
        }

        $pdo = $this->getConnection();

        // Configurar PDO para mejor rendimiento
        $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
        $pdo->beginTransaction();

        $imported = 0;
        $updated = 0;
        $errors = [];
        $batchSize = 1000; // Procesar en lotes de 1000 registros
        $currentBatch = 0;
        $totalProcessed = 0;

        // Leer encabezado
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            if (file_exists($cleanedFile)) {
                unlink($cleanedFile);
            }
            $this->sendError(400, 'Archivo CSV vacío o formato inválido.');
        }

        // Normalizar encabezados
        $header = array_map(fn($col) => strtolower(trim($col)), $header);
        $emailIndex = array_search('email', $header);

        if ($emailIndex === false) {
            fclose($handle);
            $this->sendError(400, "Falta la columna requerida: email");
        }

        // Definir campos estándar de la tabla contacts
        $standardFields = ['name', 'email', 'status', 'tags'];
        $rowNumber = 1;
        $newContactIds = [];
        $allContactIds = []; // Para almacenar TODOS los contactos (nuevos y actualizados)

        // Preparar statements una sola vez (fuera del bucle)
        $checkStmt = $pdo->prepare("SELECT id, status FROM contacts WHERE email = ?");
        $updateStmt = null;
        $insertStmt = null;
        $customFieldsStmt = $pdo->prepare("SELECT custom_fields FROM contacts WHERE id = ?");

        // Arrays para procesamiento por lotes
        $batchInserts = [];
        $batchUpdates = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $totalProcessed++;

            if (empty(array_filter($row))) continue; // Saltar filas vacías

            $email = trim($row[$emailIndex] ?? '');

            if (!$email) {
                $errors[] = "Fila $rowNumber: Falta el email.";
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Fila $rowNumber: Email inválido: $email.";
                continue;
            }

            try {
                // Verifica si ya existe el contacto
                $checkStmt->execute([$email]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                // Preparar datos para inserción/actualización
                $contactData = [];
                $customFields = [];

                // Procesar cada columna del CSV
                foreach ($header as $index => $columnName) {
                    $value = isset($row[$index]) ? trim($row[$index]) : '';
                    if ($value === '') continue;

                    if (in_array($columnName, $standardFields)) {
                        if ($columnName === 'status' || $columnName === 'estado') {
                            $validStatuses = ['active', 'inactive', 'deleted', 'unsubscribed'];
                            $mappedStatus = $this->mapStatus($value);
                            if (in_array($mappedStatus, $validStatuses)) {
                                if (!$existing) {
                                    $contactData['status'] = $mappedStatus;
                                }
                            } else if (!$existing) {
                                $contactData['status'] = 'active';
                            }
                        } else if ($columnName === 'tags') {
                            if (!empty($value)) {
                                $tagsArray = is_string($value) ? explode(',', $value) : [$value];
                                $tagsArray = array_map('trim', $tagsArray);
                                $contactData[$columnName] = json_encode($tagsArray);
                            }
                        } else {
                            if (in_array($columnName, ['name', 'email'])) {
                                $contactData[$columnName] = $value;
                            } else {
                                $customFields[$columnName] = $value;
                            }
                        }
                    } else {
                        $customFields[$columnName] = $value;
                    }
                }

                if ($existing) {
                    // Agregar a lote de actualizaciones
                    $batchUpdates[] = [
                        'id' => $existing['id'],
                        'data' => $contactData,
                        'custom_fields' => $customFields,
                        'row' => $rowNumber
                    ];
                } else {
                    // Agregar a lote de inserciones
                    if (!isset($contactData['status'])) {
                        $contactData['status'] = 'active';
                    }

                    $batchInserts[] = [
                        'data' => $contactData,
                        'custom_fields' => $customFields,
                        'row' => $rowNumber
                    ];
                }

                $currentBatch++;

                // Procesar lote cuando se alcanza el tamaño o al final del archivo
                if ($currentBatch >= $batchSize || feof($handle)) {
                    $this->processBatch($pdo, $batchInserts, $batchUpdates, $listIds, $imported, $updated, $errors, $newContactIds, $allContactIds, $customFieldsStmt);

                    // Commit periódico para evitar transacciones muy largas
                    $pdo->commit();
                    $pdo->beginTransaction();

                    // Limpiar arrays y resetear contador
                    $batchInserts = [];
                    $batchUpdates = [];
                    $currentBatch = 0;

                    // Liberar memoria
                    gc_collect_cycles();

                    // Opcional: Enviar progreso al cliente (si usas AJAX)
                    if ($totalProcessed % 5000 === 0) {
                        // Aquí podrías enviar progreso vía websocket o session
                        error_log("Procesados: $totalProcessed registros");
                    }
                }
            } catch (Exception $e) {
                $errors[] = "Fila $rowNumber: Error al procesar $email: " . $e->getMessage();
            }
        }

        // Procesar último lote si queda algo
        if (!empty($batchInserts) || !empty($batchUpdates)) {
            $this->processBatch($pdo, $batchInserts, $batchUpdates, $listIds, $imported, $updated, $errors, $newContactIds, $allContactIds, $customFieldsStmt);
        }

        // Commit final
        $pdo->commit();
        $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true);

        fclose($handle);

        // Asignar TODOS los contactos (nuevos y actualizados) a listas seleccionadas
        if (!empty($listIds) && !empty($allContactIds)) {
            $this->assignContactsToLists($pdo, $listIds, $allContactIds);
        }

        // Limpiar archivo temporal
        if (file_exists($cleanedFile)) {
            unlink($cleanedFile);
        }

        $this->sendResponse([
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors,
            'message' => "$imported contactos importados, $updated contactos actualizados correctamente"
        ]);
    }

    private function processBatch($pdo, $batchInserts, $batchUpdates, $listIds, &$imported, &$updated, &$errors, &$newContactIds, &$allContactIds, $customFieldsStmt)
    {
        // Procesar inserciones en lote
        if (!empty($batchInserts)) {
            $this->processBatchInserts($pdo, $batchInserts, $listIds, $imported, $errors, $newContactIds, $allContactIds);
        }

        // Procesar actualizaciones en lote
        if (!empty($batchUpdates)) {
            $this->processBatchUpdates($pdo, $batchUpdates, $updated, $errors, $customFieldsStmt, $allContactIds);
        }
    }

    private function processBatchInserts($pdo, $batchInserts, $listIds, &$imported, &$errors, &$newContactIds, &$allContactIds)
    {
        if (empty($batchInserts)) return;

        try {
            // Preparar campos para inserción masiva
            $firstItem = $batchInserts[0];
            $baseFields = array_keys($firstItem['data']);

            // Agregar custom_fields si algún elemento lo tiene
            $hasCustomFields = false;
            foreach ($batchInserts as $item) {
                if (!empty($item['custom_fields'])) {
                    $hasCustomFields = true;
                    break;
                }
            }

            if ($hasCustomFields) {
                $baseFields[] = 'custom_fields';
            }

            $placeholders = '(' . str_repeat('?,', count($baseFields) - 1) . '?)';
            $sql = "INSERT INTO contacts (" . implode(', ', $baseFields) . ") VALUES " .
                str_repeat($placeholders . ',', count($batchInserts) - 1) . $placeholders;

            $stmt = $pdo->prepare($sql);
            $values = [];

            foreach ($batchInserts as $item) {
                foreach ($baseFields as $field) {
                    if ($field === 'custom_fields') {
                        $values[] = !empty($item['custom_fields']) ? json_encode($item['custom_fields']) : null;
                    } else {
                        $values[] = $item['data'][$field] ?? null;
                    }
                }
            }

            $stmt->execute($values);

            // Obtener IDs de los contactos insertados
            $lastId = $pdo->lastInsertId();
            for ($i = 0; $i < count($batchInserts); $i++) {
                $contactId = $lastId + $i;
                $newContactIds[] = $contactId;
                $allContactIds[] = $contactId; // Agregar a la lista de todos los contactos
            }

            $imported += count($batchInserts);
        } catch (Exception $e) {
            // Si falla el lote, procesar uno por uno
            foreach ($batchInserts as $item) {
                $this->processSingleInsert($pdo, $item, $imported, $errors, $newContactIds, $allContactIds);
            }
        }
    }

    private function processSingleInsert($pdo, $item, &$imported, &$errors, &$newContactIds, &$allContactIds)
    {
        try {
            $insertFields = array_keys($item['data']);
            $insertValues = array_values($item['data']);

            if (!empty($item['custom_fields'])) {
                $insertFields[] = 'custom_fields';
                $insertValues[] = json_encode($item['custom_fields']);
            }

            $placeholders = str_repeat('?,', count($insertFields) - 1) . '?';
            $sql = "INSERT INTO contacts (" . implode(', ', $insertFields) . ") VALUES ($placeholders)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($insertValues);

            $contactId = $pdo->lastInsertId();
            $newContactIds[] = $contactId;
            $allContactIds[] = $contactId; // Agregar a la lista de todos los contactos
            $imported++;
        } catch (Exception $e) {
            $errors[] = "Fila {$item['row']}: Error al insertar: " . $e->getMessage();
        }
    }

    private function processBatchUpdates($pdo, $batchUpdates, &$updated, &$errors, $customFieldsStmt, &$allContactIds)
    {
        foreach ($batchUpdates as $item) {
            try {
                $updateFields = [];
                $updateValues = [];

                foreach ($item['data'] as $field => $value) {
                    if ($field !== 'status') {
                        $updateFields[] = "$field = ?";
                        $updateValues[] = $value;
                    }
                }

                if (!empty($item['custom_fields'])) {
                    $customFieldsStmt->execute([$item['id']]);
                    $existingCustomFields = $customFieldsStmt->fetchColumn();

                    $mergedCustomFields = [];
                    if ($existingCustomFields) {
                        $mergedCustomFields = json_decode($existingCustomFields, true) ?: [];
                    }

                    $mergedCustomFields = array_merge($mergedCustomFields, $item['custom_fields']);
                    $updateFields[] = "custom_fields = ?";
                    $updateValues[] = json_encode($mergedCustomFields);
                }

                if (!empty($updateFields)) {
                    $updateValues[] = $item['id'];
                    $sql = "UPDATE contacts SET " . implode(', ', $updateFields) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($updateValues);
                    $updated++;

                    // Agregar el ID del contacto actualizado a la lista de todos los contactos
                    $allContactIds[] = $item['id'];
                }
            } catch (Exception $e) {
                $errors[] = "Fila {$item['row']}: Error al actualizar: " . $e->getMessage();
            }
        }
    }

    // Nuevo método para asignar contactos a listas de forma optimizada y por lotes
    private function assignContactsToLists($pdo, $listIds, $allContactIds)
    {
        if (empty($listIds) || empty($allContactIds)) return;

        try {
            // Verificar que las listas existen
            $validListIds = [];
            $listCheckStmt = $pdo->prepare("SELECT id FROM contact_lists WHERE id = ?");

            foreach ($listIds as $listId) {
                $listCheckStmt->execute([$listId]);
                if ($listCheckStmt->fetch()) {
                    $validListIds[] = $listId;
                }
            }

            if (empty($validListIds)) return;

            // Procesar por lotes para evitar el límite de placeholders
            $batchSize = 1000; // Lotes de 1000 asignaciones por vez
            $insertStmt = $pdo->prepare("INSERT IGNORE INTO contact_list_members (list_id, contact_id) VALUES (?, ?)");

            $totalAssignments = 0;
            $batchInsertValues = [];
            $batchPlaceholders = [];

            foreach ($validListIds as $listId) {
                foreach ($allContactIds as $contactId) {
                    $batchInsertValues[] = $listId;
                    $batchInsertValues[] = $contactId;
                    $batchPlaceholders[] = '(?, ?)';
                    $totalAssignments++;

                    // Cuando llegamos al tamaño del lote, ejecutar la inserción
                    if ($totalAssignments >= $batchSize) {
                        $this->executeBatchListAssignment($pdo, $batchInsertValues, $batchPlaceholders);

                        // Resetear arrays para el siguiente lote
                        $batchInsertValues = [];
                        $batchPlaceholders = [];
                        $totalAssignments = 0;
                    }
                }
            }

            // Ejecutar el último lote si queda algo
            if ($totalAssignments > 0) {
                $this->executeBatchListAssignment($pdo, $batchInsertValues, $batchPlaceholders);
            }
        } catch (Exception $e) {
            // Log error but don't stop the process
            error_log("Error asignando contactos a listas: " . $e->getMessage());
        }
    }

    // Método auxiliar para ejecutar la inserción por lotes
    private function executeBatchListAssignment($pdo, $insertValues, $placeholders)
    {
        try {
            if (!empty($placeholders)) {
                $sql = "INSERT IGNORE INTO contact_list_members (list_id, contact_id) VALUES " .
                    implode(', ', $placeholders);

                $stmt = $pdo->prepare($sql);
                $stmt->execute($insertValues);
            }
        } catch (Exception $e) {
            // Si falla el lote, intentar uno por uno
            $this->fallbackSingleListAssignment($pdo, $insertValues);
        }
    }

    // Método de fallback para inserción individual
    private function fallbackSingleListAssignment($pdo, $insertValues)
    {
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO contact_list_members (list_id, contact_id) VALUES (?, ?)");

            // Los valores vienen en pares: [listId, contactId, listId, contactId, ...]
            for ($i = 0; $i < count($insertValues); $i += 2) {
                if (isset($insertValues[$i + 1])) {
                    $stmt->execute([$insertValues[$i], $insertValues[$i + 1]]);
                }
            }
        } catch (Exception $e) {
            error_log("Error en fallback de asignación de listas: " . $e->getMessage());
        }
    }

    // Función para limpiar archivo CSV eliminando todas las comillas dobles
    private function cleanCSVFile($filePath)
    {
        $content = file_get_contents($filePath);

        // Eliminar todas las comillas dobles y los slashes/ los cambia por guiones
        $cleanedContent = str_replace('"', '', $content);
        $cleanedContent2 = str_replace('/', '-', $cleanedContent);
        // Crear archivo temporal limpio
        $tempFile = tempnam(sys_get_temp_dir(), 'cleaned_csv_');
        file_put_contents($tempFile, $cleanedContent2);

        return $tempFile;
    }

    // Método auxiliar para mapear estados
    private function mapStatus($status)
    {
        $statusMap = [
            'activo' => 'active',
            'inactivo' => 'inactive',
            'eliminado' => 'deleted',
            'desuscrito' => 'unsubscribed'
        ];

        $status = strtolower(trim($status));
        return isset($statusMap[$status]) ? $statusMap[$status] : 'active';
    }


    // Métodos para gestionar listas de contactos
    private function handleContactLists($method, $id)
    {
        switch ($method) {
            case 'GET':
                if ($id) {
                    $this->getContactList($id);
                } else {
                    $this->getContactLists();
                }
                break;
            case 'POST':
                $this->createContactList();
                break;
            case 'PUT':
                $this->updateContactList($id);
                break;
            case 'DELETE':
                $this->deleteContactList($id);
                break;
            default:
                $this->sendError(405, 'Method not allowed');
        }
    }

    private function getContactLists()
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->query("SELECT * FROM contact_lists ORDER BY created_at DESC");
        $lists = $stmt->fetchAll();
        $this->sendResponse($lists);
    }

    private function getContactList($id)
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM contact_lists WHERE id = ?");
        $stmt->execute([$id]);
        $list = $stmt->fetch();
        if (!$list) {
            $this->sendError(404, 'List not found');
        }
        $this->sendResponse($list);
    }

    private function createContactList()
    {
        $data = $this->getJsonInput();
        $required = ['name'];
        $this->validateRequiredFields($data, $required);

        $pdo = $this->getConnection();
        $stmt = $pdo->prepare("INSERT INTO contact_lists (name, description) VALUES (?, ?)");
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null
        ]);
        $listId = $pdo->lastInsertId();
        $this->sendResponse(['id' => $listId, 'message' => 'List created successfully']);
    }

    private function updateContactList($id)
    {
        if (!$id) {
            $this->sendError(400, 'List ID required');
        }
        $data = $this->getJsonInput();
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare("UPDATE contact_lists SET name = ?, description = ? WHERE id = ?");
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $id
        ]);
        $this->sendResponse(['message' => 'List updated successfully']);
    }

    private function deleteContactList($id)
    {
        if (!$id) {
            $this->sendError(400, 'List ID required');
        }
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare("DELETE FROM contact_lists WHERE id = ?");
        $stmt->execute([$id]);
        // Opcional: eliminar miembros de la lista también
        $pdo->prepare("DELETE FROM contact_list_members WHERE list_id = ?")->execute([$id]);
        $this->sendResponse(['message' => 'List deleted successfully']);
    }

    // Métodos para gestionar miembros de listas
    private function handleContactListMembers($method, $id)
    {
        switch ($method) {
            case 'GET':
                $this->getContactListMembers();
                break;
            case 'POST':
                $this->addContactToList();
                break;
            case 'DELETE':
                $this->removeContactFromList();
                break;
            default:
                $this->sendError(405, 'Method not allowed');
        }
    }


    private function addContactToList()
    {
        $data = $this->getJsonInput();
        if (empty($data['list_id']) || empty($data['contact_ids']) || !is_array($data['contact_ids'])) {
            $this->sendError(400, 'list_id and contact_ids are required');
        }

        $pdo = $this->getConnection();
        $inserted = 0;
        $errors = [];

        $stmtCheck = $pdo->prepare("SELECT id FROM contact_list_members WHERE list_id = ? AND contact_id = ?");
        $stmtInsert = $pdo->prepare("INSERT INTO contact_list_members (list_id, contact_id) VALUES (?, ?)");

        foreach ($data['contact_ids'] as $contactId) {
            // Verifica que el contacto exista
            $stmtContact = $pdo->prepare("SELECT id FROM contacts WHERE id = ?");
            $stmtContact->execute([$contactId]);
            if (!$stmtContact->fetch()) {
                $errors[] = "Contacto no existe: $contactId";
                continue;
            }

            // Verifica si ya existe la relación
            $stmtCheck->execute([$data['list_id'], $contactId]);
            if ($stmtCheck->fetch()) {
                $errors[] = "Contacto $contactId ya está en la lista";
                continue;
            }

            try {
                $stmtInsert->execute([$data['list_id'], $contactId]);
                $inserted++;
            } catch (Exception $e) {
                $errors[] = "Error al agregar contacto $contactId: " . $e->getMessage();
            }
        }

        $this->sendResponse([
            'message' => "$inserted contactos agregados a la lista",
            'errors' => $errors
        ]);
    }

    private function removeContactFromList()
    {
        $data = $this->getJsonInput();
        if (empty($data['list_id']) || empty($data['contact_id'])) {
            $this->sendError(400, 'list_id and contact_id are required');
        }

        $pdo = $this->getConnection();
        $stmt = $pdo->prepare("DELETE FROM contact_list_members WHERE list_id = ? AND contact_id = ?");
        $stmt->execute([$data['list_id'], $data['contact_id']]);

        $this->sendResponse(['message' => 'Contact removed from list']);
    }


    private function getContactListMembers()
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->query("SELECT * FROM contact_list_members");
        $members = $stmt->fetchAll();
        $this->sendResponse($members);
    }

    // Campaign methods
    private function getCampaignsOLD()
    {
        $pdo = $this->getConnection();
        // Consulta SQL mejorada para incluir estadísticas
        $stmt = $pdo->query("
        SELECT 
            c.*, 
            s.name as sender_name, 
            s.email as sender_email,
            
            -- Contar cuántos correos se enviaron con éxito para esta campaña
            (SELECT COUNT(*) 
             FROM campaign_recipients cr 
             WHERE cr.campaign_id = c.id AND cr.status = 'sent') AS total_sent,
            
            -- Contar cuántos contactos únicos abrieron el correo para esta campaña
            (SELECT COUNT(DISTINCT ee.contact_id) 
             FROM email_events ee 
             WHERE ee.campaign_id = c.id AND ee.event_type = 'opened') AS total_opened,
            
             -- Contar cuántos contactos únicos hicieron clic para esta campaña
            (SELECT COUNT(DISTINCT ee.contact_id) 
             FROM email_events ee 
             WHERE ee.campaign_id = c.id AND ee.event_type = 'clicked') AS total_clicked

        FROM campaigns c 
        LEFT JOIN senders s ON c.sender_id = s.id 
        ORDER BY c.created_at DESC
    ");
        $campaigns = $stmt->fetchAll();

        // Iterar sobre los resultados para calcular los porcentajes de forma segura
        foreach ($campaigns as &$campaign) { // Usamos '&' para modificar el array directamente
            if ($campaign['total_sent'] > 0) {
                // Calcular porcentaje de apertura
                $campaign['open_rate'] = round(($campaign['total_opened'] / $campaign['total_sent']) * 100, 2);
                // Calcular porcentaje de clics
                $campaign['click_rate'] = round(($campaign['total_clicked'] / $campaign['total_sent']) * 100, 2);
            } else {
                // Evitar división por cero si no se han enviado correos
                $campaign['open_rate'] = 0;
                $campaign['click_rate'] = 0;
            }
        }
        unset($campaign); // Buena práctica: eliminar la referencia después del bucle

        $this->sendResponse($campaigns);
    }

    private function getCampaigns()
    {
        $pdo = $this->getConnection();
        // Consulta SQL corregida para calcular correctamente las estadísticas
        $stmt = $pdo->query("
    SELECT 
        c.*, 
        s.name as sender_name, 
        s.email as sender_email,
        
        -- Total de correos enviados con éxito (base para apertura, clic, etc.)
        (SELECT COUNT(*) FROM campaign_recipients cr WHERE cr.campaign_id = c.id AND cr.status = 'sent') AS total_sent,
        
        -- Contadores de fallos y rebotes
        (SELECT COUNT(*) FROM campaign_recipients cr WHERE cr.campaign_id = c.id AND cr.status = 'bounced') AS total_bounced,
        (SELECT COUNT(*) FROM campaign_recipients cr WHERE cr.campaign_id = c.id AND cr.status = 'failed') AS total_failed,
        
        -- Contadores de interacción (solo sobre emails enviados exitosamente)
        (SELECT COUNT(DISTINCT ee.contact_id) FROM email_events ee WHERE ee.campaign_id = c.id AND ee.event_type = 'opened') AS total_opened,
        (SELECT COUNT(DISTINCT ee.contact_id) FROM email_events ee WHERE ee.campaign_id = c.id AND ee.event_type = 'clicked') AS total_clicked

    FROM campaigns c 
    LEFT JOIN senders s ON c.sender_id = s.id 
    ORDER BY c.created_at DESC
    ");
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Iterar sobre los resultados para calcular los porcentajes correctamente
        foreach ($campaigns as &$campaign) {
            // Calcular el total de intentos reales: sent + bounced + failed
            $campaign['total_attempts'] = $campaign['total_sent'] + $campaign['total_bounced'] + $campaign['total_failed'];
            $campaign['total_attempts_2'] = $campaign['total_sent'] + $campaign['total_bounced'];

            // Tasas basadas en correos enviados exitosamente (para apertura y clic)
            if ($campaign['total_sent'] > 0) {
                $campaign['open_rate'] = round(($campaign['total_opened'] / $campaign['total_sent']) * 100, 2);
                $campaign['click_rate'] = round(($campaign['total_clicked'] / $campaign['total_sent']) * 100, 2);
            } else {
                $campaign['open_rate'] = 0;
                $campaign['click_rate'] = 0;
            }

            // Tasas de rebote y fallo basadas en el total de intentos reales
            if ($campaign['total_attempts'] > 0) {
                $campaign['bounce_rate'] = round(($campaign['total_bounced'] / $campaign['total_attempts_2']) * 100, 2);
                $campaign['failure_rate'] = round(($campaign['total_failed'] / $campaign['total_attempts_2']) * 100, 2);
            } else {
                $campaign['bounce_rate'] = 0;
                $campaign['failure_rate'] = 0;
            }

            // Opcional: Calcular tasa de éxito
            if ($campaign['total_attempts'] > 0) {
                $campaign['success_rate'] = round(($campaign['total_sent'] / $campaign['total_attempts']) * 100, 2);
            } else {
                $campaign['success_rate'] = 0;
            }
        }
        unset($campaign);

        $this->sendResponse($campaigns);
    }

    private function getCampaign($id)
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare("
            SELECT c.*, s.name as sender_name, s.email as sender_email 
            FROM campaigns c 
            LEFT JOIN senders s ON c.sender_id = s.id 
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $campaign = $stmt->fetch();

        if (!$campaign) {
            $this->sendError(404, 'Campaign not found');
        }

        $this->sendResponse($campaign);
    }

    private function createCampaign()
    {
        $data = $this->getJsonInput();

        $required = ['name', 'subject', 'html_content', 'sender_id'];
        $this->validateRequiredFields($data, $required);

        $pdo = $this->getConnection();

        // Validate sender exists
        $stmt = $pdo->prepare("SELECT id FROM senders WHERE id = ? AND is_active = 1");
        $stmt->execute([$data['sender_id']]);
        if (!$stmt->fetch()) {
            $this->sendError(400, 'Invalid sender');
        }

        $stmt = $pdo->prepare("
            INSERT INTO campaigns (name, subject, html_content, text_content, sender_id, template_id, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['name'],
            $data['subject'],
            $data['html_content'],
            $data['text_content'] ?? strip_tags($data['html_content']),
            $data['sender_id'],
            $data['template_id'] ?? null,
            $data['status'] ?? 'draft'
        ]);

        $campaignId = $pdo->lastInsertId();
        $this->sendResponse(['id' => $campaignId, 'message' => 'Campaign created successfully']);
    }

    private function updateCampaign($id)
    {
        if (!$id) {
            $this->sendError(400, 'Campaign ID required');
        }

        $data = $this->getJsonInput();

        $pdo = $this->getConnection();

        // Validate sender exists if sender_id is provided
        if (isset($data['sender_id'])) {
            $stmt = $pdo->prepare("SELECT id FROM senders WHERE id = ? AND is_active = 1");
            $stmt->execute([$data['sender_id']]);
            if (!$stmt->fetch()) {
                $this->sendError(400, 'Invalid sender');
            }
        }

        $stmt = $pdo->prepare("
            UPDATE campaigns SET 
                name = ?, 
                subject = ?, 
                html_content = ?, 
                text_content = ?, 
                sender_id = ?, 
                template_id = ?, 
                status = ?, 
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmt->execute([
            $data['name'],
            $data['subject'],
            $data['html_content'],
            $data['text_content'] ?? strip_tags($data['html_content']),
            $data['sender_id'],
            $data['template_id'] ?? null,
            $data['status'] ?? 'draft',
            $id
        ]);

        $this->sendResponse(['message' => 'Campaign updated successfully']);
    }
    /**
     * Pone en pausa una campaña que se está enviando.
     */
    private function pauseCampaign($id)
    {
        if (empty($id)) {
            $this->sendError(400, 'Se requiere el ID de la campaña.');
        }
        $pdo = $this->getConnection();
        // Solo se pueden pausar campañas que estén en estado 'sending'
        $stmt = $pdo->prepare("UPDATE campaigns SET status = 'paused' WHERE id = ? AND status = 'sending'");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            $this->sendResponse('Campaña pausada correctamente.');
        } else {
            $this->sendError(404, 'No se pudo pausar la campaña. Puede que ya no esté en estado de envío.');
        }
    }

    /**
     * Reanuda una campaña que estaba en pausa.
     */
    private function resumeCampaign($id)
    {
        if (empty($id)) {
            $this->sendError(400, 'Se requiere el ID de la campaña.');
        }
        $pdo = $this->getConnection();
        // Solo se pueden reanudar campañas que estén en estado 'paused'
        $stmt = $pdo->prepare("UPDATE campaigns SET status = 'sending' WHERE id = ? AND status = 'paused'");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            $this->sendResponse('Campaña reanudada. El envío continuará en el próximo ciclo del cron.');
        } else {
            $this->sendError(404, 'No se pudo reanudar la campaña. Puede que no estuviera en pausa.');
        }
    }

    /**
     * Cancela una campaña que está en envío o en pausa.
     */
    private function cancelCampaign($id)
    {
        if (empty($id)) {
            $this->sendError(400, 'Se requiere el ID de la campaña.');
        }
        $pdo = $this->getConnection();
        // Se pueden cancelar campañas en envío o en pausa
        $stmt = $pdo->prepare("UPDATE campaigns SET status = 'canceled' WHERE id = ? AND status IN ('sending', 'paused')");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            $this->sendResponse('Campaña cancelada correctamente.');
        } else {
            $this->sendError(404, 'No se pudo cancelar la campaña. Puede que ya haya sido enviada o cancelada.');
        }
    }


    private function handleSendTest()
    {
        $data = $this->getJsonInput();

        $required = ['sender_id', 'subject', 'html_content', 'test_email'];
        $this->validateRequiredFields($data, $required);

        $pdo = $this->getConnection();

        // Obtener datos del remitente
        $stmt = $pdo->prepare("SELECT * FROM senders WHERE id = ? AND is_active = 1");
        $stmt->execute([$data['sender_id']]);
        $sender = $stmt->fetch();

        if (!$sender) {
            $this->sendError(400, 'Remitente inválido o inactivo.');
        }

        $mail = new PHPMailer(true);

        try {
            // --- CONFIGURACIÓN SMTP (como ya la tenías) ---
            $mail->isSMTP();
            $mail->Host = $sender['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $sender['smtp_username'];
            $mail->Password = $sender['smtp_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = 465;

            // --- AJUSTES PARA PROBAR IMÁGENES EMBEBIDAS ---

            // 1. Configuración base de PHPMailer
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);

            // 2. Incrusta las imágenes desde tu ruta local usando __DIR__
            //    Esto asegura que la ruta siempre sea correcta sin importar desde dónde se ejecute el script.
            // $mail->addEmbeddedImage(__DIR__ . '/uploads/header.jpg', 'header_cid');
            // $mail->addEmbeddedImage(__DIR__ . '/uploads/about.png', 'about_cid');
            // $mail->addEmbeddedImage(__DIR__ . '/uploads/bg_1.jpg', 'counter_cid');

            // --- CONTENIDO DEL CORREO ---
            // El tracking pixel se puede mantener, no interfiere.
            $trackingPixel = '<img src="https://marketing.dom0125.com/track/open/24/1794" width="1" height="1" style="display:none;"/>';

            // Personalización de variables
            $variables = [
                '{{name}}'  => isset($data['name']) ? $data['name'] : 'Odelkys',
                '{{email}}' => isset($data['email']) ? $data['email'] : $data['test_email'],
                '{{TRACK_LINK}}' => "https://dom0125.com",
                '{{giro}}' => "Venta de dulces y golosinas",
                '{{sitio_web}}' => "https://dom0125.com",
                '{{nombre_comercial}}' => "Golosinas odelkys",
                '{{departamento}}' => "Lima"

            ];


            // $personalizedSubject = str_replace(array_keys($variables), array_values($variables), $data['subject']);
            $personalizedSubject = $this->parseDynamicTemplate($data['subject'], $variables);
            //$personalizedHtml = str_replace(array_keys($variables), array_values($variables), $data['html_content']);

            $personalizedHtml = $this->parseDynamicTemplate($data['html_content'], $variables);

            // --- ARMADO Y ENVÍO DEL CORREO ---
            $mail->setFrom($sender['email'], $sender['name']);
            $mail->addAddress($data['test_email']);
            $mail->Subject = $personalizedSubject;
            $mail->Body = $personalizedHtml . $trackingPixel;

            $mail->send();

            $this->sendResponse(['success' => true, 'message' => 'Correo de prueba enviado correctamente con imágenes incrustadas.']);
        } catch (Exception $e) {
            error_log('PHPMailer Error en Test: ' . $mail->ErrorInfo);
            $this->sendError(500, 'No se pudo enviar el correo de prueba: ' . $mail->ErrorInfo);
        }
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
    private function parseDynamicTemplate($content, $variables)
    {
        // --- ETAPA 1: PROCESAR LA LÓGICA CONDICIONAL PRIMERO ---
        // Se procesan los bloques [SI...] de forma recursiva para resolver la estructura.
        $pattern = '/\[SI\s+(.*?)\s*\](.*?)(\[SINO\](.*?))?\s*\[FIN\s+SI\]/s';

        // Usamos una iteración para resolver condiciones anidadas de forma segura
        while (preg_match($pattern, $content)) {
            $content = preg_replace_callback($pattern, function ($matches) use ($variables) {
                $condition = trim($matches[1]);
                $ifContent = $matches[2];
                $elseContent = isset($matches[4]) ? $matches[4] : '';

                $parts = explode(' ', $condition, 3);
                $key = '{{' . $parts[0] . '}}';
                $operator = isset($parts[1]) ? strtoupper($parts[1]) : 'EXISTE';
                $value = isset($parts[2]) ? $parts[2] : null;

                $isConditionMet = false;

                // Lógica para evaluar la condición (sin cambios)
                switch ($operator) {
                    case 'EXISTE':
                        $isConditionMet = isset($variables[$key]) && !empty(trim($variables[$key]));
                        break;
                    case 'NO':
                        if (isset($parts[2]) && strtoupper($parts[2]) === 'EXISTE') {
                            $isConditionMet = !isset($variables[$key]) || empty(trim($variables[$key]));
                        }
                        break;
                    case '=':
                    case '==':
                        $isConditionMet = isset($variables[$key]) && strtolower(trim($variables[$key])) == strtolower($value);
                        break;
                    case '!=':
                        $isConditionMet = !isset($variables[$key]) || strtolower(trim($variables[$key])) != strtolower($value);
                        break;
                    case 'CONTIENE':
                        $isConditionMet = isset($variables[$key]) && stripos($variables[$key], $value) !== false;
                        break;
                }

                // Devolvemos el bloque de texto correspondiente SIN procesar recursivamente aquí.
                // El bucle while se encargará de las capas anidadas.
                return $isConditionMet ? $ifContent : $elseContent;
            }, $content);
        }

        // --- ETAPA 2: PROCESAR GÉNERO ---
        $genderKey = '{{sexo}}';
        $gender = isset($variables[$genderKey]) ? strtolower(trim($variables[$genderKey])) : 'masculino';

        $content = preg_replace_callback('/\[GENDER:([^|]+)\|([^]]+)\]/', function ($matches) use ($gender) {
            $masculine = $matches[1];
            $feminine = $matches[2];
            return ($gender == 'femenino') ? $feminine : $masculine;
        }, $content);

        // --- ETAPA 3: REEMPLAZAR VARIABLES SIMPLES AL FINAL ---
        // Ahora que solo queda el texto correcto, reemplazamos las variables.
        $content = str_replace(array_keys($variables), array_values($variables), $content);

        // --- ETAPA 4: LIMPIEZA FINAL ---
        // Opcional: Remover cualquier variable {{...}} que no tuviera valor.
        $content = preg_replace('/\{\{[^}]+\}\}/', '', $content);

        return $content;
    }

    //Crea y pone la campaña en estado enviandose para que el CRON envie poco a poco los 
    //correos y evitar que se consideren spam o se bloquee el envio cuando son miles de correos
    private function handleSendCampaign($method)
    {
        if ($method != 'POST')
            return;

        $data = $this->getJsonInput();

        // Validar campos requeridos para crear la campaña
        $required = ['name', 'subject', 'html_content', 'sender_id'];
        $this->validateRequiredFields($data, $required);

        $pdo = $this->getConnection();

        // Validar que el remitente existe y está activo
        $stmt = $pdo->prepare("SELECT * FROM senders WHERE id = ? AND is_active = 1");
        $stmt->execute([$data['sender_id']]);
        $sender = $stmt->fetch();
        if (!$sender) {
            $this->sendError(400, 'Invalid sender');
        }

        // Crear la campaña en estado 'draft'
        $stmt = $pdo->prepare("
        INSERT INTO campaigns (name, subject, html_content, text_content, sender_id, template_id, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
        $stmt->execute([
            $data['name'],
            $data['subject'],
            $data['html_content'],
            $data['text_content'] ?? strip_tags($data['html_content']),
            $data['sender_id'],
            $data['template_id'] ?? null,
            'draft'
        ]);
        $campaignId = $pdo->lastInsertId();

        // Obtener contactos destinatarios
        if (!empty($data['list_ids']) && is_array($data['list_ids'])) {
            // Solo contactos activos de las listas seleccionadas
            $in = str_repeat('?,', count($data['list_ids']) - 1) . '?';
            $sql = "SELECT DISTINCT c.* 
                FROM contacts c
                JOIN contact_list_members clm ON c.id = clm.contact_id
                WHERE clm.list_id IN ($in) AND c.status = 'active'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($data['list_ids']);
            $contacts = $stmt->fetchAll();
        } else {
            // Todos los contactos activos
            $stmt = $pdo->prepare("SELECT * FROM contacts WHERE status = 'active'");
            $stmt->execute();
            $contacts = $stmt->fetchAll();
        }

        if (empty($contacts)) {
            $this->sendError(400, 'No active contacts found for this campaign');
        }

        // Actualizar estado de la campaña
        $stmt = $pdo->prepare("
        UPDATE campaigns 
        SET status = 'sending', total_recipients = ?, sent_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
        $stmt->execute([count($contacts), $campaignId]);

        // Registrar destinatarios en campaign_recipients
        $stmtRecipient = $pdo->prepare("INSERT INTO campaign_recipients (campaign_id, contact_id, status) VALUES (?, ?, 'pending')");
        foreach ($contacts as $contact) {
            try {
                $stmtRecipient->execute([$campaignId, $contact['id']]);
            } catch (Exception $e) {
                // Si ya existe, ignorar
            }
        }

        // Aquí puedes iniciar el proceso de envío real (por cron o en background)
        // Por ahora solo responde que la campaña fue creada y puesta en cola para envío

        $this->sendResponse([
            'id' => $campaignId,
            'message' => 'Campaign created and scheduled for sending',
            'total_recipients' => count($contacts)
        ]);
    }


    /**
     * Calcula y devuelve las estadísticas generales del dashboard.
     * Esta versión calcula los promedios directamente desde los datos de eventos
     * para asegurar la precisión en tiempo real.
     */
    private function handleStats()
    {
        $pdo = $this->getConnection();
        $stats = [];

        // 1. Total de campañas (sin cambios)
        $stmt = $pdo->query("SELECT COUNT(*) FROM campaigns");
        $stats['total_campaigns'] = (int) $stmt->fetchColumn();

        // 2. Total de contactos activos (sin cambios)
        $stmt = $pdo->query("SELECT COUNT(*) FROM contacts WHERE status = 'active'");
        $stats['total_contacts'] = (int) $stmt->fetchColumn();

        // --- LÓGICA DE CÁLCULO DE TASAS CORREGIDA ---

        // 3. Contar el número total de correos enviados con éxito en TODAS las campañas
        $stmt = $pdo->query("SELECT COUNT(*) FROM campaign_recipients WHERE status = 'sent'");
        $total_sent = (int) $stmt->fetchColumn();

        // 4. Contar el número total de eventos de apertura (ya filtrados por tu script)
        $stmt = $pdo->query("SELECT COUNT(*) FROM email_events WHERE event_type = 'opened'");
        $total_opened = (int) $stmt->fetchColumn();

        // 5. Contar el número total de eventos de clic
        $stmt = $pdo->query("SELECT COUNT(*) FROM email_events WHERE event_type = 'clicked'");
        $total_clicked = (int) $stmt->fetchColumn();

        // 6. Calcular los promedios generales y redondear
        // Se comprueba que total_sent sea mayor que 0 para evitar errores de división por cero.
        if ($total_sent > 0) {
            $stats['avg_open_rate'] = round(($total_opened / $total_sent) * 100, 2);
            $stats['avg_click_rate'] = round(($total_clicked / $total_sent) * 100, 2);
        } else {
            // Si no se han enviado correos, las tasas son 0.
            $stats['avg_open_rate'] = 0;
            $stats['avg_click_rate'] = 0;
        }

        $this->sendResponse($stats);
    }

    private function testSmtpConnection($config)
    {
        $mail = new PHPMailer(true);
        try {
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



            try {
                $mail->send();
                $this->sendResponse(['success' => true, 'message' => 'Correo enviado correctamente']);
            } catch (Exception $e) {
                $this->sendError(500, 'Error al enviar correo: ' . $mail->ErrorInfo);
            }
            $mail->SMTPDebug = 2; // o 3 para más detalles
            $mail->Debugoutput = function ($str, $level) {
                error_log("SMTP [$level]: $str");
            };
        } catch (Exception $e) {
            return false;
        }
    }

    private function encryptPassword($password)
    {
        return base64_encode($password); // In production, use proper encryption
    }

    private function decryptPassword($encryptedPassword)
    {
        return base64_decode($encryptedPassword); // In production, use proper decryption
    }

    private function getJsonInput()
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError(400, 'Invalid JSON input');
        }

        return $data ?: [];
    }

    private function validateRequiredFields($data, $required)
    {
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $this->sendError(400, "Required field missing: $field");
            }
        }
    }

    private function sendResponse($data, $status = 200)
    {
        http_response_code($status);
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        exit;
    }

    private function sendError($status, $message)
    {
        http_response_code($status);
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
        exit;
    }
}

// Initialize and handle the request
$api = new EmailMarketingAPI();
$api->handleRequest();
