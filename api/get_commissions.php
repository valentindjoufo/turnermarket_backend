<?php
/**
 * get_commissions.php - VERSION SANS DÉLAI - PAIEMENT IMMÉDIAT
 * Version compatible PostgreSQL (noms en minuscules)
 */

require_once 'config.php';

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
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    $userId = null;
    $userRole = 'client';
    $action = 'get_dashboard';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $userId = $_GET['userId'] ?? $_GET['vendeurId'] ?? null;
        $userRole = $_GET['role'] ?? 'client';
        $action = $_GET['action'] ?? 'get_dashboard';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    creerCommissionsManquantes($pdo);

    switch ($action) {
        case 'get_dashboard':
        default:
            getDashboardComplet($pdo, $userId, $userRole);
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
 */
function creerCommissionsManquantes($pdo) {
    try {
        error_log("🔄 Vérification des commissions manquantes...");

        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) as nb
            FROM vente v
            JOIN venteproduit vp ON v.id = vp.venteid
            LEFT JOIN commission c ON v.id = c.venteid
            WHERE c.id IS NULL
        ");
        $stmtCheck->execute();
        $result = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($result['nb'] > 0) {
            error_log("⚠️ {$result['nb']} ventes sans commission détectées - Création automatique...");

            $stmt = $pdo->prepare("
                INSERT INTO commission (venteid, vendeurid, montanttotal, montantvendeur, montantadmin, pourcentagecommission, statut, datecreation, datetraitement)
                SELECT 
                    v.id as venteid,
                    p.vendeurid,
                    (vp.prixunitaire * vp.quantite) as montanttotal,
                    ROUND((vp.prixunitaire * vp.quantite) * 0.85, 2) as montantvendeur,
                    ROUND((vp.prixunitaire * vp.quantite) * 0.15, 2) as montantadmin,
                    15 as pourcentagecommission,
                    CASE 
                        WHEN v.statut = 'paye' THEN 'paye'
                        WHEN v.statut = 'annule' THEN 'annule'
                        ELSE 'en_attente'
                    END as statut,
                    v.date as datecreation,
                    CASE WHEN v.statut = 'paye' THEN v.dateconfirmation ELSE NULL END as datetraitement
                FROM vente v
                JOIN venteproduit vp ON v.id = vp.venteid
                JOIN produit p ON vp.produitid = p.id
                LEFT JOIN commission c ON v.id = c.venteid AND p.vendeurid = c.vendeurid
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
function getDashboardComplet($pdo, $userId, $userRole) {
    try {
        error_log("🔍 getDashboardComplet - userId: $userId, role: $userRole");

        $dashboardData = [];

        if ($userRole === 'admin') {
            $dashboardData = getDashboardAdmin($pdo);
        } else {
            $dashboardData = getDashboardVendeur($pdo, $userId);
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
function getDashboardAdmin($pdo) {
    // Soldes admin
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN statut = 'paye' THEN montantadmin ELSE 0 END), 0) as soldedisponible,
            COALESCE(SUM(CASE WHEN statut = 'en_attente' THEN montantadmin ELSE 0 END), 0) as soldeenattente,
            COALESCE(SUM(CASE WHEN statut = 'annule' THEN montantadmin ELSE 0 END), 0) as soldebloque,
            COALESCE(SUM(CASE WHEN statut = 'paye' THEN montantadmin ELSE 0 END), 0) as totaldejapaye,
            COALESCE(SUM(CASE WHEN statut != 'annule' THEN montantadmin ELSE 0 END), 0) as revenuetotalplatform,
            COUNT(DISTINCT vendeurid) as vendeursactifs,
            COUNT(*) as totalcommissions,
            COUNT(DISTINCT venteid) as totalventesplatform
        FROM commission
    ");
    $stmt->execute();
    $soldesAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

    // Statistiques globales
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT v.utilisateurid) as clientstotaux,
            COALESCE(AVG(vp.prixunitaire * vp.quantite), 0) as paniermoyenplatform,
            COUNT(DISTINCT v.id) as nombreventestotal,
            COALESCE(SUM(vp.prixunitaire * vp.quantite), 0) as chiffreaffairetotal
        FROM venteproduit vp
        JOIN vente v ON vp.venteid = v.id
        WHERE v.statut != 'annule'
    ");
    $stmt->execute();
    $statsGlobales = $stmt->fetch(PDO::FETCH_ASSOC);

    // Statistiques par vendeur
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.nom,
            u.email,
            COUNT(DISTINCT p.id) as nombreformations,
            COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantvendeur ELSE 0 END), 0) as soldedisponible,
            COALESCE(SUM(CASE WHEN c.statut = 'en_attente' THEN c.montantvendeur ELSE 0 END), 0) as soldeenattente,
            COALESCE(SUM(CASE WHEN c.statut IN ('paye', 'en_attente') THEN c.montantvendeur ELSE 0 END), 0) as revenue,
            COUNT(DISTINCT c.venteid) as totalventes
        FROM utilisateur u
        LEFT JOIN produit p ON u.id = p.vendeurid
        LEFT JOIN commission c ON u.id = c.vendeurid
        WHERE u.role = 'client'
        GROUP BY u.id, u.nom, u.email
        ORDER BY revenue DESC
    ");
    $stmt->execute();
    $statistiquesVendeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top formations
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.titre,
            COUNT(DISTINCT vp.venteid) as nombreventes,
            COALESCE(SUM(vp.prixunitaire * vp.quantite), 0) as revenuetotal,
            COALESCE(SUM(ROUND((vp.prixunitaire * vp.quantite) * 0.85, 2)), 0) as revenuevendeur,
            COALESCE(SUM(ROUND((vp.prixunitaire * vp.quantite) * 0.15, 2)), 0) as revenueadmin,
            p.prix,
            u.nom as vendeur
        FROM produit p
        LEFT JOIN venteproduit vp ON p.id = vp.produitid
        LEFT JOIN vente v ON vp.venteid = v.id
        LEFT JOIN utilisateur u ON p.vendeurid = u.id
        WHERE v.statut IN ('paye', 'en_attente') OR v.statut IS NULL
        GROUP BY p.id, p.titre, p.prix, u.nom
        ORDER BY nombreventes DESC, revenuetotal DESC
        LIMIT 5
    ");
    $stmt->execute();
    $topFormations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ventes récentes
    $stmt = $pdo->prepare("
        SELECT 
            vp.id,
            p.titre as formation,
            vp.prixunitaire as prix,
            vp.quantite,
            (vp.prixunitaire * vp.quantite) as total,
            COALESCE(c.montantvendeur, ROUND((vp.prixunitaire * vp.quantite) * 0.85, 2)) as commissionvendeur,
            COALESCE(c.montantadmin, ROUND((vp.prixunitaire * vp.quantite) * 0.15, 2)) as commissionadmin,
            v.date as datevente,
            acheteur.nom as acheteur,
            vendeur.nom as vendeur,
            COALESCE(c.statut, 
                CASE 
                    WHEN v.statut = 'paye' THEN 'paye'
                    WHEN v.statut = 'annule' THEN 'annule'
                    ELSE 'en_attente'
                END
            ) as statutpaiement
        FROM venteproduit vp
        JOIN produit p ON vp.produitid = p.id
        JOIN vente v ON vp.venteid = v.id
        JOIN utilisateur acheteur ON v.utilisateurid = acheteur.id
        JOIN utilisateur vendeur ON p.vendeurid = vendeur.id
        LEFT JOIN commission c ON v.id = c.venteid AND p.vendeurid = c.vendeurid
        ORDER BY v.date DESC
        LIMIT 20
    ");
    $stmt->execute();
    $ventesRecentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Demandes de retrait
    $stmt = $pdo->prepare("
        SELECT 
            dr.id,
            dr.montant,
            dr.methodepaiement,
            dr.numerocompte,
            dr.statut,
            dr.datedemande,
            dr.datetraitement,
            u.nom as demandeur,
            u.email,
            u.role
        FROM demanderetrait dr
        JOIN utilisateur u ON dr.utilisateurid = u.id
        WHERE dr.statut = 'en_attente'
        ORDER BY dr.datedemande DESC
        LIMIT 10
    ");
    $stmt->execute();
    $demandesRetrait = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
function getDashboardVendeur($pdo, $userId) {
    // Calcul des soldes
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantvendeur ELSE 0 END), 0) as soldedisponible,
            COALESCE(SUM(CASE WHEN c.statut = 'en_attente' THEN c.montantvendeur ELSE 0 END), 0) as soldeenattente,
            COALESCE(SUM(CASE WHEN c.statut = 'annule' THEN c.montantvendeur ELSE 0 END), 0) as soldebloque,
            COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantvendeur ELSE 0 END), 0) as totaldejapaye,
            COALESCE(SUM(CASE WHEN c.statut IN ('paye', 'en_attente') THEN c.montantvendeur ELSE 0 END), 0) as revenuetotal,
            COALESCE(SUM(CASE WHEN c.statut != 'annule' THEN c.montanttotal ELSE 0 END), 0) as ventesbrutes,
            COALESCE(SUM(CASE WHEN c.statut != 'annule' THEN c.montantadmin ELSE 0 END), 0) as commissionsprelevees,
            COUNT(DISTINCT c.venteid) as totalventes
        FROM commission c
        WHERE c.vendeurid = ?
    ");
    $stmt->execute([$userId]);
    $soldes = $stmt->fetch(PDO::FETCH_ASSOC);

    // Statistiques personnelles
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT p.id) as nombreformations,
            COUNT(DISTINCT v.utilisateurid) as clientsuniques,
            COALESCE(AVG(vp.prixunitaire * vp.quantite), 0) as paniermoyen,
            COUNT(DISTINCT vp.venteid) as totaltransactions
        FROM produit p
        LEFT JOIN venteproduit vp ON p.id = vp.produitid
        LEFT JOIN vente v ON vp.venteid = v.id
        WHERE p.vendeurid = ? AND v.statut != 'annule'
    ");
    $stmt->execute([$userId]);
    $statsPerso = $stmt->fetch(PDO::FETCH_ASSOC);

    $soldesComplets = array_merge($soldes, $statsPerso);

    // Top formations
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.titre,
            COUNT(DISTINCT vp.venteid) as nombreventes,
            COALESCE(SUM(vp.prixunitaire * vp.quantite), 0) as revenuebrut,
            COALESCE(SUM(ROUND((vp.prixunitaire * vp.quantite) * 0.85, 2)), 0) as revenue,
            COALESCE(SUM(ROUND((vp.prixunitaire * vp.quantite) * 0.15, 2)), 0) as commissionsplateforme,
            p.prix
        FROM produit p
        LEFT JOIN venteproduit vp ON p.id = vp.produitid
        LEFT JOIN vente v ON vp.venteid = v.id
        WHERE p.vendeurid = ? AND v.statut IN ('paye', 'en_attente')
        GROUP BY p.id, p.titre, p.prix
        ORDER BY nombreventes DESC, revenue DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $topFormations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ventes récentes
    $stmt = $pdo->prepare("
        SELECT 
            vp.id,
            p.titre as formation,
            vp.prixunitaire as prix,
            vp.quantite,
            (vp.prixunitaire * vp.quantite) as totalbrut,
            COALESCE(c.montantvendeur, ROUND((vp.prixunitaire * vp.quantite) * 0.85, 2)) as total,
            COALESCE(c.montantadmin, ROUND((vp.prixunitaire * vp.quantite) * 0.15, 2)) as commissionplateforme,
            v.date as datevente,
            acheteur.nom as acheteur,
            COALESCE(c.statut,
                CASE 
                    WHEN v.statut = 'paye' THEN 'paye'
                    WHEN v.statut = 'annule' THEN 'annule'
                    ELSE 'en_attente'
                END
            ) as statutpaiement
        FROM venteproduit vp
        JOIN produit p ON vp.produitid = p.id
        JOIN vente v ON vp.venteid = v.id
        JOIN utilisateur acheteur ON v.utilisateurid = acheteur.id
        LEFT JOIN commission c ON v.id = c.venteid AND p.vendeurid = c.vendeurid
        WHERE p.vendeurid = ?
        ORDER BY v.date DESC
        LIMIT 20
    ");
    $stmt->execute([$userId]);
    $ventesRecentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Demandes de retrait
    $stmt = $pdo->prepare("
        SELECT 
            dr.id,
            dr.montant,
            dr.methodepaiement,
            dr.numerocompte,
            dr.statut,
            dr.datedemande,
            dr.datetraitement
        FROM demanderetrait dr
        WHERE dr.utilisateurid = ? AND dr.statut IN ('en_attente', 'approuve')
        ORDER BY dr.datedemande DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $demandesRetrait = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'soldes' => $soldesComplets,
        'top_formations' => $topFormations,
        'ventes_recentes' => $ventesRecentes,
        'demandes_retrait' => $demandesRetrait
    ];
}

error_log("=== GET COMMISSIONS API TERMINÉE ===");
?>