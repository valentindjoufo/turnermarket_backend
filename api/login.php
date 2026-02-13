<?php
// 🌐 Gestion CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

set_time_limit(30);

// Réponse aux requêtes pré-vol (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Connexion à la base
require 'config.php';

// Lecture du JSON brut
$rawInput = file_get_contents("php://input");

// Nettoyage : suppression BOM UTF-8 et espaces inutiles
$cleanInput = preg_replace('/^\xEF\xBB\xBF/', '', $rawInput);
$cleanInput = trim($cleanInput);

// Logs de debug
error_log("=== LOGIN REQUEST ===");
error_log("Timestamp: " . date('Y-m-d H:i:s'));
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Raw Input (first 100 chars): " . substr($rawInput, 0, 100));
error_log("Clean Input: " . substr($cleanInput, 0, 100));

// Décodage JSON
$data = json_decode($cleanInput, true);

// Vérification JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON Error: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON invalide (erreur de syntaxe)']);
    exit;
}

// Vérification champs requis
if (!is_array($data) || empty($data['email']) || empty($data['motDePasse'])) {
    error_log("Champs requis manquants ou invalides");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Champs requis manquants']);
    exit;
}

$email = trim($data['email']);
$motDePasse = trim($data['motDePasse']);

// Vérification utilisateur
try {
    $stmt = $pdo->prepare("SELECT id, nom, email, role, etat, motDePasse, telephone, nationalite 
                           FROM utilisateur WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        error_log("Utilisateur non trouvé : $email");
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Adresse email incorrecte']);
        exit;
    }

    // Vérification mot de passe
    if (!isset($user['motDePasse']) || !password_verify($motDePasse, $user['motDePasse'])) {
        error_log("Mot de passe incorrect pour : $email");
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Mot de passe incorrect']);
        exit;
    }

    // Vérification compte désactivé
    if ($user['etat'] === 'inactif' || $user['etat'] === '0' || $user['etat'] === 0) {
        error_log("Compte désactivé pour : $email");
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Votre compte a été désactivé. Veuillez contacter l\'administrateur.',
            'utilisateur' => [
                'id' => $user['id'],
                'nom' => $user['nom'],
                'email' => $user['email'],
                'role' => $user['role'],
                'etat' => $user['etat']
            ]
        ]);
        exit;
    }

    // Succès
    error_log("Connexion réussie pour : $email");
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Connexion réussie',
        'utilisateur' => [
            'id' => $user['id'],
            'nom' => $user['nom'],
            'email' => $user['email'],
            'role' => $user['role'],
            'etat' => $user['etat'],
            'telephone' => $user['telephone'] ?? '',
            'nationalite' => $user['nationalite'] ?? 'Cameroun'
        ]
    ]);
    exit;

} catch (PDOException $e) {
    error_log("Erreur DB : " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur. Veuillez réessayer plus tard.']);
    exit;
}
