<?php
/**
 * login.php - Connexion des utilisateurs
 * Version sécurisée avec hachage et JSON
 */

require_once 'config.php';

// CORS et headers JSON
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    // Récupérer les données JSON
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!$data || !isset($data['email']) || !isset($data['motDePasse'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Email et mot de passe requis"]);
        exit;
    }

    $email = trim($data['email']);
    $motDePasse = trim($data['motDePasse']);

    error_log("=== LOGIN REQUEST ===");
    error_log("Timestamp: " . date('Y-m-d H:i:s'));
    error_log("Attempting login for: $email");

    // Chercher l'utilisateur
    $stmt = $pdo->prepare("SELECT * FROM Utilisateur WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Email ou mot de passe incorrect"]);
        exit;
    }

    error_log("User found, checking password");

    // Vérification du mot de passe avec password_verify
    if (!password_verify($motDePasse, $user['motDePasse'])) {
        error_log("Password incorrect for: $email");
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Email ou mot de passe incorrect"]);
        exit;
    }

    // Vérifier si le compte est actif
    if ($user['etat'] !== 'actif') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Votre compte a été désactivé", "utilisateur" => $user]);
        exit;
    }

    // Connexion réussie
    $responseUser = [
        "id" => (int)$user['id'],
        "matricule" => $user['matricule'],
        "nom" => $user['nom'],
        "email" => $user['email'],
        "telephone" => $user['telephone'],
        "role" => $user['role'],
        "etat" => $user['etat'],
        "nationalite" => $user['nationalite'] ?? '',
        "photoProfil" => $user['photoProfil'] ?? null
    ];

    echo json_encode([
        "success" => true,
        "message" => "Connexion réussie",
        "utilisateur" => $responseUser,
        "timestamp" => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Erreur serveur",
        "debug" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ]);
}
?>
