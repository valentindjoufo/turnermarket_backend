<?php
// api/update_utilisateur.php
// Mise à jour du profil utilisateur avec upload d'avatar vers Cloudflare R2

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
    // Initialisation du client S3 pour R2
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

    // Récupération des données POST
    $userId = isset($_POST['userId']) ? intval($_POST['userId']) : 0;
    $nom = isset($_POST['nom']) ? trim($_POST['nom']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $telephone = isset($_POST['telephone']) ? trim($_POST['telephone']) : '';
    $adresse = isset($_POST['adresse']) ? trim($_POST['adresse']) : '';

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID utilisateur requis']);
        exit;
    }

    // Récupérer l'ancien avatar pour éventuelle suppression
    $stmt = $pdo->prepare("SELECT avatar_key, photoprofil FROM utilisateur WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Utilisateur non trouvé']);
        exit;
    }
    $oldAvatarKey = $user['avatar_key'];

    // Gestion de l'upload du nouvel avatar
    $newAvatarKey = $oldAvatarKey; // par défaut, on garde l'ancien
    $newAvatarUrl = $user['photoprofil']; // par défaut

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $tmpFile = $_FILES['avatar']['tmp_name'];
        $originalName = $_FILES['avatar']['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = $extension ?: 'jpg'; // fallback

        // Générer une clé unique pour R2
        $newAvatarKey = 'avatars/' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;

        // Upload vers R2
        try {
            $s3Client->putObject([
                'Bucket' => CLOUDFLARE_BUCKET,
                'Key'    => $newAvatarKey,
                'SourceFile' => $tmpFile,
                'ContentType' => mime_content_type($tmpFile) ?: 'image/jpeg',
            ]);

            // Si ancien avatar existe, le supprimer
            if ($oldAvatarKey) {
                try {
                    $s3Client->deleteObject([
                        'Bucket' => CLOUDFLARE_BUCKET,
                        'Key'    => $oldAvatarKey
                    ]);
                    error_log("✅ Ancien avatar supprimé de R2: $oldAvatarKey");
                } catch (AwsException $e) {
                    error_log("⚠️ Erreur suppression ancien avatar R2: " . $e->getMessage());
                }
            }

            $newAvatarUrl = generateCloudflareUrl($newAvatarKey);
            error_log("✅ Avatar uploadé vers R2: $newAvatarKey");
        } catch (AwsException $e) {
            error_log("❌ Erreur upload avatar R2: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => "Échec de l'upload de l'avatar"]);
            exit;
        }
    }

    // Mise à jour des informations en base
    // Construire la requête dynamiquement en fonction des champs fournis
    $updateFields = [];
    $params = [];

    if (!empty($nom)) {
        $updateFields[] = "nom = ?";
        $params[] = $nom;
    }
    if (!empty($email)) {
        $updateFields[] = "email = ?";
        $params[] = $email;
    }
    if (!empty($telephone)) {
        $updateFields[] = "telephone = ?";
        $params[] = $telephone;
    }
    if (!empty($adresse)) {
        $updateFields[] = "adresse = ?";
        $params[] = $adresse;
    }
    // Mettre à jour l'avatar seulement si un nouveau a été uploadé
    if ($newAvatarKey !== $oldAvatarKey) {
        $updateFields[] = "photoprofil = ?";
        $params[] = $newAvatarUrl;
        $updateFields[] = "avatar_key = ?";
        $params[] = $newAvatarKey;
    }

    if (empty($updateFields)) {
        echo json_encode(['success' => true, 'message' => 'Aucune modification']);
        exit;
    }

    $params[] = $userId;
    $sql = "UPDATE utilisateur SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'message' => 'Profil mis à jour',
        'photoprofil' => $newAvatarUrl,
        'avatar_key' => $newAvatarKey
    ]);

} catch (PDOException $e) {
    error_log("❌ Erreur update_utilisateur: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de données']);
} catch (Exception $e) {
    error_log("❌ Erreur update_utilisateur: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
?>