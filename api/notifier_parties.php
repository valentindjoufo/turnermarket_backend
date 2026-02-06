<?php
// notifier_parties.php - API complète de notifications
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// Configuration des timeouts
set_time_limit(20);
ini_set('max_execution_time', 20);
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
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_PERSISTENT => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ];
    
    $conn = new PDO("mysql:host=localhost;dbname=gestvente;charset=utf8mb4", "root", "", $options);

    // Vérification du contenu
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

    // Déterminer le type d'opération
    $action = $data['action'] ?? 'simple';

    error_log("=== NOTIFICATION API - Action: $action ===");

    // Router vers la fonction appropriée
    switch ($action) {
        case 'simple':
            // Notification simple (ancien comportement)
            handleSimpleNotification($conn, $data);
            break;
            
        case 'parties':
            // Notifications pour toutes les parties (nouveau)
            handlePartiesNotification($conn, $data);
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
 * Gère les notifications simples (1 utilisateur)
 */
function handleSimpleNotification($conn, $data) {
    $utilisateurId = $data['utilisateurId'] ?? null;
    $titre = $data['titre'] ?? null;
    $message = $data['message'] ?? null;
    $type = $data['type'] ?? 'info';
    $lien = $data['lien'] ?? null;
    
    if (!$utilisateurId || !$titre || !$message) {
        throw new Exception("Données incomplètes pour notification simple");
    }
    
    $stmt = $conn->prepare("
        INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
        VALUES (?, ?, ?, ?, ?, NOW(), 0)
    ");
    $stmt->execute([$utilisateurId, $titre, $message, $type, $lien]);
    
    error_log("✅ Notification simple créée - User: $utilisateurId");
    
    sendResponse(true, "Notification créée avec succès", [
        'notification_id' => $conn->lastInsertId(),
        'utilisateur_id' => $utilisateurId
    ]);
}

/**
 * Gère les notifications pour toutes les parties (client, vendeurs, admins)
 */
function handlePartiesNotification($conn, $data) {
    $transactionId = $data['transactionId'] ?? null;
    $type = $data['type'] ?? null;

    // Validation
    if (!$transactionId) {
        throw new Exception("Transaction ID requis");
    }
    
    if (!$type) {
        throw new Exception("Type de notification requis");
    }

    // Nettoyer les données
    $transactionId = trim($transactionId);
    $type = trim($type);

    // Vérifier que la transaction existe
    $stmt = $conn->prepare("
        SELECT v.*, u.nom as nomClient, u.email as emailClient
        FROM Vente v 
        JOIN Utilisateur u ON v.utilisateurId = u.id
        WHERE v.transactionId = ?
    ");
    $stmt->execute([$transactionId]);
    $vente = $stmt->fetch();

    if (!$vente) {
        throw new Exception("Transaction non trouvée: " . $transactionId);
    }

    error_log("Transaction trouvée - ID: " . $vente['id'] . ", Statut: " . $vente['statut']);

    // Récupérer les commissions/vendeurs
    $stmtCommissions = $conn->prepare("
        SELECT c.*, u.nom as nomVendeur, u.email as emailVendeur
        FROM Commission c 
        JOIN Utilisateur u ON c.vendeurId = u.id 
        WHERE c.venteId = ?
    ");
    $stmtCommissions->execute([$vente['id']]);
    $commissions = $stmtCommissions->fetchAll();

    error_log("Vendeurs concernés: " . count($commissions));

    // Dispatcher selon le type
    $resultats = [];
    
    switch ($type) {
        case 'paiement_confirme':
            $resultats = notifierPaiementConfirme($conn, $vente, $commissions);
            break;
            
        case 'remboursement':
            $motif = $data['motif'] ?? 'Non spécifié';
            $resultats = notifierRemboursement($conn, $vente, $commissions, $motif);
            break;
            
        case 'annulation':
            $motif = $data['motif'] ?? 'Non spécifié';
            $resultats = notifierAnnulation($conn, $vente, $commissions, $motif);
            break;
            
        case 'livraison':
            $details = $data['details'] ?? 'Votre formation est disponible';
            $resultats = notifierLivraison($conn, $vente, $commissions, $details);
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
 * Notifications pour paiement confirmé
 */
function notifierPaiementConfirme($conn, $vente, $commissions) {
    error_log("🔔 Notification: Paiement confirmé");
    
    $envoyees = 0;
    $total = 0;
    $details = [];
    
    $totalCommissionsAdmin = 0;
    foreach ($commissions as $commission) {
        $totalCommissionsAdmin += $commission['montantAdmin'];
    }

    // 1. Notifier le CLIENT
    try {
        $messageClient = "🎉 Votre achat a été confirmé ! Accédez à votre formation.";
        
        $stmt = $conn->prepare("
            INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
            VALUES (?, 'Achat confirmé', ?, 'success', '/mes-formations', NOW(), 0)
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
            // ✅ AMÉLIORATION : Récupérer le nom de la formation
            $stmtProduit = $conn->prepare("
                SELECT p.titre 
                FROM VenteProduit vp
                JOIN Produit p ON vp.produitId = p.id
                WHERE vp.venteId = ? AND vp.vendeurId = ?
                LIMIT 1
            ");
            $stmtProduit->execute([$vente['id'], $commission['vendeurId']]);
            $produit = $stmtProduit->fetch();
            $titreFormation = $produit ? $produit['titre'] : 'votre formation';
            
            // ✅ MESSAGE AMÉLIORÉ avec le nom de la formation et le prix
            $messageVendeur = "🎉 Félicitation ! Vous venez de vendre \"$titreFormation\" de " . 
                            $commission['montantTotal'] . " FCFA avec succès. " .
                            $commission['montantVendeur'] . " FCFA ont été crédités à votre solde.";
            
            $stmt = $conn->prepare("
                INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
                VALUES (?, 'Vente réussie', ?, 'success', '/vendeur/solde', NOW(), 0)
            ");
            $stmt->execute([$commission['vendeurId'], $messageVendeur]);
            $envoyees++;
            $details[] = "vendeur_" . $commission['vendeurId'];
            error_log("📧 Notification vendeur envoyée: " . $commission['vendeurId']);
        } catch (Exception $e) {
            error_log("⚠️ Erreur notification vendeur " . $commission['vendeurId'] . ": " . $e->getMessage());
            $details[] = "vendeur_erreur_" . $commission['vendeurId'];
        }
        $total++;
    }

    // 3. Notifier les ADMINS
    try {
        $messageAdmin = "📈 Nouvelle vente #" . $vente['id'] . " - Commissions admin: " . $totalCommissionsAdmin . " FCFA";
        
        // ✅ CORRECTION ICI
        $stmtAdmins = $conn->prepare("SELECT id FROM Utilisateur WHERE role = 'admin' AND etat = 'actif'");
        $stmtAdmins->execute();
        $admins = $stmtAdmins->fetchAll();
        
        foreach ($admins as $admin) {
            $stmt = $conn->prepare("
                INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
                VALUES (?, 'Nouvelle commission', ?, 'info', '/admin/commissions', NOW(), 0)
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
 * Notifications pour remboursement
 */
function notifierRemboursement($conn, $vente, $commissions, $motif) {
    error_log("🔔 Notification: Remboursement - " . $motif);
    
    $envoyees = 0;
    $total = 0;
    $details = [];

    // 1. Notifier le CLIENT
    try {
        $messageClient = "🔄 Remboursement en cours - Motif: " . $motif . " - Montant: " . $vente['total'] . " FCFA";
        
        $stmt = $conn->prepare("
            INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
            VALUES (?, 'Remboursement', ?, 'warning', '/mes-achats', NOW(), 0)
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
            $messageVendeur = "⚠️ Remboursement client - " . $commission['montantVendeur'] . " FCFA débités - Motif: " . $motif;
            
            $stmt = $conn->prepare("
                INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
                VALUES (?, 'Remboursement client', ?, 'warning', '/vendeur/ventes', NOW(), 0)
            ");
            $stmt->execute([$commission['vendeurId'], $messageVendeur]);
            $envoyees++;
            $details[] = "vendeur_" . $commission['vendeurId'];
            error_log("📧 Notification remboursement vendeur envoyée: " . $commission['vendeurId']);
        } catch (Exception $e) {
            error_log("⚠️ Erreur notification remboursement vendeur " . $commission['vendeurId'] . ": " . $e->getMessage());
            $details[] = "vendeur_erreur_" . $commission['vendeurId'];
        }
        $total++;
    }

    // 3. Notifier les ADMINS
    try {
        $messageAdmin = "🔄 Remboursement transaction #" . $vente['id'] . " - Motif: " . $motif . " - Montant: " . $vente['total'] . " FCFA";
        
        $stmtAdmins = $conn->prepare("SELECT id FROM Utilisateur WHERE role = 'admin' AND etat = 'actif'");
        $stmtAdmins->execute();
        $admins = $stmtAdmins->fetchAll();
        
        foreach ($admins as $admin) {
            $stmt = $conn->prepare("
                INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
                VALUES (?, 'Remboursement', ?, 'warning', '/admin/transactions', NOW(), 0)
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
 * Notifications pour annulation
 */
function notifierAnnulation($conn, $vente, $commissions, $motif) {
    error_log("🔔 Notification: Annulation - " . $motif);
    
    $envoyees = 0;
    $total = 0;
    $details = [];

    // 1. Notifier le CLIENT
    try {
        $messageClient = "❌ Transaction annulée - Motif: " . $motif;
        
        $stmt = $conn->prepare("
            INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
            VALUES (?, 'Transaction annulée', ?, 'error', '/panier', NOW(), 0)
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
            
            $stmt = $conn->prepare("
                INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
                VALUES (?, 'Vente annulée', ?, 'error', '/vendeur/ventes', NOW(), 0)
            ");
            $stmt->execute([$commission['vendeurId'], $messageVendeur]);
            $envoyees++;
            $details[] = "vendeur_" . $commission['vendeurId'];
            error_log("📧 Notification annulation vendeur envoyée: " . $commission['vendeurId']);
        } catch (Exception $e) {
            error_log("⚠️ Erreur notification annulation vendeur " . $commission['vendeurId'] . ": " . $e->getMessage());
            $details[] = "vendeur_erreur_" . $commission['vendeurId'];
        }
        $total++;
    }

    // 3. Notifier les ADMINS
    try {
        $messageAdmin = "❌ Annulation transaction #" . $vente['id'] . " - Motif: " . $motif;
        
        $stmtAdmins = $conn->prepare("SELECT id FROM Utilisateur WHERE role = 'admin' AND etat = 'actif'");
        $stmtAdmins->execute();
        $admins = $stmtAdmins->fetchAll();
        
        foreach ($admins as $admin) {
            $stmt = $conn->prepare("
                INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
                VALUES (?, 'Annulation', ?, 'error', '/admin/transactions', NOW(), 0)
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
 * Notifications pour livraison/formation disponible
 */
function notifierLivraison($conn, $vente, $commissions, $details) {
    error_log("🔔 Notification: Livraison - " . $details);
    
    $envoyees = 0;
    $total = 0;
    $resultats = [];

    // Notifier uniquement le CLIENT pour la livraison
    try {
        $messageClient = "📦 " . $details;
        
        $stmt = $conn->prepare("
            INSERT INTO Notification (utilisateurId, titre, message, type, lien, dateCreation, estLu)
            VALUES (?, 'Formation disponible', ?, 'info', '/mes-formations', NOW(), 0)
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