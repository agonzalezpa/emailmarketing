<?php
//sunat.php
// Clase para realizar scraping a SUNAT
class SunatScraper
{

    private $cookieFile;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

    public function __construct()
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'sunat_cookies');
    }

    public function __destruct()
    {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }

    /**
     * Obtiene el token y cookies necesarios para la consulta
     */
    private function obtenerToken()
    {
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
    private function extraerToken($html)
    {
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
    private function generarTokenBasico()
    {
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
    public function buscarPorRazonSocial($razonSocial)
    {
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
    private function parsearResultados($html)
    {
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
    public function buscarPorRucOLD($ruc)
    {
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


    public function buscarPorRuc($ruc)
    {
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

            // Parsear los datos del RUC
            $parsedData = $this->parseRucData($response);

            return [
                'success' => true,
                'http_code' => $httpCode,
                'data' => $parsedData,
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

    // Nuevo método para parsear todos los datos del RUC
    private function parseRucData($html)
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $data = [];

        // Función helper para limpiar texto
        $cleanText = function ($text) {
            return trim(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
        };

        // Extraer RUC y Razón Social del título
        $rucElement = $xpath->query('//h4[contains(text(), "Número de RUC:")]/following-sibling::div//h4')[0];
        if ($rucElement) {
            $rucText = $cleanText($rucElement->textContent);
            if (preg_match('/(\d{11})\s*-\s*(.+)/', $rucText, $matches)) {
                $data['ruc'] = $matches[1];
                $data['razon_social'] = $matches[2];
            }
        }

        // Extraer todos los campos de la lista
        $items = $xpath->query('//div[@class="list-group-item"]');

        foreach ($items as $item) {
            $headings = $xpath->query('.//h4[@class="list-group-item-heading"]', $item);
            $texts = $xpath->query('.//p[@class="list-group-item-text"]', $item);

            foreach ($headings as $index => $heading) {
                $fieldName = $cleanText($heading->textContent);
                $fieldValue = '';

                if (isset($texts[$index])) {
                    $fieldValue = $cleanText($texts[$index]->textContent);
                }

                // Mapear campos específicos
                switch (true) {
                    case strpos($fieldName, 'Tipo Contribuyente') !== false:
                        $data['tipo_contribuyente'] = $fieldValue;
                        break;

                    case strpos($fieldName, 'Nombre Comercial') !== false:
                        $data['nombre_comercial'] = $fieldValue === '-' ? '' : $fieldValue;
                        break;

                    case strpos($fieldName, 'Fecha de Inscripción') !== false:
                        $data['fecha_inscripcion'] = $fieldValue;
                        break;

                    case strpos($fieldName, 'Fecha de Inicio de Actividades') !== false:
                        $data['fecha_inicio_actividades'] = $fieldValue;
                        break;

                    case strpos($fieldName, 'Estado del Contribuyente') !== false:
                        // Extraer estado y fecha de baja si existe
                        $lines = explode("\n", $fieldValue);
                        $data['estado'] = trim($lines[0]);
                        if (count($lines) > 1 && strpos($fieldValue, 'Fecha de Baja') !== false) {
                            if (preg_match('/Fecha de Baja:\s*(\d{2}\/\d{2}\/\d{4})/', $fieldValue, $matches)) {
                                $data['fecha_baja'] = $matches[1];
                            }
                        }
                        break;

                    case strpos($fieldName, 'Condición del Contribuyente') !== false:
                        $lines = explode("\n", $fieldValue);
                        $data['condicion'] = trim($lines[0]);
                        if (count($lines) > 1) {
                            $data['condicion_observacion'] = trim($lines[1]);
                        }
                        break;

                    case strpos($fieldName, 'Domicilio Fiscal') !== false:
                        $data['domicilio_fiscal'] = $fieldValue;
                        break;

                    case strpos($fieldName, 'Sistema Emisión de Comprobante') !== false:
                        $data['sistema_emision_comprobante'] = $fieldValue;
                        break;

                    case strpos($fieldName, 'Actividad Comercio Exterior') !== false:
                        $data['actividad_comercio_exterior'] = $fieldValue;
                        break;

                    case strpos($fieldName, 'Sistema Contabilidad') !== false:
                        $data['sistema_contabilidad'] = $fieldValue;
                        break;

                    case strpos($fieldName, 'Sistema de Emisión Electrónica') !== false:
                        $data['sistema_emision_electronica'] = $fieldValue === '-' ? '' : $fieldValue;
                        break;

                    case strpos($fieldName, 'Emisor electrónico desde') !== false:
                        $data['emisor_electronico_desde'] = $fieldValue === '-' ? '' : $fieldValue;
                        break;

                    case strpos($fieldName, 'Afiliado al PLE desde') !== false:
                        $data['afiliado_ple_desde'] = $fieldValue === '-' ? '' : $fieldValue;
                        break;
                }
            }
        }

        // Extraer actividades económicas
        $data['actividades_economicas'] = $this->extractActividades($xpath);

        // Extraer padrones
        $data['padrones'] = $this->extractPadrones($xpath);

        // Verificar si está en estado crítico (BAJA DE OFICIO)
        $data['estado_critico'] = (strpos($data['estado'] ?? '', 'BAJA DE OFICIO') !== false);

        return $data;
    }

    // Método para extraer actividades económicas
    private function extractActividades($xpath)
    {
        $actividades = [];

        // Buscar la tabla de actividades económicas
        $actividadesTable = $xpath->query('//h4[contains(text(), "Actividad(es) Económica(s)")]/following-sibling::div//table//tr');

        foreach ($actividadesTable as $row) {
            $text = trim($row->textContent);
            if (!empty($text)) {
                // Extraer código y descripción
                if (preg_match('/(\w+)\s*-\s*(\d+)\s*-\s*(.+)/', $text, $matches)) {
                    $actividades[] = [
                        'tipo' => $matches[1],
                        'codigo' => $matches[2],
                        'descripcion' => $matches[3]
                    ];
                }
            }
        }

        return $actividades;
    }
    // Método para extraer padrones
    private function extractPadrones($xpath)
    {
        $padrones = [];

        $padronesTable = $xpath->query('//h4[contains(text(), "Padrones")]/following-sibling::div//table//tr');

        foreach ($padronesTable as $row) {
            $text = trim($row->textContent);
            if (!empty($text) && $text !== 'NINGUNO') {
                $padrones[] = $text;
            }
        }

        return $padrones;
    }
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
