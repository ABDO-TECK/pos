<?php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'ABDO-TECK-POS-Updater');
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);
echo json_encode(['code' => $httpCode, 'error' => $error, 'body' => $result]);
