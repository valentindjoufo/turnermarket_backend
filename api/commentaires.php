<?php 
// Inclure la configuration de connexion à la base de données
require_once 'config.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if (isset($_SERVER['HTTP_NGROK_SKIP_BROWSER_WARNING'])) {
    header("ngrok-skip-browser-warning: true");
}

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration de debug
error_reporting(E_ALL);
ini_set('display_errors', 1);

// La variable $pdo est déjà définie dans config.php
// Configuration PostgreSQL - ajuster le jeu de caractères si nécessaire
$pdo->exec("SET NAMES 'UTF8'");

// Configuration des uploads
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('VOICE_DIR', UPLOAD_DIR . 'voice/');
define('IMAGE_DIR', UPLOAD_DIR . 'images/');
define('MAX_FILE_SIZE', 15 * 1024 * 1024); // 15MB pour les fichiers audio/vidéo

// Créer les dossiers s'ils n'existent pas
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
if (!is_dir(VOICE_DIR)) mkdir(VOICE_DIR, 0755, true);
if (!is_dir(IMAGE_DIR)) mkdir(IMAGE_DIR, 0755, true);

// Fonction upload
function uploadFile($file, $type = 'voice') {
    $uploadDir = ($type === 'voice') ? VOICE_DIR : IMAGE_DIR;
    
    $allowedTypes = ($type === 'voice') 
        ? [
            'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/webm', 'audio/ogg', 
            'audio/x-m4a', 'audio/m4a', 'video/mp4', 'audio/aac', 'audio/3gpp',
            'application/octet-stream'
          ]
        : ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];
    
    error_log("=== UPLOAD FILE DEBUG ===");
    error_log("File name: " . $file['name']);
    error_log("File size: " . $file['size']);
    error_log("File error: " . $file['error']);
    error_log("File type reported: " . ($file['type'] ?? 'non défini'));
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erreur upload: Code ' . $file['error']);
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception('Fichier trop volumineux: ' . round($file['size'] / (1024*1024), 2) . ' MB');
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    error_log("MIME type détecté: " . $detectedMimeType);
    
    $pathInfo = pathinfo($file['name']);
    $extension = isset($pathInfo['extension']) ? strtolower($pathInfo['extension']) : '';
    error_log("Extension originale: " . $extension);
    
    $isValidType = false;
    $finalExtension = '';
    
    if ($type === 'voice') {
        $validExtensions = ['mp4', 'm4a', 'wav', 'mp3', 'ogg', 'webm', 'aac', '3gp'];
        $isValidType = in_array($detectedMimeType, $allowedTypes) || 
                      in_array($extension, $validExtensions) ||
                      strpos($detectedMimeType, 'audio') !== false ||
                      strpos($detectedMimeType, 'video') !== false;
        
        if (in_array($extension, $validExtensions)) {
            $finalExtension = '.' . $extension;
        } else if (strpos($detectedMimeType, 'mp4') !== false || strpos($detectedMimeType, 'm4a') !== false) {
            $finalExtension = '.m4a';
        } else if (strpos($detectedMimeType, 'webm') !== false) {
            $finalExtension = '.webm';
        } else if (strpos($detectedMimeType, 'wav') !== false) {
            $finalExtension = '.wav';
        } else if (strpos($detectedMimeType, 'ogg') !== false) {
            $finalExtension = '.ogg';
        } else {
            $finalExtension = '.m4a';
        }
    } else {
        $isValidType = in_array($detectedMimeType, $allowedTypes) || 
                      in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $finalExtension = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? 
                         '.' . $extension : '.jpg';
    }
    
    if (!$isValidType) {
        throw new Exception('Type de fichier non autorisé. MIME: ' . $detectedMimeType . ', Extension: ' . $extension);
    }
    
    $fileName = uniqid() . '_' . time() . $finalExtension;
    $filePath = $uploadDir . $fileName;
    
    error_log("Tentative de sauvegarde vers: " . $filePath);
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Impossible de sauvegarder le fichier. Vérifiez les permissions du dossier.');
    }
    
    if (!file_exists($filePath)) {
        throw new Exception('Le fichier n\'a pas été sauvegardé correctement.');
    }
    
    error_log("Fichier sauvé avec succès: " . $filePath . " (taille: " . filesize($filePath) . " bytes)");
    
    // Retourner le chemin relatif uniquement
    return 'uploads/' . ($type === 'voice' ? 'voice/' : 'images/') . $fileName;
}

