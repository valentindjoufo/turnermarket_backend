<?php
/**
 * get_events.php - Récupérer les événements depuis la base de données
 * Version compatible PostgreSQL (noms en minuscules)
 */

require_once 'config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, ngrok-skip-browser-warning, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    // Requête avec noms en minuscules (conformité PostgreSQL)
    $stmt = $pdo->prepare("
        SELECT 
            id,
            nom,
            description,
            date_evenement,
            type,
            couleur,
            actif,
            datecreation
        FROM evenement 
        WHERE actif = TRUE
        ORDER BY date_evenement ASC
    ");
    
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("✅ Événements récupérés: " . count($events));

    // Formater la réponse en conservant les clés attendues par le frontend
    $formattedEvents = array_map(function($event) {
        return [
            'id' => (int)$event['id'],
            'nom' => $event['nom'],
            'description' => $event['description'],
            'date_evenement' => $event['date_evenement'],
            'type' => $event['type'],
            'couleur' => $event['couleur'],
            'actif' => (bool)$event['actif'],
            'dateCreation' => $event['datecreation'] // conversion pour garder la casse attendue
        ];
    }, $events);

    echo json_encode([
        "success" => true,
        "events" => $formattedEvents,
        "count" => count($formattedEvents),
        "timestamp" => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    error_log("❌ ERREUR PDO GET_EVENTS: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Erreur serveur lors de la récupération des événements",
        "details" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("❌ ERREUR GET_EVENTS: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
?>