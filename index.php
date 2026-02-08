<?php
header("Content-Type: application/json; charset=UTF-8");
http_response_code(200);

echo json_encode([
    "success" => true,
    "message" => "TurnerMarket API is running",
    "status" => "OK",
    "timestamp" => date('Y-m-d H:i:s')
]);
