<?php
/**
 * notify.php - Reçoit les notifications de PayUnit
 * Version avec connexion PostgreSQL via config.php
 */

// 📦 Inclusion de la configuration (connexion PDO PostgreSQL)
require_once 'config.php';

// 🚦 Configuration des headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

try {
    // 💾 Vérification que la connexion PDO est bien disponible
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    // 📥 Récupération des données d'entrée
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    error_log("=== NOTIFICATION PAYUNIT ===");
    error_log("Données brutes reçues: " . $input);
    error_log("Données JSON décodées: " . json_encode($data));

    // 🔄 Support des données POST au cas où
    if (!$data || empty($data)) {
        $data = $_POST;
        error_log("Données depuis POST: " . json_encode($data));
    }

    // 🆔 Extraction de l'ID de transaction
    $transactionId = $data['transaction_id'] ?? $data['purchaseRef'] ?? $data['transactionId'] ?? null;
    $status = $data['status'] ?? $data['state'] ?? null;

    error_log("Transaction ID: " . $transactionId);
    error_log("Status: " . $status);

    if (!$transactionId) {
        throw new Exception("Transaction ID manquant");
    }

    // 🔍 Vérifier si la transaction existe
    $stmt = $pdo->prepare("SELECT * FROM Vente WHERE transactionId = ?");
    $stmt->execute([$transactionId]);
    $vente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vente) {
        error_log("❌ Transaction non trouvée: " . $transactionId);
        throw new Exception("Transaction non trouvée: " . $transactionId);
    }

    error_log("✅ Transaction trouvée - ID: " . $vente['id'] . ", Statut actuel: " . $vente['statut']);

    // ✅ Traitement selon le statut
    if ($status === 'SUCCESS' || $status === 'APPROVED' || $status === 'success' || $status === 'approved') {
        error_log("🔔 Paiement réussi détecté - Mise à jour des données");
        
        try {
            $pdo->beginTransaction();
            
            // 📝 Mettre à jour le statut de la vente
            $stmt = $pdo->prepare("UPDATE Vente SET statut = 'confirme' WHERE transactionId = ?");
            $stmt->execute([$transactionId]);
            error_log("✅ Vente marquée comme confirmée");
            
            // 🛒 Marquer les produits comme achetés
            $stmt = $pdo->prepare("UPDATE VenteProduit SET achetee = TRUE WHERE venteId = ?");
            $stmt->execute([$vente['id']]);
            $produitsAchetes = $stmt->rowCount();
            error_log("✅ Produits marqués comme achetés: " . $produitsAchetes);
            
            // 💰 Mettre à jour les commissions
            $stmt = $pdo->prepare("UPDATE Commission SET statut = 'paye', dateTraitement = NOW() WHERE venteId = ?");
            $stmt->execute([$vente['id']]);
            $commissionsPayees = $stmt->rowCount();
            error_log("✅ Commissions marquées comme payées: " . $commissionsPayees);
            
            // 👥 Créditer les vendeurs
            $stmtCommission = $pdo->prepare("SELECT vendeurId, montantVendeur FROM Commission WHERE venteId = ?");
            $stmtCommission->execute([$vente['id']]);
            $commissions = $stmtCommission->fetchAll(PDO::FETCH_ASSOC);
            
            $stmtUpdateSolde = $pdo->prepare("UPDATE Utilisateur SET soldeVendeur = soldeVendeur + ?, nbVentes = nbVentes + 1 WHERE id = ?");
            $vendeursCredites = 0;
            
            foreach ($commissions as $commission) {
                $stmtUpdateSolde->execute([$commission['montantVendeur'], $commission['vendeurId']]);
                $vendeursCredites++;
                error_log("💰 Vendeur crédité - ID: " . $commission['vendeurId'] . ", Montant: " . $commission['montantVendeur'] . " FCFA");
            }
            
            $pdo->commit();
            error_log("✅ Transaction BDD commitée avec succès");
            error_log("📊 Résumé: $produitsAchetes produit(s), $commissionsPayees commission(s), $vendeursCredites vendeur(s)");
            
        } catch (Exception $e) {
            // ❌ Rollback en cas d'erreur
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                error_log("❌ Rollback effectué suite à une erreur");
            }
            throw $e;
        }
        
        error_log("🎉 Notification succès traitée: " . $transactionId);
        
    } else {
        // ❌ Paiement échoué
        error_log("❌ Paiement échoué détecté - Statut: " . $status);
        
        $stmt = $pdo->prepare("UPDATE Vente SET statut = 'echoue' WHERE transactionId = ?");
        $stmt->execute([$transactionId]);
        $rowsUpdated = $stmt->rowCount();
        
        error_log("✅ Vente marquée comme échouée - Lignes mises à jour: " . $rowsUpdated);
        error_log("⚠️ Notification échec traitée: " . $transactionId);
    }

    // ✅ Réponse à PayUnit
    http_response_code(200);
    echo json_encode([
        "status" => "ok",
        "message" => "Notification traitée avec succès",
        "transaction_id" => $transactionId,
        "status_received" => $status,
        "timestamp" => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // ❌ Erreur de base de données
    error_log("❌ ERREUR PDO NOTIFICATION PAYUNIT: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Erreur de base de données",
        "debug" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // ❌ Autres erreurs
    error_log("❌ ERREUR NOTIFICATION PAYUNIT: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        "status" => "error", 
        "message" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ]);
}
?>