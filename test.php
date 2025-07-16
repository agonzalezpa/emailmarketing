
<?php
// Datos
$token = 'apis-token-17175.SXA3pxft3bc1fZCd4fHPQ9Tf55JKUvxl';
$apiperu= 'fe35ca952fa59aea0f7165287ba408e786307911146b9b6b82d758771df34650';

// Lista de RUCs predefinidos para consultar
$rucs = [
    'RN AUTOBOUTIQUE Y ESTRUCTURAS METALICAS E.I.R.L',
    'ECOFERTILIZING SAC'
//'20603516509',
  //  '20131376503',
   // '20153408191'
];

// Función para consultar un RUC
function consultarRUC($ruc, $token)
{
    $curl = curl_init();

  curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.apis.net.pe/v2/sunat/ruc/full?numero=' . $ruc,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Referer: http://apis.net.pe/api-ruc',
            'Authorization: Bearer ' . $token
        ),
    ));

    $response = curl_exec($curl);
   // $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    return  $response;
}

// Función para API Perú
function buscarEnApiPeru($nombre, $token) {
    $curl = curl_init();
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://apiperu.pro/api/ruc/search?nombre=' . $nombre,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ),
    ));
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    return [
        'http_code' => $httpCode,
        'response' => $response,
        'data' => json_decode($response)
    ];
}

// Procesar cada RUC
echo "<h2>Resultados de consultas RUC</h2>\n";
echo "<hr>\n";

foreach ($rucs as $ruc) {
    echo "<h3>RUC: $ruc</h3>\n";

    $resultado = buscarEnApiPeru($ruc, $apiperu);
    // Datos de empresas según padron reducido
     echo "<p><strong>Código HTTP:</strong> " . $resultado['http_code'] . "</p>\n";
   echo "<pre>" . json_encode($resultado['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE). "</pre>\n";
    
    echo "<hr>\n";

    // Pausa breve para evitar saturar la API
    sleep(1);
}

echo "<p><strong>Consulta completada para " . count($rucs) . " RUCs</strong></p>\n";