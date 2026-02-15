<?php
// api/ajouter_video.php
// Version simplifiée pour Cloudflare R2 + PostgreSQL
// Reçoit les métadonnées JSON après upload direct du client

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cloudflare-config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

try {
    // Lire les données JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validation des champs obligatoires
    $produitId = intval($input['produitId'] ?? 0);
    $userId = intval($input['userId'] ?? 0);
    $titre = trim($input['titre'] ?? '');
    $objectKey = trim($input['objectKey'] ?? '');       // Clé R2 de la vidéo principale
    $previewObjectKey = trim($input['previewObjectKey'] ?? ''); // Clé R2 de l'aperçu (optionnel)
    $description = trim($input['description'] ?? '');
    $ordre = intval($input['ordre'] ?? 1);

    if ($produitId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'produitId requis']);
        exit;
    }

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'userId requis']);
        exit;
    }

    if (empty($titre)) {
        http_response_code(400);
        echo json_encode(['error' => 'titre requis']);
        exit;
    }

    if (empty($objectKey)) {
        http_response_code(400);
        echo json_encode(['error' => 'objectKey requis (clé R2)']);
        exit;
    }

    // Vérifier que l'utilisateur est bien le vendeur de la formation
    $stmt = $pdo->prepare("SELECT vendeurId FROM produit WHERE id = ?");
    $stmt->execute([$produitId]);
    $vendeurId = $stmt->fetchColumn();

    if (!$vendeurId) {
        http_response_code(404);
        echo json_encode(['error' => 'Formation non trouvée']);
        exit;
    }

    if ($vendeurId != $userId) {
        http_response_code(403);
        echo json_encode(['error' => 'Vous n\'êtes pas autorisé à ajouter des vidéos à cette formation']);
        exit;
    }

    // Vérifier la limite de vidéos gratuites (max 3)
    if (!empty($previewObjectKey)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM video WHERE produitId = ? AND preview_url IS NOT NULL AND preview_url != ''");
        $stmt->execute([$produitId]);
        $freeCount = $stmt->fetchColumn();

        if ($freeCount >= 3) {
            http_response_code(400);
            echo json_encode(['error' => 'Maximum 3 vidéos gratuites par formation']);
            exit;
        }
    }

    // Vérifier si la table video possède les colonnes object_key / preview_object_key
    $checkCol = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='video' AND column_name='object_key'");
    $hasObjectKey = $checkCol->fetchColumn() ? true : false;

    if ($hasObjectKey) {
        // Stocker les clés R2 (recommandé pour pouvoir supprimer facilement)
        $sql = "INSERT INTO video (produitId, titre, object_key, preview_object_key, ordre, description, dateCreation) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $params = [$produitId, $titre, $objectKey, $previewObjectKey ?: null, $ordre, $description];
    } else {
        // Fallback : stocker l'URL publique complète
        $url = generateCloudflareUrl($objectKey);
        $previewUrl = $previewObjectKey ? generateCloudflareUrl($previewObjectKey) : null;

        $sql = "INSERT INTO video (produitId, titre, url, preview_url, ordre, description, dateCreation) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $params = [$produitId, $titre, $url, $previewUrl, $ordre, $description];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $videoId = $pdo->lastInsertId();

    // Construction de la réponse
    $response = [
        'success' => true,
        'message' => 'Vidéo enregistrée avec succès',
        'id' => $videoId,
        'data' => [
            'produitId' => $produitId,
            'titre' => $titre,
            'ordre' => $ordre,
            'description' => $description,
            'is_free' => !empty($previewObjectKey),
            'video_url' => generateCloudflareUrl($objectKey),
            'preview_url' => $previewObjectKey ? generateCloudflareUrl($previewObjectKey) : null,
            'object_key' => $objectKey,
            'preview_object_key' => $previewObjectKey
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    error_log("❌ Erreur ajouter_video: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur interne du serveur',
        'message' => $e->getMessage()
    ]);
}
?>