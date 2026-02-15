<?php
/**
 * ajouter_commentaire.php - Ajouter un commentaire à un produit
 * Compatible PostgreSQL et utilisant la configuration fournie (config.php)
 */

// Inclusion de la configuration (initialise $pdo)
require_once 'config.php';

// Gestion des requêtes CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');

// Réponse immédiate pour les requêtes OPTIONS (préflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Vérification que la connexion PDO est disponible
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Connexion à la base de données non disponible');
    }

    // Lecture et décodage des données JSON reçues
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    error_log('=== AJOUT COMMENTAIRE ===');
    error_log('Données reçues : ' . json_encode($data));

    if (!$data) {
        throw new Exception('Données JSON invalides ou absentes');
    }

    // Validation des champs obligatoires
    if (!isset($data['produitId'], $data['texte'])) {
        throw new Exception('Paramètres manquants : produitId et texte sont requis');
    }

    $produitId = (int) $data['produitId'];
    $texte = trim($data['texte']);
    $utilisateurId = isset($data['utilisateurId']) ? (int) $data['utilisateurId'] : null;

    // Validations complémentaires
    if ($produitId <= 0) {
        throw new Exception('ID produit invalide');
    }

    if ($texte === '') {
        throw new Exception('Le commentaire ne peut pas être vide');
    }

    if (strlen($texte) > 1000) {
        throw new Exception('Le commentaire est trop long (maximum 1000 caractères)');
    }

    // Vérifier que le produit existe
    $stmtCheck = $pdo->prepare('SELECT id FROM Produit WHERE id = ?');
    $stmtCheck->execute([$produitId]);
    if (!$stmtCheck->fetch()) {
        throw new Exception('Produit introuvable');
    }

    // Vérifier que l'utilisateur existe (si fourni)
    if ($utilisateurId && $utilisateurId > 0) {
        $stmtCheckUser = $pdo->prepare('SELECT id FROM Utilisateur WHERE id = ?');
        $stmtCheckUser->execute([$utilisateurId]);
        if (!$stmtCheckUser->fetch()) {
            error_log('⚠️ Utilisateur non trouvé – commentaire anonyme');
            $utilisateurId = null;
        }
    }

    // Insertion du commentaire (RETURNING est spécifique PostgreSQL)
    $stmt = $pdo->prepare('
        INSERT INTO Commentaire (produitId, utilisateurId, texte, dateCreation)
        VALUES (:produitId, :utilisateurId, :texte, NOW())
        RETURNING id, dateCreation
    ');

    $stmt->execute([
        ':produitId' => $produitId,
        ':utilisateurId' => $utilisateurId,
        ':texte' => $texte
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $lastId = $result['id'];
    $dateCreation = $result['dateCreation'];

    error_log("✅ Commentaire ajouté – ID: $lastId, Produit: $produitId, Utilisateur: " . ($utilisateurId ?? 'anonyme'));

    // Notification au vendeur (si l'utilisateur est connecté)
    if ($utilisateurId) {
        try {
            // Récupérer les informations du produit et du vendeur
            $stmtVendeur = $pdo->prepare('SELECT vendeurId, titre FROM Produit WHERE id = ?');
            $stmtVendeur->execute([$produitId]);
            $produitInfo = $stmtVendeur->fetch(PDO::FETCH_ASSOC);

            if ($produitInfo && $produitInfo['vendeurId'] != $utilisateurId) {
                // Récupérer le nom de l'utilisateur commentateur
                $stmtUser = $pdo->prepare('SELECT nom FROM Utilisateur WHERE id = ?');
                $stmtUser->execute([$utilisateurId]);
                $userInfo = $stmtUser->fetch(PDO::FETCH_ASSOC);

                $nomUtilisateur = $userInfo ? $userInfo['nom'] : 'Un utilisateur';
                $titreProduit = $produitInfo['titre'];
                $messageNotification = "💬 $nomUtilisateur a commenté votre formation \"$titreProduit\"";

                // Insertion de la notification (concaténation PostgreSQL avec ||)
                $stmtNotif = $pdo->prepare('
                    INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
                    VALUES (?, \'Nouveau commentaire\', ?, \'info\', \'/vendeur/produits/\' || ?, NOW(), FALSE)
                ');
                $stmtNotif->execute([$produitInfo['vendeurId'], $messageNotification, $produitId]);

                error_log("📧 Notification envoyée au vendeur: " . $produitInfo['vendeurId']);
            }
        } catch (Exception $e) {
            // Ne pas bloquer l'ajout du commentaire si la notification échoue
            error_log('⚠️ Erreur lors de l\'envoi de la notification : ' . $e->getMessage());
        }
    }

    // Réponse succès
    echo json_encode([
        'success' => true,
        'id' => $lastId,
        'dateCreation' => $dateCreation,
        'message' => 'Commentaire ajouté avec succès',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    // Erreur de base de données
    error_log('❌ ERREUR PDO AJOUT COMMENTAIRE : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur de base de données',
        'debug' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Autres erreurs (paramètres, validation, etc.)
    error_log('❌ ERREUR AJOUT COMMENTAIRE : ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
?>