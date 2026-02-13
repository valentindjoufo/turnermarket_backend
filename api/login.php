<?php
// 🌐 Gestion CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

set_time_limit(30);

// ✅ Réponse aux requêtes pré-vol (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 📦 Connexion à la base
require 'config.php';

// 🧾 Lecture du JSON
$rawInput = file_get_contents("php://input");

// 🧹 NETTOYAGE INDISPENSABLE : supprimer le BOM et les espaces blancs inutiles
$cleanInput = preg_replace('/^\xEF\xBB\xBF/', '', $rawInput); // supprime BOM UTF-8
$cleanInput = trim($cleanInput);                             // supprime espaces, retours à la ligne

// 🔍 LOGS DE DÉBOGAGE (utiles pour vérifier ce qui est reçu)
error_log("=== LOGIN REQUEST ===");
error_log("Timestamp: " . date('Y-m-d H:i:s'));
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Raw Input (hex): " . bin2hex(substr($rawInput, 0, 50)) . "...");
error_log("Clean Input: " . $cleanInput);

// 📦 Décodage JSON
$data = json_decode($cleanInput, true);

// ⚠️ Vérification stricte du JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON Error: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON invalide (erreur de syntaxe)']);
    exit;
}

// ❌ Vérification que $data est un tableau et contient les champs requis
if (!is_array($data) || !isset($data['email'], $data['motDePasse'])) {
    error_log("Missing fields or invalid structure");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Champs requis manquants ou structure incorrecte']);
    exit;
}

$email = trim($data['email']);
$motDePasse = trim($data['motDePasse']);

error_log("Attempting login for: $email");

try {
    $stmt = $pdo->prepare("SELECT id, nom, email, role, etat, motDePasse, telephone, nationalite FROM utilisateur WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        error_log("User not found: $email");
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Adresse email incorrecte']);
        exit;
    }

    if (!password_verify($motDePasse, $user['motDePasse'])) {
        error_log("Password incorrect for: $email");
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Mot de passe incorrect']);
        exit;
    }

    // ✅ Vérification du compte désactivé
    if ($user['etat'] === 'inactif' || $user['etat'] === '0' || $user['etat'] === 0) {
        error_log("Account disabled for: $email");
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

    error_log("Login successful for: $email (ID: {$user['id']})");
    
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
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    // ⚠️ Ne pas exposer les détails de l'erreur en production
    echo json_encode(['success' => false, 'message' => 'Erreur serveur. Veuillez réessayer plus tard.']);
    exit;
}
?>