<?php
// sunat_priority_config.php - Configuración de prioridades

class SunatPriorityConfig {
    private $configFile;
    private $config;
    
    public function __construct() {
        $this->configFile = __DIR__ . '/config/sunat_priority.json';
        $this->loadConfig();
    }
    
    private function loadConfig() {
        if (!file_exists($this->configFile)) {
            $this->createDefaultConfig();
        }
        
        $this->config = json_decode(file_get_contents($this->configFile), true);
        if (!$this->config) {
            $this->createDefaultConfig();
            $this->config = json_decode(file_get_contents($this->configFile), true);
        }
    }
    
    private function createDefaultConfig() {
        $defaultConfig = [
            'enabled' => true,
            'priority_contact_lists' => [
                // IDs de las listas prioritarias
                // Ejemplo: [1, 2, 3]
            ],
            'priority_emails' => [
                // Emails específicos prioritarios
                // Ejemplo: ["empresa@ejemplo.com", "contacto@importante.com"]
            ],
            'daily_limit' => 150,
            'priority_percentage' => 100, // 100% para prioritarios, 0% para regulares
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $configDir = dirname($this->configFile);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        file_put_contents($this->configFile, json_encode($defaultConfig, JSON_PRETTY_PRINT));
    }
    
    public function getConfig() {
        return $this->config;
    }
    
    public function updateConfig($newConfig) {
        $this->config = array_merge($this->config, $newConfig);
        $this->config['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($this->configFile, json_encode($this->config, JSON_PRETTY_PRINT));
    }
    
    public function addPriorityList($listId) {
        if (!in_array($listId, $this->config['priority_contact_lists'])) {
            $this->config['priority_contact_lists'][] = $listId;
            $this->updateConfig([]);
        }
    }
    
    public function addPriorityEmail($email) {
        if (!in_array($email, $this->config['priority_emails'])) {
            $this->config['priority_emails'][] = $email;
            $this->updateConfig([]);
        }
    }
    
    public function removePriorityList($listId) {
        $key = array_search($listId, $this->config['priority_contact_lists']);
        if ($key !== false) {
            unset($this->config['priority_contact_lists'][$key]);
            $this->config['priority_contact_lists'] = array_values($this->config['priority_contact_lists']);
            $this->updateConfig([]);
        }
    }
    
    public function removePriorityEmail($email) {
        $key = array_search($email, $this->config['priority_emails']);
        if ($key !== false) {
            unset($this->config['priority_emails'][$key]);
            $this->config['priority_emails'] = array_values($this->config['priority_emails']);
            $this->updateConfig([]);
        }
    }
}