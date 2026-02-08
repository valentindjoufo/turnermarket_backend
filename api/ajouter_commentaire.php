<?php
// ⚡ Autoriser l'accès depuis n'importe quelle origine (à personnaliser)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

// ⚠️ Si la requête est OPTIONS (préflight), on termine ici
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 📌 Connexion à la base PostgreSQL via DATABASE_URL
$url = parse_url(getenv("DATABASE_URL"));

$host = $url["host"] ?? 'localhost';
$dbname = ltrim($url["path"] ?? '/defaultdb', '/');
$user = $url["user"] ?? 'user';
$password = $url["pass"] ?? '';
$port = $url["port"] ?? 5432;

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // 📥 Lecture des données JSON envoyées
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

    // ⚡ Insertion en PostgreSQL
    $stmt = $pdo->prepare(
        "INSERT INTO Commentaire (produitId, utilisateurId, texte) VALUES (:produitId, :utilisateurId, :texte) RETURNING id"
    );
    $stmt->execute([
        ':produitId' => $produitId,
        ':utilisateurId' => $utilisateurId,
        ':texte' => $texte
    ]);

    // 🔹 Récupérer l'ID du commentaire inséré
    $lastId = $stmt->fetchColumn();

    echo json_encode(['success' => true, 'id' => $lastId]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Erreur base de données',
        'message' => $e->getMessage()
    ]);
}
?>
