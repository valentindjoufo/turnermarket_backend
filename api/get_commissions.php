<?php
// get_commissions.php - VERSION SANS DÉLAI - PAIEMENT IMMÉDIAT
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

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
    ], JSON_PRETTY_PRINT);
    exit();
}

try {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ];
    
    $conn = new PDO("mysql:host=localhost;dbname=gestvente;charset=utf8mb4", "root", "", $options);

    // Récupération des paramètres
    $userId = null;
    $userRole = 'client';
    $action = 'get_dashboard';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $userId = $_GET['userId'] ?? $_GET['vendeurId'] ?? null;
        $userRole = $_GET['role'] ?? 'client';
        $action = $_GET['action'] ?? 'get_dashboard';
    } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $input = file_get_contents('php://input');
            if (!empty($input)) {
                $data = json_decode($input, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $userId = $data['userId'] ?? $data['vendeurId'] ?? null;
                    $userRole = $data['role'] ?? 'client';
                    $action = $data['action'] ?? 'get_dashboard';
                }
            }
        } else {
            $userId = $_POST['userId'] ?? $_POST['vendeurId'] ?? null;
            $userRole = $_POST['role'] ?? 'client';
            $action = $_POST['action'] ?? 'get_dashboard';
        }
    }

    if (empty($userId) || !is_numeric($userId)) {
        error_log("❌ userId manquant ou invalide: " . var_export($userId, true));
        throw new Exception("userId/vendeurId requis et doit être un nombre valide");
    }

    $userId = intval($userId);
    error_log("=== GET COMMISSIONS API ===");
    error_log("User ID: $userId, Role: $userRole, Action: $action");

    // Créer les commissions manquantes
    creerCommissionsManquantes($conn);

    switch ($action) {
        case 'get_dashboard':
        default:
            getDashboardComplet($conn, $userId, $userRole);
            break;
    }

} catch (PDOException $e) {
    error_log("❌ ERREUR BDD: " . $e->getMessage());
    sendResponse(false, "Erreur base de données: " . $e->getMessage(), [], 500);
} catch (Exception $e) {
    error_log("❌ ERREUR: " . $e->getMessage());
    sendResponse(false, $e->getMessage(), [], 400);
}

/**
 * 🔧 CRÉER AUTOMATIQUEMENT LES COMMISSIONS MANQUANTES
 * Statut par défaut : 'en_attente'
 */
