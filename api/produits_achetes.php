<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// En-têtes CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Gérer les requêtes pré-vol (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = 'localhost';
$dbname = 'gestvente';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (!isset($_GET['userId']) || empty($_GET['userId'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Paramètre userId manquant.'
        ]);
        exit;
    }

    $userId = intval($_GET['userId']);

    $sql = "SELECT f.* FROM ventes v
            JOIN produits_ventes pv ON v.id = pv.vente_id
            JOIN formations f ON pv.produit_id = f.id
            WHERE v.utilisateur_id = :userId";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['userId' => $userId]);
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($produits)) {
        echo json_encode([
            'success' => false,
            'message' => 'Aucun produit acheté trouvé pour cet utilisateur.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'produits' => $produits
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur base de données : ' . $e->getMessage()
    ]);
}
