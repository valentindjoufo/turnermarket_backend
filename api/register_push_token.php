<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    $userId = intval($data['userId'] ?? 0);
    $token = $data['token'] ?? '';
    $platform = $data['platform'] ?? 'android';
    
    if ($userId === 0 || empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Données manquantes']);
        exit();
    }
    
    try {
        // Vérifier si le token existe déjà pour cet utilisateur
        $checkStmt = $pdo->prepare("SELECT id FROM push_tokens WHERE userId = ? AND token = ?");
        $checkStmt->execute([$userId, $token]);
        
        if ($checkStmt->rowCount() > 0) {
            // Mettre à jour la date de modification
            $updateStmt = $pdo->prepare("UPDATE push_tokens SET updatedAt = NOW() WHERE userId = ? AND token = ?");
            $updateStmt->execute([$userId, $token]);
        } else {
            // Insérer le nouveau token
            $insertStmt = $pdo->prepare("INSERT INTO push_tokens (userId, token, platform, createdAt, updatedAt) 
                                        VALUES (?, ?, ?, NOW(), NOW())");
            $insertStmt->execute([$userId, $token, $platform]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Token enregistré avec succès']);
        
    } catch (PDOException $e) {
        error_log("Erreur register_push_token: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erreur base de données']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
}
?>