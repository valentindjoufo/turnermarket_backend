<?php
require 'config.php';

// 🔐 En-têtes CORS pour permettre les requêtes depuis n'importe quelle origine
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// 🔁 Gérer les requêtes OPTIONS (pré-vol) automatiquement envoyées par le navigateur
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 🔽 Lecture et traitement des données JSON
$input = json_decode(file_get_contents("php://input"), true);

$produitId = intval($input['produitId'] ?? 0);
$utilisateurId = intval($input['utilisateurId'] ?? 0);
$isTyping = intval($input['isTyping'] ?? 0); // 1 ou 0

// ⚠️ Validation
if (!$produitId || !$utilisateurId) {
    http_response_code(400);
    echo json_encode(['error' => 'Champs manquants']);
    exit;
}

// ✅ Insertion ou mise à jour dans la base de données
try {
    $pdo->prepare("INSERT INTO UtilisateurTyping (produitId, utilisateurId, typing)
                   VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE typing = ?, dateUpdate = NOW()")
        ->execute([$produitId, $utilisateurId, $isTyping, $isTyping]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
