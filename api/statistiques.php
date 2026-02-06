<?php
// statistiques.php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$host   = "localhost";
$user   = "root"; 
$pass   = "";     
$dbname = "gestvente";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["error" => "Échec de connexion : " . $conn->connect_error]);
    exit;
}
$conn->set_charset("utf8mb4");

// Récupérer le filtre (jour, semaine, mois, annee)
$filter = isset($_GET["filter"]) ? $_GET["filter"] : "mois";

// Vérifier si on demande un produit précis
$produitId = isset($_GET["produitId"]) ? intval($_GET["produitId"]) : 0;

// ============================================================
// 🔹 Cas 1 : Retourner uniquement les utilisateurs d'un produit
// ============================================================
if ($produitId > 0) {
    $sql = "
        SELECT u.id AS utilisateurId, u.nom AS utilisateurNom, u.dateCreation,
               COUNT(DISTINCT v.id) AS achats,
               COALESCE(SUM(vp.quantite * vp.prixUnitaire),0) AS montant,
               MIN(v.date) AS datePremierAchat
        FROM Produit p
        JOIN VenteProduit vp ON vp.produitId = p.id
        JOIN Vente v ON v.id = vp.venteId
        JOIN Utilisateur u ON u.id = v.utilisateurId
        WHERE u.role = 'client' AND p.id = $produitId
        GROUP BY u.id, u.nom, u.dateCreation
        ORDER BY achats DESC
    ";

    $res = $conn->query($sql);
    $utilisateurs = [];
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $utilisateurs[] = [
                "id" => (int)$row["utilisateurId"],
                "nom" => $row["utilisateurNom"],
                "achats" => (int)$row["achats"],
                "montant" => (float)$row["montant"],
                "datePremierAchat" => $row["datePremierAchat"],
                "dateCreation" => $row["dateCreation"]
            ];
        }
    }

    echo json_encode([
        "status" => "success",
        "produitId" => $produitId,
        "utilisateurs" => $utilisateurs
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $conn->close();
    exit;
}

// ============================================================
// 🔹 Cas 2 : Statistiques globales + classement
// ============================================================

function getSingleValue($conn, $sql, $default = 0) {
    if ($result = $conn->query($sql)) {
        $row = $result->fetch_assoc();
        return $row ? (int)$row["total"] : $default;
    }
    return $default;
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

$today = date("Y-m-d");
$month = (int)date("m");
$year  = (int)date("Y");

$clients_today = getSingleValue($conn, "SELECT COUNT(*) AS total FROM Utilisateur WHERE role='client' AND DATE(dateCreation)='$today'");
$clients_month = getSingleValue($conn, "SELECT COUNT(*) AS total FROM Utilisateur WHERE role='client' AND MONTH(dateCreation)=$month AND YEAR(dateCreation)=$year");
$total_users  = getSingleValue($conn, "SELECT COUNT(*) AS total FROM Utilisateur");
$total_admins = getSingleValue($conn, "SELECT COUNT(*) AS total FROM Utilisateur WHERE role='admin'");

// 🔹 Revenus par produit
$sql_revenus = "
    SELECT p.id AS produitId, p.titre AS produit, 
           COALESCE(SUM(vp.quantite * vp.prixUnitaire), 0) AS revenu
    FROM Produit p
    LEFT JOIN VenteProduit vp ON vp.produitId = p.id
    GROUP BY p.id, p.titre
    ORDER BY revenu DESC
";
$res_revenus = $conn->query($sql_revenus);
$revenus = [];
if ($res_revenus && $res_revenus->num_rows > 0) {
    while ($row = $res_revenus->fetch_assoc()) {
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
$res_pays_inscriptions = $conn->query($sql_pays_inscriptions);
$stats_pays = [];
if ($res_pays_inscriptions && $res_pays_inscriptions->num_rows > 0) {
    while ($row = $res_pays_inscriptions->fetch_assoc()) {
        $pays = $row["pays"];
        $code = getCountryCode($pays);
        
        // Statistiques d'achats par pays
        $sql_achats_pays = "
            SELECT COALESCE(SUM(vp.quantite * vp.prixUnitaire), 0) AS montantTotal,
                   COUNT(DISTINCT v.id) AS nombreAchats
            FROM Utilisateur u
            JOIN Vente v ON v.utilisateurId = u.id
            JOIN VenteProduit vp ON vp.venteId = v.id
            WHERE u.nationalite = '" . $conn->real_escape_string($pays) . "'
        ";
        $res_achats = $conn->query($sql_achats_pays);
        $achats_data = $res_achats->fetch_assoc();
        
        $stats_pays[] = [
            "pays" => $pays,
            "code" => $code,
            "inscriptions" => (int)$row["inscriptions"],
            "montantTotal" => (float)$achats_data["montantTotal"],
            "nombreAchats" => (int)$achats_data["nombreAchats"]
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
        $dateFormat = "%H:00";
        $dateGroup = "HOUR(v.date)";
        $whereClause = "DATE(v.date) = CURDATE()";
        $limit = 24;
        break;
    case "semaine":
        $dateFormat = "%W";
        $dateGroup = "DAYOFWEEK(v.date)";
        $whereClause = "YEARWEEK(v.date, 1) = YEARWEEK(CURDATE(), 1)";
        $limit = 7;
        break;
    case "annee":
        $dateFormat = "%b";
        $dateGroup = "MONTH(v.date)";
        $whereClause = "YEAR(v.date) = YEAR(CURDATE())";
        $limit = 12;
        break;
    case "mois":
    default:
        $dateFormat = "%d/%m";
        $dateGroup = "DATE(v.date)";
        $whereClause = "YEAR(v.date) = YEAR(CURDATE()) AND MONTH(v.date) = MONTH(CURDATE())";
        $limit = 31;
        break;
}

$sql_evolution = "
    SELECT DATE_FORMAT(v.date, '$dateFormat') AS label,
           COALESCE(SUM(vp.quantite * vp.prixUnitaire), 0) AS valeur
    FROM Vente v
    LEFT JOIN VenteProduit vp ON vp.venteId = v.id
    WHERE $whereClause
    GROUP BY $dateGroup, label
    ORDER BY v.date ASC
    LIMIT $limit
";

$res_evolution = $conn->query($sql_evolution);
if ($res_evolution && $res_evolution->num_rows > 0) {
    while ($row = $res_evolution->fetch_assoc()) {
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
            $labels = ["Dim", "Lun", "Mar", "Mer", "Jeu", "Ven", "Sam"];
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
$res_global = $conn->query($sql_classement_global);
$classement_today = [];
if ($res_global && $res_global->num_rows > 0) {
    while ($row = $res_global->fetch_assoc()) {
        $classement_today[] = [
            "id" => (int)$row["utilisateurId"],
            "nom" => $row["utilisateurNom"],
            "achats" => (int)$row["achats"],
            "montant" => (float)$row["montant"],
            "datePremierAchat" => $row["datePremierAchat"],
            "dateCreation" => $row["dateCreation"]
        ];
    }
}

// 🔹 Classement par produit
$classementProduits = [];
$sql_produits = "SELECT id FROM Produit";
$res_produits = $conn->query($sql_produits);
if ($res_produits && $res_produits->num_rows > 0) {
    while ($prod = $res_produits->fetch_assoc()) {
        $pid = (int)$prod['id'];
        $sql_users = "
            SELECT u.id AS utilisateurId, u.nom AS utilisateurNom, u.dateCreation,
                   COUNT(DISTINCT v.id) AS achats,
                   COALESCE(SUM(vp.quantite * vp.prixUnitaire),0) AS montant,
                   MIN(v.date) AS datePremierAchat
            FROM Utilisateur u
            JOIN Vente v ON v.utilisateurId = u.id
            JOIN VenteProduit vp ON vp.venteId = v.id
            WHERE u.role='client' AND vp.produitId=$pid
            GROUP BY u.id, u.nom, u.dateCreation
            ORDER BY achats DESC
        ";
        $res_users = $conn->query($sql_users);
        $users = [];
        if ($res_users && $res_users->num_rows > 0) {
            while ($user = $res_users->fetch_assoc()) {
                $users[] = [
                    "id" => (int)$user["utilisateurId"],
                    "nom" => $user["utilisateurNom"],
                    "achats" => (int)$user["achats"],
                    "montant" => (float)$user["montant"],
                    "datePremierAchat" => $user["datePremierAchat"],
                    "dateCreation" => $user["dateCreation"]
                ];
            }
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
$conn->close();
?>