<?php
require_once 'config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!$data || !isset($data['email'], $data['motDePasse'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Email ou mot de passe manquant"]);
        exit;
    }

    $email = $data['email'];
    $motDePasse = $data['motDePasse'];

    // Récupérer l'utilisateur par email
    $stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Utilisateur non trouvé"]);
        exit;
    }

    // Vérifier le mot de passe hashé
    if (!password_verify($motDePasse, $user['motDePasse'])) {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Mot de passe incorrect"]);
        exit;
    }

    // Connexion réussie
    echo json_encode([
        "success" => true,
        "message" => "Connexion réussie",
        "user" => [
            "id" => (int)$user['id'],
            "matricule" => $user['matricule'],
            "nom" => $user['nom'],
            "email" => $user['email'],
            "telephone" => $user['telephone'],
            "role" => $user['role'],
            "etat" => $user['etat'],
            "photoProfil" => isset($user['photoProfil']) ? BASE_URL . $user['photoProfil'] : null
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
