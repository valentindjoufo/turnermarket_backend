<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

$response = ['success' => false, 'message' => ''];

try {
    $vendeurId = $_GET['vendeurId'] ?? null;
    $role = $_GET['role'] ?? 'client';
    
    if (!$vendeurId) {
        throw new Exception('ID utilisateur manquant');
    }

    if ($role === 'admin') {
        // DASHBOARD ADMIN - Commission 15% sur toutes les ventes
        // 1. Récupérer les commissions ADMIN depuis la table Commission
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT p.id) as totalFormationsPlatform,
                COUNT(DISTINCT vp.id) as totalVentesPlatform,
                COUNT(DISTINCT u.id) as vendeursActifs,
                COUNT(DISTINCT v.utilisateurId) as clientsTotaux,
                COALESCE(AVG(vp.prixUnitaire), 0) as panierMoyenPlatform,
                
                -- COMMISSIONS ADMIN (15%)
                COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantAdmin ELSE 0 END), 0) as soldeDisponible,
                COALESCE(SUM(CASE WHEN c.statut = 'en_attente' THEN c.montantAdmin ELSE 0 END), 0) as soldeEnAttente,
                COALESCE(SUM(CASE WHEN c.statut = 'annule' THEN c.montantAdmin ELSE 0 END), 0) as soldeBloque,
                COALESCE(SUM(c.montantAdmin), 0) as revenueTotalPlatform,
                COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantAdmin ELSE 0 END), 0) as totalDejaPaye
            FROM Produit p
            LEFT JOIN VenteProduit vp ON p.id = vp.produitId
            LEFT JOIN Vente v ON vp.venteId = v.id
            LEFT JOIN Utilisateur u ON p.vendeurId = u.id AND u.role = 'client'
            LEFT JOIN Commission c ON v.id = c.venteId
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Statistiques par vendeur avec leurs commissions
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.nom,
                u.email,
                COUNT(DISTINCT p.id) as nombreFormations,
                COUNT(DISTINCT vp.id) as totalVentes,
                
                -- COMMISSIONS VENDEUR (85%)
                COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantVendeur ELSE 0 END), 0) as soldeDisponible,
                COALESCE(SUM(CASE WHEN c.statut = 'en_attente' THEN c.montantVendeur ELSE 0 END), 0) as soldeEnAttente,
                COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantVendeur ELSE 0 END), 0) as totalDejaPaye,
                COALESCE(SUM(c.montantVendeur), 0) as revenue
            FROM Utilisateur u
            LEFT JOIN Produit p ON u.id = p.vendeurId
            LEFT JOIN VenteProduit vp ON p.id = vp.produitId
            LEFT JOIN Commission c ON u.id = c.vendeurId
            WHERE u.role = 'client'
            GROUP BY u.id, u.nom, u.email
            ORDER BY revenue DESC
        ");
        $stmt->execute();
        $statistiquesVendeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Top formations avec commissions
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.titre,
                COUNT(vp.id) as nombreVentes,
                COALESCE(SUM(vp.prixUnitaire * vp.quantite), 0) as revenueTotal,
                COALESCE(SUM(c.montantVendeur), 0) as revenueVendeur,
                COALESCE(SUM(c.montantAdmin), 0) as revenueAdmin,
                p.prix,
                u.nom as vendeur
            FROM Produit p
            LEFT JOIN VenteProduit vp ON p.id = vp.produitId
            LEFT JOIN Utilisateur u ON p.vendeurId = u.id
            LEFT JOIN Commission c ON vp.venteId = c.venteId AND p.vendeurId = c.vendeurId
            GROUP BY p.id, p.titre, p.prix, u.nom
            ORDER BY revenueTotal DESC
            LIMIT 5
        ");
        $stmt->execute();
        $topFormations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Ventes récentes avec commissions
        $stmt = $pdo->prepare("
            SELECT 
                vp.id,
                p.titre as formation,
                vp.prixUnitaire as prix,
                vp.quantite,
                (vp.prixUnitaire * vp.quantite) as total,
                c.montantVendeur as commissionVendeur,
                c.montantAdmin as commissionAdmin,
                v.date as dateVente,
                acheteur.nom as acheteur,
                vendeur.nom as vendeur,
                c.statut as statutPaiement
            FROM VenteProduit vp
            JOIN Produit p ON vp.produitId = p.id
            JOIN Vente v ON vp.venteId = v.id
            JOIN Utilisateur acheteur ON v.utilisateurId = acheteur.id
            JOIN Utilisateur vendeur ON p.vendeurId = vendeur.id
            LEFT JOIN Commission c ON v.id = c.venteId AND p.vendeurId = c.vendeurId
            ORDER BY v.date DESC
            LIMIT 10
        ");
        $stmt->execute();
        $ventesRecentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Demandes de retrait
        $stmt = $pdo->prepare("
            SELECT 
                dr.id,
                dr.montant,
                dr.methodePaiement,
                dr.numeroCompte,
                dr.statut,
                dr.dateDemande,
                dr.dateTraitement,
                u.nom as demandeur,
                u.email
            FROM DemandeRetrait dr
            JOIN Utilisateur u ON dr.utilisateurId = u.id
            WHERE dr.statut IN ('en_attente')
            ORDER BY dr.dateDemande DESC
            LIMIT 10
        ");
        $stmt->execute();
        $demandeRetraitEnCours = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = [
            'success' => true,
            'dashboard' => [
                // COMMISSIONS ADMIN (15%)
                'soldeDisponible' => $stats['soldeDisponible'],
                'soldeEnAttente' => $stats['soldeEnAttente'],
                'soldeBloque' => $stats['soldeBloque'],
                'totalDejaPaye' => $stats['totalDejaPaye'],
                
                // Statistiques générales
                'nombreFormations' => $stats['totalFormationsPlatform'],
                'totalVentes' => $stats['totalVentesPlatform'],
                'revenueTotalPlatform' => $stats['revenueTotalPlatform'],
                'clientsUniques' => $stats['clientsTotaux'],
                'panierMoyen' => $stats['panierMoyenPlatform'],
                'vendeursActifs' => $stats['vendeursActifs'],
                
                // Données spécifiques admin
                'statistiquesVendeurs' => $statistiquesVendeurs,
                'ventesRecentes' => $ventesRecentes,
                'topFormations' => $topFormations,
                'demandeRetraitEnCours' => $demandeRetraitEnCours
            ]
        ];

    } else {
        // DASHBOARD CLIENT/VENDEUR - Commission 85% sur ses ventes
        // 1. Récupérer les commissions depuis la table Commission
        $stmt = $pdo->prepare("
            SELECT 
                -- COMMISSIONS VENDEUR (85%)
                COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantVendeur ELSE 0 END), 0) as soldeDisponible,
                COALESCE(SUM(CASE WHEN c.statut = 'en_attente' THEN c.montantVendeur ELSE 0 END), 0) as soldeEnAttente,
                COALESCE(SUM(CASE WHEN c.statut = 'annule' THEN c.montantVendeur ELSE 0 END), 0) as soldeBloque,
                COALESCE(SUM(CASE WHEN c.statut = 'paye' THEN c.montantVendeur ELSE 0 END), 0) as totalDejaPaye,
                
                -- Statistiques
                COUNT(DISTINCT p.id) as nombreFormations,
                COUNT(DISTINCT vp.id) as totalVentes,
                COALESCE(SUM(c.montantVendeur), 0) as revenueTotal,
                COUNT(DISTINCT v.utilisateurId) as clientsUniques,
                COALESCE(AVG(vp.prixUnitaire), 0) as panierMoyen
            FROM Utilisateur u
            LEFT JOIN Produit p ON u.id = p.vendeurId
            LEFT JOIN VenteProduit vp ON p.id = vp.produitId
            LEFT JOIN Vente v ON vp.venteId = v.id
            LEFT JOIN Commission c ON u.id = c.vendeurId
            WHERE u.id = ?
        ");
        $stmt->execute([$vendeurId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Ventes récentes avec commissions
        $stmt = $pdo->prepare("
            SELECT 
                vp.id,
                p.titre as formation,
                vp.prixUnitaire as prix,
                vp.quantite,
                (vp.prixUnitaire * vp.quantite) as totalBrut,
                c.montantVendeur as total, -- Montant net après commission (85%)
                c.montantAdmin as commissionPlateforme,
                v.date as dateVente,
                acheteur.nom as acheteur,
                c.statut as statutPaiement
            FROM VenteProduit vp
            JOIN Produit p ON vp.produitId = p.id
            JOIN Vente v ON vp.venteId = v.id
            JOIN Utilisateur acheteur ON v.utilisateurId = acheteur.id
            LEFT JOIN Commission c ON v.id = c.venteId AND p.vendeurId = c.vendeurId
            WHERE p.vendeurId = ?
            ORDER BY v.date DESC
            LIMIT 10
        ");
        $stmt->execute([$vendeurId]);
        $ventesRecentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Top formations avec commissions
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.titre,
                COUNT(vp.id) as nombreVentes,
                COALESCE(SUM(vp.prixUnitaire * vp.quantite), 0) as revenueBrut,
                COALESCE(SUM(c.montantVendeur), 0) as revenue, -- Revenue net après commission
                COALESCE(SUM(c.montantAdmin), 0) as commissionsPlateforme,
                p.prix
            FROM Produit p
            LEFT JOIN VenteProduit vp ON p.id = vp.produitId
            LEFT JOIN Commission c ON vp.venteId = c.venteId AND p.vendeurId = c.vendeurId
            WHERE p.vendeurId = ?
            GROUP BY p.id, p.titre, p.prix
            ORDER BY revenue DESC
            LIMIT 5
        ");
        $stmt->execute([$vendeurId]);
        $topFormations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Demandes de retrait
        $stmt = $pdo->prepare("
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
        $stmt->execute([$vendeurId]);
        $demandeRetraitEnCours = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = [
            'success' => true,
            'dashboard' => [
                ...$stats,
                'ventesRecentes' => $ventesRecentes,
                'topFormations' => $topFormations,
                'demandeRetraitEnCours' => $demandeRetraitEnCours
            ]
        ];
    }

} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
}

echo json_encode($response);
?>