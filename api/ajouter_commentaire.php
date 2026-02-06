<?php
// Autoriser l'accès depuis n'importe quelle origine (à personnaliser si besoin)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

// Si la requête est une requête préliminaire (OPTIONS), on arrête ici
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Connexion à la base de données
$host = 'localhost';
$dbname = 'gestvente';
$user = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Lecture des données JSON envoyées
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['produitId'], $data['texte'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Paramètres manquants']);
        exit;
    }

    $produitId = (int) $data['produitId'];
    $texte = trim($data['texte']);
    $utilisateurId = isset($data['utilisateurId']) ? (int) $data['utilisateurId'] : null;

    if ($texte === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Le commentaire est vide']);
        exit;
    }

    // Insertion en base de données
    $stmt = $pdo->prepare("INSERT INTO Commentaire (produitId, utilisateurId, texte) VALUES (?, ?, ?)");
    $stmt->execute([$produitId, $utilisateurId, $texte]);

    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
