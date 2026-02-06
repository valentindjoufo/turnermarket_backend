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

    $notificationId = $data['notificationId'] ?? null;
    $notificationIds = $data['notificationIds'] ?? null;

    if ($notificationId) {
        $stmt = $conn->prepare("DELETE FROM Notification WHERE id = ?");
        $stmt->execute([$notificationId]);
        $message = "Notification supprimée";
    } elseif ($notificationIds && is_array($notificationIds)) {
        $placeholders = str_repeat('?,', count($notificationIds) - 1) . '?';
        $stmt = $conn->prepare("DELETE FROM Notification WHERE id IN ($placeholders)");
        $stmt->execute($notificationIds);
        $message = count($notificationIds) . " notification(s) supprimée(s)";
    } else {
        throw new Exception("Aucune notification à supprimer");
    }

    echo json_encode(["success" => true, "message" => $message]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>