function creerCommissionsManquantes($conn) {
    try {
        error_log("🔄 Vérification des commissions manquantes...");
        
        $stmtCheck = $conn->prepare("
            SELECT COUNT(*) as nb
            FROM Vente v
            JOIN VenteProduit vp ON v.id = vp.venteId
            LEFT JOIN Commission c ON v.id = c.venteId
            WHERE c.id IS NULL
        ");
        $stmtCheck->execute();
        $result = $stmtCheck->fetch();
        
        if ($result['nb'] > 0) {
            error_log("⚠️ {$result['nb']} ventes sans commission détectées - Création automatique...");
            
            $stmt = $conn->prepare("
                INSERT INTO Commission (venteId, vendeurId, montantTotal, montantVendeur, montantAdmin, pourcentageCommission, statut, dateCreation, dateTraitement)
                SELECT 
                    v.id as venteId,
                    p.vendeurId,
                    (vp.prixUnitaire * vp.quantite) as montantTotal,
                    ROUND((vp.prixUnitaire * vp.quantite) * 0.85, 2) as montantVendeur,
                    ROUND((vp.prixUnitaire * vp.quantite) * 0.15, 2) as montantAdmin,
                    15 as pourcentageCommission,
                    CASE 
                        WHEN v.statut = 'paye' THEN 'paye'
                        WHEN v.statut = 'annule' THEN 'annule'
                        ELSE 'en_attente'
                    END as statut,
                    v.date as dateCreation,
                    CASE WHEN v.statut = 'paye' THEN v.dateConfirmation ELSE NULL END as dateTraitement
                FROM Vente v
                JOIN VenteProduit vp ON v.id = vp.venteId
                JOIN Produit p ON vp.produitId = p.id
                LEFT JOIN Commission c ON v.id = c.venteId AND p.vendeurId = c.vendeurId
                WHERE c.id IS NULL
            ");
            $stmt->execute();
            $created = $stmt->rowCount();
            error_log("✅ {$created} commissions créées automatiquement avec statut 'en_attente'");
        } else {
            error_log("ℹ️ Aucune nouvelle commission à créer");
        }
    } catch (Exception $e) {
        error_log("⚠️ Erreur création commissions auto: " . $e->getMessage());
    }
}

/**
 * 🎯 DASHBOARD COMPLET PAR UTILISATEUR
 */
function getDashboardComplet($conn, $userId, $userRole) {
    try {
        error_log("🔍 getDashboardComplet - userId: $userId, role: $userRole");
        
        $dashboardData = [];
        
        if ($userRole === 'admin') {
            $dashboardData = getDashboardAdmin($conn);
        } else {
            $dashboardData = getDashboardVendeur($conn, $userId);
        }
        
        error_log("✅ Dashboard complet récupéré - Role: $userRole, UserId: $userId");
        sendResponse(true, "Dashboard récupéré avec succès", $dashboardData);
        
    } catch (Exception $e) {
        error_log("❌ Erreur getDashboardComplet: " . $e->getMessage());
        throw $e;
    }
}

/**
 * 👑 DASHBOARD ADMIN
 */
function getDashboardAdmin($conn) {
    // Soldes admin
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN statut = 'paye' THEN montantAdmin ELSE 0 END), 0) as soldeDisponible,
            COALESCE(SUM(CASE WHEN statut = 'en_attente' THEN montantAdmin ELSE 0 END), 0) as soldeEnAttente,
            COALESCE(SUM(CASE WHEN statut = 'annule' THEN montantAdmin ELSE 0 END), 0) as soldeBloque,
            COALESCE(SUM(CASE WHEN statut = 'paye' THEN montantAdmin ELSE 0 END), 0) as totalDejaPaye,
            COALESCE(SUM(CASE WHEN statut != 'annule' THEN montantAdmin ELSE 0 END), 0) as revenueTotalPlatform,
            COUNT(DISTINCT vendeurId) as vendeursActifs,
            COUNT(*) as totalCommissions,
            COUNT(DISTINCT venteId) as totalVentesPlatform
        FROM Commission
    ");
    $stmt->execute();
    $soldesAdmin = $stmt->fetch();
    
    // Statistiques globales
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT v.utilisateurId) as clientsTotaux,
            COALESCE(AVG(vp.prixUnitaire * vp.quantite), 0) as panierMoyenPlatform,
            COUNT(DISTINCT v.id) as nombreVentesTotal,
            COALESCE(SUM(vp.prixUnitaire * vp.quantite), 0) as chiffreAffaireTotal
        FROM VenteProduit vp
        JOIN Vente v ON vp.venteId = v.id
        WHERE v.statut != 'annule'
    ");
    $stmt->execute();
    $statsGlobales = $stmt->fetch();
    
    // Statistiques par vendeur
    $stmt = $conn->prepare("
        SELECT 
            u.id,
            u.nom,
            u.email,
            COUNT(DISTINCT p.id) as nombreFormations,
            COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantVendeur ELSE 0 END), 0) as soldeDisponible,
            COALESCE(SUM(CASE WHEN c.statut = 'en_attente' THEN c.montantVendeur ELSE 0 END), 0) as soldeEnAttente,
            COALESCE(SUM(CASE WHEN c.statut IN ('paye', 'en_attente') THEN c.montantVendeur ELSE 0 END), 0) as revenue,
            COUNT(DISTINCT c.venteId) as totalVentes
        FROM Utilisateur u
        LEFT JOIN Produit p ON u.id = p.vendeurId
        LEFT JOIN Commission c ON u.id = c.vendeurId
        WHERE u.role = 'client'
        GROUP BY u.id, u.nom, u.email
        ORDER BY revenue DESC
    ");
    $stmt->execute();
    $statistiquesVendeurs = $stmt->fetchAll();
    
    // Top formations
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.titre,
            COUNT(DISTINCT vp.venteId) as nombreVentes,
            COALESCE(SUM(vp.prixUnitaire * vp.quantite), 0) as revenueTotal,
            COALESCE(SUM(ROUND((vp.prixUnitaire * vp.quantite) * 0.85, 2)), 0) as revenueVendeur,
            COALESCE(SUM(ROUND((vp.prixUnitaire * vp.quantite) * 0.15, 2)), 0) as revenueAdmin,
            p.prix,
            u.nom as vendeur
        FROM Produit p
        LEFT JOIN VenteProduit vp ON p.id = vp.produitId
        LEFT JOIN Vente v ON vp.venteId = v.id
        LEFT JOIN Utilisateur u ON p.vendeurId = u.id
        WHERE v.statut IN ('paye', 'en_attente') OR v.statut IS NULL
        GROUP BY p.id, p.titre, p.prix, u.nom
        ORDER BY nombreVentes DESC, revenueTotal DESC
        LIMIT 5
    ");
    $stmt->execute();
    $topFormations = $stmt->fetchAll();
    
    // Ventes récentes
    $stmt = $conn->prepare("
        SELECT 
            vp.id,
            p.titre as formation,
            vp.prixUnitaire as prix,
            vp.quantite,
            (vp.prixUnitaire * vp.quantite) as total,
            COALESCE(c.montantVendeur, ROUND((vp.prixUnitaire * vp.quantite) * 0.85, 2)) as commissionVendeur,
            COALESCE(c.montantAdmin, ROUND((vp.prixUnitaire * vp.quantite) * 0.15, 2)) as commissionAdmin,
            v.date as dateVente,
            acheteur.nom as acheteur,
            vendeur.nom as vendeur,
            COALESCE(c.statut, 
                CASE 
                    WHEN v.statut = 'paye' THEN 'paye'
                    WHEN v.statut = 'annule' THEN 'annule'
                    ELSE 'en_attente'
                END
            ) as statutPaiement
        FROM VenteProduit vp
        JOIN Produit p ON vp.produitId = p.id
        JOIN Vente v ON vp.venteId = v.id
        JOIN Utilisateur acheteur ON v.utilisateurId = acheteur.id
        JOIN Utilisateur vendeur ON p.vendeurId = vendeur.id
        LEFT JOIN Commission c ON v.id = c.venteId AND p.vendeurId = c.vendeurId
        ORDER BY v.date DESC
        LIMIT 20
    ");
    $stmt->execute();
    $ventesRecentes = $stmt->fetchAll();
    
    // Demandes de retrait
    $stmt = $conn->prepare("
        SELECT 
            dr.id,
            dr.montant,
            dr.methodePaiement,
            dr.numeroCompte,
            dr.statut,
            dr.dateDemande,
            dr.dateTraitement,
            u.nom as demandeur,
            u.email,
            u.role
        FROM DemandeRetrait dr
        JOIN Utilisateur u ON dr.utilisateurId = u.id
        WHERE dr.statut = 'en_attente'
        ORDER BY dr.dateDemande DESC
        LIMIT 10
    ");
    $stmt->execute();
    $demandesRetrait = $stmt->fetchAll();
    
    $soldesComplets = array_merge($soldesAdmin, $statsGlobales);
    
    return [
        'soldes' => $soldesComplets,
        'statistiques_vendeurs' => $statistiquesVendeurs,
        'top_formations' => $topFormations,
        'ventes_recentes' => $ventesRecentes,
        'demandes_retrait' => $demandesRetrait
    ];
}

/**
 * 🛒 DASHBOARD VENDEUR
 */
function getDashboardVendeur($conn, $userId) {
    // Calcul des soldes
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantVendeur ELSE 0 END), 0) as soldeDisponible,
            COALESCE(SUM(CASE WHEN c.statut = 'en_attente' THEN c.montantVendeur ELSE 0 END), 0) as soldeEnAttente,
            COALESCE(SUM(CASE WHEN c.statut = 'annule' THEN c.montantVendeur ELSE 0 END), 0) as soldeBloque,
            COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantVendeur ELSE 0 END), 0) as totalDejaPaye,
            COALESCE(SUM(CASE WHEN c.statut IN ('paye', 'en_attente') THEN c.montantVendeur ELSE 0 END), 0) as revenueTotal,
            COALESCE(SUM(CASE WHEN c.statut != 'annule' THEN c.montantTotal ELSE 0 END), 0) as ventesBrutes,
            COALESCE(SUM(CASE WHEN c.statut != 'annule' THEN c.montantAdmin ELSE 0 END), 0) as commissionsPrelevees,
            COUNT(DISTINCT c.venteId) as totalVentes
        FROM Commission c
        WHERE c.vendeurId = ?
    ");
    $stmt->execute([$userId]);
    $soldes = $stmt->fetch();
    
    // Statistiques personnelles
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT p.id) as nombreFormations,
            COUNT(DISTINCT v.utilisateurId) as clientsUniques,
            COALESCE(AVG(vp.prixUnitaire * vp.quantite), 0) as panierMoyen,
            COUNT(DISTINCT vp.venteId) as totalTransactions
        FROM Produit p
        LEFT JOIN VenteProduit vp ON p.id = vp.produitId
        LEFT JOIN Vente v ON vp.venteId = v.id
        WHERE p.vendeurId = ? AND v.statut != 'annule'
    ");
    $stmt->execute([$userId]);
    $statsPerso = $stmt->fetch();
    
    $soldesComplets = array_merge($soldes, $statsPerso);
    
    // Top formations
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.titre,
            COUNT(DISTINCT vp.venteId) as nombreVentes,
            COALESCE(SUM(vp.prixUnitaire * vp.quantite), 0) as revenueBrut,
            COALESCE(SUM(ROUND((vp.prixUnitaire * vp.quantite) * 0.85, 2)), 0) as revenue,
            COALESCE(SUM(ROUND((vp.prixUnitaire * vp.quantite) * 0.15, 2)), 0) as commissionsPlateforme,
            p.prix
        FROM Produit p
        LEFT JOIN VenteProduit vp ON p.id = vp.produitId
        LEFT JOIN Vente v ON vp.venteId = v.id
        WHERE p.vendeurId = ? AND v.statut IN ('paye', 'en_attente')
        GROUP BY p.id, p.titre, p.prix
        ORDER BY nombreVentes DESC, revenue DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $topFormations = $stmt->fetchAll();
    
    // Ventes récentes
    $stmt = $conn->prepare("
        SELECT 
            vp.id,
            p.titre as formation,
            vp.prixUnitaire as prix,
            vp.quantite,
            (vp.prixUnitaire * vp.quantite) as totalBrut,
            COALESCE(c.montantVendeur, ROUND((vp.prixUnitaire * vp.quantite) * 0.85, 2)) as total,
            COALESCE(c.montantAdmin, ROUND((vp.prixUnitaire * vp.quantite) * 0.15, 2)) as commissionPlateforme,
            v.date as dateVente,
            acheteur.nom as acheteur,
            COALESCE(c.statut,
                CASE 
                    WHEN v.statut = 'paye' THEN 'paye'
                    WHEN v.statut = 'annule' THEN 'annule'
                    ELSE 'en_attente'
                END
            ) as statutPaiement
        FROM VenteProduit vp
        JOIN Produit p ON vp.produitId = p.id
        JOIN Vente v ON vp.venteId = v.id
        JOIN Utilisateur acheteur ON v.utilisateurId = acheteur.id
        LEFT JOIN Commission c ON v.id = c.venteId AND p.vendeurId = c.vendeurId
        WHERE p.vendeurId = ?
        ORDER BY v.date DESC
        LIMIT 20
    ");
    $stmt->execute([$userId]);
    $ventesRecentes = $stmt->fetchAll();
    
    // Demandes de retrait
    $stmt = $conn->prepare("
        SELECT 
            dr.id,
            dr.montant,
            dr.methodePaiement,
            dr.numeroCompte,
            dr.statut,
            dr.dateDemande,
            dr.dateTraitement
        FROM DemandeRetrait dr
        WHERE dr.utilisateurId = ? AND dr.statut IN ('en_attente', 'approuve')
        ORDER BY dr.dateDemande DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $demandesRetrait = $stmt->fetchAll();
    
    return [
        'soldes' => $soldesComplets,
        'top_formations' => $topFormations,
        'ventes_recentes' => $ventesRecentes,
        'demandes_retrait' => $demandesRetrait
    ];
}

error_log("=== GET COMMISSIONS API TERMINÉE ===");
?>