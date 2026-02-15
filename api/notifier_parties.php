<?php
/**
 * notifier_parties.php - API complète de notifications
 * Version compatible PostgreSQL (noms en minuscules + alias)
 */

require_once 'config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

set_time_limit(20);
ini_set('max_execution_time', 20);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendResponse($success, $message, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data,
        "timestamp" => time()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        throw new Exception("Content-Type must be application/json");
    }

    $input = file_get_contents('php://input');
    if (empty($input)) {
        throw new Exception("Données JSON manquantes");
    }

    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON invalide: " . json_last_error_msg());
    }

    $action = $data['action'] ?? 'simple';
    error_log("=== NOTIFICATION API - Action: $action ===");

    switch ($action) {
        case 'simple':
            handleSimpleNotification($data);
            break;
        case 'parties':
            handlePartiesNotification($data);
            break;
        default:
            throw new Exception("Action non supportée: $action");
    }

} catch (PDOException $e) {
    error_log("❌ ERREUR BDD NOTIFICATION: " . $e->getMessage());
    sendResponse(false, "Erreur base de données", [], 500);
} catch (Exception $e) {
    error_log("❌ ERREUR NOTIFICATION: " . $e->getMessage());
    sendResponse(false, $e->getMessage(), [], 500);
}

/**
 * 📧 Gère les notifications simples (1 utilisateur)
 */
