<?php
// api/commentaires.php
// Gestion des commentaires avec upload de fichiers (audio, image) vers Cloudflare R2

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cloudflare-config.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if (isset($_SERVER['HTTP_NGROK_SKIP_BROWSER_WARNING'])) {
    header("ngrok-skip-browser-warning: true");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Vérification de la connexion PDO (déjà dans config.php)
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(503);
    echo json_encode(['error' => 'Base de données non disponible']);
    exit;
}

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

define('MAX_FILE_SIZE', 15 * 1024 * 1024); // 15MB (identique à avant)

// Fonction d'upload vers R2
function uploadToR2($file, $type, $s3Client) {
    // $type : 'voice' ou 'image'
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erreur upload: Code ' . $file['error']);
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception('Fichier trop volumineux: ' . round($file['size'] / (1024*1024), 2) . ' MB');
    }

    // Détection du type MIME réel
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    // Vérification des types autorisés (basée sur les constantes de cloudflare-config.php)
    $allowedMimes = ($type === 'voice') 
        ? ['audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/webm', 'audio/ogg', 
           'audio/x-m4a', 'audio/m4a', 'video/mp4', 'audio/aac', 'audio/3gpp',
           'application/octet-stream']
        : ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];

    if (!in_array($detectedMime, $allowedMimes)) {
        throw new Exception('Type de fichier non autorisé. MIME détecté: ' . $detectedMime);
    }

    // Génération d'une clé unique pour R2
    $extension = '';
    if ($type === 'voice') {
        // On déduit l'extension du type MIME ou on garde celle du fichier original
        $extMap = [
            'audio/mp4' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/webm' => 'webm',
            'audio/ogg' => 'ogg',
            'audio/x-m4a' => 'm4a',
            'audio/m4a' => 'm4a',
            'video/mp4' => 'm4a',
            'audio/aac' => 'aac',
            'audio/3gpp' => '3gp',
        ];
        $extension = $extMap[$detectedMime] ?? 'm4a';
    } else {
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $extension = $extMap[$detectedMime] ?? 'jpg';
    }

    $key = 'commentaires/' . $type . '/' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;

    try {
        // Upload vers R2
        $s3Client->putObject([
            'Bucket' => CLOUDFLARE_BUCKET,
            'Key'    => $key,
            'SourceFile' => $file['tmp_name'],
            'ContentType' => $detectedMime,
        ]);

        $publicUrl = generateCloudflareUrl($key);
        return [
            'url' => $publicUrl,
            'key' => $key
        ];
    } catch (AwsException $e) {
        error_log("Erreur upload R2: " . $e->getMessage());
        throw new Exception("Échec de l'upload vers le cloud");
    }
}

