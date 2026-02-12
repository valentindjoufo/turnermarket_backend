<?php
// api/generate-upload-url.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/cloudflare-config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $fileName = $input['fileName'] ?? uniqid('video_') . '.mp4';
        $produitId = intval($input['produitId'] ?? 0);
        $type = $input['type'] ?? 'video';
        
        if ($produitId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'produitId requis']);
            exit;
        }
        
        $client = getCloudflareS3Client();
        
        // Générer une clé d'objet unique
        $objectKey = generateR2ObjectKey($fileName, $produitId, $type);
        
        // Générer une URL signée pour l'upload (valide 1 heure)
        $cmd = $client->getCommand('PutObject', [
            'Bucket' => CLOUDFLARE_BUCKET,
            'Key' => $objectKey,
            'ContentType' => 'video/mp4',
            'ACL' => 'public-read',
        ]);
        
        $request = $client->createPresignedRequest($cmd, '+60 minutes');
        $presignedUrl = (string)$request->getUri();
        
        echo json_encode([
            'success' => true,
            'uploadUrl' => $presignedUrl,
            'objectKey' => $objectKey,
            'publicUrl' => generateCloudflareUrl($objectKey),
            'expires' => time() + 3600,
        ]);
        
    } catch (Exception $e) {
        error_log("❌ Erreur generate-upload-url: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
}
?>