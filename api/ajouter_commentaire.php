<?php
/**
 * ajouter_commentaire.php - Ajouter un commentaire
 * Version avec connexion PostgreSQL via config.php
 */

// 📦 Inclusion de la configuration (connexion PDO PostgreSQL)
require_once 'config.php';

// 🚦 Autoriser l'accès depuis n'importe quelle origine
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');

// ⚠️ Si la requête est OPTIONS (préflight), on termine ici
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // 💾 Vérification que la connexion PDO est bien disponible
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    // 📥 Lecture des données JSON envoyées
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    error_log("=== AJOUT COMMENTAIRE ===");
    error_log("Données reçues: " . json_encode($data));

    if (!$data) {
        throw new Exception("Données JSON invalides ou manquantes");
    }

    // 🛡️ Validation des paramètres requis
    if (!isset($data['produitId'], $data['texte'])) {
        throw new Exception("Paramètres manquants: produitId et texte sont requis");
    }

    $produitId = (int) $data['produitId'];
    $texte = trim($data['texte']);
    $utilisateurId = isset($data['utilisateurId']) ? (int) $data['utilisateurId'] : null;

    // 🛡️ Validation supplémentaire
    if ($produitId <= 0) {
        throw new Exception("ID produit invalide");
    }

    if ($texte === '') {
        throw new Exception("Le commentaire est vide");
    }

    if (strlen($texte) > 1000) {
        throw new Exception("Le commentaire est trop long (max 1000 caractères)");
    }

    // 🔍 Vérifier que le produit existe
    $stmtCheck = $pdo->prepare("SELECT id FROM Produit WHERE id = ?");
    $stmtCheck->execute([$produitId]);
    if (!$stmtCheck->fetch()) {
        throw new Exception("Produit non trouvé");
    }

    // 🔍 Vérifier que l'utilisateur existe (si fourni)
    if ($utilisateurId && $utilisateurId > 0) {
        $stmtCheckUser = $pdo->prepare("SELECT id FROM Utilisateur WHERE id = ?");
        $stmtCheckUser->execute([$utilisateurId]);
        if (!$stmtCheckUser->fetch()) {
            error_log("⚠️ Utilisateur non trouvé, commentaire anonyme");
            $utilisateurId = null;
        }
    }

    // 📝 Insertion du commentaire
    $stmt = $pdo->prepare("
        INSERT INTO Commentaire (produitId, utilisateurId, texte, dateCreation) 
        VALUES (:produitId, :utilisateurId, :texte, NOW()) 
        RETURNING id, dateCreation
    ");
    
    $stmt->execute([
        ':produitId' => $produitId,
        ':utilisateurId' => $utilisateurId,
        ':texte' => $texte
    ]);

    // 🔹 Récupérer l'ID du commentaire inséré et la date
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $lastId = $result['id'];
    $dateCreation = $result['dateCreation'];

    error_log("✅ Commentaire ajouté - ID: $lastId, Produit: $produitId, Utilisateur: " . ($utilisateurId ?? 'anonyme'));

    // 🔔 Notification optionnelle au vendeur (si utilisateur connecté)
    if ($utilisateurId) {
        try {
            // Récupérer le vendeur du produit
            $stmtVendeur = $pdo->prepare("
                SELECT vendeurId, titre FROM Produit WHERE id = ?
            ");
            $stmtVendeur->execute([$produitId]);
            $produitInfo = $stmtVendeur->fetch(PDO::FETCH_ASSOC);
            
            if ($produitInfo && $produitInfo['vendeurId'] != $utilisateurId) {
                // Récupérer le nom de l'utilisateur
                $stmtUser = $pdo->prepare("SELECT nom FROM Utilisateur WHERE id = ?");
                $stmtUser->execute([$utilisateurId]);
                $userInfo = $stmtUser->fetch(PDO::FETCH_ASSOC);
                
                $nomUtilisateur = $userInfo ? $userInfo['nom'] : 'Un utilisateur';
                $titreProduit = $produitInfo['titre'];
                
                $messageNotification = "💬 $nomUtilisateur a commenté votre formation \"$titreProduit\"";
                
                $stmtNotif = $pdo->prepare("
                    INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
                    VALUES (?, 'Nouveau commentaire', ?, 'info', '/vendeur/produits/' || ?, NOW(), FALSE)
                ");
                $stmtNotif->execute([$produitInfo['vendeurId'], $messageNotification, $produitId]);
                
                error_log("📧 Notification envoyée au vendeur: " . $produitInfo['vendeurId']);
            }
        } catch (Exception $e) {
            error_log("⚠️ Erreur notification vendeur: " . $e->getMessage());
            // Ne pas bloquer l'ajout du commentaire si la notification échoue
        }
    }

    echo json_encode([
        'success' => true, 
        'id' => $lastId,
        'dateCreation' => $dateCreation,
        'message' => 'Commentaire ajouté avec succès',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    // ❌ Erreur de base de données
    error_log("❌ ERREUR PDO AJOUT COMMENTAIRE: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur de base de données',
        'debug' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // ❌ Autres erreurs
    error_log("❌ ERREUR AJOUT COMMENTAIRE: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
?>