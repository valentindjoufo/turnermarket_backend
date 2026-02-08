<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Inclure le fichier de configuration pour la connexion à la base de données
require 'config.php'; // Connexion PDO $pdo disponible

try {
    // Validation des paramètres
    $formationId = isset($_GET['formationId']) ? intval($_GET['formationId']) : 0;
    
    if ($formationId <= 0) {
        throw new Exception('ID formation invalide');
    }

    // Compter les vidéos gratuites (celles avec preview_url non nul)
    $stmt = $pdo->prepare("SELECT COUNT(*) as free_count FROM Video WHERE produitId = ? AND preview_url IS NOT NULL AND preview_url != ''");
    $stmt->execute([$formationId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $freeCount = $result['free_count'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'free_videos_count' => (int)$freeCount,
        'max_free_videos' => 3,
        'can_add_more' => $freeCount < 3
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("❌ ERREUR api_free_videos_count : " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'free_videos_count' => 0,
        'can_add_more' => true
    ]);
}
?>