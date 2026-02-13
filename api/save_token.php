<?php
/**
 * save_token.php - Enregistrement des tokens de notification push
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

    // Créer la table si elle n'existe pas
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_tokens (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            platform VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, token)
        )
    ");

    // Vérifier si le token existe déjà
    $checkTokenStmt = $pdo->prepare("SELECT id FROM push_tokens WHERE user_id = ? AND token = ?");
    $checkTokenStmt->execute([$userId, $token]);
    
    if ($checkTokenStmt->fetch()) {
        // Mettre à jour la date
        $updateStmt = $pdo->prepare("UPDATE push_tokens SET updated_at = NOW(), platform = ? WHERE user_id = ? AND token = ?");
        $updateStmt->execute([$platform, $userId, $token]);
        
        error_log("Token updated for user $userId");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Token mis à jour',
            'action' => 'updated'
        ]);
    } else {
        // Insérer le nouveau token
        $insertStmt = $pdo->prepare("INSERT INTO push_tokens (user_id, token, platform) VALUES (?, ?, ?)");
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