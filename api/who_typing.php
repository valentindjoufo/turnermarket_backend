<?php
require 'config.php';

// 🔐 Autoriser les requêtes depuis toutes les origines
header("Access-Control-Allow-Origin: *");
// 🔐 Autoriser les méthodes HTTP spécifiques
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
// 🔐 Autoriser certains en-têtes spécifiques (ex. Content-Type)
header("Access-Control-Allow-Headers: Content-Type");
// 🔐 Type de contenu de la réponse
header("Content-Type: application/json");

// 🔁 Répondre immédiatement aux requêtes OPTIONS (pré-vol)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$produitId = intval($_GET['produitId'] ?? 0);
$utilisateurId = intval($_GET['utilisateurId'] ?? 0);

if (!$produitId) {
    http_response_code(400);
    echo json_encode(['error' => 'produitId requis']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT utilisateurNom FROM UtilisateurTyping 
        JOIN Utilisateur ON Utilisateur.id = UtilisateurTyping.utilisateurId
        WHERE produitId = ? AND typing = 1 AND utilisateurId != ?");
    $stmt->execute([$produitId, $utilisateurId]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode($rows);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
