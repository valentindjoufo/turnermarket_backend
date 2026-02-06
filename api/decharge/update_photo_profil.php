<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'config.php'; // Connexion PDO via $pdo

// Configuration des uploads
define('UPLOAD_DIR', __DIR__ . '/uploads/profils/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'image/gif']);
define('BASE_URL', 'http://10.97.71.236/gestvente/api/');

// Créer le dossier s'il n'existe pas
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Fonction pour uploader la photo de profil
function uploadPhotoProfil($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erreur lors de l\'upload: Code ' . $file['error']);
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception('Photo trop volumineuse (max 5MB)');
    }
    
    // Détecter le type MIME réel
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($detectedMimeType, ALLOWED_TYPES)) {
        throw new Exception('Type de fichier non autorisé. Utilisez JPG, PNG, WEBP ou GIF.');
    }
    
    // Vérifier l'extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($extension, $allowedExtensions)) {
        throw new Exception('Extension de fichier non autorisée.');
    }
    
    // Générer un nom unique
    $fileName = 'profil_' . uniqid() . '_' . time() . '.' . $extension;
    $filePath = UPLOAD_DIR . $fileName;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Impossible de sauvegarder la photo.');
    }
    
    // Redimensionner l'image si nécessaire (optionnel)
    resizeImage($filePath, 500, 500);
    
    return 'uploads/profils/' . $fileName;
}

// Fonction pour redimensionner l'image (optionnelle)
function resizeImage($filePath, $maxWidth, $maxHeight) {
    try {
        $imageInfo = getimagesize($filePath);
        if (!$imageInfo) return;
        
        list($width, $height, $type) = $imageInfo;
        
        // Calculer les nouvelles dimensions
        $ratio = $width / $height;
        if ($maxWidth / $maxHeight > $ratio) {
            $newWidth = $maxHeight * $ratio;
            $newHeight = $maxHeight;
        } else {
            $newWidth = $maxWidth;
            $newHeight = $maxWidth / $ratio;
        }
        
        // Créer une nouvelle image
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($filePath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($filePath);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($filePath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($filePath);
                break;
            default:
                return; // Type non supporté
        }
        
        $destination = imagecreatetruecolor($newWidth, $newHeight);
        
        // Conserver la transparence pour PNG et GIF
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
            imagecolortransparent($destination, imagecolorallocatealpha($destination, 0, 0, 0, 127));
            imagealphablending($destination, false);
            imagesavealpha($destination, true);
        }
        
        imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        // Sauvegarder l'image redimensionnée
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($destination, $filePath, 90);
                break;
            case IMAGETYPE_PNG:
                imagepng($destination, $filePath, 9);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($destination, $filePath, 90);
                break;
            case IMAGETYPE_GIF:
                imagegif($destination, $filePath);
                break;
        }
        
        imagedestroy($source);
        imagedestroy($destination);
        
    } catch (Exception $e) {
        // Ignorer les erreurs de redimensionnement
        error_log("Erreur redimensionnement: " . $e->getMessage());
    }
}

// Fonction pour supprimer une photo de profil
function deletePhotoProfil($utilisateurId, $pdo) {
    $stmt = $pdo->prepare("SELECT photoProfil FROM Utilisateur WHERE id = ?");
    $stmt->execute([$utilisateurId]);
    $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($utilisateur && $utilisateur['photoProfil']) {
        $filePath = __DIR__ . '/' . $utilisateur['photoProfil'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Mettre à jour la base de données
        $stmt = $pdo->prepare("UPDATE Utilisateur SET photoProfil = NULL WHERE id = ?");
        $stmt->execute([$utilisateurId]);
        
        return true;
    }
    
    return false;
}

// Fonction pour récupérer la photo de profil
function getPhotoProfil($utilisateurId, $pdo) {
    $stmt = $pdo->prepare("SELECT photoProfil FROM Utilisateur WHERE id = ?");
    $stmt->execute([$utilisateurId]);
    $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($utilisateur && $utilisateur['photoProfil']) {
        return [
            'photoProfil' => $utilisateur['photoProfil'],
            'photoProfilUrl' => BASE_URL . $utilisateur['photoProfil']
        ];
    }
    
    return null;
}

// Traitement des différentes méthodes HTTP
switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        // Vérifier que l'utilisateurId est présent
        if (!isset($_POST['utilisateurId']) || empty($_POST['utilisateurId'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID utilisateur requis']);
            exit;
        }
        
        $utilisateurId = intval($_POST['utilisateurId']);
        
        // Vérifier que le fichier est présent
        if (!isset($_FILES['photoProfil']) || $_FILES['photoProfil']['error'] === UPLOAD_ERR_NO_FILE) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Aucune photo fournie']);
            exit;
        }
        
        try {
            // Vérifier que l'utilisateur existe
            $stmt = $pdo->prepare("SELECT id FROM Utilisateur WHERE id = ?");
            $stmt->execute([$utilisateurId]);
            $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$utilisateur) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Utilisateur introuvable']);
                exit;
            }
            
            // Supprimer l'ancienne photo si elle existe
            deletePhotoProfil($utilisateurId, $pdo);
            
            // Uploader la nouvelle photo
            $photoProfil = uploadPhotoProfil($_FILES['photoProfil']);
            
            // Mettre à jour la base de données
            $stmt = $pdo->prepare("UPDATE Utilisateur SET photoProfil = ? WHERE id = ?");
            $stmt->execute([$photoProfil, $utilisateurId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Photo de profil mise à jour avec succès',
                'photoProfil' => $photoProfil,
                'photoProfilUrl' => BASE_URL . $photoProfil
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'GET':
        // Récupérer la photo de profil d'un utilisateur
        if (!isset($_GET['utilisateurId']) || empty($_GET['utilisateurId'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID utilisateur requis']);
            exit;
        }
        
        $utilisateurId = intval($_GET['utilisateurId']);
        
        try {
            $photoData = getPhotoProfil($utilisateurId, $pdo);
            
            if ($photoData) {
                echo json_encode([
                    'success' => true,
                    'photoProfil' => $photoData['photoProfil'],
                    'photoProfilUrl' => $photoData['photoProfilUrl']
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'photoProfil' => null,
                    'photoProfilUrl' => null,
                    'message' => 'Aucune photo de profil'
                ]);
            }
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'DELETE':
        // Supprimer la photo de profil
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['utilisateurId']) || empty($input['utilisateurId'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID utilisateur requis']);
            exit;
        }
        
        $utilisateurId = intval($input['utilisateurId']);
        
        try {
            $deleted = deletePhotoProfil($utilisateurId, $pdo);
            
            if ($deleted) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Photo de profil supprimée avec succès'
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => 'Aucune photo de profil à supprimer'
                ]);
            }
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
        break;
}
?>