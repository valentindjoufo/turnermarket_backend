<?php
// Autoriser les requêtes CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'config.php';

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['produitId']) || empty($input['type']) || empty($input['utilisateurId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'produitId, type et utilisateurId sont requis']);
    exit;
}

$produitId = intval($input['produitId']);
$type = $input['type'];
$utilisateurId = intval($input['utilisateurId']);

$typesValides = ['like', 'pouce'];
if (!in_array($type, $typesValides)) {
    http_response_code(400);
    echo json_encode(['error' => 'Type de réaction invalide']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM ReactionUtilisateur WHERE produitId = ? AND utilisateurId = ?");
    $stmt->execute([$produitId, $utilisateurId]);
    $reaction = $stmt->fetch(PDO::FETCH_ASSOC);

    $colonneReaction = ($type === 'like') ? 'likeReaction' : 'pouceReaction';
    $colonneCount = ($type === 'like') ? 'likes' : 'pouces';

    if ($reaction) {
        $nouvelleValeur = !$reaction[$colonneReaction];

        $updateStmt = $pdo->prepare("UPDATE ReactionUtilisateur SET $colonneReaction = ?, dateCreation = NOW() WHERE id = ?");
        $updateStmt->execute([$nouvelleValeur, $reaction['id']]);

        if ($nouvelleValeur) {
            $pdo->prepare("INSERT INTO ProduitReaction (produitId, likes, pouces) VALUES (?, 0, 0) ON DUPLICATE KEY UPDATE produitId=produitId")->execute([$produitId]);
            $pdo->prepare("UPDATE ProduitReaction SET $colonneCount = $colonneCount + 1 WHERE produitId = ?")->execute([$produitId]);

            echo json_encode([
                'message' => 'Réaction ajoutée',
                'action' => 'added'
            ]);
        } else {
            $pdo->prepare("UPDATE ProduitReaction SET $colonneCount = GREATEST($colonneCount - 1, 0) WHERE produitId = ?")->execute([$produitId]);

            echo json_encode([
                'message' => 'Réaction supprimée',
                'action' => 'removed'
            ]);
        }

    } else {
        $likeVal = ($type === 'like') ? 1 : 0;
        $pouceVal = ($type === 'pouce') ? 1 : 0;

        $pdo->prepare("INSERT INTO ReactionUtilisateur (utilisateurId, produitId, likeReaction, pouceReaction) VALUES (?, ?, ?, ?)")
            ->execute([$utilisateurId, $produitId, $likeVal, $pouceVal]);

        $pdo->prepare("INSERT INTO ProduitReaction (produitId, likes, pouces) VALUES (?, 0, 0) ON DUPLICATE KEY UPDATE produitId=produitId")->execute([$produitId]);
        $pdo->prepare("UPDATE ProduitReaction SET $colonneCount = $colonneCount + 1 WHERE produitId = ?")->execute([$produitId]);

        echo json_encode([
            'message' => 'Réaction ajoutée',
            'action' => 'added'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur: ' . $e->getMessage()]);
}
?>
