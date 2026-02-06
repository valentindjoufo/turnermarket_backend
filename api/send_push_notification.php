<?php
// send_push_notification.php - Envoyer une notification push via Node.js
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Lire les données JSON
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Format JSON invalide: " . json_last_error_msg());
    }
    
    $userId = $data['userId'] ?? null;
    $titre = $data['titre'] ?? null;
    $message = $data['message'] ?? null;
    $type = $data['type'] ?? 'info';
    $lien = $data['lien'] ?? null;
    
    // ✅ VALIDATION CRITIQUE
    if (!$userId) {
        throw new Exception("userId est requis");
    }
    
    if (!$titre) {
        throw new Exception("titre est requis");
    }
    
    if (!$message) {
        throw new Exception("message est requis");
    }
    
    // Convertir userId en entier
    $userId = intval($userId);
    
    if ($userId <= 0) {
        throw new Exception("userId invalide");
    }
    
    // Connexion à la base de données
    $host = 'localhost';
    $dbname = 'gestvente';
    $username = 'root';
    $password = '';
    
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. Sauvegarder dans la table Notification
    $stmt = $conn->prepare("
        INSERT INTO Notification (utilisateurId, titre, message, type, lien, estLu, dateCreation) 
        VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");
    $stmt->execute([$userId, $titre, $message, $type, $lien]);
    
    $notificationId = $conn->lastInsertId();
    
    error_log("✅ Notification créée avec ID: $notificationId pour user: $userId");
    
    // 2. Envoyer la notification push via Node.js
    $nodeJsUrl = 'http://localhost:8000/api/send-push';
    
    $pushData = [
        'userId' => $userId,
        'title' => $titre,
        'body' => $message,
        'data' => [
            'notificationId' => $notificationId,
            'type' => $type,
            'lien' => $lien
        ]
    ];
    
    $ch = curl_init($nodeJsUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($pushData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $pushSent = ($httpCode === 200);
    
    if (!$pushSent) {
        error_log("⚠️ Erreur push notification: HTTP $httpCode - $curlError");
    } else {
        error_log("✅ Push notification envoyé avec succès");
    }
    
    echo json_encode([
        "success" => true,
        "notificationId" => $notificationId,
        "pushSent" => $pushSent,
        "message" => "Notification créée" . ($pushSent ? " et push envoyé" : " (push échoué)"),
        "userId" => $userId
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("❌ Erreur BDD send_push_notification.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Erreur de base de données: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("❌ Erreur send_push_notification.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>