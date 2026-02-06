<?php
// confirmer_vente.php - VERSION AVEC PAIEMENT IMMÉDIAT DES COMMISSIONS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// Configuration des timeouts
set_time_limit(30);
ini_set('max_execution_time', 30);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Fonction de réponse standardisée
function sendResponse($success, $message, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data,
        "timestamp" => time()
    ]);
    exit();
}

try {
    // Configuration PDO
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ];
    
    $conn = new PDO("mysql:host=localhost;dbname=gestvente;charset=utf8mb4", "root", "", $options);

    // Récupération des données
    $input = file_get_contents('php://input');
    if (empty($input)) {
        throw new Exception("Données JSON manquantes");
    }

    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON invalide");
    }

    error_log("=== CONFIRMATION VENTE DEBUT ===");
    error_log("Données reçues: " . json_encode($data));

    $transactionId = $data['transactionId'] ?? null;
    $statut = $data['statut'] ?? 'satisfait';

    if (!$transactionId) {
        throw new Exception("Transaction ID manquant");
    }

    $transactionId = trim($transactionId);
    error_log("Transaction ID: " . $transactionId);

    // Vérifier l'état actuel
    $stmtCheck = $conn->prepare("
        SELECT 
            v.id,
            v.statut,
            v.total,
            v.utilisateurId,
            v.date,
            v.transactionId,
            u.nom as nomClient, 
            u.email as emailClient
        FROM Vente v 
        JOIN Utilisateur u ON v.utilisateurId = u.id
        WHERE v.transactionId = ?
    ");
    $stmtCheck->execute([$transactionId]);
    $vente = $stmtCheck->fetch();

    if (!$vente) {
        throw new Exception("Transaction non trouvée: " . $transactionId);
    }

    $statutActuel = $vente['statut'] ?? 'en_attente';
    
    // Normalisation du statut
    if ($statutActuel === null || $statutActuel === '' || $statutActuel === 'NULL') {
        $statutActuel = 'en_attente';
        error_log("⚠️ Statut NULL/vide détecté - Conversion en 'en_attente'");
    }

    error_log("Vente trouvée - ID: " . $vente['id'] . ", Statut actuel: '" . $statutActuel . "'");

    // Gestion des différents statuts
    if ($statutActuel === 'paye') {
        error_log("ℹ️ Transaction déjà payée - Renvoi succès");
        
        sendResponse(true, "Transaction déjà payée précédemment", [
            "transaction_id" => $transactionId,
            "statut" => $statutActuel,
            "vente_id" => $vente['id'],
            "deja_paye" => true
        ]);
        exit();
    }

    if ($statutActuel === 'annule') {
        throw new Exception("Transaction annulée - impossible de la confirmer");
    }

    if ($statutActuel === 'rembourse') {
        throw new Exception("Transaction remboursée - impossible de la confirmer");
    }

    if ($statutActuel !== 'en_attente') {
        error_log("⚠️ Statut non standard détecté: '$statutActuel' - Traitement comme 'en_attente'");
    }

    // 🎯 TRAITEMENT DE LA CONFIRMATION
    $conn->beginTransaction();

    try {
        // 1. Mettre à jour le statut de la vente
        $stmt = $conn->prepare("
            UPDATE Vente 
            SET 
                statut = 'paye', 
                dateConfirmation = NOW(),
                datePaiement = NOW()
            WHERE transactionId = ? AND (statut IS NULL OR statut = 'en_attente' OR statut = '')
        ");
        $stmt->execute([$transactionId]);
        
        $rowsUpdated = $stmt->rowCount();
        
        if ($rowsUpdated === 0) {
            $stmtCheckAgain = $conn->prepare("SELECT statut FROM Vente WHERE transactionId = ?");
            $stmtCheckAgain->execute([$transactionId]);
            $currentStatus = $stmtCheckAgain->fetchColumn();
            
            if ($currentStatus === 'paye') {
                error_log("ℹ️ Transaction déjà payée entre-temps");
                sendResponse(true, "Transaction déjà payée", [
                    "transaction_id" => $transactionId,
                    "statut" => 'paye',
                    "vente_id" => $vente['id'],
                    "deja_paye" => true
                ]);
                $conn->rollBack();
                exit();
            } else {
                throw new Exception("Échec mise à jour statut - Statut actuel: '$currentStatus'");
            }
        }
        
        error_log("✅ Vente marquée comme payée - Lignes mises à jour: " . $rowsUpdated);

        // 2. Marquer les produits comme achetés
        $stmt = $conn->prepare("
            UPDATE VenteProduit 
            SET achetee = 1
            WHERE venteId = ?
        ");
        $stmt->execute([$vente['id']]);
        $produitsAchetes = $stmt->rowCount();
        error_log("✅ Produits marqués comme achetés: " . $produitsAchetes);

        // 3. 🔥 CRÉER LES COMMISSIONS DIRECTEMENT AVEC STATUT "PAYÉ"
        error_log("🔥 CRÉATION DES COMMISSIONS AVEC STATUT 'paye'");
        
        // Vérifier si des commissions existent déjà pour cette vente
        $stmtCheckCommissions = $conn->prepare("
            SELECT COUNT(*) as nb FROM Commission WHERE venteId = ?
        ");
        $stmtCheckCommissions->execute([$vente['id']]);
        $existingCommissions = $stmtCheckCommissions->fetch();
        
        if ($existingCommissions['nb'] > 0) {
            error_log("⚠️ {$existingCommissions['nb']} commissions déjà existantes - Mise à jour du statut");
            
            // Mettre à jour les commissions existantes
            $stmtUpdateCommissions = $conn->prepare("
                UPDATE Commission 
                SET 
                    statut = 'paye',
                    dateTraitement = NOW()
                WHERE venteId = ? AND (statut = 'en_attente' OR statut IS NULL OR statut = '')
            ");
            $stmtUpdateCommissions->execute([$vente['id']]);
            $commissionsUpdated = $stmtUpdateCommissions->rowCount();
            error_log("✅ Commissions mises à jour: " . $commissionsUpdated);
            
            // Récupérer les commissions pour paiement
            $stmtCommissions = $conn->prepare("
                SELECT 
                    c.*, 
                    u.nom as nomVendeur, 
                    u.email as emailVendeur,
                    p.titre as produitTitre
                FROM Commission c 
                JOIN Utilisateur u ON c.vendeurId = u.id 
                JOIN VenteProduit vp ON c.venteId = vp.venteId
                JOIN Produit p ON vp.produitId = p.id
                WHERE c.venteId = ? AND c.statut = 'paye'
                GROUP BY c.id
            ");
            $stmtCommissions->execute([$vente['id']]);
            $commissions = $stmtCommissions->fetchAll();
            
        } else {
            error_log("🆕 Aucune commission existante - Création avec statut 'paye'");
            
            // Créer les commissions directement avec statut 'paye'
            $stmtCreateCommissions = $conn->prepare("
                INSERT INTO Commission (
                    venteId, 
                    vendeurId, 
                    montantTotal, 
                    montantVendeur, 
                    montantAdmin, 
                    pourcentageCommission, 
                    statut, 
                    dateCreation, 
                    dateTraitement
                )
                SELECT 
                    v.id as venteId,
                    p.vendeurId,
                    (vp.prixUnitaire * vp.quantite) as montantTotal,
                    ROUND((vp.prixUnitaire * vp.quantite) * 0.85, 2) as montantVendeur,
                    ROUND((vp.prixUnitaire * vp.quantite) * 0.15, 2) as montantAdmin,
                    15 as pourcentageCommission,
                    'paye' as statut,
                    NOW() as dateCreation,
                    NOW() as dateTraitement
                FROM Vente v
                JOIN VenteProduit vp ON v.id = vp.venteId
                JOIN Produit p ON vp.produitId = p.id
                WHERE v.id = ?
            ");
            $stmtCreateCommissions->execute([$vente['id']]);
            $commissionsCreated = $stmtCreateCommissions->rowCount();
            error_log("✅ Commissions créées avec statut 'paye': " . $commissionsCreated);
            
            // Récupérer les commissions créées
            $stmtCommissions = $conn->prepare("
                SELECT 
                    c.*, 
                    u.nom as nomVendeur, 
                    u.email as emailVendeur,
                    p.titre as produitTitre
                FROM Commission c 
                JOIN Utilisateur u ON c.vendeurId = u.id 
                JOIN VenteProduit vp ON c.venteId = vp.venteId
                JOIN Produit p ON vp.produitId = p.id
                WHERE c.venteId = ?
                GROUP BY c.id
            ");
            $stmtCommissions->execute([$vente['id']]);
            $commissions = $stmtCommissions->fetchAll();
        }

        error_log("📊 Commissions à payer: " . count($commissions));

        // 4. 🔥 PAYER IMMÉDIATEMENT LES VENDEURS
        $stmtUpdateSolde = $conn->prepare("
            UPDATE Utilisateur 
            SET soldeVendeur = soldeVendeur + ?, 
                nbVentes = nbVentes + 1
            WHERE id = ?
        ");

        $commissionsPayees = 0;
        $totalMontantVendeurs = 0;

        foreach ($commissions as $commission) {
            if ($commission['montantVendeur'] <= 0) {
                continue;
            }
            
            // Créditer le vendeur IMMÉDIATEMENT
            $stmtUpdateSolde->execute([
                $commission['montantVendeur'], 
                $commission['vendeurId']
            ]);
            
            $commissionsPayees++;
            $totalMontantVendeurs += $commission['montantVendeur'];
            
            error_log("💰 Vendeur payé IMMÉDIATEMENT - ID: " . $commission['vendeurId'] . 
                     ", Montant: " . $commission['montantVendeur'] . " FCFA, " .
                     "Produit: " . $commission['produitTitre']);
        }

        $conn->commit();
        error_log("✅ Transaction BDD commitée avec succès");

        // Vérification finale du statut
        $stmtVerif = $conn->prepare("SELECT statut FROM Vente WHERE transactionId = ?");
        $stmtVerif->execute([$transactionId]);
        $statutFinal = $stmtVerif->fetchColumn();
        
        // Vérification finale des commissions
        $stmtVerifCommissions = $conn->prepare("
            SELECT COUNT(*) as nb, SUM(montantVendeur) as total
            FROM Commission 
            WHERE venteId = ? AND statut = 'paye'
        ");
        $stmtVerifCommissions->execute([$vente['id']]);
        $verifCommissions = $stmtVerifCommissions->fetch();
        
        error_log("🔍 Vérification finale:");
        error_log("   - Statut vente: '$statutFinal'");
        error_log("   - Commissions payées: " . $verifCommissions['nb']);
        error_log("   - Montant total distribué: " . $verifCommissions['total'] . " FCFA");

        // Notifications (non bloquant)
        try {
            envoyerNotificationsConfirmation($conn, $vente, $commissions);
        } catch (Exception $e) {
            error_log("⚠️ Erreur notifications: " . $e->getMessage());
        }

        error_log("=== CONFIRMATION VENTE TERMINEE ===");

        sendResponse(true, "Vente payée et commissions distribuées immédiatement", [
            "transaction_id" => $transactionId,
            "statut" => $statutFinal,
            "vente_id" => $vente['id'],
            "commissions_payees" => $commissionsPayees,
            "total_commissions" => $totalMontantVendeurs,
            "produits_achetes" => $produitsAchetes,
            "deja_paye" => false,
            "statut_final" => $statutFinal,
            "paiement_immediat" => true,
            "commissions_creees_avec_statut_paye" => true
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("❌ Rollback transaction: " . $e->getMessage());
        throw $e;
    }

} catch (PDOException $e) {
    error_log("❌ ERREUR BDD: " . $e->getMessage());
    sendResponse(false, "Erreur de connexion à la base de données", [], 500);
} catch (Exception $e) {
    error_log("❌ ERREUR CONFIRMATION: " . $e->getMessage());
    sendResponse(false, $e->getMessage(), [], 500);
}

/**
 * Envoie les notifications pour confirmation de vente
 */
function envoyerNotificationsConfirmation($conn, $vente, $commissions) {
    error_log("=== ENVOI NOTIFICATIONS ===");
    
    $notificationsEnvoyees = 0;
    $totalCommissionsAdmin = 0;
    
    foreach ($commissions as $commission) {
        $totalCommissionsAdmin += $commission['montantAdmin'];
    }
    
    // 1. Notification au CLIENT
    try {
        $messageClient = "🎉 Votre achat a été confirmé ! Formation débloquée.";
        $stmt = $conn->prepare("
            INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation)
            VALUES (?, 'Achat confirmé', ?, 'success', '/mes-formations', NOW())
        ");
        $stmt->execute([$vente['utilisateurId'], $messageClient]);
        $notificationsEnvoyees++;
    } catch (Exception $e) {
        error_log("⚠️ Erreur notification client: " . $e->getMessage());
    }
    
    // 2. Notification aux VENDEURS
    foreach ($commissions as $commission) {
        try {
            $messageVendeur = sprintf(
                "💰 Nouvelle vente ! Vous avez reçu %s FCFA immédiatement pour votre formation.",
                number_format($commission['montantVendeur'], 0, ',', ' ')
            );
            
            $stmt = $conn->prepare("
                INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation)
                VALUES (?, 'Paiement reçu', ?, 'success', '/vendeur/ventes', NOW())
            ");
            $stmt->execute([$commission['vendeurId'], $messageVendeur]);
            $notificationsEnvoyees++;
            
        } catch (Exception $e) {
            error_log("⚠️ Erreur notification vendeur: " . $e->getMessage());
        }
    }
    
    // 3. Notification aux ADMINS
    try {
        $messageAdmin = sprintf(
            "💼 Nouvelle vente #%d - Total: %s FCFA - Commissions: %s FCFA",
            $vente['id'],
            number_format($vente['total'], 0, ',', ' '),
            number_format($totalCommissionsAdmin, 0, ',', ' ')
        );
        
        $stmtAdmins = $conn->prepare("SELECT id FROM Utilisateur WHERE role = 'admin' AND etat = 'actif'");
        $stmtAdmins->execute();
        $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($admins as $admin) {
            $stmt = $conn->prepare("
                INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation)
                VALUES (?, 'Nouvelle commission', ?, 'info', '/admin/commissions', NOW())
            ");
            $stmt->execute([$admin['id'], $messageAdmin]);
            $notificationsEnvoyees++;
        }
    } catch (Exception $e) {
        error_log("⚠️ Erreur notification admin: " . $e->getMessage());
    }
    
    error_log("✅ Notifications envoyées: {$notificationsEnvoyees}");
}

?>