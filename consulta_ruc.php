
<?php
// Datos
$token = 'apis-token-17175.SXA3pxft3bc1fZCd4fHPQ9Tf55JKUvxl';

// Lista de RUCs predefinidos para consultar
$rucs = [
    '20546030700',
    '20100070970',
    '20131312955',
    '20543540171',
    '20100017491'
];

// Función para consultar un RUC
function consultarRUC($ruc, $token)
{
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.apis.net.pe/v2/sunat/ruc?numero=' . $ruc,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
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

// Procesar cada RUC
echo "<h2>Resultados de consultas RUC</h2>\n";
echo "<hr>\n";

foreach ($rucs as $ruc) {
    echo "<h3>RUC: $ruc</h3>\n";

    $resultado = consultarRUC($ruc, $token);
    // Datos de empresas según padron reducido
    $empresa = json_decode($response);
    var_dump($empresa);

    echo "<hr>\n";

    // Pausa breve para evitar saturar la API
    sleep(1);
}

echo "<p><strong>Consulta completada para " . count($rucs) . " RUCs</strong></p>\n";
?>