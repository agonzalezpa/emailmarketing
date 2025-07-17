<?php
require_once 'sunat.php';
require_once 'sunat_priority_config.php';

// Configuración de base de datos
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'u750684196_email_marketin');
    define('DB_USER', 'u750684196_info');
    define('DB_PASS', 'Olivera19%');
}

class SunatCron
{
    private $db;
    private $scraper;
    private $priorityConfig;
    private $logFile;

    public function __construct()
    {
        $this->initializeDatabase();
        $this->scraper = new SunatScraper();
        $this->priorityConfig = new SunatPriorityConfig();
        $this->logFile = __DIR__ . '/logs/sunat_cron_' . date('Y-m-d') . '.log';
        $this->createLogDirectory();
    }

    private function initializeDatabase()
    {
        try {
            $this->db = new PDO(
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
            $this->log("ERROR: Conexión a base de datos fallida: " . $e->getMessage());
            throw $e;
        }
    }

    private function createLogDirectory()
    {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    private function log($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        echo $logMessage;
    }

    /**
     * Obtiene contactos prioritarios
     */
    private function getPriorityContacts($limit = 50)
    {
        $config = $this->priorityConfig->getConfig();
        $conditions = [];
        $params = [];

        // Condición para listas prioritarias
        if (!empty($config['priority_contact_lists'])) {
            $listPlaceholders = str_repeat('?,', count($config['priority_contact_lists']) - 1) . '?';
            $conditions[] = "c.id IN (
                SELECT clm.contact_id 
                FROM contact_list_members clm 
                WHERE clm.list_id IN ($listPlaceholders)
            )";
            $params = array_merge($params, $config['priority_contact_lists']);
        }

        // Condición para emails prioritarios
        if (!empty($config['priority_emails'])) {
            $emailPlaceholders = str_repeat('?,', count($config['priority_emails']) - 1) . '?';
            $conditions[] = "c.email IN ($emailPlaceholders)";
            $params = array_merge($params, $config['priority_emails']);
        }

        if (empty($conditions)) {
            return [];
        }

        $whereClause = implode(' OR ', $conditions);

        $sql = "
            SELECT c.id, c.name, c.email, c.custom_fields 
            FROM contacts c
            WHERE c.status = 'active' 
            AND JSON_EXTRACT(c.custom_fields, '$.country') = 'Peru'
            AND (
                JSON_EXTRACT(c.custom_fields, '$.sunat_updated') IS NULL 
                OR JSON_EXTRACT(c.custom_fields, '$.sunat_updated') = 0
            )
            AND ($whereClause)
            ORDER BY c.created_at ASC
            LIMIT ?
        ";

        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Obtiene contactos regulares (no prioritarios)
     */
    private function getRegularContacts($limit = 30)
    {
        $config = $this->priorityConfig->getConfig();
        $conditions = [];
        $params = [];

        // Excluir listas prioritarias
        if (!empty($config['priority_contact_lists'])) {
            $listPlaceholders = str_repeat('?,', count($config['priority_contact_lists']) - 1) . '?';
            $conditions[] = "c.id NOT IN (
                SELECT clm.contact_id 
                FROM contact_list_members clm 
                WHERE clm.list_id IN ($listPlaceholders)
            )";
            $params = array_merge($params, $config['priority_contact_lists']);
        }

        // Excluir emails prioritarios
        if (!empty($config['priority_emails'])) {
            $emailPlaceholders = str_repeat('?,', count($config['priority_emails']) - 1) . '?';
            $conditions[] = "c.email NOT IN ($emailPlaceholders)";
            $params = array_merge($params, $config['priority_emails']);
        }

        $whereClause = '';
        if (!empty($conditions)) {
            $whereClause = 'AND (' . implode(' AND ', $conditions) . ')';
        }

        $sql = "
            SELECT c.id, c.name, c.email, c.custom_fields 
            FROM contacts c
            WHERE c.status = 'active' 
            AND JSON_EXTRACT(c.custom_fields, '$.country') = 'Peru'
            AND (
                JSON_EXTRACT(c.custom_fields, '$.sunat_updated') IS NULL 
                OR JSON_EXTRACT(c.custom_fields, '$.sunat_updated') = 0
            )
            $whereClause
            ORDER BY c.created_at ASC
            LIMIT ?
        ";

        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Procesa un contacto (busca por RUC o razón social)
     */
    /**
     * Procesa un contacto (busca por RUC o razón social)
     */
    private function processContact($contact)
    {
        $customFields = json_decode($contact['custom_fields'], true);

        // Intentar buscar por RUC si existe
        if (!empty($customFields['ruc'])) {
            $this->log("Procesando por RUC: {$customFields['ruc']} - {$contact['name']}");
            $result = $this->scraper->buscarPorRuc($customFields['ruc']);

            if ($result['success']) {
                $this->updateContact($contact['id'], $result['data'], $customFields);
                $this->log("ÉXITO - al actualizar los datos del contacto ID: {$contact['id']}");
                return true;
            } else {
                $this->log("ERROR RUC: " . ($result['error'] ?? 'Desconocido'));
            }
        } else {
            // Si no hay RUC, buscar por razón social solo si es de Perú
            if (empty($customFields['country']) || $customFields['country'] === "peru") {

                // Determinar qué usar para la búsqueda
                $searchTerm = '';
                $searchType = '';

                if (!empty($customFields['razon_social'])) {
                    $searchTerm = $customFields['razon_social'];
                    $searchType = 'razón social';
                } elseif (!empty($contact['name'])) {
                    $searchTerm = $contact['name'];
                    $searchType = 'nombre del contacto';
                }

                if (!empty($searchTerm)) {
                    $this->log("Procesando por {$searchType}: {$searchTerm} - {$contact['name']}");
                    $result = $this->scraper->buscarPorRazonSocial($searchTerm);

                    if ($result['success'] && !empty($result['data'])) {
                        $this->log("ÉXITO - {$searchType}: {$searchTerm}");
                        $selectedCompany = $result['data'][0]; // Tomar el primer resultado

                        // Ahora buscar por RUC para obtener toda la información
                        $this->log("Obteniendo información completa del RUC: {$selectedCompany['ruc']}");
                        $detailResult = $this->scraper->buscarPorRuc($selectedCompany['ruc']);

                        if ($detailResult['success']) {
                            $this->updateContact($contact['id'], $detailResult['data'], $customFields);
                            $this->log("ÉXITO - al actualizar los datos del contacto ID: {$contact['id']}");
                            return true;
                        } else {
                            $this->log("ERROR al obtener detalles del RUC {$selectedCompany['ruc']}: " . ($detailResult['error'] ?? 'Desconocido'));
                        }
                    } else {
                        $this->log("ERROR {$searchType}: " . ($result['error'] ?? 'Sin resultados'));
                    }
                } else {
                    $this->log("NOTA: No se pudo hacer la búsqueda en SUNAT porque no tiene ni RUC ni razón social ni nombre para el contacto ID: {$contact['id']}");
                }
            } else {
                $this->log("NOTA: Contacto no es de Perú, omitiendo búsqueda SUNAT para el contacto ID: {$contact['id']}");
            }
        }

        return false;
    }

    /**
     * Actualiza el contacto con datos de SUNAT
     */
    private function updateContactOLD($contactId, $sunatData, $existingFields)
    {
        $updatedFields = array_merge($existingFields, [
            'ruc' => $sunatData['ruc'] ?? $existingFields['ruc'] ?? '',
            'razon_social' => $sunatData['razon_social'] ?? $existingFields['razon_social'] ?? '',
            'direccion' => $sunatData['ubicacion'] ?? $existingFields['direccion'] ?? '',
            'estado_sunat' => $sunatData['estado'] ?? $existingFields['estado_sunat'] ?? '',
            'sunat_updated' => 1,
            'sunat_updated_at' => date('Y-m-d H:i:s'),
            'country' => 'Peru'
        ]);

        try {
            $sql = "UPDATE contacts SET custom_fields = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([json_encode($updatedFields), $contactId]);

            $this->log("Contacto actualizado ID: {$contactId}");
        } catch (PDOException $e) {
            $this->log("ERROR actualizando contacto {$contactId}: " . $e->getMessage());
        }
    }
    // Método actualizado para actualizar contacto con todos los datos
    private function updateContact($contactId, $sunatData, $existingFields)
    {
        // Preparar actividades económicas como string
        $actividadesString = '';
        if (!empty($sunatData['actividades_economicas'])) {
            $actividadesArray = [];
            foreach ($sunatData['actividades_economicas'] as $actividad) {
                $actividadesArray[] = $actividad['tipo'] . ': ' . $actividad['descripcion'];
            }
            $actividadesString = implode(' | ', $actividadesArray);
        }

        // Preparar comprobantes como string
        $comprobantesString = !empty($sunatData['comprobantes_pago']) ?
            implode(', ', $sunatData['comprobantes_pago']) : '';

        // Preparar padrones como string
        $padronesString = !empty($sunatData['padrones']) ?
            implode(', ', $sunatData['padrones']) : '';

        $updatedFields = array_merge($existingFields, [
            // Datos básicos
            'ruc' => $sunatData['ruc'] ?? $existingFields['ruc'] ?? '',
            'razon_social' => $sunatData['razon_social'] ?? $existingFields['razon_social'] ?? '',
            'nombre_comercial' => $sunatData['nombre_comercial'] ?? $existingFields['nombre_comercial'] ?? '',
            'tipo_contribuyente' => $sunatData['tipo_contribuyente'] ?? $existingFields['tipo_contribuyente'] ?? '',

            // Fechas importantes
            'fecha_inscripcion' => $sunatData['fecha_inscripcion'] ?? $existingFields['fecha_inscripcion'] ?? '',
            'fecha_inicio_actividades' => $sunatData['fecha_inicio_actividades'] ?? $existingFields['fecha_inicio_actividades'] ?? '',
            'fecha_baja' => $sunatData['fecha_baja'] ?? $existingFields['fecha_baja'] ?? '',

            // Estados y condiciones
            'estado_sunat' => $sunatData['estado'] ?? $existingFields['estado_sunat'] ?? '',
            'condicion_sunat' => $sunatData['condicion'] ?? $existingFields['condicion_sunat'] ?? '',
            'condicion_observacion' => $sunatData['condicion_observacion'] ?? $existingFields['condicion_observacion'] ?? '',
            'estado_critico' => $sunatData['estado_critico'] ?? false,

            // Ubicación
            'direccion' => $sunatData['domicilio_fiscal'] ?? $existingFields['direccion'] ?? '',
            'domicilio_fiscal' => $sunatData['domicilio_fiscal'] ?? $existingFields['domicilio_fiscal'] ?? '',

            // Actividades económicas (CAMPO MÁS IMPORTANTE)
            'actividades_economicas' => $actividadesString,
            'giro_negocio' => $actividadesString, // También como giro de negocio

            // Sistemas y configuraciones
            'sistema_emision_comprobante' => $sunatData['sistema_emision_comprobante'] ?? $existingFields['sistema_emision_comprobante'] ?? '',
            'sistema_contabilidad' => $sunatData['sistema_contabilidad'] ?? $existingFields['sistema_contabilidad'] ?? '',
            'sistema_emision_electronica' => $sunatData['sistema_emision_electronica'] ?? $existingFields['sistema_emision_electronica'] ?? '',
            'actividad_comercio_exterior' => $sunatData['actividad_comercio_exterior'] ?? $existingFields['actividad_comercio_exterior'] ?? '',

            // Comprobantes y padrones
            'comprobantes_pago' => $comprobantesString,
            'padrones' => $padronesString,
            'comprobantes_electronicos' => !empty($sunatData['comprobantes_electronicos']) ?
                implode(', ', $sunatData['comprobantes_electronicos']) : '',

            // Fechas de emisión electrónica
            'emisor_electronico_desde' => $sunatData['emisor_electronico_desde'] ?? $existingFields['emisor_electronico_desde'] ?? '',
            'afiliado_ple_desde' => $sunatData['afiliado_ple_desde'] ?? $existingFields['afiliado_ple_desde'] ?? '',

            // Metadatos
            'sunat_updated' => 1,
            'sunat_updated_at' => date('Y-m-d H:i:s'),
            'country' => 'Peru'
        ]);

        try {
            $sql = "UPDATE contacts SET custom_fields = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([json_encode($updatedFields), $contactId]);

            $this->log("Contacto actualizado ID: {$contactId} con datos completos del SUNAT");

            // Log de actividades económicas para debug
            if (!empty($actividadesString)) {
                $this->log("Actividades económicas encontradas: " . $actividadesString);
            }
        } catch (PDOException $e) {
            $this->log("ERROR actualizando contacto {$contactId}: " . $e->getMessage());
        }
    }

    /**
     * Ejecuta el cron
     */
    public function run()
    {
        $this->log("=== INICIANDO CRON SUNAT SIMPLIFICADO ===");

        $config = $this->priorityConfig->getConfig();

        if (!$config['enabled']) {
            $this->log("Sistema deshabilitado en configuración");
            return;
        }

        $totalProcessed = 0;
        $successCount = 0;
        $dailyLimit = $config['daily_limit'];

        // Calcular límites
        $priorityLimit = (int)($dailyLimit * ($config['priority_percentage'] / 100));
        $regularLimit = $dailyLimit - $priorityLimit;

        $this->log("Límites: Prioritarios={$priorityLimit}, Regulares={$regularLimit}");

        // Procesar contactos prioritarios
        $priorityContacts = $this->getPriorityContacts($priorityLimit);
        $this->log("Contactos prioritarios encontrados: " . count($priorityContacts));

        foreach ($priorityContacts as $contact) {
            if ($totalProcessed >= $dailyLimit) break;

            if ($this->processContact($contact)) {
                $successCount++;
            }

            $totalProcessed++;

            // Delay entre consultas
            sleep(rand(3, 15));
        }

        // Procesar contactos regulares si aún hay límite
        if ($totalProcessed < $dailyLimit) {
            $regularContacts = $this->getRegularContacts($regularLimit);
            $this->log("Contactos regulares encontrados: " . count($regularContacts));

            foreach ($regularContacts as $contact) {
                if ($totalProcessed >= $dailyLimit) break;

                if ($this->processContact($contact)) {
                    $successCount++;
                }

                $totalProcessed++;

                // Delay entre consultas
                sleep(rand(3, 6));
            }
        }

        $this->log("=== CRON FINALIZADO - Procesados: {$totalProcessed}, Éxitos: {$successCount} ===");
    }
}

// Ejemplo de uso de la configuración
/*
$priorityConfig = new SunatPriorityConfig();

// Agregar una lista prioritaria
$priorityConfig->addPriorityList(1);
$priorityConfig->addPriorityList(2);

// Agregar emails prioritarios
$priorityConfig->addPriorityEmail('empresa@importante.com');
$priorityConfig->addPriorityEmail('contacto@prioridad.com');

// Cambiar configuración
$priorityConfig->updateConfig([
    'daily_limit' => 150,
    'priority_percentage' => 80
]);
*/

// Ejecutar el cron
if (php_sapi_name() === 'cli' || basename($_SERVER['PHP_SELF']) === 'sunat_cron.php') {
    try {
        $cron = new SunatCron();
        $cron->run();
    } catch (Exception $e) {
        error_log("Error en CRON SUNAT: " . $e->getMessage());
        echo "Error: " . $e->getMessage() . "\n";
    }
}
