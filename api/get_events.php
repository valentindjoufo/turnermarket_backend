<?php
/**
 * get_events.php - Récupérer les événements depuis la base de données
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

    // 📅 Récupérer les événements actifs
    $stmt = $pdo->prepare("
        SELECT 
            id,
            nom,
            description,
            date_evenement,
            type,
            couleur,
            actif,
            dateCreation
        FROM Evenement 
        WHERE actif = TRUE
        ORDER BY date_evenement ASC
    ");
    
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("✅ Événements récupérés: " . count($events));

    // 📋 Formater la réponse
    $formattedEvents = array_map(function($event) {
        return [
            'id' => (int)$event['id'],
            'nom' => $event['nom'],
            'description' => $event['description'],
            'date_evenement' => $event['date_evenement'],
            'type' => $event['type'],
            'couleur' => $event['couleur'],
            'actif' => (bool)$event['actif'],
            'dateCreation' => $event['dateCreation']
        ];
    }, $events);

    echo json_encode([
        "success" => true,
        "events" => $formattedEvents,
        "count" => count($formattedEvents),
        "timestamp" => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    // ❌ Erreur de base de données
    error_log("❌ ERREUR PDO GET_EVENTS: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Erreur serveur lors de la récupération des événements",
        "details" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // ❌ Autres erreurs
    error_log("❌ ERREUR GET_EVENTS: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
?>