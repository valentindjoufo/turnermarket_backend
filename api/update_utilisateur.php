<?php
// En-têtes CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Gérer les requêtes OPTIONS (pré-vol CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'config.php'; // Connexion PDO dans $pdo

// Configuration pour les uploads de photos - CORRIGÉ
define('UPLOAD_BASE_DIR', __DIR__ . '/uploads/');
define('PROFILS_DIR', 'profils/');
define('UPLOAD_DIR', UPLOAD_BASE_DIR . PROFILS_DIR);
define('BASE_URL', 'http://10.97.71.236/gestvente/api/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

// Créer les dossiers uploads s'ils n'existent pas
if (!file_exists(UPLOAD_BASE_DIR)) {
    mkdir(UPLOAD_BASE_DIR, 0777, true);
}
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

// Récupérer les données JSON envoyées
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données JSON manquantes ou invalides']);
    exit;
}

// Vérification des champs nécessaires
if (!isset($data['id'], $data['nom'], $data['sexe'], $data['nationalite'], $data['telephone'], $data['email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Champs obligatoires manquants']);
    exit;
}

// Nettoyage / validation simple
$id = intval($data['id']);
$nom = trim($data['nom']);
$sexe = trim($data['sexe']);
$nationalite = trim($data['nationalite']);
$telephone = trim($data['telephone']);
$email = trim($data['email']);
$photoProfil = isset($data['photoProfil']) ? $data['photoProfil'] : null;

try {
    // Vérifier si l'utilisateur existe
    $checkStmt = $pdo->prepare("SELECT id, photoProfil FROM Utilisateur WHERE id = ?");
    $checkStmt->execute([$id]);
    $utilisateurExistant = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$utilisateurExistant) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Utilisateur non trouvé']);
        exit;
    }

    // Vérifier l'unicité de l'email si modifié
    if (isset($data['email'])) {
        $emailStmt = $pdo->prepare("SELECT id FROM Utilisateur WHERE email = ? AND id != ?");
        $emailStmt->execute([$email, $id]);
        if ($emailStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Cet email est déjà utilisé par un autre utilisateur']);
            exit;
        }
    }

    // Gestion de la photo de profil
    $nouvellePhoto = null;
    $anciennePhoto = $utilisateurExistant['photoProfil'];

    // Cas 1: Photo fournie en base64 (nouvelle photo)
    if ($photoProfil && preg_match('/^data:image\/(jpeg|png|gif|webp|jpg);base64,/', $photoProfil, $matches)) {
        $imageType = $matches[1];
        // Corriger l'extension pour jpg
        if ($imageType === 'jpg') $imageType = 'jpeg';
        
        $imageData = base64_decode(substr($photoProfil, strpos($photoProfil, ',') + 1));
        
        // Valider les données base64
        if ($imageData === false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Données image base64 invalides']);
            exit;
        }

        // Valider la taille du fichier
        if (strlen($imageData) > MAX_FILE_SIZE) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Image trop volumineuse (max 5MB)']);
            exit;
        }

        // Générer un nom de fichier unique - CORRIGÉ selon votre format
        $nouvellePhoto = 'profil_' . uniqid() . '_' . time() . '.' . $imageType;
        $filePath = UPLOAD_DIR . $nouvellePhoto;

        // Sauvegarder le fichier
        if (file_put_contents($filePath, $imageData) === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erreur lors de la sauvegarde de l\'image']);
            exit;
        }

        // Supprimer l'ancienne photo si elle existe
        if ($anciennePhoto) {
            $oldFilePath = UPLOAD_BASE_DIR . $anciennePhoto;
            if (file_exists($oldFilePath)) {
                unlink($oldFilePath);
            }
        }
    }
    // Cas 2: Photo fournie comme nom de fichier (déjà uploadée)
    elseif ($photoProfil && is_string($photoProfil) && !empty($photoProfil)) {
        $nouvellePhoto = $photoProfil;
    }
    // Cas 3: Photo est null (supprimer la photo)
    elseif ($photoProfil === null) {
        $nouvellePhoto = null;
        // Supprimer l'ancienne photo si elle existe
        if ($anciennePhoto) {
            $oldFilePath = UPLOAD_BASE_DIR . $anciennePhoto;
            if (file_exists($oldFilePath)) {
                unlink($oldFilePath);
            }
        }
    }
    // Cas 4: Aucune photo fournie, garder l'ancienne
    else {
        $nouvellePhoto = $anciennePhoto;
    }

    // Préparer la requête SQL de mise à jour
    if ($nouvellePhoto !== $anciennePhoto) {
        // Mise à jour avec la photo - CORRIGÉ : chemin COMPLET avec "uploads/"
        $photoPourBDD = $nouvellePhoto ? ('uploads/' . PROFILS_DIR . $nouvellePhoto) : null;
        
        $stmt = $pdo->prepare("UPDATE Utilisateur SET 
            nom = :nom,
            sexe = :sexe,
            nationalite = :nationalite,
            telephone = :telephone,
            email = :email,
            photoProfil = :photoProfil
            WHERE id = :id");
        $stmt->bindParam(':photoProfil', $photoPourBDD);
    } else {
        // Mise à jour sans changer la photo
        $stmt = $pdo->prepare("UPDATE Utilisateur SET 
            nom = :nom,
            sexe = :sexe,
            nationalite = :nationalite,
            telephone = :telephone,
            email = :email
            WHERE id = :id");
    }

    $stmt->bindParam(':nom', $nom);
    $stmt->bindParam(':sexe', $sexe);
    $stmt->bindParam(':nationalite', $nationalite);
    $stmt->bindParam(':telephone', $telephone);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);

    $stmt->execute();

    // Préparer la réponse
    $response = [
        'success' => true, 
        'message' => 'Profil mis à jour avec succès',
        'photoUpdated' => ($nouvellePhoto !== $anciennePhoto)
    ];
    
    // Ajouter l'information sur la photo si elle a été mise à jour
    if ($nouvellePhoto !== $anciennePhoto) {
        $response['photoProfil'] = 'uploads/' . PROFILS_DIR . $nouvellePhoto; // Chemin complet
        $response['photoProfilUrl'] = BASE_URL . 'uploads/' . PROFILS_DIR . $nouvellePhoto; // URL complète
    }

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
}