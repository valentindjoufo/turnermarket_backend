<?php
/**
 * get_notifications.php - Récupérer les notifications d'un utilisateur
 * Version avec connexion PostgreSQL via config.php
 */

// 📦 Inclusion de la configuration (connexion PDO PostgreSQL)
require_once 'config.php';

// 🚦 Configuration des headers CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, ngrok-skip-browser-warning, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // 💾 Vérification que la connexion PDO est bien disponible
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    // 📥 Récupérer et valider l'ID utilisateur
    $userId = $_GET['userId'] ?? null;
    
    if (!$userId || !filter_var($userId, FILTER_VALIDATE_INT)) {
        error_log("❌ User ID invalide ou manquant: " . $userId);
        
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "error" => "User ID invalide ou manquant",
            "details" => "L'ID utilisateur doit être un nombre entier valide"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $userId = intval($userId);
    error_log("🔍 Récupération notifications pour utilisateur ID: $userId");

    // 📨 Récupérer les notifications
    $stmt = $pdo->prepare("
        SELECT 
            id,
            titre,
            message,
            type,
            lien,
            estLu,
            utilisateurId,
            dateCreation
        FROM Notification 
        WHERE utilisateurId = ? 
        ORDER BY dateCreation DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("✅ Notifications récupérées: " . count($notifications) . " pour utilisateur $userId");

    // 📋 Formater les types de données correctement
    $formattedNotifications = [];
    $nonLues = 0;
    
    foreach ($notifications as $notif) {
        $formattedNotifications[] = [
            'id' => (int)$notif['id'],
            'titre' => $notif['titre'],
            'message' => $notif['message'],
            'type' => $notif['type'],
            'lien' => $notif['lien'],
            'estLu' => (bool)$notif['estLu'],
            'utilisateurId' => (int)$notif['utilisateurId'],
            'dateCreation' => $notif['dateCreation'],
            'dateCreationDisplay' => date('d/m/Y H:i', strtotime($notif['dateCreation']))
        ];
        
        if (!$notif['estLu']) {
            $nonLues++;
        }
    }

    // 📤 Formater la réponse JSON
    echo json_encode([
        "success" => true,
        "notifications" => $formattedNotifications,
        "count" => count($formattedNotifications),
        "nonLues" => $nonLues,
        "userId" => $userId,
        "timestamp" => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    // ❌ Erreur de base de données
    error_log("❌ ERREUR PDO GET_NOTIFICATIONS: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Erreur serveur lors de la récupération des notifications",
        "details" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // ❌ Autres erreurs
    error_log("❌ ERREUR GET_NOTIFICATIONS: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
?>