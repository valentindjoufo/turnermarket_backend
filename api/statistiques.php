<?php
// statistiques.php

// Inclure la configuration de connexion à la base de données
require_once 'config.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Récupérer le filtre (jour, semaine, mois, annee)
$filter = isset($_GET["filter"]) ? $_GET["filter"] : "mois";

// Vérifier si on demande un produit précis
$produitId = isset($_GET["produitId"]) ? intval($_GET["produitId"]) : 0;

// Fonction utilitaire pour obtenir une valeur simple depuis une requête
function getSingleValue($pdo, $sql, $default = 0) {
    try {
        $stmt = $pdo->query($sql);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row["total"] : $default;
    } catch (Exception $e) {
        error_log("Erreur dans getSingleValue: " . $e->getMessage());
        return $default;
    }
}

// Fonction pour obtenir le code pays ISO
function getCountryCode($country) {
    $codes = [
        'Cameroun' => 'CM',
        'France' => 'FR',
        'États-Unis' => 'US',
        'Canada' => 'CA',
        'Sénégal' => 'SN',
        'Côte d\'Ivoire' => 'CI',
        'Mali' => 'ML',
        'Burkina Faso' => 'BF',
        'Niger' => 'NE',
        'Tchad' => 'TD',
        'Gabon' => 'GA',
        'Congo' => 'CG',
        'Bénin' => 'BJ',
        'Togo' => 'TG',
        'Guinée' => 'GN',
        'Belgique' => 'BE',
        'Suisse' => 'CH',
        'Allemagne' => 'DE',
        'Royaume-Uni' => 'GB',
        'Espagne' => 'ES',
        'Italie' => 'IT',
        'Maroc' => 'MA',
        'Algérie' => 'DZ',
        'Tunisie' => 'TN',
        'Égypte' => 'EG',
        'Afrique du Sud' => 'ZA',
        'Nigeria' => 'NG',
        'Ghana' => 'GH',
        'Kenya' => 'KE',
        'Éthiopie' => 'ET',
        'Chine' => 'CN',
        'Japon' => 'JP',
        'Corée du Sud' => 'KR',
        'Inde' => 'IN',
        'Brésil' => 'BR',
        'Argentine' => 'AR',
        'Mexique' => 'MX',
    ];
    
    return isset($codes[$country]) ? $codes[$country] : 'XX';
}

// ============================================================
// 🔹 Cas 1 : Retourner uniquement les utilisateurs d'un produit
// ============================================================
if ($produitId > 0) {
    try {
        $sql = "
            SELECT u.id AS utilisateurId, u.nom AS utilisateurNom, u.dateCreation,
                   COUNT(DISTINCT v.id) AS achats,
                   COALESCE(SUM(vp.quantite * vp.prixUnitaire),0) AS montant,
                   MIN(v.date) AS datePremierAchat
            FROM Produit p
            JOIN VenteProduit vp ON vp.produitId = p.id
            JOIN Vente v ON v.id = vp.venteId
            JOIN Utilisateur u ON u.id = v.utilisateurId
            WHERE u.role = 'client' AND p.id = :produitId
            GROUP BY u.id, u.nom, u.dateCreation
            ORDER BY achats DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':produitId' => $produitId]);
        $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formater les données
        $formattedUtilisateurs = [];
        foreach ($utilisateurs as $row) {
            $formattedUtilisateurs[] = [
                "id" => (int)$row["utilisateurId"],
                "nom" => $row["utilisateurNom"],
                "achats" => (int)$row["achats"],
                "montant" => (float)$row["montant"],
                "datePremierAchat" => $row["datePremierAchat"],
                "dateCreation" => $row["dateCreation"]
            ];
        }

        echo json_encode([
            "status" => "success",
            "produitId" => $produitId,
            "utilisateurs" => $formattedUtilisateurs
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        exit;
        
    } catch (PDOException $e) {
        error_log("❌ Erreur SQL (utilisateurs produit): " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["error" => "Erreur lors de la récupération des utilisateurs"]);
        exit;
    }
}

// ============================================================
// 🔹 Cas 2 : Statistiques globales + classement
// ============================================================

