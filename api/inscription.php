<?php
/**
 * inscription.php - Gestion de l'inscription des utilisateurs
 * Version CORRIGÉE avec TRIM sur tous les champs
 */

// 📦 Inclusion de la configuration (connexion PDO PostgreSQL)
require_once 'config.php';

// 🚦 Autoriser l'accès à toutes les origines (CORS)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// 📤 Gestion des requêtes pré-vol OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 🔧 Configuration des uploads
define('UPLOAD_DIR', __DIR__ . '/uploads/profils/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('BASE_URL', '/gestvente/api/');

// 📁 Créer le dossier s'il n'existe pas
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// 📤 Fonction pour uploader la photo de profil
function uploadPhotoProfil($file) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'image/gif'];
    
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
    
    if (!in_array($detectedMimeType, $allowedTypes)) {
        throw new Exception('Type de fichier non autorisé. Utilisez JPG, PNG, WEBP ou GIF.');
    }
    
    // 🔒 Validation supplémentaire par extension
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($extension, $allowedExtensions)) {
        throw new Exception('Extension de fichier non autorisée.');
    }
    
    // 🏷️ Générer un nom unique
    $fileName = 'profil_' . uniqid() . '_' . time() . '.' . $extension;
    $filePath = UPLOAD_DIR . $fileName;
    
    // 📤 Déplacer le fichier uploadé
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Impossible de sauvegarder la photo.');
    }
    
    // ✅ Vérifier que le fichier a bien été créé
    if (!file_exists($filePath)) {
        throw new Exception('Échec de la création du fichier.');
    }
    
    return 'uploads/profils/' . $fileName;
}

// 🔢 Générer un matricule unique
function genererMatricule($prefix = "USR") {
    return $prefix . strtoupper(uniqid());
}

