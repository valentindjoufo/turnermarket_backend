<?php
// api/delete_image.php
// Supprime une image (avatar ou image de formation) de Cloudflare R2 et met à jour la base

require_once 'config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/cloudflare-config.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

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
    
    $type = $input['type'] ?? ''; // 'avatar' ou 'formation'
    $id = intval($input['id'] ?? 0); // ID de l'utilisateur ou de la formation
    $userId = intval($input['userId'] ?? 0); // pour vérification des droits

    if (empty($type) || $id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Type et ID requis']);
        exit;
    }

    // Initialisation du client S3
    $s3Client = new S3Client([
        'region' => CLOUDFLARE_REGION,
        'version' => 'latest',
        'endpoint' => CLOUDFLARE_ENDPOINT,
        'credentials' => [
            'key' => CLOUDFLARE_ACCESS_KEY,
            'secret' => CLOUDFLARE_SECRET_KEY,
        ],
        'use_path_style_endpoint' => true,
        'signature_version' => 'v4',
    ]);

    if ($type === 'avatar') {
        // Vérifier que l'utilisateur a le droit de supprimer son avatar
        if ($userId <= 0 || $userId != $id) {
            http_response_code(403);
            echo json_encode(['error' => 'Non autorisé']);
            exit;
        }

        // Récupérer la clé de l'avatar
        $stmt = $pdo->prepare("SELECT avatar_key FROM utilisateur WHERE id = ?");
        $stmt->execute([$id]);
        $avatarKey = $stmt->fetchColumn();

        if (!$avatarKey) {
            echo json_encode(['success' => true, 'message' => 'Aucun avatar à supprimer']);
            exit;
        }

        // Supprimer de R2
        try {
            $s3Client->deleteObject([
                'Bucket' => CLOUDFLARE_BUCKET,
                'Key'    => $avatarKey
            ]);
            error_log("✅ Avatar supprimé de R2: $avatarKey");
        } catch (AwsException $e) {
            error_log("⚠️ Erreur suppression avatar R2: " . $e->getMessage());
            // On continue pour mettre à jour la base même si l'objet n'existe pas
        }

        // Mettre à jour la base
        $stmt = $pdo->prepare("UPDATE utilisateur SET photoprofil = NULL, avatar_key = NULL WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Avatar supprimé']);

    } elseif ($type === 'formation') {
        // Vérifier que l'utilisateur est le vendeur de la formation
        if ($userId <= 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Non autorisé']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT vendeurId, image_key FROM produit WHERE id = ?");
        $stmt->execute([$id]);
        $formation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$formation) {
            http_response_code(404);
            echo json_encode(['error' => 'Formation non trouvée']);
            exit;
        }

        if ($formation['vendeurId'] != $userId) {
            http_response_code(403);
            echo json_encode(['error' => 'Vous n\'êtes pas le propriétaire de cette formation']);
            exit;
        }

        $imageKey = $formation['image_key'];

        if (!$imageKey) {
            echo json_encode(['success' => true, 'message' => 'Aucune image à supprimer']);
            exit;
        }

        // Supprimer de R2
        try {
            $s3Client->deleteObject([
                'Bucket' => CLOUDFLARE_BUCKET,
                'Key'    => $imageKey
            ]);
            error_log("✅ Image de formation supprimée de R2: $imageKey");
        } catch (AwsException $e) {
            error_log("⚠️ Erreur suppression image formation R2: " . $e->getMessage());
        }

        // Mettre à jour la base
        $stmt = $pdo->prepare("UPDATE produit SET imageUrl = NULL, image_key = NULL WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Image de formation supprimée']);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Type inconnu']);
    }

} catch (Exception $e) {
    error_log("❌ Erreur delete_image: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
?>