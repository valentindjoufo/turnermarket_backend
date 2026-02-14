<?php
/**
 * save-token.php - Enregistrement des tokens de notification push
 * VERSION CORRIGÉE pour PostgreSQL
 */

// 🚦 Gestion CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// ✅ Réponse aux requêtes pré-vol OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 📦 Connexion à la base
require_once 'config.php';

// 🧾 Lecture du JSON
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

error_log("=== SAVE TOKEN REQUEST ===");
error_log("Raw Input: " . $rawInput);

// Vérification des données
if (!isset($data['userId']) || !isset($data['token'])) {
    error_log("Missing fields: userId or token");
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'userId et token requis'
    ]);
    exit;
}

$userId = (int)$data['userId'];
$token = trim($data['token']);
$platform = $data['platform'] ?? 'unknown';

error_log("Saving token for user: $userId");
error_log("Token: " . substr($token, 0, 20) . "...");
error_log("Platform: $platform");

try {
    // Vérifier si l'utilisateur existe
    $checkStmt = $pdo->prepare("SELECT id FROM utilisateur WHERE id = ?");
    $checkStmt->execute([$userId]);
    
    if (!$checkStmt->fetch()) {
        error_log("User not found: $userId");
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'Utilisateur non trouvé'
        ]);
        exit;
    }

    // ✅ CORRECTION : Créer la table avec noms PostgreSQL compatibles
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_tokens (
            id SERIAL PRIMARY KEY,
            userid INTEGER NOT NULL,
            token TEXT NOT NULL,
            platform VARCHAR(50),
            createdat TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updatedat TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(userid, token)
        )
    ");

    // ✅ CORRECTION : Utiliser userid au lieu de user_id
    $checkTokenStmt = $pdo->prepare("SELECT id FROM push_tokens WHERE userid = ? AND token = ?");
    $checkTokenStmt->execute([$userId, $token]);
    
    if ($checkTokenStmt->fetch()) {
        // ✅ CORRECTION : updatedat au lieu de updated_at
        $updateStmt = $pdo->prepare("UPDATE push_tokens SET updatedat = NOW(), platform = ? WHERE userid = ? AND token = ?");
        $updateStmt->execute([$platform, $userId, $token]);
        
        error_log("Token updated for user $userId");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Token mis à jour',
            'action' => 'updated'
        ]);
    } else {
        // ✅ CORRECTION : userid au lieu de user_id
        $insertStmt = $pdo->prepare("INSERT INTO push_tokens (userid, token, platform) VALUES (?, ?, ?)");
        $insertStmt->execute([$userId, $token, $platform]);
        
        error_log("Token saved for user $userId");
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Token enregistré',
            'action' => 'created'
        ]);
    }

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Erreur serveur',
        'error' => $e->getMessage()
    ]);
}
?>