// --- GET requests ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
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
        echo json_encode(['nonLus' => (int)$result['nonlus']]); // PostgreSQL retourne en minuscules
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
    
    // AJOUT: Inclure le statut "vu" par cet utilisateur
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
    
    // AJOUT: Jointure avec la table commentaire_vus
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
        $commentaire['utilisateurId'] = (int)$commentaire['utilisateurnid']; // PostgreSQL retourne en minuscules
        
        // CORRIGÉ: Le statut "vu" est maintenant spécifique à l'utilisateur
        if (isset($commentaire['vu'])) {
            $commentaire['vu'] = (bool)$commentaire['vu'];
        }
        
        if ($commentaire['utilisateurrole'] === 'admin') { // minuscules
            $commentaire['utilisateurnom'] = 'Admin'; // minuscules
        }
        unset($commentaire['utilisateurrole']);
        
        if ($extended) {
            $commentaire['type'] = $commentaire['type'] ?: 'text';
            $commentaire['replyTo'] = $commentaire['replyto'] ? (int)$commentaire['replyto'] : null;
            $commentaire['voiceDuration'] = $commentaire['voiceduration'] ? (int)$commentaire['voiceduration'] : null;
            $commentaire['isEdited'] = (bool)$commentaire['isedited'];
            
            // SOLUTION 1 APPLIQUÉE: Retourner uniquement le chemin relatif
            if ($commentaire['voiceuri']) {
                $fullPath = __DIR__ . '/' . $commentaire['voiceuri'];
                if (!file_exists($fullPath)) {
                    error_log("Fichier vocal manquant: " . $fullPath);
                    $commentaire['voiceUri'] = null;
                } else {
                    $commentaire['voiceUri'] = $commentaire['voiceuri'];
                }
            }
            if ($commentaire['imageuri']) {
                $fullPath = __DIR__ . '/' . $commentaire['imageuri'];
                if (!file_exists($fullPath)) {
                    error_log("Fichier image manquant: " . $fullPath);
                    $commentaire['imageUri'] = null;
                } else {
                    $commentaire['imageUri'] = $commentaire['imageuri'];
                }
            }
        }
    }

    echo json_encode($commentaires);
    exit;
}