try {
    $today = date("Y-m-d");
    $month = (int)date("m");
    $year  = (int)date("Y");

    $clients_today = getSingleValue($pdo, "SELECT COUNT(*) AS total FROM Utilisateur WHERE role='client' AND DATE(dateCreation)='$today'");
    $clients_month = getSingleValue($pdo, "SELECT COUNT(*) AS total FROM Utilisateur WHERE role='client' AND EXTRACT(MONTH FROM dateCreation)=$month AND EXTRACT(YEAR FROM dateCreation)=$year");
    $total_users  = getSingleValue($pdo, "SELECT COUNT(*) AS total FROM Utilisateur");
    $total_admins = getSingleValue($pdo, "SELECT COUNT(*) AS total FROM Utilisateur WHERE role='admin'");

    // 🔹 Revenus par produit
    $sql_revenus = "
        SELECT p.id AS produitId, p.titre AS produit, 
               COALESCE(SUM(vp.quantite * vp.prixUnitaire), 0) AS revenu
        FROM Produit p
        LEFT JOIN VenteProduit vp ON vp.produitId = p.id
        GROUP BY p.id, p.titre
        ORDER BY revenu DESC
    ";
    
    $stmt_revenus = $pdo->query($sql_revenus);
    $revenus = [];
    if ($stmt_revenus) {
        $rows = $stmt_revenus->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $revenus[] = [
                "id"      => (int)$row["produitId"],
                "produit" => $row["produit"],
                "revenu"  => (float)$row["revenu"]
            ];
        }
    }

    // 🔹 Statistiques par pays (inscriptions)
    $sql_pays_inscriptions = "
        SELECT nationalite AS pays, COUNT(*) AS inscriptions
        FROM Utilisateur
        WHERE role = 'client' AND nationalite IS NOT NULL AND nationalite != ''
        GROUP BY nationalite
        ORDER BY inscriptions DESC
    ";
    
    $stmt_pays = $pdo->query($sql_pays_inscriptions);
    $stats_pays = [];
    if ($stmt_pays) {
        $rows = $stmt_pays->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $pays = $row["pays"];
            $code = getCountryCode($pays);
            
            // Statistiques d'achats par pays
            $sql_achats_pays = "
                SELECT COALESCE(SUM(vp.quantite * vp.prixUnitaire), 0) AS montantTotal,
                       COUNT(DISTINCT v.id) AS nombreAchats
                FROM Utilisateur u
                JOIN Vente v ON v.utilisateurId = u.id
                JOIN VenteProduit vp ON vp.venteId = v.id
                WHERE u.nationalite = :pays
            ";
            
            $stmt_achats = $pdo->prepare($sql_achats_pays);
            $stmt_achats->execute([':pays' => $pays]);
            $achats_data = $stmt_achats->fetch(PDO::FETCH_ASSOC);
            
            $stats_pays[] = [
                "pays" => $pays,
                "code" => $code,
                "inscriptions" => (int)$row["inscriptions"],
                "montantTotal" => (float)($achats_data["montanttotal"] ?? 0),
                "nombreAchats" => (int)($achats_data["nombreachats"] ?? 0)
            ];
        }
    }

    // 🔹 Évolution des ventes selon le filtre
    $evolution = [];
    $dateFormat = "";
    $dateGroup = "";
    $limit = 30;

    switch ($filter) {
        case "jour":
            $dateFormat = 'HH24:00';
            $dateGroup = "EXTRACT(HOUR FROM v.date)";
            $whereClause = "DATE(v.date) = CURRENT_DATE";
            $limit = 24;
            break;
        case "semaine":
            $dateFormat = 'Day';
            $dateGroup = "EXTRACT(DOW FROM v.date)";
            $whereClause = "EXTRACT(YEAR FROM v.date) = EXTRACT(YEAR FROM CURRENT_DATE) 
                            AND EXTRACT(WEEK FROM v.date) = EXTRACT(WEEK FROM CURRENT_DATE)";
            $limit = 7;
            break;
        case "annee":
            $dateFormat = 'Mon';
            $dateGroup = "EXTRACT(MONTH FROM v.date)";
            $whereClause = "EXTRACT(YEAR FROM v.date) = EXTRACT(YEAR FROM CURRENT_DATE)";
            $limit = 12;
            break;
        case "mois":
        default:
            $dateFormat = 'DD/MM';
            $dateGroup = "DATE(v.date)";
            $whereClause = "EXTRACT(YEAR FROM v.date) = EXTRACT(YEAR FROM CURRENT_DATE) 
                            AND EXTRACT(MONTH FROM v.date) = EXTRACT(MONTH FROM CURRENT_DATE)";
            $limit = 31;
            break;
    }

    $sql_evolution = "
        SELECT TO_CHAR(v.date, '$dateFormat') AS label,
               COALESCE(SUM(vp.quantite * vp.prixUnitaire), 0) AS valeur
        FROM Vente v
        LEFT JOIN VenteProduit vp ON vp.venteId = v.id
        WHERE $whereClause
        GROUP BY $dateGroup, label
        ORDER BY v.date ASC
        LIMIT $limit
    ";

    $stmt_evolution = $pdo->query($sql_evolution);
    if ($stmt_evolution) {
        $rows = $stmt_evolution->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $evolution[] = [
                "label" => $row["label"],
                "valeur" => (float)$row["valeur"]
            ];
        }
    }

    // Si pas de données d'évolution, créer des données vides
    if (empty($evolution)) {
        $labels = [];
        switch ($filter) {
            case "jour":
                for ($i = 0; $i < 24; $i++) {
                    $labels[] = sprintf("%02d:00", $i);
                }
                break;
            case "semaine":
                $labels = ["Dimanche", "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi"];
                break;
            case "mois":
                for ($i = 1; $i <= 31; $i++) {
                    $labels[] = sprintf("%02d/%02d", $i, date('m'));
                }
                break;
            case "annee":
                $labels = ["Jan", "Fév", "Mar", "Avr", "Mai", "Juin", "Juil", "Août", "Sep", "Oct", "Nov", "Déc"];
                break;
        }
        
        foreach ($labels as $label) {
            $evolution[] = [
                "label" => $label,
                "valeur" => 0
            ];
        }
    }

    // 🔹 Classement global des utilisateurs
    $sql_classement_global = "
        SELECT u.id AS utilisateurId, u.nom AS utilisateurNom, u.dateCreation,
               COUNT(DISTINCT v.id) AS achats,
               COALESCE(SUM(vp.quantite * vp.prixUnitaire),0) AS montant,
               MIN(v.date) AS datePremierAchat
        FROM Utilisateur u
        LEFT JOIN Vente v ON v.utilisateurId = u.id
        LEFT JOIN VenteProduit vp ON vp.venteId = v.id
        WHERE u.role = 'client'
        GROUP BY u.id, u.nom, u.dateCreation
        ORDER BY achats DESC
    ";
    
    $stmt_global = $pdo->query($sql_classement_global);
    $classement_today = [];
    if ($stmt_global) {
        $rows = $stmt_global->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $classement_today[] = [
                "id" => (int)$row["utilisateurid"],
                "nom" => $row["utilisateurnom"],
                "achats" => (int)$row["achats"],
                "montant" => (float)$row["montant"],
                "datePremierAchat" => $row["datepremierachat"],
                "dateCreation" => $row["datecreation"]
            ];
        }
    }

    // 🔹 Classement par produit
    $classementProduits = [];
    $sql_produits = "SELECT id FROM Produit";
    $stmt_produits = $pdo->query($sql_produits);
    
    if ($stmt_produits) {
        $produits = $stmt_produits->fetchAll(PDO::FETCH_ASSOC);
        foreach ($produits as $prod) {
            $pid = (int)$prod['id'];
            $sql_users = "
                SELECT u.id AS utilisateurId, u.nom AS utilisateurNom, u.dateCreation,
                       COUNT(DISTINCT v.id) AS achats,
                       COALESCE(SUM(vp.quantite * vp.prixUnitaire),0) AS montant,
                       MIN(v.date) AS datePremierAchat
                FROM Utilisateur u
                JOIN Vente v ON v.utilisateurId = u.id
                JOIN VenteProduit vp ON vp.venteId = v.id
                WHERE u.role='client' AND vp.produitId = :pid
                GROUP BY u.id, u.nom, u.dateCreation
                ORDER BY achats DESC
            ";
            
            $stmt_users = $pdo->prepare($sql_users);
            $stmt_users->execute([':pid' => $pid]);
            $users = [];
            $rows = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($rows as $user) {
                $users[] = [
                    "id" => (int)$user["utilisateurid"],
                    "nom" => $user["utilisateurnom"],
                    "achats" => (int)$user["achats"],
                    "montant" => (float)$user["montant"],
                    "datePremierAchat" => $user["datepremierachat"],
                    "dateCreation" => $user["datecreation"]
                ];
            }
            $classementProduits[$pid] = $users;
        }
    }

    // Réponse finale
    $response = [
        "status" => "success",
        "data" => [
            "clients_today"      => $clients_today,
            "clients_month"      => $clients_month,
            "total_users"        => $total_users,
            "total_admins"       => $total_admins,
            "revenus"            => $revenus,
            "stats_pays"         => $stats_pays,
            "evolution"          => $evolution,
            "classement_today"   => $classement_today,
            "classementProduits" => $classementProduits
        ]
    ];

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("❌ Erreur SQL générale: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erreur lors de la récupération des statistiques", "details" => $e->getMessage()]);
} catch (Exception $e) {
    error_log("❌ Erreur générale: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erreur serveur"]);
}
?>