try {
    // 💾 Vérification que la connexion PDO est bien disponible
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    // 📥 Traitement de l'inscription
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    // Variables
    $nom = '';
    $sexe = '';
    $nationalite = '';
    $telephone = '';
    $email = '';
    $motDePasse = '';
    $photoProfil = null;

    error_log("=== TRAITEMENT INSCRIPTION ===");
    error_log("Content-Type: $contentType");

    // Vérifier si c'est un formulaire multipart (avec photo)
    if (strpos($contentType, 'multipart/form-data') !== false || !empty($_FILES)) {
        error_log("📤 Formulaire multipart détecté");
        
        // ✅ CORRECTION : Récupération des données avec TRIM
        $nom = trim($_POST['nom'] ?? '');
        $sexe = trim($_POST['sexe'] ?? '');
        $nationalite = trim($_POST['nationalite'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $motDePasse = trim($_POST['motDePasse'] ?? '');  // ✅ TRIM AJOUTÉ
        
        error_log("📝 Mot de passe reçu (FormData) : '$motDePasse' (longueur: " . strlen($motDePasse) . ")");
        
        // 📷 Upload de la photo si présente
        if (isset($_FILES['photoProfil']) && $_FILES['photoProfil']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $photoProfil = uploadPhotoProfil($_FILES['photoProfil']);
                error_log("✅ Photo uploadée: $photoProfil");
            } catch (Exception $e) {
                error_log("❌ Erreur upload photo: " . $e->getMessage());
                http_response_code(400);
                echo json_encode(["success" => false, "message" => $e->getMessage()]);
                exit;
            }
        } else {
            error_log("ℹ️ Aucune photo fournie");
        }
    } else {
        // 📝 Données JSON (sans photo)
        error_log("📝 Données JSON détectées");
        $raw = file_get_contents("php://input");
        $data = json_decode($raw, true);
        
        if (!$data) {
            error_log("❌ Données JSON invalides");
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Données JSON invalides ou manquantes."]);
            exit;
        }
        
        // ✅ CORRECTION : Trim sur les données JSON aussi
        $nom = trim($data['nom'] ?? '');
        $sexe = trim($data['sexe'] ?? '');
        $nationalite = trim($data['nationalite'] ?? '');
        $telephone = trim($data['telephone'] ?? '');
        $email = trim($data['email'] ?? '');
        $motDePasse = trim($data['motDePasse'] ?? '');  // ✅ TRIM AJOUTÉ
        
        error_log("📝 Mot de passe reçu (JSON) : '$motDePasse' (longueur: " . strlen($motDePasse) . ")");
    }

    // 🛡️ Vérification des champs requis
    $champsRequis = ['nom', 'sexe', 'nationalite', 'telephone', 'email', 'motDePasse'];
    $champsVides = [];

    foreach ($champsRequis as $champ) {
        if (empty($$champ)) {
            $champsVides[] = $champ;
        }
    }

    if (!empty($champsVides)) {
        error_log("❌ Champs requis manquants: " . implode(', ', $champsVides));
        
        // Supprimer la photo si elle a été uploadée
        if ($photoProfil && file_exists(__DIR__ . '/' . $photoProfil)) {
            unlink(__DIR__ . '/' . $photoProfil);
        }
        
        http_response_code(400);
        echo json_encode([
            "success" => false, 
            "message" => "Champs requis manquants: " . implode(', ', $champsVides)
        ]);
        exit;
    }

    // 🛡️ Validation de l'email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log("❌ Format d'email invalide: $email");
        
        if ($photoProfil && file_exists(__DIR__ . '/' . $photoProfil)) {
            unlink(__DIR__ . '/' . $photoProfil);
        }
        
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Format d'email invalide."]);
        exit;
    }

    // 🛡️ Validation du mot de passe (minimum 6 caractères)
    if (strlen($motDePasse) < 6) {
        error_log("❌ Mot de passe trop court: " . strlen($motDePasse) . " caractères");
        
        if ($photoProfil && file_exists(__DIR__ . '/' . $photoProfil)) {
            unlink(__DIR__ . '/' . $photoProfil);
        }
        
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Le mot de passe doit contenir au moins 6 caractères."]);
        exit;
    }

    // 🔢 Générer un matricule unique
    $matricule = genererMatricule();
    error_log("🔢 Matricule généré: $matricule");

    // 🔐 Hachage du mot de passe
    $motDePasseHache = password_hash($motDePasse, PASSWORD_DEFAULT);
    error_log("🔐 Hash généré : " . substr($motDePasseHache, 0, 30) . "... (longueur: " . strlen($motDePasseHache) . ")");
    
    // ✅ TEST : Vérifier immédiatement que le hash fonctionne
    if (password_verify($motDePasse, $motDePasseHache)) {
        error_log("✅ Vérification hash : OK - Le mot de passe peut être vérifié");
    } else {
        error_log("❌ ERREUR CRITIQUE : Le hash ne peut pas être vérifié !");
    }

    // 🔍 Vérifier si l'email ou téléphone existe déjà
    $checkStmt = $pdo->prepare("SELECT id FROM utilisateur WHERE email = ? OR telephone = ?");
    $checkStmt->execute([$email, $telephone]);
    
    if ($checkStmt->fetch()) {
        error_log("❌ Email ou téléphone déjà utilisé - Email: $email, Téléphone: $telephone");
        
        // Supprimer la photo uploadée si l'utilisateur existe déjà
        if ($photoProfil && file_exists(__DIR__ . '/' . $photoProfil)) {
            unlink(__DIR__ . '/' . $photoProfil);
        }
        
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Email ou téléphone déjà utilisé."]);
        exit;
    }

    // 📝 Insertion dans la base de données
    // ✅ CORRECTION : Utiliser les noms de colonnes en MINUSCULE (PostgreSQL)
    $stmt = $pdo->prepare("
        INSERT INTO utilisateur (
            matricule, nom, sexe, nationalite, telephone, email, motdepasse, photoprofil,
            role, etat, datecreation, nombrefollowers, nombrefollowing, 
            notevendeur, soldevendeur, nbventes, statutvendeur
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'client', 'actif', NOW(), 0, 0, 0, 0, 0, 'nouveau')
    ");
    
    $stmt->execute([
        $matricule,
        $nom,
        $sexe,
        $nationalite,
        $telephone,
        $email,
        $motDePasseHache,
        $photoProfil
    ]);

    $userId = $pdo->lastInsertId();
    
    error_log("✅ Utilisateur créé - ID: $userId, Matricule: $matricule, Nom: $nom");
    error_log("✅ Email: $email, Mot de passe hashé stocké");

    $response = [
        "success" => true,
        "message" => "Inscription réussie",
        "user" => [
            "id" => (int)$userId,
            "matricule" => $matricule,
            "nom" => $nom,
            "email" => $email,
            "telephone" => $telephone,
            "role" => "client",
            "etat" => "actif"
        ],
        "timestamp" => date('Y-m-d H:i:s')
    ];
    
    // 📷 Ajouter l'URL de la photo si présente
    if ($photoProfil) {
        $response['user']['photoProfil'] = BASE_URL . $photoProfil;
        $response['user']['photoProfilPath'] = $photoProfil;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    // ❌ Erreur de base de données
    error_log("❌ ERREUR PDO INSCRIPTION: " . $e->getMessage());
    
    // Supprimer la photo en cas d'erreur d'insertion
    if ($photoProfil && file_exists(__DIR__ . '/' . $photoProfil)) {
        unlink(__DIR__ . '/' . $photoProfil);
    }
    
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "Erreur lors de l'inscription",
        "debug" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // ❌ Autres erreurs
    error_log("❌ ERREUR INSCRIPTION: " . $e->getMessage());
    
    // Supprimer la photo en cas d'erreur
    if ($photoProfil && file_exists(__DIR__ . '/' . $photoProfil)) {
        unlink(__DIR__ . '/' . $photoProfil);
    }
    
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ]);
}
?>