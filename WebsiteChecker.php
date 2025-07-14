
<?php
 class WebsiteChecker {
    private $cacheFile;
    private $cacheDuration;
    
    public function __construct($cacheFile = __DIR__ .'/logs/website_cache.log', $cacheDays = 15) {
        $this->cacheFile = $cacheFile;
        $this->cacheDuration = $cacheDays * 24 * 60 * 60; // Convertir días a segundos
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
        
        $cacheData = file_get_contents($this->cacheFile);
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
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_NOBODY => true, // Solo HEAD request para ser más eficiente
            CURLOPT_HEADER => true
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
            $lines = file($this->cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if ($data && $data['url'] !== $url) {
                    // Mantener solo entradas que no sean del mismo URL
                    $existingCache[] = $line;
                }
            }
        }
        
        // Agregar nueva entrada
        $existingCache[] = json_encode($cacheEntry);
        
        // Escribir todo el caché
        file_put_contents($this->cacheFile, implode("\n", $existingCache) . "\n");
    }
    
    /**
     * Obtiene mensaje descriptivo del código de estado
     */
    private function getStatusMessage($code) {
        $messages = [
            200 => 'OK - Sitio web accesible',
            301 => 'Moved Permanently - Sitio redirigido',
            302 => 'Found - Sitio redirigido temporalmente',
            404 => 'Not Found - Sitio no encontrado',
            500 => 'Internal Server Error - Error del servidor',
            503 => 'Service Unavailable - Servicio no disponible'
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
        
        $lines = file($this->cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $validLines = [];
        
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if ($data && (time() - $data['timestamp']) < $this->cacheDuration) {
                $validLines[] = $line;
            }
        }
        
        file_put_contents($this->cacheFile, implode("\n", $validLines) . "\n");
    }
    
    /**
     * Obtiene información del caché
     */
    public function getCacheInfo() {
        if (!file_exists($this->cacheFile)) {
            return ['total' => 0, 'entries' => []];
        }
        
        $lines = file($this->cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entries = [];
        
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if ($data) {
                $entries[] = $data;
            }
        }
        
        return ['total' => count($entries), 'entries' => $entries];
    }
}
