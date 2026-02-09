<?php
// annuler_transaction.php - Gère l'annulation et le remboursement AVEC NOTIFICATIONS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once 'config.php';

    error_log("=== ANNULATION TRANSACTION ===");
    error_log("Méthode: " . $_SERVER['REQUEST_METHOD']);

    // Vérifier la méthode de la requête et récupérer les données
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        error_log("Données brutes reçues: " . $input);
        
        if (!empty($input)) {
            $data = json_decode($input, true);
            // Si JSON invalide, utiliser $_POST
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON invalide, utilisation de $_POST");
                $data = $_POST;
            }
        } else {
            $data = $_POST;
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $data = $_GET;
        error_log("Données GET reçues: " . print_r($data, true));
    } else {
        throw new Exception("Méthode non autorisée");
    }

    // Récupérer transactionId depuis différentes sources possibles
    $transactionId = null;
    
    // Essayer différentes clés possibles
    $possibleKeys = ['transactionId', 'transaction_id', 'id_transaction', 'id', 'payment_id'];
    
    foreach ($possibleKeys as $key) {
        if (isset($data[$key]) && !empty($data[$key])) {
            $transactionId = $data[$key];
            error_log("TransactionId trouvé avec clé '$key': " . $transactionId);
            break;
        }
    }
    
    // Si toujours null, vérifier dans $_GET directement
    if (!$transactionId && isset($_GET['transactionId'])) {
        $transactionId = $_GET['transactionId'];
        error_log("TransactionId trouvé dans \$_GET: " . $transactionId);
    }
    
    // Récupération des autres données
    $motif = $data['motif'] ?? ($data['raison'] ?? 'Non spécifié');
    $userId = $data['userId'] ?? ($data['user_id'] ?? $data['utilisateurId'] ?? null);
    $formationId = $data['formationId'] ?? ($data['formation_id'] ?? $data['produitId'] ?? null);
    $montant = $data['montant'] ?? null;

    // Validation
    if (!$transactionId) {
        error_log("ERREUR: Aucun ID de transaction trouvé");
        error_log("Données disponibles: " . print_r($data, true));
        error_log("Keys disponibles: " . implode(', ', array_keys($data)));
        
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "error" => "ID de transaction manquant",
            "message" => "ID de transaction manquant",
            "details" => "Les clés recherchées étaient: " . implode(', ', $possibleKeys),
            "data_received" => $data
        ]);
        exit();
    }

    // Nettoyer le transactionId
    $transactionId = trim($transactionId);
    error_log("Transaction ID final: " . $transactionId);
    error_log("User ID: " . $userId);
    error_log("Formation ID: " . $formationId);
    error_log("Motif: " . $motif);
    error_log("Montant: " . $montant);

    // Vérifier que la transaction existe
    $stmt = $conn->prepare("
        SELECT v.*, u.nom as nomClient, u.email as emailClient 
        FROM Vente v 
        JOIN Utilisateur u ON v.utilisateurId = u.id 
        WHERE v.transactionId = ? OR v.reference = ?
    ");
    $stmt->execute([$transactionId, $transactionId]);
    $vente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vente) {
        error_log("ERREUR: Transaction non trouvée - " . $transactionId);
        
        // Essayer de rechercher par ID numérique si $transactionId est numérique
        if (is_numeric($transactionId)) {
            $stmt = $conn->prepare("
                SELECT v.*, u.nom as nomClient, u.email as emailClient 
                FROM Vente v 
                JOIN Utilisateur u ON v.utilisateurId = u.id 
                WHERE v.id = ?
            ");
            $stmt->execute([$transactionId]);
            $vente = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($vente) {
                error_log("Transaction trouvée par ID numérique: " . $transactionId);
            }
        }
        
        if (!$vente) {
            http_response_code(404);
            echo json_encode([
                "success" => false,
                "error" => "Transaction non trouvée",
                "message" => "Transaction non trouvée: " . $transactionId
            ]);
            exit();
        }
    }

    error_log("Vente trouvée - ID: " . $vente['id'] . ", Statut actuel: " . $vente['statut'] . ", TransactionId: " . $vente['transactionId']);

    // Vérifier que la transaction n'a pas déjà été annulée
    if ($vente['statut'] === 'annule' || $vente['statut'] === 'rembourse') {
        error_log("Transaction déjà annulée - Statut: " . $vente['statut']);
        
        echo json_encode([
            "success" => false,
            "error" => "Transaction déjà annulée",
            "message" => "Cette transaction a déjà été annulée",
            "statut_actuel" => $vente['statut']
        ]);
        exit();
    }

    // Vérifier que c'est bien l'acheteur qui demande le remboursement (si userId fourni)
    if ($userId && $vente['utilisateurId'] != $userId) {
        error_log("ERREUR: Tentative d'annulation non autorisée");
        error_log("UserId fourni: " . $userId . ", UserId propriétaire: " . $vente['utilisateurId']);
        
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "error" => "Non autorisé",
            "message" => "Vous n'êtes pas autorisé à annuler cette transaction"
        ]);
        exit();
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
        error_log("ERREUR: Aucun produit trouvé pour cette transaction");
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
        WHERE id = ?
    ");
    $stmt->execute([$motif, $vente['id']]);
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
    if (!empty($commissions)) {
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

    // Créer une entrée dans la table Remboursement
    $remboursementId = null;
    if (in_array('produitId', $columnNames) && in_array('acheteurId', $columnNames) && in_array('vendeurId', $columnNames)) {
        // Structure complète
        $stmt = $conn->prepare("
            INSERT INTO Remboursement 
            (venteId, produitId, acheteurId, vendeurId, montant, motif, pourcentageVisionne, statut, dateCreation) 
            VALUES (?, ?, ?, ?, ?, ?, 0, 'demande', NOW())
        ");
        $stmt->execute([
            $vente['id'], 
            $produitId,
            $vente['utilisateurId'],
            $vendeurId,
            $vente['total'], 
            $motif
        ]);
        error_log("Remboursement créé avec structure complète");
    } else {
        // Structure alternative
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
    // 🔔 SYSTÈME DE NOTIFICATIONS
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

    echo json_encode([
        "success" => true,
        "message" => "Transaction annulée avec succès. Le remboursement sera traité sous 48h.",
        "transaction_id" => $transactionId,
        "remboursement_id" => $remboursementId,
        "motif" => $motif,
        "montant" => $vente['total'],
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
?>