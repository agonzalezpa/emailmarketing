<?php
class WebsiteChecker {
    private $cacheFile;
    private $cacheDuration;
    
    public function __construct($cacheFile = null, $cacheDays = 15) {
        // Si no se especifica archivo, usar directorio actual
        if ($cacheFile === null) {
            $cacheFile = __DIR__ . '/website_cache.log';
        }
        
        $this->cacheFile = $cacheFile;
        $this->cacheDuration = $cacheDays * 24 * 60 * 60;
        
        // Crear directorio si no existe
        $this->ensureDirectoryExists();
    }
    
    /**
     * Asegura que el directorio para el archivo de caché existe
     */
    private function ensureDirectoryExists() {
        $directory = dirname($this->cacheFile);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                error_log("No se pudo crear el directorio: $directory");
            }
        }
    }
    
    /**
     * Verifica si un sitio web existe
     * @param string $url URL del sitio web a verificar
     * @return array Resultado con status, mensaje y si vino del caché
     */
    public function checkWebsite($url) {
        // Normalizar URL
        $url = $this->normalizeUrl($url);
        
        // Verificar caché primero
        $cachedResult = $this->getCachedResult($url);
        if ($cachedResult !== null) {
            return [
                'url' => $url,
                'exists' => $cachedResult['exists'],
                'status_code' => $cachedResult['status_code'],
                'message' => $cachedResult['message'],
                'cached' => true,
                'cached_date' => $cachedResult['date']
            ];
        }
        
        // Verificar sitio web
        $result = $this->performWebsiteCheck($url);
        
        // Guardar en caché
        $this->saveCachedResult($url, $result);
        
        return array_merge($result, ['cached' => false]);
    }
    
    /**
     * Normaliza la URL agregando http:// si es necesario
     */
    private function normalizeUrl($url) {
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'http://' . $url;
        }
        return $url;
    }
    
    /**
     * Verifica si existe un resultado en caché válido
     */
    private function getCachedResult($url) {
        if (!file_exists($this->cacheFile)) {
            return null;
        }
        
        $cacheData = @file_get_contents($this->cacheFile);
        if ($cacheData === false) {
            return null;
        }
        
        $lines = explode("\n", $cacheData);
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $data = json_decode($line, true);
            if ($data && $data['url'] === $url) {
                // Verificar si el caché sigue válido
                if (time() - $data['timestamp'] < $this->cacheDuration) {
                    return $data;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Realiza la verificación del sitio web
     */
    private function performWebsiteCheck($url) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            CURLOPT_NOBODY => true, // Solo HEAD request para ser más eficiente
            CURLOPT_HEADER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            return [
                'url' => $url,
                'exists' => false,
                'status_code' => 0,
                'message' => 'Error de conexión: ' . $error
            ];
        }
        
        // Considerar exitosos los códigos 2xx y 3xx
        $exists = ($httpCode >= 200 && $httpCode < 400);
        $message = $this->getStatusMessage($httpCode);
        
        return [
            'url' => $url,
            'exists' => $exists,
            'status_code' => $httpCode,
            'message' => $message
        ];
    }
    
    /**
     * Guarda el resultado en el archivo de caché
     */
    private function saveCachedResult($url, $result) {
        $cacheEntry = [
            'url' => $url,
            'exists' => $result['exists'],
            'status_code' => $result['status_code'],
            'message' => $result['message'],
            'timestamp' => time(),
            'date' => date('Y-m-d H:i:s')
        ];
        
        // Leer caché existente
        $existingCache = [];
        if (file_exists($this->cacheFile)) {
            $lines = @file($this->cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $data = json_decode($line, true);
                    if ($data && $data['url'] !== $url) {
                        // Mantener solo entradas que no sean del mismo URL
                        $existingCache[] = $line;
                    }
                }
            }
        }
        
        // Agregar nueva entrada
        $existingCache[] = json_encode($cacheEntry);
        
        // Escribir todo el caché
        $result = @file_put_contents($this->cacheFile, implode("\n", $existingCache) . "\n", LOCK_EX);
        if ($result === false) {
            error_log("No se pudo escribir en el archivo de caché: " . $this->cacheFile);
        }
    }
    
    /**
     * Obtiene mensaje descriptivo del código de estado
     */
    private function getStatusMessage($code) {
        $messages = [
            200 => 'OK - Sitio web accesible',
            301 => 'Moved Permanently - Sitio redirigido',
            302 => 'Found - Sitio redirigido temporalmente',
            403 => 'Forbidden - Acceso denegado',
            404 => 'Not Found - Sitio no encontrado',
            500 => 'Internal Server Error - Error del servidor',
            503 => 'Service Unavailable - Servicio no disponible',
            0 => 'No response - Sin respuesta del servidor'
        ];
        
        return isset($messages[$code]) ? $messages[$code] : "Código de estado: $code";
    }
    
    /**
     * Limpia entradas expiradas del caché
     */
    public function cleanExpiredCache() {
        if (!file_exists($this->cacheFile)) {
            return;
        }
        
        $lines = @file($this->cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        
        $validLines = [];
        
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if ($data && (time() - $data['timestamp']) < $this->cacheDuration) {
                $validLines[] = $line;
            }
        }
        
        @file_put_contents($this->cacheFile, implode("\n", $validLines) . "\n", LOCK_EX);
    }
    
    /**
     * Obtiene información del caché
     */
    public function getCacheInfo() {
        if (!file_exists($this->cacheFile)) {
            return ['total' => 0, 'entries' => []];
        }
        
        $lines = @file($this->cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return ['total' => 0, 'entries' => []];
        }
        
        $entries = [];
        
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if ($data) {
                $entries[] = $data;
            }
        }
        
        return ['total' => count($entries), 'entries' => $entries];
    }
    
    /**
     * Verifica si un sitio web está "activo" (códigos 2xx y 3xx)
     */
    public function isWebsiteActive($url) {
        $result = $this->checkWebsite($url);
        return $result['exists']; // Ya considera códigos 2xx y 3xx como exitosos
    }
}
