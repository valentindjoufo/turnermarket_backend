<?php
// get_notifications.php - Récupérer les notifications d'un utilisateur
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, ngrok-skip-browser-warning, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Configuration de la base de données
    $host = 'localhost';
    $dbname = 'gestvente';
    $username = 'root';
    $password = '';
    
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // Récupérer et valider l'ID utilisateur
    $userId = $_GET['userId'] ?? null;
    
    if (!$userId || !filter_var($userId, FILTER_VALIDATE_INT)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "error" => "User ID invalide ou manquant"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Récupérer les notifications
    $stmt = $conn->prepare("
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
    $stmt->execute([intval($userId)]);
    $notifications = $stmt->fetchAll();

    // Formater les types de données correctement
    $formattedNotifications = [];
    foreach ($notifications as $notif) {
        $formattedNotifications[] = [
            'id' => (int)$notif['id'],
            'titre' => $notif['titre'],
            'message' => $notif['message'],
            'type' => $notif['type'],
            'lien' => $notif['lien'],
            'estLu' => (int)$notif['estLu'],
            'utilisateurId' => (int)$notif['utilisateurId'],
            'dateCreation' => $notif['dateCreation']
        ];
    }

    // Formater la réponse JSON
    echo json_encode([
        "success" => true,
        "notifications" => $formattedNotifications,
        "count" => count($formattedNotifications)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    error_log("Erreur BDD get_notifications.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Erreur serveur lors de la récupération des notifications",
        "details" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>