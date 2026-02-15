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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $fileName = $input['fileName'] ?? '';
    $produitId = intval($input['produitId'] ?? 0);
    $type = $input['type'] ?? 'video'; // 'video' ou 'preview'
    
    if ($produitId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'produitId requis et doit être > 0']);
        exit;
    }
    
    if (empty($fileName)) {
        // Générer un nom par défaut si non fourni
        $fileName = 'video_' . uniqid() . '.mp4';
    }
    
    $client = getCloudflareS3Client();
    
    // Générer une clé d'objet unique
    $objectKey = generateR2ObjectKey($fileName, $produitId, $type);
    
    // Déterminer le Content-Type à partir de l'extension
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeTypes = [
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'wmv' => 'video/x-ms-wmv',
        'mkv' => 'video/x-matroska',
        'webm' => 'video/webm',
        'flv' => 'video/x-flv',
        '3gp' => 'video/3gpp',
        'm4v' => 'video/mp4',
    ];
    $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
    // Générer une URL signée pour l'upload (valide 1 heure)
    $cmd = $client->getCommand('PutObject', [
        'Bucket' => CLOUDFLARE_BUCKET,
        'Key'    => $objectKey,
        'ContentType' => $contentType,
        // 'ACL' => 'public-read', // R2 ne supporte pas ACL, on l'omet
    ]);
    
    $request = $client->createPresignedRequest($cmd, '+60 minutes');
    $presignedUrl = (string)$request->getUri();
    
    echo json_encode([
        'success'    => true,
        'uploadUrl'  => $presignedUrl,
        'objectKey'  => $objectKey,
        'publicUrl'  => generateCloudflareUrl($objectKey),
        'expires'    => time() + 3600,
    ]);
    
} catch (Exception $e) {
    error_log("❌ Erreur generate-upload-url: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur interne du serveur'
    ]);
}
?>