// --- GET requests ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // (inchangé, sauf si vous souhaitez retourner directement les URLs publiques)
    // Le code existant reste valide car les URLs stockées en base sont déjà complètes
    // (nous les construirons lors de l'insertion)
    
    // Compter les messages non lus PAR UTILISATEUR
    if (isset($_GET['count_unread'], $_GET['produitId'], $_GET['utilisateurId'])) {
        $produitId = (int) $_GET['produitId'];
        $utilisateurId = (int) $_GET['utilisateurId'];

        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS nonLus 
            FROM Commentaire c 
            LEFT JOIN commentaire_vus cv ON c.id = cv.commentaire_id AND cv.utilisateur_id = ?
            WHERE c.produitId = ? 
            AND cv.id IS NULL 
            AND c.utilisateurId != ?
        ");
        $stmt->execute([$utilisateurId, $produitId, $utilisateurId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['nonLus' => (int)$result['nonlus']]);
        exit;
    }

    // Compter le total des commentaires
    if (isset($_GET['count_total'], $_GET['produitId'])) {
        $produitId = (int) $_GET['produitId'];

        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total 
            FROM Commentaire 
            WHERE produitId = :produitId
        ");
        $stmt->execute(['produitId' => $produitId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['total' => (int)$result['total']]);
        exit;
    }

    // Récupérer les commentaires avec statut "vu" par utilisateur
    if (!isset($_GET['produitId']) || !is_numeric($_GET['produitId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'produitId manquant ou invalide']);
        exit;
    }

    $produitId = (int) $_GET['produitId'];
    $utilisateurId = isset($_GET['utilisateurId']) ? (int)$_GET['utilisateurId'] : null;
    $extended = isset($_GET['extended']) && $_GET['extended'] === '1';

    $sql = "
        SELECT 
            c.id,
            c.texte,
            c.dateCreation,
            c.utilisateurId,
            u.nom AS utilisateurNom,
            u.role AS utilisateurRole";
    
    if ($utilisateurId) {
        $sql .= ",
            CASE WHEN cv.id IS NOT NULL THEN 1 ELSE 0 END as vu";
    }
    
    if ($extended) {
        $sql .= ",
            c.type,
            c.reply_to as replyTo,
            c.voice_uri as voiceUri,
            c.voice_duration as voiceDuration,
            c.image_uri as imageUri,
            c.is_edited as isEdited,
            c.date_modification as dateModification";
    }
    
    $sql .= "
        FROM Commentaire c
        INNER JOIN Utilisateur u ON c.utilisateurId = u.id";
    
    if ($utilisateurId) {
        $sql .= " 
        LEFT JOIN commentaire_vus cv ON c.id = cv.commentaire_id AND cv.utilisateur_id = :utilisateurId";
    }
    
    $sql .= "
        WHERE c.produitId = :produitId
        ORDER BY c.dateCreation DESC";

    $stmt = $pdo->prepare($sql);
    
    $params = ['produitId' => $produitId];
    if ($utilisateurId) {
        $params['utilisateurId'] = $utilisateurId;
    }
    
    $stmt->execute($params);
    $commentaires = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($commentaires as &$commentaire) {
        $commentaire['id'] = (int)$commentaire['id'];
        $commentaire['utilisateurId'] = (int)$commentaire['utilisateurid'];
        
        if (isset($commentaire['vu'])) {
            $commentaire['vu'] = (bool)$commentaire['vu'];
        }
        
        if ($commentaire['utilisateurrole'] === 'admin') {
            $commentaire['utilisateurnom'] = 'Admin';
        }
        unset($commentaire['utilisateurrole']);
        
        if ($extended) {
            $commentaire['type'] = $commentaire['type'] ?: 'text';
            $commentaire['replyTo'] = $commentaire['replyto'] ? (int)$commentaire['replyto'] : null;
            $commentaire['voiceDuration'] = $commentaire['voiceduration'] ? (int)$commentaire['voiceduration'] : null;
            $commentaire['isEdited'] = (bool)$commentaire['isedited'];
            // Les URLs sont déjà complètes, pas besoin de vérifier l'existence locale
            // (on peut conserver les URLs telles quelles)
        }
    }

    echo json_encode($commentaires);
    exit;
}

// --- POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== POST REQUEST DEBUG ===");

    // Marquer comme lus (inchangé)
    if (isset($_GET['marquer_vus'])) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['utilisateurId']) || !is_numeric($input['utilisateurId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'utilisateurId manquant ou invalide']);
            exit;
        }
        $utilisateurId = (int) $input['utilisateurId'];

        try {
            if (isset($input['produitId']) && is_numeric($input['produitId'])) {
                $produitId = (int) $input['produitId'];
                $sql = "SELECT c.id 
                        FROM Commentaire c 
                        LEFT JOIN commentaire_vus cv ON c.id = cv.commentaire_id AND cv.utilisateur_id = ?
                        WHERE c.produitId = ? 
                        AND cv.id IS NULL 
                        AND c.utilisateurId != ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$utilisateurId, $produitId, $utilisateurId]);
                $commentairesNonLus = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $insertSql = "INSERT INTO commentaire_vus (commentaire_id, utilisateur_id, date_vue) 
                              VALUES (?, ?, CURRENT_TIMESTAMP) 
                              ON CONFLICT (commentaire_id, utilisateur_id) DO NOTHING";
                $insertStmt = $pdo->prepare($insertSql);
                
                foreach ($commentairesNonLus as $commentaireId) {
                    $insertStmt->execute([$commentaireId, $utilisateurId]);
                }
                
                echo json_encode(['success' => true, 'marques' => count($commentairesNonLus)]);
            } else {
                // Marquer tous
                $sql = "SELECT c.id 
                        FROM Commentaire c 
                        LEFT JOIN commentaire_vus cv ON c.id = cv.commentaire_id AND cv.utilisateur_id = ?
                        WHERE cv.id IS NULL 
                        AND c.utilisateurId != ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$utilisateurId, $utilisateurId]);
                $commentairesNonLus = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $insertSql = "INSERT INTO commentaire_vus (commentaire_id, utilisateur_id, date_vue) 
                              VALUES (?, ?, CURRENT_TIMESTAMP) 
                              ON CONFLICT (commentaire_id, utilisateur_id) DO NOTHING";
                $insertStmt = $pdo->prepare($insertSql);
                
                foreach ($commentairesNonLus as $commentaireId) {
                    $insertStmt->execute([$commentaireId, $utilisateurId]);
                }
                
                echo json_encode(['success' => true, 'marques' => count($commentairesNonLus)]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur mise à jour vu: ' . $e->getMessage()]);
        }
        exit;
    }

    // Nouveau commentaire
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    $produitId = null;
    $utilisateurId = null;
    $texte = '';
    $type = 'text';
    $replyTo = null;
    $voiceUri = null;
    $voiceKey = null;
    $voiceDuration = null;
    $imageUri = null;
    $imageKey = null;
    
    if (strpos($contentType, 'multipart/form-data') !== false || !empty($_FILES)) {
        $produitId = $_POST['produitId'] ?? null;
        $utilisateurId = $_POST['utilisateurId'] ?? null;
        $texte = $_POST['texte'] ?? '';
        $type = $_POST['type'] ?? 'text';
        $replyTo = $_POST['replyTo'] ?? null;
        
        try {
            if (isset($_FILES['voiceFile']) && $_FILES['voiceFile']['error'] !== UPLOAD_ERR_NO_FILE) {
                error_log("=== TRAITEMENT FICHIER VOCAL R2 ===");
                $uploadResult = uploadToR2($_FILES['voiceFile'], 'voice', $s3Client);
                $voiceUri = $uploadResult['url'];
                $voiceKey = $uploadResult['key'];
                $voiceDuration = intval($_POST['voiceDuration'] ?? 15);
                $type = 'voice';
                error_log("Fichier vocal uploadé vers R2: " . $voiceKey);
            }
            
            if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] !== UPLOAD_ERR_NO_FILE) {
                error_log("=== TRAITEMENT FICHIER IMAGE R2 ===");
                $uploadResult = uploadToR2($_FILES['imageFile'], 'image', $s3Client);
                $imageUri = $uploadResult['url'];
                $imageKey = $uploadResult['key'];
                $type = 'image';
                error_log("Fichier image uploadé vers R2: " . $imageKey);
            }
        } catch (Exception $e) {
            error_log("=== ERREUR UPLOAD R2 ===");
            error_log($e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erreur upload: ' . $e->getMessage()]);
            exit;
        }
        
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Données JSON invalides']);
            exit;
        }
        $produitId = $input['produitId'] ?? null;
        $utilisateurId = $input['utilisateurId'] ?? null;
        $texte = $input['texte'] ?? '';
        $type = $input['type'] ?? 'text';
        $replyTo = $input['replyTo'] ?? null;
    }

    if (!$produitId || !$utilisateurId || !is_numeric($produitId) || !is_numeric($utilisateurId)) {
        http_response_code(400);
        echo json_encode(['error' => 'produitId et utilisateurId requis et doivent être numériques']);
        exit;
    }

    if ($type === 'text' && trim($texte) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Le texte ne peut pas être vide pour un message texte']);
        exit;
    }

    try {
        // Vérification utilisateur
        $stmtUser = $pdo->prepare("SELECT nom FROM Utilisateur WHERE id = ?");
        $stmtUser->execute([$utilisateurId]);
        $utilisateur = $stmtUser->fetch();
        if (!$utilisateur) {
            http_response_code(400);
            echo json_encode(['error' => 'Utilisateur introuvable']);
            exit;
        }
        $utilisateurNom = $utilisateur['nom'];

        // Anti-doublon pour les messages texte
        if ($type === 'text' && !empty($texte)) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM Commentaire 
                WHERE produitId = ? AND utilisateurId = ? AND texte = ? 
                AND dateCreation > CURRENT_TIMESTAMP - INTERVAL '5 seconds'
            ");
            $stmt->execute([$produitId, $utilisateurId, trim($texte)]);
            $recent = $stmt->fetch();
            if ($recent['count'] > 0) {
                error_log("Doublon détecté - ignoré");
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Message déjà envoyé récemment']);
                exit;
            }
        }

        // Insertion en base (avec les nouvelles colonnes voice_key et image_key)
        $stmt = $pdo->prepare("
            INSERT INTO Commentaire (
                produitId, utilisateurId, texte, type, reply_to, 
                voice_uri, voice_key, voice_duration, image_uri, image_key, dateCreation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        
        $result = $stmt->execute([
            $produitId, $utilisateurId, trim($texte), $type, 
            $replyTo ? (int)$replyTo : null,
            $voiceUri, $voiceKey, $voiceDuration ? (int)$voiceDuration : null, 
            $imageUri, $imageKey
        ]);

        if (!$result) {
            throw new Exception('Échec de l\'insertion en base de données');
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $commentId = $row['id'];

        if (!$commentId) {
            throw new Exception('Impossible d\'obtenir l\'ID du commentaire créé');
        }

        error_log("=== COMMENTAIRE CRÉÉ AVEC SUCCÈS ===");
        error_log("ID: " . $commentId);
        error_log("Type: " . $type);
        error_log("VoiceUri: " . ($voiceUri ?: 'none'));
        error_log("ImageUri: " . ($imageUri ?: 'none'));

        $response = [
            'success' => true,
            'id' => (int)$commentId,
            'utilisateurNom' => $utilisateurNom,
            'dateCreation' => date('Y-m-d H:i:s'),
            'type' => $type,
            'message' => 'Commentaire ajouté avec succès'
        ];

        if ($voiceUri) {
            $response['voiceUri'] = $voiceUri; // URL publique complète
            $response['voiceDuration'] = $voiceDuration;
        }
        if ($imageUri) {
            $response['imageUri'] = $imageUri; // URL publique complète
        }

        echo json_encode($response);

    } catch (Exception $e) {
        error_log("=== ERREUR BASE DE DONNÉES ===");
        error_log("Erreur: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur base de données: ' . $e->getMessage()]);
    }
    exit;
}

// --- PUT requests (édition) ---
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Données JSON invalides']);
        exit;
    }

    $id = $input['id'] ?? null;
    $texte = $input['texte'] ?? '';
    $utilisateurId = $input['utilisateurId'] ?? null;

    if (!$id || !$utilisateurId || !is_numeric($id) || !is_numeric($utilisateurId)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID et utilisateurId requis']);
        exit;
    }

    if (trim($texte) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Le texte ne peut pas être vide']);
        exit;
    }

    try {
        $stmtCheck = $pdo->prepare("SELECT utilisateurId, type FROM Commentaire WHERE id = ?");
        $stmtCheck->execute([$id]);
        $comment = $stmtCheck->fetch();

        if (!$comment) {
            http_response_code(404);
            echo json_encode(['error' => 'Commentaire non trouvé']);
            exit;
        }

        if ($comment['utilisateurid'] != $utilisateurId) {
            http_response_code(403);
            echo json_encode(['error' => 'Vous ne pouvez pas modifier ce commentaire']);
            exit;
        }

        if ($comment['type'] !== 'text') {
            http_response_code(400);
            echo json_encode(['error' => 'Seuls les messages texte peuvent être modifiés']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE Commentaire SET texte = ?, is_edited = 1, date_modification = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([trim($texte), $id]);

        echo json_encode(['success' => true, 'message' => 'Commentaire modifié avec succès']);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur base de données: ' . $e->getMessage()]);
    }
    exit;
}

// --- DELETE requests ---
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = $input['id'] ?? null;
    $utilisateurId = $input['utilisateurId'] ?? null;

    if (!$id || !$utilisateurId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID et utilisateurId requis']);
        exit;
    }

    try {
        // Récupérer les clés R2 avant suppression
        $stmtCheck = $pdo->prepare("SELECT utilisateurId, voice_key, image_key FROM Commentaire WHERE id = ?");
        $stmtCheck->execute([$id]);
        $comment = $stmtCheck->fetch();

        if (!$comment || $comment['utilisateurid'] != $utilisateurId) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission refusée']);
            exit;
        }

        // Supprimer les fichiers de R2
        if ($comment['voice_key']) {
            try {
                $s3Client->deleteObject([
                    'Bucket' => CLOUDFLARE_BUCKET,
                    'Key'    => $comment['voice_key']
                ]);
                error_log("Fichier vocal supprimé de R2: " . $comment['voice_key']);
            } catch (AwsException $e) {
                error_log("Erreur suppression voice R2: " . $e->getMessage());
            }
        }
        if ($comment['image_key']) {
            try {
                $s3Client->deleteObject([
                    'Bucket' => CLOUDFLARE_BUCKET,
                    'Key'    => $comment['image_key']
                ]);
                error_log("Fichier image supprimé de R2: " . $comment['image_key']);
            } catch (AwsException $e) {
                error_log("Erreur suppression image R2: " . $e->getMessage());
            }
        }

        // Supprimer les entrées commentaire_vus
        $stmtVus = $pdo->prepare("DELETE FROM commentaire_vus WHERE commentaire_id = ?");
        $stmtVus->execute([$id]);

        // Supprimer le commentaire
        $stmt = $pdo->prepare("DELETE FROM Commentaire WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Commentaire supprimé']);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée']);
exit;
?>