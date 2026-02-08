<?php
// annuler_transaction.php - Gère l'annulation et le remboursement AVEC NOTIFICATIONS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
   require_once __DIR__ . '/config.php';

$input = file_get_contents('php://input');

    $data = json_decode($input, true);

    error_log("=== ANNULATION TRANSACTION ===");
    error_log("Données reçues: " . $input);

    // Récupération des données
    $transactionId = $data['transactionId'] ?? null;
    $motif = $data['motif'] ?? 'Non spécifié';
    $userId = $data['userId'] ?? null;
    $formationId = $data['formationId'] ?? null;
    $montant = $data['montant'] ?? null;

    // Validation
    if (!$transactionId) {
        throw new Exception("Transaction ID manquant");
    }

    error_log("Transaction ID: " . $transactionId);
    error_log("User ID: " . $userId);
    error_log("Formation ID: " . $formationId);
    error_log("Motif: " . $motif);

    // Vérifier que la transaction existe
    $stmt = $conn->prepare("
        SELECT v.*, u.nom as nomClient, u.email as emailClient 
        FROM Vente v 
        JOIN Utilisateur u ON v.utilisateurId = u.id 
        WHERE v.transactionId = ?
    ");
    $stmt->execute([$transactionId]);
    $vente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vente) {
        error_log("ERREUR: Transaction non trouvée - " . $transactionId);
        throw new Exception("Transaction non trouvée: " . $transactionId);
    }

    error_log("Vente trouvée - ID: " . $vente['id'] . ", Statut actuel: " . $vente['statut']);

    // Vérifier que la transaction n'a pas déjà été annulée
    if ($vente['statut'] === 'annule' || $vente['statut'] === 'rembourse') {
        throw new Exception("Cette transaction a déjà été annulée");
    }

    // Vérifier que c'est bien l'acheteur qui demande le remboursement
    if ($userId && $vente['utilisateurId'] != $userId) {
        throw new Exception("Vous n'êtes pas autorisé à annuler cette transaction");
    }

    // Récupérer le produitId et vendeurId associés à cette vente
    $stmtProduit = $conn->prepare("
        SELECT vp.produitId, vp.vendeurId, p.vendeurId as produitVendeurId, p.titre as produitTitre
        FROM VenteProduit vp
        LEFT JOIN Produit p ON vp.produitId = p.id
        WHERE vp.venteId = ? 
        LIMIT 1
    ");
    $stmtProduit->execute([$vente['id']]);
    $produitInfo = $stmtProduit->fetch(PDO::FETCH_ASSOC);
    
    if (!$produitInfo) {
        throw new Exception("Aucun produit trouvé pour cette transaction");
    }
    
    $produitId = $produitInfo['produitId'];
    $vendeurId = $produitInfo['vendeurId'] ?? $produitInfo['produitVendeurId'];
    $produitTitre = $produitInfo['produitTitre'];
    
    error_log("Produit ID récupéré: " . $produitId);
    error_log("Vendeur ID récupéré: " . $vendeurId);
    error_log("Produit titre: " . $produitTitre);

    $conn->beginTransaction();

    // Mettre à jour le statut de la vente
    $stmt = $conn->prepare("
        UPDATE Vente 
        SET statut = 'annule', 
            motifAnnulation = ?, 
            dateAnnulation = NOW() 
        WHERE transactionId = ?
    ");
    $stmt->execute([$motif, $transactionId]);
    error_log("Vente mise à jour avec statut 'annule'");

    // Marquer les produits comme non achetés
    $stmt = $conn->prepare("UPDATE VenteProduit SET achetee = 0 WHERE venteId = ?");
    $stmt->execute([$vente['id']]);
    error_log("Produits marqués comme non achetés");

    // Annuler les commissions
    $stmt = $conn->prepare("
        UPDATE Commission 
        SET statut = 'annule', 
            dateTraitement = NOW() 
        WHERE venteId = ?
    ");
    $stmt->execute([$vente['id']]);
    error_log("Commissions annulées");

    // Récupérer les commissions pour débiter les vendeurs
    $stmtCommission = $conn->prepare("
        SELECT vendeurId, montantVendeur 
        FROM Commission 
        WHERE venteId = ? AND statut = 'annule'
    ");
    $stmtCommission->execute([$vente['id']]);
    $commissions = $stmtCommission->fetchAll(PDO::FETCH_ASSOC);

    // Débiter les vendeurs qui avaient été crédités
    $stmtUpdateSolde = $conn->prepare("
        UPDATE Utilisateur 
        SET soldeVendeur = GREATEST(0, soldeVendeur - ?), 
            nbVentes = GREATEST(0, nbVentes - 1) 
        WHERE id = ?
    ");
    
    foreach ($commissions as $commission) {
        $stmtUpdateSolde->execute([
            $commission['montantVendeur'], 
            $commission['vendeurId']
        ]);
        error_log("Vendeur débité - ID: " . $commission['vendeurId'] . ", Montant: " . $commission['montantVendeur']);
    }

    // Vérifier la structure exacte de la table Remboursement
    $stmtCheckTable = $conn->prepare("
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'remboursement'
");
$stmtCheckTable->execute();

$columns = $stmtCheckTable->fetchAll(PDO::FETCH_ASSOC);
$columnNames = array_column($columns, 'column_name');

    error_log("Colonnes de la table Remboursement: " . implode(', ', $columnNames));

    // Créer une entrée dans la table Remboursement - ADAPTÉ À VOTRE STRUCTURE
    if (in_array('produitId', $columnNames) && in_array('acheteurId', $columnNames) && in_array('vendeurId', $columnNames)) {
        // Votre structure actuelle avec tous les champs
        $stmt = $conn->prepare("
            INSERT INTO Remboursement 
            (venteId, produitId, acheteurId, vendeurId, montant, motif, pourcentageVisionne, statut, dateCreation) 
            VALUES (?, ?, ?, ?, ?, ?, 0, 'demande', NOW())
        ");
        $stmt->execute([
            $vente['id'], 
            $produitId,
            $vente['utilisateurId'], // acheteurId
            $vendeurId, // vendeurId
            $vente['total'], 
            $motif
        ]);
        error_log("Remboursement créé avec structure complète");
    } else {
        // Structure alternative si certains champs manquent
        $stmt = $conn->prepare("
            INSERT INTO Remboursement 
            (venteId, montant, motif, statut, dateCreation) 
            VALUES (?, ?, ?, 'en_attente', NOW())
        ");
        $stmt->execute([$vente['id'], $vente['total'], $motif]);
        error_log("Remboursement créé avec structure basique");
    }
    
    $remboursementId = $conn->lastInsertId();
    error_log("Remboursement créé - ID: " . $remboursementId);

    // ============================================
    // 🔔 SYSTÈME DE NOTIFICATIONS - NOUVEAU
    // ============================================
    error_log("=== ENVOI DES NOTIFICATIONS ===");
    
    // 1. Notification au CLIENT (acheteur)
    $messageClient = "🔄 Remboursement demandé - " . $vente['total'] . " FCFA - Motif: " . $motif;
    $stmtNotifClient = $conn->prepare("
        INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
        VALUES (?, 'Demande de remboursement', ?, 'warning', '/mes-achats', NOW(), 0)
    ");
    $stmtNotifClient->execute([$vente['utilisateurId'], $messageClient]);
    error_log("📧 Notification envoyée au client: " . $vente['utilisateurId']);

    // 2. Notification au VENDEUR (formateur)
    $messageVendeur = "⚠️ Remboursement demandé pour '" . $produitTitre . "' - " . 
                     $vente['total'] . " FCFA - Motif client: " . $motif;
    $stmtNotifVendeur = $conn->prepare("
        INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
        VALUES (?, 'Remboursement client', ?, 'warning', '/vendeur/ventes', NOW(), 0)
    ");
    $stmtNotifVendeur->execute([$vendeurId, $messageVendeur]);
    error_log("📧 Notification envoyée au vendeur: " . $vendeurId);

    // 3. Notification à tous les ADMINS
    $messageAdmin = "🔄 Remboursement #" . $remboursementId . " - Transaction: " . $transactionId . 
                   " - Montant: " . $vente['total'] . " FCFA - Motif: " . $motif;
    
    $stmtAdmins = $conn->prepare("SELECT id FROM Utilisateur WHERE role = 'admin'");
    $stmtAdmins->execute();
    $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($admins as $admin) {
        $stmtNotifAdmin = $conn->prepare("
            INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
            VALUES (?, 'Nouveau remboursement', ?, 'warning', '/admin/remboursements', NOW(), 0)
        ");
        $stmtNotifAdmin->execute([$admin['id'], $messageAdmin]);
        error_log("📧 Notification envoyée à l'admin: " . $admin['id']);
    }

    // 4. Notifier tous les vendeurs concernés par les commissions annulées
    foreach ($commissions as $commission) {
        $messageVendeurCommission = "💰 Débit de " . $commission['montantVendeur'] . 
                                   " FCFA - Remboursement client pour transaction: " . $transactionId;
        
        $stmtNotifVendeurCommission = $conn->prepare("
            INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
            VALUES (?, 'Ajustement de solde', ?, 'info', '/vendeur/solde', NOW(), 0)
        ");
        $stmtNotifVendeurCommission->execute([$commission['vendeurId'], $messageVendeurCommission]);
        error_log("📧 Notification ajustement envoyée au vendeur: " . $commission['vendeurId']);
    }

    error_log("✅ Toutes les notifications envoyées");

    $conn->commit();

    error_log("✅ Transaction annulée avec succès: " . $transactionId);

    // Récupérer les informations complètes pour l'email
    $stmt = $conn->prepare("
        SELECT r.*, v.transactionId, u.nom as nomUtilisateur, u.email as emailUtilisateur
        FROM Remboursement r
        JOIN Vente v ON r.venteId = v.id
        JOIN Utilisateur u ON v.utilisateurId = u.id
        WHERE r.id = ?
    ");
    $stmt->execute([$remboursementId]);
    $remboursementInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    // Envoyer un email à l'admin (si la fonction existe)
    if (function_exists('envoyerEmailAdminNouveauRemboursement')) {
        envoyerEmailAdminNouveauRemboursement($remboursementInfo);
        error_log("Email envoyé à l'admin");
    }

    echo json_encode([
        "success" => true,
        "message" => "Transaction annulée avec succès. Le remboursement sera traité sous 48h.",
        "transaction_id" => $transactionId,
        "remboursement_id" => $remboursementId,
        "motif" => $motif,
        "notifications_envoyees" => [
            "client" => true,
            "vendeur" => true,
            "admins" => count($admins),
            "vendeurs_commissions" => count($commissions)
        ]
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
        error_log("Transaction rollback effectué");
    }
    
    error_log("❌ ERREUR ANNULATION: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
        "message" => $e->getMessage()
    ]);
}

/**
 * Fonction pour envoyer un email à l'admin (optionnelle)
 */
function envoyerEmailAdminNouveauRemboursement($remboursementInfo) {
    // Implémentez l'envoi d'email ici si nécessaire
    error_log("📧 Email admin - Remboursement #" . $remboursementInfo['id'] . " pour " . $remboursementInfo['montant'] . " FCFA");
}
?>