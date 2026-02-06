<?php
// confirmation.php - Gère le retour depuis PayUnit
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

try {
    $conn = new PDO("mysql:host=localhost;dbname=gestvente;charset=utf8", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Récupérer les paramètres de retour
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

    // Vérifier la vente
    $stmt = $conn->prepare("SELECT * FROM Vente WHERE transactionId = ?");
    $stmt->execute([$transactionId]);
    $vente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vente) {
        throw new Exception("Transaction non trouvée: " . $transactionId);
    }

    if ($status === 'success' || $status === 'SUCCESS') {
        $conn->beginTransaction();

        // Mettre à jour le statut
        $stmt = $conn->prepare("UPDATE Vente SET statut = 'confirme' WHERE transactionId = ?");
        $stmt->execute([$transactionId]);

        // Marquer les produits comme achetés
        $stmt = $conn->prepare("UPDATE VenteProduit SET achetee = 1 WHERE venteId = ?");
        $stmt->execute([$vente['id']]);

        // Mettre à jour les commissions
        $stmt = $conn->prepare("UPDATE Commission SET statut = 'paye', dateTraitement = NOW() WHERE venteId = ?");
        $stmt->execute([$vente['id']]);

        // Créditer les vendeurs
        $stmtCommission = $conn->prepare("SELECT vendeurId, montantVendeur FROM Commission WHERE venteId = ?");
        $stmtCommission->execute([$vente['id']]);
        $commissions = $stmtCommission->fetchAll(PDO::FETCH_ASSOC);

        $stmtUpdateSolde = $conn->prepare("UPDATE Utilisateur SET soldeVendeur = soldeVendeur + ?, nbVentes = nbVentes + 1 WHERE id = ?");
        foreach ($commissions as $commission) {
            $stmtUpdateSolde->execute([$commission['montantVendeur'], $commission['vendeurId']]);
        }

        $conn->commit();

        echo json_encode([
            "success" => true,
            "message" => "Paiement confirmé avec succès!",
            "transaction_id" => $transactionId
        ]);

    } else {
        $stmt = $conn->prepare("UPDATE Vente SET statut = 'echoue' WHERE transactionId = ?");
        $stmt->execute([$transactionId]);

        echo json_encode([
            "success" => false,
            "message" => "Paiement échoué",
            "transaction_id" => $transactionId
        ]);
    }

} catch (Exception $e) {
    error_log("ERREUR CONFIRMATION: " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>