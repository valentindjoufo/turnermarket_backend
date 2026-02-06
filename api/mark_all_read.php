<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=gestvente;charset=utf8", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    $userId = $data['userId'] ?? null;

    if (!$userId) {
        throw new Exception("User ID requis");
    }

    $stmt = $conn->prepare("UPDATE Notification SET estLu = 1 WHERE utilisateurId = ? AND estLu = 0");
    $stmt->execute([$userId]);

    echo json_encode(["success" => true, "message" => "Toutes les notifications marquées comme lues"]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>