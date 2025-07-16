
<?php
// Datos
$token = 'apis-token-17175.SXA3pxft3bc1fZCd4fHPQ9Tf55JKUvxl';
$apiperu = '2076906695f742ab0a67d5934af452463b8adf8c1040a15db0d5e961e8b50c3d';

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
/*
function buscarEnApiPeru($nombre, $token)
{
    $params = json_encode(['nombre_o_razon_social' => $nombre]);
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://apiperu.dev/api/ruc",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer INGRESAR_TOKEN_AQUI'
        ],
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);

    return [
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $response,
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
    echo "<pre>" . json_encode($resultado['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>\n";
    echo "<p><strong>Código HTTP:</strong> " . $resultado['error'] . "</p>\n";
    echo "<hr>\n";

    // Pausa breve para evitar saturar la API
    sleep(1);
}

echo "<p><strong>Consulta completada para " . count($rucs) . " RUCs</strong></p>\n";*/



$url = 'https://e-consultaruc.sunat.gob.pe/cl-ti-itmrconsruc/jcrS00Alias';

// Datos POST que deseas enviar
$data = [
    'accion'    => 'consPorRazonSoc',
    'razSoc'    => 'AISLAMIENTO INKA S.A.C.'
    
];

// Iniciar cURL
$ch = curl_init($url);

// Configurar opciones cURL
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

// Simular navegador para evitar bloqueo
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');

// Para evitar problemas de SSL (solo si no tienes certificados configurados)
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// Ejecutar solicitud
$response = curl_exec($ch);

// Comprobar errores
if (curl_errno($ch)) {
    echo 'Error en cURL: ' . curl_error($ch);
} else {
    echo $response;
}

// Cerrar cURL
curl_close($ch);