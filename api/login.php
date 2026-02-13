<?php
require_once 'config.php';
header("Content-Type: application/json; charset=UTF-8");

// Récupération des données JSON
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$email = $data['email'] ?? '';
$motDePasse = $data['motDePasse'] ?? '';

if (empty($email) || empty($motDePasse)) {
    echo json_encode(["success" => false, "message" => "Email et mot de passe requis"]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM Utilisateur WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["success" => false, "message" => "Utilisateur introuvable"]);
        exit;
    }

    // Vérifier le mot de passe
    if (password_verify($motDePasse, $user['motDePasse'])) {
        // Connexion réussie
        echo json_encode([
            "success" => true,
            "message" => "Connexion réussie",
            "utilisateur" => [
                "id" => (int)$user['id'],
                "email" => $user['email'],
                "nom" => $user['nom'],
                "role" => $user['role'] ?? 'client'
            ]
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Mot de passe incorrect"]);
    }
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Erreur serveur", "debug" => $e->getMessage()]);
}
?>
