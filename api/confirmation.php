<?php
/**
 * confirmation.php - Gère le retour depuis PayUnit
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

    // 📥 Récupérer les paramètres de retour
    $transactionId = $_GET['transaction_id'] ?? $_POST['transaction_id'] ?? null;
    $status = $_GET['status'] ?? $_POST['status'] ?? 'unknown';

    error_log("=== CONFIRMATION PAYUNIT ===");
    error_log("Transaction ID: " . $transactionId);
    error_log("Status: " . $status);
    error_log("GET: " . json_encode($_GET));
    error_log("POST: " . json_encode($_POST));

    if (!$transactionId) {
        throw new Exception("Transaction ID manquant");
    }

    // 🔍 Vérifier la vente
    $stmt = $pdo->prepare("SELECT * FROM Vente WHERE transactionId = ?");
    $stmt->execute([$transactionId]);
    $vente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vente) {
        throw new Exception("Transaction non trouvée: " . $transactionId);
    }

    if ($status === 'success' || $status === 'SUCCESS') {
        // 🔄 Début de la transaction
        $pdo->beginTransaction();

        try {
            // 📝 Mettre à jour le statut
            $stmt = $pdo->prepare("UPDATE Vente SET statut = 'confirme' WHERE transactionId = ?");
            $stmt->execute([$transactionId]);

            // 🛒 Marquer les produits comme achetés
            $stmt = $pdo->prepare("UPDATE VenteProduit SET achetee = 1 WHERE venteId = ?");
            $stmt->execute([$vente['id']]);

            // 💰 Mettre à jour les commissions
            $stmt = $pdo->prepare("UPDATE Commission SET statut = 'paye', dateTraitement = NOW() WHERE venteId = ?");
            $stmt->execute([$vente['id']]);

            // 👥 Créditer les vendeurs
            $stmtCommission = $pdo->prepare("SELECT vendeurId, montantVendeur FROM Commission WHERE venteId = ?");
            $stmtCommission->execute([$vente['id']]);
            $commissions = $stmtCommission->fetchAll(PDO::FETCH_ASSOC);

            $stmtUpdateSolde = $pdo->prepare("UPDATE Utilisateur SET soldeVendeur = soldeVendeur + ?, nbVentes = nbVentes + 1 WHERE id = ?");
            foreach ($commissions as $commission) {
                $stmtUpdateSolde->execute([$commission['montantVendeur'], $commission['vendeurId']]);
            }

            // ✅ Validation de la transaction
            $pdo->commit();

            echo json_encode([
                "success" => true,
                "message" => "Paiement confirmé avec succès!",
                "transaction_id" => $transactionId
            ]);

        } catch (Exception $e) {
            // ❌ Rollback en cas d'erreur
            $pdo->rollBack();
            throw $e;
        }

    } else {
        // ❌ Paiement échoué
        $stmt = $pdo->prepare("UPDATE Vente SET statut = 'echoue' WHERE transactionId = ?");
        $stmt->execute([$transactionId]);

        echo json_encode([
            "success" => false,
            "message" => "Paiement échoué",
            "transaction_id" => $transactionId
        ]);
    }

} catch (PDOException $e) {
    // 📝 Log des erreurs PDO
    error_log("❌ ERREUR PDO CONFIRMATION: " . $e->getMessage());
    error_log("Code erreur: " . $e->getCode());
    
    echo json_encode([
        "success" => false,
        "message" => "Erreur de base de données",
        "debug" => $e->getMessage()
    ]);
    
} catch (Exception $e) {
    // 📝 Log des autres erreurs
    error_log("❌ ERREUR CONFIRMATION: " . $e->getMessage());
    
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>