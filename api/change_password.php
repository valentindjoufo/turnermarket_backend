<?php
// En-têtes CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Gestion des requêtes OPTIONS (pré-vol CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'config.php'; // connexion PDO $pdo

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id'], $data['oldPassword'], $data['newPassword'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
    exit;
}

$id = intval($data['id']);
$oldPassword = $data['oldPassword'];
$newPassword = $data['newPassword'];

// Vérifier l'utilisateur et l'ancien mot de passe
try {
    $stmt = $pdo->prepare("SELECT motDePasse FROM Utilisateur WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Utilisateur non trouvé']);
        exit;
    }

    // Vérifie mot de passe (supposons hashé avec password_hash)
    if (!password_verify($oldPassword, $user['motDePasse'])) {
        echo json_encode(['success' => false, 'message' => 'Ancien mot de passe incorrect']);
        exit;
    }

    // Met à jour avec nouveau mot de passe hashé
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $pdo->prepare("UPDATE Utilisateur SET motDePasse = ? WHERE id = ?");
    $update->execute([$newHash, $id]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
}
