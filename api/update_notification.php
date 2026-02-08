<?php
// Inclure la configuration de connexion à la base de données
require_once 'config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // La variable $pdo est déjà définie dans config.php
    // Utiliser directement $pdo au lieu de créer une nouvelle connexion
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    $notificationId = $data['notificationId'] ?? null;
    $estLu = $data['estLu'] ?? 1;

    if (!$notificationId) {
        throw new Exception("Notification ID requis");
    }

    $stmt = $pdo->prepare("UPDATE Notification SET estLu = ? WHERE id = ?");
    $stmt->execute([$estLu, $notificationId]);

    echo json_encode(["success" => true, "message" => "Notification mise à jour"]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>