// --- POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== POST REQUEST DEBUG ===");
    error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'non défini'));
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));

    // CORRIGÉ: Marquer comme lus PAR UTILISATEUR
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
                
                // CORRIGÉ: Récupérer les commentaires non lus pour ce produit
                $sql = "SELECT c.id 
                        FROM Commentaire c 
                        LEFT JOIN commentaire_vus cv ON c.id = cv.commentaire_id AND cv.utilisateur_id = ?
                        WHERE c.produitId = ? 
                        AND cv.id IS NULL 
                        AND c.utilisateurId != ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$utilisateurId, $produitId, $utilisateurId]);
                $commentairesNonLus = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // CORRIGÉ: Marquer chaque commentaire comme lu pour cet utilisateur
                // PostgreSQL: Utiliser ON CONFLICT au lieu de INSERT IGNORE
                $insertSql = "INSERT INTO commentaire_vus (commentaire_id, utilisateur_id, date_vue) 
                              VALUES (?, ?, CURRENT_TIMESTAMP) 
                              ON CONFLICT (commentaire_id, utilisateur_id) DO NOTHING";
                $insertStmt = $pdo->prepare($insertSql);
                
                foreach ($commentairesNonLus as $commentaireId) {
                    $insertStmt->execute([$commentaireId, $utilisateurId]);
                }
                
                echo json_encode(['success' => true, 'marques' => count($commentairesNonLus)]);
            } else {
                // Marquer tous les commentaires non lus comme lus pour cet utilisateur
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
    $voiceDuration = null;
    $imageUri = null;
    
    if (strpos($contentType, 'multipart/form-data') !== false || !empty($_FILES)) {
        $produitId = $_POST['produitId'] ?? null;
        $utilisateurId = $_POST['utilisateurId'] ?? null;
        $texte = $_POST['texte'] ?? '';
        $type = $_POST['type'] ?? 'text';
        $replyTo = $_POST['replyTo'] ?? null;
        
        try {
            if (isset($_FILES['voiceFile']) && $_FILES['voiceFile']['error'] !== UPLOAD_ERR_NO_FILE) {
                error_log("=== TRAITEMENT FICHIER VOCAL ===");
                if ($_FILES['voiceFile']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Erreur upload vocal: ' . $_FILES['voiceFile']['error']);
                }
                $voiceUri = uploadFile($_FILES['voiceFile'], 'voice');
                $voiceDuration = intval($_POST['voiceDuration'] ?? 15);
                $type = 'voice';
                error_log("Fichier vocal traité: " . $voiceUri . " (durée: " . $voiceDuration . "s)");
            }
            
            if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] !== UPLOAD_ERR_NO_FILE) {
                error_log("=== TRAITEMENT FICHIER IMAGE ===");
                if ($_FILES['imageFile']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Erreur upload image: ' . $_FILES['imageFile']['error']);
                }
                $imageUri = uploadFile($_FILES['imageFile'], 'image');
                $type = 'image';
                error_log("Fichier image traité: " . $imageUri);
            }
        } catch (Exception $e) {
            error_log("=== ERREUR UPLOAD ===");
            error_log("Erreur upload: " . $e->getMessage());
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
        $stmtUser = $pdo->prepare("SELECT nom FROM Utilisateur WHERE id = ?");
        $stmtUser->execute([$utilisateurId]);
        $utilisateur = $stmtUser->fetch();
        if (!$utilisateur) {
            http_response_code(400);
            echo json_encode(['error' => 'Utilisateur introuvable']);
            exit;
        }
        $utilisateurNom = $utilisateur['nom'];

        if ($type === 'text' && !empty($texte)) {
            // PostgreSQL: Utiliser CURRENT_TIMESTAMP au lieu de NOW()
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

        // SUPPRIMÉ: Le champ 'vu' n'est plus utilisé dans Commentaire
        // PostgreSQL: Utiliser CURRENT_TIMESTAMP au lieu de NOW()
        $stmt = $pdo->prepare("
            INSERT INTO Commentaire (
                produitId, utilisateurId, texte, type, reply_to, 
                voice_uri, voice_duration, image_uri, dateCreation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        
        $result = $stmt->execute([
            $produitId, $utilisateurId, trim($texte), $type, 
            $replyTo ? (int)$replyTo : null,
            $voiceUri, $voiceDuration ? (int)$voiceDuration : null, $imageUri
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

        // SOLUTION 1 APPLIQUÉE: Retourner uniquement le chemin relatif
        if ($voiceUri) {
            $response['voiceUri'] = $voiceUri; // Chemin relatif uniquement
            $response['voiceDuration'] = $voiceDuration;
        }
        
        if ($imageUri) {
            $response['imageUri'] = $imageUri; // Chemin relatif uniquement
        }

        echo json_encode($response);

    } catch (Exception $e) {
        error_log("=== ERREUR BASE DE DONNÉES ===");
        error_log("Erreur: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
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

        if ($comment['utilisateurnid'] != $utilisateurId) { // minuscules
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
        $stmtCheck = $pdo->prepare("SELECT utilisateurId, voice_uri, image_uri FROM Commentaire WHERE id = ?");
        $stmtCheck->execute([$id]);
        $comment = $stmtCheck->fetch();

        if (!$comment || $comment['utilisateurnid'] != $utilisateurId) { // minuscules
            http_response_code(403);
            echo json_encode(['error' => 'Permission refusée']);
            exit;
        }

        // Supprimer les fichiers
        if ($comment['voice_uri']) {
            $filePath = __DIR__ . '/' . $comment['voice_uri'];
            if (file_exists($filePath)) {
                unlink($filePath);
                error_log("Fichier vocal supprimé: " . $filePath);
            }
        }
        if ($comment['image_uri']) {
            $filePath = __DIR__ . '/' . $comment['image_uri'];
            if (file_exists($filePath)) {
                unlink($filePath);
                error_log("Fichier image supprimé: " . $filePath);
            }
        }

        // CORRIGÉ: Supprimer aussi les entrées dans commentaire_vus
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