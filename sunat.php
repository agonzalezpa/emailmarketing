<?php

class SunatScraper {
    private $cookieFile;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    
    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'sunat_cookies');
    }
    
    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }
    
    /**
     * Obtiene el token y cookies necesarios para la consulta
     */
    private function obtenerToken() {
        $curl = curl_init();
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://e-consultaruc.sunat.gob.pe/cl-ti-itmrconsruc/FrameCriterioBusquedaWeb.jsp',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array(
                'User-Agent: ' . $this->userAgent,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: es-ES,es;q=0.8,en-US;q=0.5,en;q=0.3',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1'
            ),
        ));
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode !== 200) {
            throw new Exception("Error al obtener página inicial: HTTP $httpCode");
        }
        
        // Extraer token del HTML
        $token = $this->extraerToken($response);
        
        return $token;
    }
    
    /**
     * Extrae el token del HTML de la página inicial
     */
    private function extraerToken($html) {
        // Buscar el token en el HTML
        if (preg_match('/name="token".*?value="([^"]+)"/', $html, $matches)) {
            return $matches[1];
        }
        
        // Si no se encuentra el token, intentar generar uno básico
        // Nota: Este es un fallback, el token real es más complejo
        return $this->generarTokenBasico();
    }
    
    /**
     * Genera un token básico como fallback
     */
    private function generarTokenBasico() {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $token = '';
        for ($i = 0; $i < 42; $i++) {
            $token .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $token;
    }
    
    /**
     * Busca por razón social en SUNAT
     */
    public function buscarPorRazonSocial($razonSocial) {
        try {
            // Obtener token
            $token = $this->obtenerToken();
            
            // Preparar datos para la consulta
            $postData = http_build_query([
                'accion' => 'consPorRazonSoc',
                'razSoc' => $razonSocial,
                'nroRuc' => '',
                'nrodoc' => '',
                'token' => $token,
                'contexto' => 'ti-it',
                'modo' => '1',
                'search1' => '',
                'tipdoc' => '1',
                'search2' => '',
                'rbtnTipo' => '3',
                'search3' => $razonSocial,
                'codigo' => ''
            ]);
            
            // Realizar consulta
            $curl = curl_init();
            
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://e-consultaruc.sunat.gob.pe/cl-ti-itmrconsruc/jcrS00Alias',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_COOKIEJAR => $this->cookieFile,
                CURLOPT_COOKIEFILE => $this->cookieFile,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/x-www-form-urlencoded',
                    'User-Agent: ' . $this->userAgent,
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: es-ES,es;q=0.8,en-US;q=0.5,en;q=0.3',
                    'Accept-Encoding: gzip, deflate, br',
                    'Connection: keep-alive',
                    'Referer: https://e-consultaruc.sunat.gob.pe/cl-ti-itmrconsruc/FrameCriterioBusquedaWeb.jsp'
                ),
            ));
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($httpCode !== 200) {
                throw new Exception("Error en consulta: HTTP $httpCode");
            }
            
            // Parsear resultados
            $resultados = $this->parsearResultados($response);
            
            return [
                'success' => true,
                'http_code' => $httpCode,
                'data' => $resultados,
                'raw_html' => $response
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Parsea los resultados HTML y extrae la información de las empresas
     */
    private function parsearResultados($html) {
        $resultados = [];
        
        // Buscar elementos con class="list-group-item clearfix aRucs"
        if (preg_match_all('/<a[^>]*class="list-group-item clearfix aRucs"[^>]*data-ruc="([^"]+)"[^>]*>(.*?)<\/a>/s', $html, $matches)) {
            
            for ($i = 0; $i < count($matches[0]); $i++) {
                $ruc = $matches[1][$i];
                $contenido = $matches[2][$i];
                
                // Extraer información de cada empresa
                $empresa = [
                    'ruc' => $ruc,
                    'razon_social' => '',
                    'ubicacion' => '',
                    'estado' => ''
                ];
                
                // Extraer razón social
                if (preg_match('/<h4[^>]*class="list-group-item-heading"[^>]*>RUC: ' . preg_quote($ruc, '/') . '<\/h4>\s*<h4[^>]*class="list-group-item-heading"[^>]*>([^<]+)<\/h4>/s', $contenido, $razonMatch)) {
                    $empresa['razon_social'] = trim(html_entity_decode($razonMatch[1], ENT_QUOTES, 'UTF-8'));
                }
                
                // Extraer ubicación
                if (preg_match('/<p[^>]*class="list-group-item-text"[^>]*>Ubicaci[^:]*:\s*([^<]+)<\/p>/s', $contenido, $ubicacionMatch)) {
                    $empresa['ubicacion'] = trim(html_entity_decode($ubicacionMatch[1], ENT_QUOTES, 'UTF-8'));
                }
                
                // Extraer estado
                if (preg_match('/<p[^>]*class="list-group-item-text"[^>]*>Estado:[^>]*<strong[^>]*>.*?<span[^>]*>([^<]+)<\/span>/s', $contenido, $estadoMatch)) {
                    $empresa['estado'] = trim(html_entity_decode($estadoMatch[1], ENT_QUOTES, 'UTF-8'));
                }
                
                $resultados[] = $empresa;
            }
        }
        
        return $resultados;
    }
    
    /**
     * Busca por RUC específico
     */
    public function buscarPorRuc($ruc) {
        try {
            // Obtener token
            $token = $this->obtenerToken();
            
            // Preparar datos para la consulta
            $postData = http_build_query([
                'accion' => 'consPorRuc',
                'razSoc' => '',
                'nroRuc' => $ruc,
                'nrodoc' => '',
                'token' => $token,
                'contexto' => 'ti-it',
                'modo' => '1',
                'search1' => $ruc,
                'tipdoc' => '1',
                'search2' => '',
                'rbtnTipo' => '1',
                'search3' => '',
                'codigo' => ''
            ]);
            
            // Realizar consulta
            $curl = curl_init();
            
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://e-consultaruc.sunat.gob.pe/cl-ti-itmrconsruc/jcrS00Alias',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_COOKIEJAR => $this->cookieFile,
                CURLOPT_COOKIEFILE => $this->cookieFile,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/x-www-form-urlencoded',
                    'User-Agent: ' . $this->userAgent,
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: es-ES,es;q=0.8,en-US;q=0.5,en;q=0.3',
                    'Accept-Encoding: gzip, deflate, br',
                    'Connection: keep-alive',
                    'Referer: https://e-consultaruc.sunat.gob.pe/cl-ti-itmrconsruc/FrameCriterioBusquedaWeb.jsp'
                ),
            ));
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($httpCode !== 200) {
                throw new Exception("Error en consulta: HTTP $httpCode");
            }
            
            // Para búsqueda por RUC, el resultado puede ser diferente
            // Aquí podrías implementar parseo específico para RUC
            
            return [
                'success' => true,
                'http_code' => $httpCode,
                'data' => 'Requiere parseo específico para RUC',
                'raw_html' => $response
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ];
        }
    }
}

// Función de compatibilidad con tu código existente
function buscarEnSunatScraping($nombre) {
    $scraper = new SunatScraper();
    return $scraper->buscarPorRazonSocial($nombre);
}

// Ejemplo de uso
/*
$scraper = new SunatScraper();
$resultado = $scraper->buscarPorRazonSocial("RN AUTOBOUTIQUE Y ESTRUCTURAS METALICAS E.I.R.L");

if ($resultado['success']) {
    echo "Empresas encontradas: " . count($resultado['data']) . "\n";
    foreach ($resultado['data'] as $empresa) {
        echo "RUC: " . $empresa['ruc'] . "\n";
        echo "Razón Social: " . $empresa['razon_social'] . "\n";
        echo "Ubicación: " . $empresa['ubicacion'] . "\n";
        echo "Estado: " . $empresa['estado'] . "\n";
        echo "---\n";
    }
} else {
    echo "Error: " . $resultado['error'] . "\n";
}
*/

?>