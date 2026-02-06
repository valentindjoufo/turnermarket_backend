<?php
// Autoriser l'accès à toutes les origines (CORS)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Gestion des requêtes pré-vol OPTIONS (nécessaire pour CORS côté Web)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration des uploads
define('UPLOAD_DIR', __DIR__ . '/uploads/profils/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('BASE_URL', 'http://10.97.71.236/gestvente/api/');

// Créer le dossier s'il n'existe pas
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Connexion à la base de données
try {
    $pdo = new PDO('mysql:host=localhost;dbname=gestvente;charset=utf8', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erreur de connexion à la base de données."]);
    exit;
}

// Fonction pour uploader la photo de profil
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
    
    // Générer un nom unique
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (!$extension) {
        $extension = 'jpg';
    }
    $fileName = 'profil_' . uniqid() . '_' . time() . '.' . strtolower($extension);
    $filePath = UPLOAD_DIR . $fileName;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Impossible de sauvegarder la photo.');
    }
    
    return 'uploads/profils/' . $fileName;
}

// Générer un matricule unique
function genererMatricule($prefix = "USR") {
    return $prefix . strtoupper(uniqid());
}

// Traitement de l'inscription
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

// Variables
$nom = '';
$sexe = '';
$nationalite = '';
$telephone = '';
$email = '';
$motDePasse = '';
$photoProfil = null;

// Vérifier si c'est un formulaire multipart (avec photo)
if (strpos($contentType, 'multipart/form-data') !== false || !empty($_FILES)) {
    // Récupération des données depuis $_POST
    $nom = $_POST['nom'] ?? '';
    $sexe = $_POST['sexe'] ?? '';
    $nationalite = $_POST['nationalite'] ?? '';
    $telephone = $_POST['telephone'] ?? '';
    $email = $_POST['email'] ?? '';
    $motDePasse = $_POST['motDePasse'] ?? '';
    
    // Upload de la photo si présente
    if (isset($_FILES['photoProfil']) && $_FILES['photoProfil']['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            $photoProfil = uploadPhotoProfil($_FILES['photoProfil']);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
            exit;
        }
    }
} else {
    // Données JSON (sans photo)
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Données JSON invalides ou manquantes."]);
        exit;
    }
    
    $nom = $data['nom'] ?? '';
    $sexe = $data['sexe'] ?? '';
    $nationalite = $data['nationalite'] ?? '';
    $telephone = $data['telephone'] ?? '';
    $email = $data['email'] ?? '';
    $motDePasse = $data['motDePasse'] ?? '';
}

// Vérification des champs requis
$champsRequis = ['nom', 'sexe', 'nationalite', 'telephone', 'email', 'motDePasse'];
$champsVides = [];

foreach ($champsRequis as $champ) {
    if (empty($$champ)) {
        $champsVides[] = $champ;
    }
}

if (!empty($champsVides)) {
    http_response_code(400);
    echo json_encode([
        "success" => false, 
        "message" => "Champs requis manquants: " . implode(', ', $champsVides)
    ]);
    exit;
}

// Validation de l'email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Format d'email invalide."]);
    exit;
}

// Validation du mot de passe (minimum 6 caractères)
if (strlen($motDePasse) < 6) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Le mot de passe doit contenir au moins 6 caractères."]);
    exit;
}

// Générer un matricule unique
$matricule = genererMatricule();

// Hachage du mot de passe
$motDePasseHache = password_hash($motDePasse, PASSWORD_DEFAULT);

try {
    // Vérifier si l'email ou téléphone existe déjà
    $checkStmt = $pdo->prepare("SELECT id FROM Utilisateur WHERE email = ? OR telephone = ?");
    $checkStmt->execute([$email, $telephone]);
    
    if ($checkStmt->fetch()) {
        // Supprimer la photo uploadée si l'utilisateur existe déjà
        if ($photoProfil && file_exists(__DIR__ . '/' . $photoProfil)) {
            unlink(__DIR__ . '/' . $photoProfil);
        }
        
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Email ou téléphone déjà utilisé."]);
        exit;
    }

    // Insertion dans la base de données
    $stmt = $pdo->prepare("
        INSERT INTO Utilisateur (
            matricule, nom, sexe, nationalite, telephone, email, motDePasse, photoProfil
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
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

    $response = [
        "success" => true,
        "message" => "Inscription réussie",
        "matricule" => $matricule
    ];
    
    // Ajouter l'URL de la photo si présente
    if ($photoProfil) {
        $response['photoProfil'] = BASE_URL . $photoProfil;
    }
    
    echo json_encode($response);

} catch (PDOException $e) {
    // Supprimer la photo en cas d'erreur d'insertion
    if ($photoProfil && file_exists(__DIR__ . '/' . $photoProfil)) {
        unlink(__DIR__ . '/' . $photoProfil);
    }
    
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "Erreur lors de l'inscription: " . $e->getMessage()
    ]);
}
?>