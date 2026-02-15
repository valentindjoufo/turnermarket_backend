<?php
/**
 * marquer_notifications_lues.php - Marquer toutes les notifications comme lues
 * Version compatible PostgreSQL (noms en minuscules)
 */

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
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception("Données JSON invalides ou manquantes");
    }

    $userId = $data['userId'] ?? null;

    if (!$userId || !filter_var($userId, FILTER_VALIDATE_INT)) {
        throw new Exception("User ID requis et doit être un nombre valide");
    }

    $userId = intval($userId);
    error_log("🔔 Marquer notifications comme lues pour utilisateur ID: $userId");

    // Requête adaptée à PostgreSQL : table en minuscules, colonnes en minuscules
    $stmt = $pdo->prepare("UPDATE notification SET estlu = TRUE WHERE utilisateurid = ? AND estlu = FALSE");
    $stmt->execute([$userId]);

    $rowsUpdated = $stmt->rowCount();

    error_log("✅ $rowsUpdated notification(s) marquée(s) comme lue(s) pour utilisateur $userId");

    echo json_encode([
        "success" => true,
        "message" => "Toutes les notifications marquées comme lues",
        "notifications_mises_a_jour" => $rowsUpdated,
        "user_id" => $userId,
        "timestamp" => date('Y-m-d H:i:s')
    ]);

} catch (PDOException $e) {
    error_log("❌ ERREUR PDO MARQUER NOTIFICATIONS: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Erreur de base de données",
        "debug" => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("❌ ERREUR MARQUER NOTIFICATIONS: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>