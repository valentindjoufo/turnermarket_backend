<?php
// notify.php - Reçoit les notifications de PayUnit
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

try {
    $conn = new PDO("mysql:host=localhost;dbname=gestvente;charset=utf8", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    error_log("=== NOTIFICATION PAYUNIT ===");
    error_log("Données reçues: " . $input);

    if (!$data) $data = $_POST;

    $transactionId = $data['transaction_id'] ?? $data['purchaseRef'] ?? null;
    $status = $data['status'] ?? null;

    if (!$transactionId) {
        throw new Exception("Transaction ID manquant");
    }

    $stmt = $conn->prepare("SELECT * FROM Vente WHERE transactionId = ?");
    $stmt->execute([$transactionId]);
    $vente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vente) {
        throw new Exception("Transaction non trouvée: " . $transactionId);
    }

    if ($status === 'SUCCESS' || $status === 'APPROVED') {
        $conn->beginTransaction();
        $stmt = $conn->prepare("UPDATE Vente SET statut = 'confirme' WHERE transactionId = ?");
        $stmt->execute([$transactionId]);
        $stmt = $conn->prepare("UPDATE VenteProduit SET achetee = 1 WHERE venteId = ?");
        $stmt->execute([$vente['id']]);
        $stmt = $conn->prepare("UPDATE Commission SET statut = 'paye', dateTraitement = NOW() WHERE venteId = ?");
        $stmt->execute([$vente['id']]);
        $conn->commit();
        error_log("Notification succès: " . $transactionId);
    } else {
        $stmt = $conn->prepare("UPDATE Vente SET statut = 'echoue' WHERE transactionId = ?");
        $stmt->execute([$transactionId]);
        error_log("Notification échec: " . $transactionId);
    }

    http_response_code(200);
    echo json_encode(["status" => "ok"]);

} catch (Exception $e) {
    error_log("ERREUR NOTIFICATION: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error"]);
}
?>