function handleSimpleNotification($data) {
    global $pdo;

    $utilisateurId = $data['utilisateurId'] ?? null;
    $titre = $data['titre'] ?? null;
    $message = $data['message'] ?? null;
    $type = $data['type'] ?? 'info';
    $lien = $data['lien'] ?? null;

    if (!$utilisateurId || !$titre || !$message) {
        throw new Exception("Données incomplètes pour notification simple");
    }

    // Insertion avec noms de colonnes en minuscules (table notification)
    $stmt = $pdo->prepare("
        INSERT INTO notification (utilisateurid, titre, message, type, lien, datecreation, estlu)
        VALUES (?, ?, ?, ?, ?, NOW(), FALSE)
    ");
    $stmt->execute([$utilisateurId, $titre, $message, $type, $lien]);

    error_log("✅ Notification simple créée - User: $utilisateurId");

    sendResponse(true, "Notification créée avec succès", [
        'notification_id' => $pdo->lastInsertId(),
        'utilisateur_id' => $utilisateurId
    ]);
}

/**
 * 👥 Gère les notifications pour toutes les parties (client, vendeurs, admins)
 */
function handlePartiesNotification($data) {
    global $pdo;

    $transactionId = $data['transactionId'] ?? null;
    $type = $data['type'] ?? null;

    if (!$transactionId) {
        throw new Exception("Transaction ID requis");
    }
    if (!$type) {
        throw new Exception("Type de notification requis");
    }

    $transactionId = trim($transactionId);
    $type = trim($type);

    // Récupération de la vente avec alias pour conserver la casse utilisée dans le code
    $stmt = $pdo->prepare("
        SELECT 
            v.id AS \"id\",
            v.utilisateurid AS \"utilisateurId\",
            v.statut AS \"statut\",
            v.total AS \"total\",
            v.date AS \"date\",
            v.dateconfirmation AS \"dateConfirmation\",
            u.nom AS \"nomClient\",
            u.email AS \"emailClient\"
        FROM vente v 
        JOIN utilisateur u ON v.utilisateurid = u.id
        WHERE v.transactionid = ?
    ");
    $stmt->execute([$transactionId]);
    $vente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vente) {
        throw new Exception("Transaction non trouvée: " . $transactionId);
    }

    error_log("Transaction trouvée - ID: " . $vente['id'] . ", Statut: " . $vente['statut']);

    // Récupération des commissions / vendeurs
    $stmtCommissions = $pdo->prepare("
        SELECT 
            c.*,
            u.nom AS \"nomVendeur\",
            u.email AS \"emailVendeur\"
        FROM commission c 
        JOIN utilisateur u ON c.vendeurid = u.id 
        WHERE c.venteid = ?
    ");
    $stmtCommissions->execute([$vente['id']]);
    $commissions = $stmtCommissions->fetchAll(PDO::FETCH_ASSOC);

    error_log("Vendeurs concernés: " . count($commissions));

    $resultats = [];

    switch ($type) {
        case 'paiement_confirme':
            $resultats = notifierPaiementConfirme($vente, $commissions);
            break;
        case 'remboursement':
            $motif = $data['motif'] ?? 'Non spécifié';
            $resultats = notifierRemboursement($vente, $commissions, $motif);
            break;
        case 'annulation':
            $motif = $data['motif'] ?? 'Non spécifié';
            $resultats = notifierAnnulation($vente, $commissions, $motif);
            break;
        case 'livraison':
            $details = $data['details'] ?? 'Votre formation est disponible';
            $resultats = notifierLivraison($vente, $commissions, $details);
            break;
        default:
            throw new Exception("Type de notification non supporté: " . $type);
    }

    error_log("✅ Notifications parties terminées - " . $resultats['envoyees'] . "/" . $resultats['total'] . " envoyées");

    sendResponse(true, "Notifications traitées avec succès", [
        "transaction_id" => $transactionId,
        "type" => $type,
        "notifications_envoyees" => $resultats['envoyees'],
        "notifications_total" => $resultats['total'],
        "details" => $resultats['details'] ?? []
    ]);
}

/**
 * 🎉 Notifications pour paiement confirmé
 */
function notifierPaiementConfirme($vente, $commissions) {
    global $pdo;

    error_log("🔔 Notification: Paiement confirmé");

    $envoyees = 0;
    $total = 0;
    $details = [];

    $totalCommissionsAdmin = 0;
    foreach ($commissions as $commission) {
        $totalCommissionsAdmin += $commission['montantadmin']; // en minuscules car issu de la table commission
    }

    // 1. Notifier le CLIENT
    try {
        $messageClient = "🎉 Votre achat a été confirmé ! Accédez à votre formation.";

        $stmt = $pdo->prepare("
            INSERT INTO notification (utilisateurid, titre, message, type, lien, datecreation, estlu)
            VALUES (?, 'Achat confirmé', ?, 'success', '/mes-formations', NOW(), FALSE)
        ");
        $stmt->execute([$vente['utilisateurId'], $messageClient]);
        $envoyees++;
        $details[] = "client";
        error_log("📧 Notification client envoyée");
    } catch (Exception $e) {
        error_log("⚠️ Erreur notification client: " . $e->getMessage());
        $details[] = "client_erreur";
    }
    $total++;

    // 2. Notifier chaque VENDEUR
    foreach ($commissions as $commission) {
        try {
            // Récupérer le titre de la formation
            $stmtProduit = $pdo->prepare("
                SELECT p.titre
                FROM venteproduit vp
                JOIN produit p ON vp.produitid = p.id
                WHERE vp.venteid = ? AND vp.vendeurid = ?
                LIMIT 1
            ");
            $stmtProduit->execute([$vente['id'], $commission['vendeurid']]);
            $produit = $stmtProduit->fetch(PDO::FETCH_ASSOC);
            $titreFormation = $produit ? $produit['titre'] : 'votre formation';

            $messageVendeur = "🎉 Félicitation ! Vous venez de vendre \"$titreFormation\" de " .
                $commission['montanttotal'] . " FCFA avec succès. " .
                $commission['montantvendeur'] . " FCFA ont été crédités à votre solde.";

            $stmt = $pdo->prepare("
                INSERT INTO notification (utilisateurid, titre, message, type, lien, datecreation, estlu)
                VALUES (?, 'Vente réussie', ?, 'success', '/vendeur/solde', NOW(), FALSE)
            ");
            $stmt->execute([$commission['vendeurid'], $messageVendeur]);
            $envoyees++;
            $details[] = "vendeur_" . $commission['vendeurid'];
            error_log("📧 Notification vendeur envoyée: " . $commission['vendeurid']);
        } catch (Exception $e) {
            error_log("⚠️ Erreur notification vendeur " . $commission['vendeurid'] . ": " . $e->getMessage());
            $details[] = "vendeur_erreur_" . $commission['vendeurid'];
        }
        $total++;
    }

    // 3. Notifier les ADMINS
    try {
        $messageAdmin = "📈 Nouvelle vente #" . $vente['id'] . " - Commissions admin: " . $totalCommissionsAdmin . " FCFA";

        $stmtAdmins = $pdo->prepare("SELECT id FROM utilisateur WHERE role = 'admin' AND etat = 'actif'");
        $stmtAdmins->execute();
        $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

        foreach ($admins as $admin) {
            $stmt = $pdo->prepare("
                INSERT INTO notification (utilisateurid, titre, message, type, lien, datecreation, estlu)
                VALUES (?, 'Nouvelle commission', ?, 'info', '/admin/commissions', NOW(), FALSE)
            ");
            $stmt->execute([$admin['id'], $messageAdmin]);
            $envoyees++;
            $details[] = "admin_" . $admin['id'];
            error_log("📧 Notification admin envoyée: " . $admin['id']);
        }
        $total += count($admins);
    } catch (Exception $e) {
        error_log("⚠️ Erreur notification admin: " . $e->getMessage());
        $details[] = "admin_erreur";
    }

    return [
        'envoyees' => $envoyees,
        'total' => $total,
        'details' => $details,
        'commissions_admin' => $totalCommissionsAdmin
    ];
}

/**
 * 🔄 Notifications pour remboursement
 */
function notifierRemboursement($vente, $commissions, $motif) {
    global $pdo;

    error_log("🔔 Notification: Remboursement - " . $motif);

    $envoyees = 0;
    $total = 0;
    $details = [];

    // 1. Notifier le CLIENT
    try {
        $messageClient = "🔄 Remboursement en cours - Motif: " . $motif . " - Montant: " . $vente['total'] . " FCFA";

        $stmt = $pdo->prepare("
            INSERT INTO notification (utilisateurid, titre, message, type, lien, datecreation, estlu)
            VALUES (?, 'Remboursement', ?, 'warning', '/mes-achats', NOW(), FALSE)
        ");
        $stmt->execute([$vente['utilisateurId'], $messageClient]);
        $envoyees++;
        $details[] = "client";
        error_log("📧 Notification remboursement client envoyée");
    } catch (Exception $e) {
        error_log("⚠️ Erreur notification remboursement client: " . $e->getMessage());
        $details[] = "client_erreur";
    }
    $total++;

    // 2. Notifier les VENDEURS
    foreach ($commissions as $commission) {
        try {
            $messageVendeur = "⚠️ Remboursement client - " . $commission['montantvendeur'] . " FCFA débités - Motif: " . $motif;

            $stmt = $pdo->prepare("
                INSERT INTO notification (utilisateurid, titre, message, type, lien, datecreation, estlu)
                VALUES (?, 'Remboursement client', ?, 'warning', '/vendeur/ventes', NOW(), FALSE)
            ");
            $stmt->execute([$commission['vendeurid'], $messageVendeur]);
            $envoyees++;
            $details[] = "vendeur_" . $commission['vendeurid'];
            error_log("📧 Notification remboursement vendeur envoyée: " . $commission['vendeurid']);
        } catch (Exception $e) {
            error_log("⚠️ Erreur notification remboursement vendeur " . $commission['vendeurid'] . ": " . $e->getMessage());
            $details[] = "vendeur_erreur_" . $commission['vendeurid'];
        }
        $total++;
    }

    // 3. Notifier les ADMINS
    try {
        $messageAdmin = "🔄 Remboursement transaction #" . $vente['id'] . " - Motif: " . $motif . " - Montant: " . $vente['total'] . " FCFA";

        $stmtAdmins = $pdo->prepare("SELECT id FROM utilisateur WHERE role = 'admin' AND etat = 'actif'");
        $stmtAdmins->execute();
        $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

        foreach ($admins as $admin) {
            $stmt = $pdo->prepare("
                INSERT INTO notification (utilisateurid, titre, message, type, lien, datecreation, estlu)
                VALUES (?, 'Remboursement', ?, 'warning', '/admin/transactions', NOW(), FALSE)
            ");
            $stmt->execute([$admin['id'], $messageAdmin]);
            $envoyees++;
            $details[] = "admin_" . $admin['id'];
            error_log("📧 Notification remboursement admin envoyée: " . $admin['id']);
        }
        $total += count($admins);
    } catch (Exception $e) {
        error_log("⚠️ Erreur notification remboursement admin: " . $e->getMessage());
        $details[] = "admin_erreur";
    }

    return [
        'envoyees' => $envoyees,
        'total' => $total,
        'details' => $details,
        'motif' => $motif
    ];
}

/**
 * ❌ Notifications pour annulation
 */
function notifierAnnulation($vente, $commissions, $motif) {
    global $pdo;

    error_log("🔔 Notification: Annulation - " . $motif);

    $envoyees = 0;
    $total = 0;
    $details = [];

    // 1. Notifier le CLIENT
    try {
        $messageClient = "❌ Transaction annulée - Motif: " . $motif;

        $stmt = $pdo->prepare("
            INSERT INTO notification (utilisateurid, titre, message, type, lien, datecreation, estlu)
            VALUES (?, 'Transaction annulée', ?, 'error', '/panier', NOW(), FALSE)
        ");
        $stmt->execute([$vente['utilisateurId'], $messageClient]);
        $envoyees++;
        $details[] = "client";
        error_log("📧 Notification annulation client envoyée");
    } catch (Exception $e) {
        error_log("⚠️ Erreur notification annulation client: " . $e->getMessage());
        $details[] = "client_erreur";
    }
    $total++;

    // 2. Notifier les VENDEURS
    foreach ($commissions as $commission) {
        try {
            $messageVendeur = "❌ Vente annulée - Motif: " . $motif;

            $stmt = $pdo->prepare("
                INSERT INTO notification (utilisateurid, titre, message, type, lien, datecreation, estlu)
                VALUES (?, 'Vente annulée', ?, 'error', '/vendeur/ventes', NOW(), FALSE)
            ");
            $stmt->execute([$commission['vendeurid'], $messageVendeur]);
            $envoyees++;
            $details[] = "vendeur_" . $commission['vendeurid'];
            error_log("📧 Notification annulation vendeur envoyée: " . $commission['vendeurid']);
        } catch (Exception $e) {
            error_log("⚠️ Erreur notification annulation vendeur " . $commission['vendeurid'] . ": " . $e->getMessage());
            $details[] = "vendeur_erreur_" . $commission['vendeurid'];
        }
        $total++;
    }

    // 3. Notifier les ADMINS
    try {
        $messageAdmin = "❌ Annulation transaction #" . $vente['id'] . " - Motif: " . $motif;

        $stmtAdmins = $pdo->prepare("SELECT id FROM utilisateur WHERE role = 'admin' AND etat = 'actif'");
        $stmtAdmins->execute();
        $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

        foreach ($admins as $admin) {
            $stmt = $pdo->prepare("
                INSERT INTO notification (utilisateurid, titre, message, type, lien, datecreation, estlu)
                VALUES (?, 'Annulation', ?, 'error', '/admin/transactions', NOW(), FALSE)
            ");
            $stmt->execute([$admin['id'], $messageAdmin]);
            $envoyees++;
            $details[] = "admin_" . $admin['id'];
            error_log("📧 Notification annulation admin envoyée: " . $admin['id']);
        }
        $total += count($admins);
    } catch (Exception $e) {
        error_log("⚠️ Erreur notification annulation admin: " . $e->getMessage());
        $details[] = "admin_erreur";
    }

    return [
        'envoyees' => $envoyees,
        'total' => $total,
        'details' => $details,
        'motif' => $motif
    ];
}

/**
 * 📦 Notifications pour livraison/formation disponible
 */
function notifierLivraison($vente, $commissions, $details) {
    global $pdo;

    error_log("🔔 Notification: Livraison - " . $details);

    $envoyees = 0;
    $total = 0;
    $resultats = [];

    // Notifier uniquement le CLIENT
    try {
        $messageClient = "📦 " . $details;

        $stmt = $pdo->prepare("
            INSERT INTO notification (utilisateurid, titre, message, type, lien, datecreation, estlu)
            VALUES (?, 'Formation disponible', ?, 'info', '/mes-formations', NOW(), FALSE)
        ");
        $stmt->execute([$vente['utilisateurId'], $messageClient]);
        $envoyees++;
        $resultats[] = "client_livraison";
        error_log("📧 Notification livraison client envoyée");
    } catch (Exception $e) {
        error_log("⚠️ Erreur notification livraison: " . $e->getMessage());
        $resultats[] = "client_erreur";
    }
    $total++;

    return [
        'envoyees' => $envoyees,
        'total' => $total,
        'details' => $resultats
    ];
}

error_log("=== NOTIFICATION API TERMINE ===");
?>