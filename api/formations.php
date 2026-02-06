<?php
// Autorisations CORS complètes
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Répondre au préflight (OPTIONS) et sortir immédiatement
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Connexion à la base de données
$host = 'localhost';
$dbname = 'gestvente';
$user = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Erreur connexion: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion', 'details' => $e->getMessage()]);
    exit;
}

// Répertoire des uploads
define('UPLOAD_DIR', __DIR__ . '/uploads/');
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

$method = $_SERVER['REQUEST_METHOD'];

// Utilitaire pour lire JSON depuis php://input
function getJsonInput()
{
    $data = json_decode(file_get_contents("php://input"), true);
    return is_array($data) ? $data : [];
}

// Fonction pour formater les dates pour l'affichage
function formatDateForDisplay($dateString) {
    if (empty($dateString)) return null;
    try {
        $date = new DateTime($dateString);
        return $date->format('d/m/Y');
    } catch (Exception $e) {
        return null;
    }
}

// ------------------------
// 📚 GET - Récupérer les formations
// ------------------------
if ($method === 'GET') {
    try {
        $userId = isset($_GET['userId']) ? intval($_GET['userId']) : 0;
        $vendeurId = isset($_GET['vendeurId']) ? intval($_GET['vendeurId']) : null;
        $mode = isset($_GET['mode']) ? $_GET['mode'] : 'all'; // ✅ NOUVEAU: Mode de filtrage
        
        // ✅ MODE "FOLLOWING" - Formations des comptes suivis
        if ($mode === 'following') {
            if ($userId <= 0) {
                echo json_encode([]);
                exit;
            }
            
            $sql = "
                SELECT p.*, 
                    u.nom as vendeur_nom, 
                    u.email as vendeur_email, 
                    u.photoProfil as vendeur_photo,
                    u.id as vendeurId,
                    -- ✅ Statistiques du vendeur
                    (
                        SELECT COUNT(*) 
                        FROM Follow f 
                        WHERE f.followingId = u.id
                    ) as nombreFollowers,
                    (
                        SELECT COUNT(*) 
                        FROM Follow f 
                        WHERE f.followerId = u.id
                    ) as nombreFollowing,
                    -- ✅ État de suivi (toujours 1 dans ce mode)
                    1 as isFollowing,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM VenteProduit vp 
                            JOIN Vente v ON v.id = vp.venteId 
                            WHERE vp.produitId = p.id AND v.utilisateurId = :userId AND vp.achetee = 1
                        ) THEN 1 ELSE 0
                    END AS achetee,
                    COALESCE(r.likes, 0) AS likes,
                    COALESCE(r.pouces, 0) AS pouces,
                    CASE 
                        WHEN p.estEnPromotion = 1 AND p.prix > 0 AND p.prixPromotion > 0 
                        THEN ROUND(((p.prix - p.prixPromotion) / p.prix) * 100)
                        ELSE 0
                    END AS pourcentageReduction
                FROM Produit p
                LEFT JOIN Utilisateur u ON p.vendeurId = u.id
                LEFT JOIN ProduitReaction r ON p.id = r.produitId
                INNER JOIN Follow f ON p.vendeurId = f.followingId
                WHERE f.followerId = :followerId
                ORDER BY p.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['userId' => $userId, 'followerId' => $userId]);
            $formations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Formater les dates pour l'affichage
            foreach ($formations as &$formation) {
                if ($formation['dateDebutPromo']) {
                    $formation['dateDebutPromoDisplay'] = formatDateForDisplay($formation['dateDebutPromo']);
                }
                if ($formation['dateFinPromo']) {
                    $formation['dateFinPromoDisplay'] = formatDateForDisplay($formation['dateFinPromo']);
                }
                if ($formation['expiration']) {
                    $formation['expirationDisplay'] = formatDateForDisplay($formation['expiration']);
                }
            }
            unset($formation);

            echo json_encode($formations);
            exit;
        }
        
        // ✅ MODE "POPULAR" - Formations populaires
        if ($mode === 'popular') {
            $sql = "
                SELECT p.*, 
                    u.nom as vendeur_nom, 
                    u.email as vendeur_email, 
                    u.photoProfil as vendeur_photo,
                    u.id as vendeurId,
                    -- ✅ Statistiques du vendeur
                    (
                        SELECT COUNT(*) 
                        FROM Follow f 
                        WHERE f.followingId = u.id
                    ) as nombreFollowers,
                    (
                        SELECT COUNT(*) 
                        FROM Follow f 
                        WHERE f.followerId = u.id
                    ) as nombreFollowing,
                    -- ✅ État de suivi
                    CASE 
                        WHEN :userId > 0 AND EXISTS (
                            SELECT 1 FROM Follow 
                            WHERE followerId = :userId AND followingId = p.vendeurId
                        ) THEN 1 ELSE 0
                    END AS isFollowing,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM VenteProduit vp 
                            JOIN Vente v ON v.id = vp.venteId 
                            WHERE vp.produitId = p.id AND v.utilisateurId = :userId AND vp.achetee = 1
                        ) THEN 1 ELSE 0
                    END AS achetee,
                    COALESCE(r.likes, 0) AS likes,
                    COALESCE(r.pouces, 0) AS pouces,
                    CASE 
                        WHEN p.estEnPromotion = 1 AND p.prix > 0 AND p.prixPromotion > 0 
                        THEN ROUND(((p.prix - p.prixPromotion) / p.prix) * 100)
                        ELSE 0
                    END AS pourcentageReduction
                FROM Produit p
                LEFT JOIN Utilisateur u ON p.vendeurId = u.id
                LEFT JOIN ProduitReaction r ON p.id = r.produitId
                ORDER BY (COALESCE(r.likes, 0) + COALESCE(r.pouces, 0)) DESC, p.id DESC
                LIMIT 50
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['userId' => $userId]);
            $formations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Formater les dates pour l'affichage
            foreach ($formations as &$formation) {
                if ($formation['dateDebutPromo']) {
                    $formation['dateDebutPromoDisplay'] = formatDateForDisplay($formation['dateDebutPromo']);
                }
                if ($formation['dateFinPromo']) {
                    $formation['dateFinPromoDisplay'] = formatDateForDisplay($formation['dateFinPromo']);
                }
                if ($formation['expiration']) {
                    $formation['expirationDisplay'] = formatDateForDisplay($formation['expiration']);
                }
            }
            unset($formation);

            echo json_encode($formations);
            exit;
        }

        // Si un ID est fourni, récupérer une formation spécifique
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $sql = "
                SELECT p.*, 
                    u.nom as vendeur_nom, 
                    u.email as vendeur_email, 
                    u.photoProfil as vendeur_photo,
                    u.id as vendeurId,
                    -- ✅ Statistiques du vendeur
                    (
                        SELECT COUNT(*) 
                        FROM Follow f 
                        WHERE f.followingId = u.id
                    ) as nombreFollowers,
                    (
                        SELECT COUNT(*) 
                        FROM Follow f 
                        WHERE f.followerId = u.id
                    ) as nombreFollowing,
                    -- ✅ État de suivi
                    CASE 
                        WHEN :userId > 0 AND EXISTS (
                            SELECT 1 FROM Follow 
                            WHERE followerId = :userId AND followingId = p.vendeurId
                        ) THEN 1 ELSE 0
                    END AS isFollowing,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM VenteProduit vp 
                            JOIN Vente v ON v.id = vp.venteId 
                            WHERE vp.produitId = p.id AND v.utilisateurId = :userId AND vp.achetee = 1
                        ) THEN 1 ELSE 0
                    END AS achetee,
                    COALESCE(r.likes, 0) AS likes,
                    COALESCE(r.pouces, 0) AS pouces,
                    -- Calculer le pourcentage de réduction si en promotion
                    CASE 
                        WHEN p.estEnPromotion = 1 AND p.prix > 0 AND p.prixPromotion > 0 
                        THEN ROUND(((p.prix - p.prixPromotion) / p.prix) * 100)
                        ELSE 0
                    END AS pourcentageReduction
                FROM Produit p
                LEFT JOIN Utilisateur u ON p.vendeurId = u.id
                LEFT JOIN ProduitReaction r ON p.id = r.produitId
                WHERE p.id = :id
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id, 'userId' => $userId]);
            $formation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$formation) {
                http_response_code(404);
                echo json_encode(['error' => 'Formation introuvable']);
                exit;
            }

            // Formater les dates pour l'affichage
            if ($formation['dateDebutPromo']) {
                $formation['dateDebutPromoDisplay'] = formatDateForDisplay($formation['dateDebutPromo']);
            }
            if ($formation['dateFinPromo']) {
                $formation['dateFinPromoDisplay'] = formatDateForDisplay($formation['dateFinPromo']);
            }
            if ($formation['expiration']) {
                $formation['expirationDisplay'] = formatDateForDisplay($formation['expiration']);
            }

            echo json_encode($formation);
            exit;
        }
        
        // Si vendeurId est fourni, récupérer les formations d'un vendeur spécifique
        elseif ($vendeurId !== null) {
            $sql = "
                SELECT p.*, 
                    u.nom as vendeur_nom, 
                    u.photoProfil as vendeur_photo,
                    u.id as vendeurId,
                    -- ✅ Statistiques du vendeur
                    (
                        SELECT COUNT(*) 
                        FROM Follow f 
                        WHERE f.followingId = u.id
                    ) as nombreFollowers,
                    (
                        SELECT COUNT(*) 
                        FROM Follow f 
                        WHERE f.followerId = u.id
                    ) as nombreFollowing,
                    -- ✅ État de suivi
                    CASE 
                        WHEN :userId > 0 AND EXISTS (
                            SELECT 1 FROM Follow 
                            WHERE followerId = :userId AND followingId = p.vendeurId
                        ) THEN 1 ELSE 0
                    END AS isFollowing,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM VenteProduit vp 
                            JOIN Vente v ON v.id = vp.venteId 
                            WHERE vp.produitId = p.id AND v.utilisateurId = :userId AND vp.achetee = 1
                        ) THEN 1 ELSE 0
                    END AS achetee,
                    COALESCE(r.likes, 0) AS likes,
                    COALESCE(r.pouces, 0) AS pouces,
                    -- Calculer le pourcentage de réduction si en promotion
                    CASE 
                        WHEN p.estEnPromotion = 1 AND p.prix > 0 AND p.prixPromotion > 0 
                        THEN ROUND(((p.prix - p.prixPromotion) / p.prix) * 100)
                        ELSE 0
                    END AS pourcentageReduction
                FROM Produit p
                LEFT JOIN Utilisateur u ON p.vendeurId = u.id
                LEFT JOIN ProduitReaction r ON p.id = r.produitId
                WHERE p.vendeurId = :vendeurId
                ORDER BY p.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['userId' => $userId, 'vendeurId' => $vendeurId]);
            $formations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formater les dates pour l'affichage
            foreach ($formations as &$formation) {
                if ($formation['dateDebutPromo']) {
                    $formation['dateDebutPromoDisplay'] = formatDateForDisplay($formation['dateDebutPromo']);
                }
                if ($formation['dateFinPromo']) {
                    $formation['dateFinPromoDisplay'] = formatDateForDisplay($formation['dateFinPromo']);
                }
                if ($formation['expiration']) {
                    $formation['expirationDisplay'] = formatDateForDisplay($formation['expiration']);
                }
            }
            unset($formation);

            echo json_encode($formations);
            exit;
        } else {
            // Récupérer toutes les formations
            $sql = "
                SELECT p.*, 
                    u.nom as vendeur_nom, 
                    u.email as vendeur_email, 
                    u.photoProfil as vendeur_photo,
                    u.id as vendeurId,
                    -- ✅ Statistiques du vendeur
                    (
                        SELECT COUNT(*) 
                        FROM Follow f 
                        WHERE f.followingId = u.id
                    ) as nombreFollowers,
                    (
                        SELECT COUNT(*) 
                        FROM Follow f 
                        WHERE f.followerId = u.id
                    ) as nombreFollowing,
                    -- ✅ État de suivi
                    CASE 
                        WHEN :userId > 0 AND EXISTS (
                            SELECT 1 FROM Follow 
                            WHERE followerId = :userId AND followingId = p.vendeurId
                        ) THEN 1 ELSE 0
                    END AS isFollowing,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM VenteProduit vp 
                            JOIN Vente v ON v.id = vp.venteId 
                            WHERE vp.produitId = p.id AND v.utilisateurId = :userId AND vp.achetee = 1
                        ) THEN 1 ELSE 0
                    END AS achetee,
                    COALESCE(r.likes, 0) AS likes,
                    COALESCE(r.pouces, 0) AS pouces,
                    -- Calculer le pourcentage de réduction si en promotion
                    CASE 
                        WHEN p.estEnPromotion = 1 AND p.prix > 0 AND p.prixPromotion > 0 
                        THEN ROUND(((p.prix - p.prixPromotion) / p.prix) * 100)
                        ELSE 0
                    END AS pourcentageReduction
                FROM Produit p
                LEFT JOIN Utilisateur u ON p.vendeurId = u.id
                LEFT JOIN ProduitReaction r ON p.id = r.produitId
                ORDER BY p.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['userId' => $userId]);
            $formations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formater les dates pour l'affichage
            foreach ($formations as &$formation) {
                if ($formation['dateDebutPromo']) {
                    $formation['dateDebutPromoDisplay'] = formatDateForDisplay($formation['dateDebutPromo']);
                }
                if ($formation['dateFinPromo']) {
                    $formation['dateFinPromoDisplay'] = formatDateForDisplay($formation['dateFinPromo']);
                }
                if ($formation['expiration']) {
                    $formation['expirationDisplay'] = formatDateForDisplay($formation['expiration']);
                }
            }
            unset($formation);

            echo json_encode($formations);
            exit;
        }
    } catch (PDOException $e) {
        error_log("Erreur GET: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur récupération', 'details' => $e->getMessage()]);
    }
    exit;
}

// ------------------------
// ✏️ POST - Créer ou Modifier une formation
// ------------------------
if ($method === 'POST') {
    try {
        // CRÉATION (POST normal)
        if (!isset($_GET['id'])) {
            $titre = $_POST['titre'] ?? '';
            $description = $_POST['description'] ?? '';
            $prix = $_POST['prix'] ?? '';
            $categorie = strtolower($_POST['categorie'] ?? 'cuisine');
            $vendeurId = isset($_POST['vendeurId']) ? intval($_POST['vendeurId']) : null;

            // Gestion de la promotion
            $estEnPromotion = isset($_POST['estEnPromotion']) ? (bool)$_POST['estEnPromotion'] : false;
            $nomPromotion = $_POST['nomPromotion'] ?? null;
            $prixPromotion = $_POST['prixPromotion'] ?? null;
            $dateDebutPromo = $_POST['dateDebutPromo'] ?? null;
            $dateFinPromo = $_POST['dateFinPromo'] ?? null;
            $expiration = $_POST['expiration'] ?? null;

            if (!$titre || !$prix || !is_numeric($prix) || !$vendeurId) {
                http_response_code(400);
                echo json_encode(['error' => 'Titre, prix valides et vendeurId requis']);
                exit;
            }

            // Vérifier que le vendeur existe
            $stmt = $pdo->prepare("SELECT id FROM Utilisateur WHERE id = ?");
            $stmt->execute([$vendeurId]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Vendeur introuvable']);
                exit;
            }

            // Validation des données de promotion
            if ($estEnPromotion) {
                if (empty($nomPromotion)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Le nom de la promotion est obligatoire']);
                    exit;
                }

                if (!$prixPromotion || !is_numeric($prixPromotion) || floatval($prixPromotion) <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Le prix promotionnel doit être un nombre valide supérieur à 0']);
                    exit;
                }

                if (floatval($prixPromotion) >= floatval($prix)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Le prix promotionnel doit être inférieur au prix normal']);
                    exit;
                }

                if ($dateDebutPromo && $dateFinPromo) {
                    try {
                        $debut = new DateTime($dateDebutPromo);
                        $fin = new DateTime($dateFinPromo);
                        if ($debut >= $fin) {
                            http_response_code(400);
                            echo json_encode(['error' => 'La date de fin doit être après la date de début']);
                            exit;
                        }
                    } catch (Exception $e) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Format de date invalide']);
                        exit;
                    }
                }

                if (!$expiration) {
                    http_response_code(400);
                    echo json_encode(['error' => 'La date et heure de fin de promotion sont obligatoires']);
                    exit;
                }

                try {
                    $expirationDate = new DateTime($expiration);
                    if ($expirationDate <= new DateTime()) {
                        http_response_code(400);
                        echo json_encode(['error' => 'La date de fin de promotion doit être dans le futur']);
                        exit;
                    }
                } catch (Exception $e) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Format de date expiration invalide']);
                    exit;
                }
            } else {
                // Si pas de promotion, on vide les champs
                $nomPromotion = null;
                $prixPromotion = null;
                $dateDebutPromo = null;
                $dateFinPromo = null;
                $expiration = null;
            }

            // Gérer l'upload d'image
            $imageUrl = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $tmp = $_FILES['image']['tmp_name'];
                $name = basename($_FILES['image']['name']);
                $name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $name);
                $uniqueName = uniqid() . '_' . $name;
                $target = UPLOAD_DIR . $uniqueName;
                if (move_uploaded_file($tmp, $target)) {
                    $imageUrl = 'api/uploads/' . $uniqueName;
                }
            }

            $stmt = $pdo->prepare("INSERT INTO Produit 
                (titre, description, prix, imageUrl, categorie, date_ajout, 
                 estEnPromotion, nomPromotion, prixPromotion, dateDebutPromo, dateFinPromo, expiration, vendeurId) 
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $titre, $description, $prix, $imageUrl, $categorie,
                $estEnPromotion, $nomPromotion, $prixPromotion, $dateDebutPromo, $dateFinPromo, $expiration, $vendeurId
            ]);

            $newId = $pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'message' => 'Formation créée avec succès',
                'id' => $newId
            ]);
            exit;
        }

        // MODIFICATION (POST avec id)
        $id = intval($_GET['id']);
        $input = getJsonInput();

        $titre = trim($input['titre'] ?? '');
        $description = trim($input['description'] ?? '');
        $prix = $input['prix'] ?? '';
        $categorie = strtolower(trim($input['categorie'] ?? ''));
        $vendeurId = isset($input['vendeurId']) ? intval($input['vendeurId']) : null;

        // Gestion de la promotion
        $estEnPromotion = isset($input['estEnPromotion']) ? (bool)$input['estEnPromotion'] : false;
        $nomPromotion = $input['nomPromotion'] ?? null;
        $prixPromotion = $input['prixPromotion'] ?? null;
        $dateDebutPromo = $input['dateDebutPromo'] ?? null;
        $dateFinPromo = $input['dateFinPromo'] ?? null;
        $expiration = $input['expiration'] ?? null;

        $categoriesValides = ['cuisine', 'informatique', 'savons', 'design', 'marketing', 'autre'];
        if (!empty($categorie) && !in_array($categorie, $categoriesValides)) {
            http_response_code(400);
            echo json_encode(['error' => 'Catégorie invalide']);
            exit;
        }

        if (!$titre || !$prix || !is_numeric($prix)) {
            http_response_code(400);
            echo json_encode(['error' => 'Titre et prix valides requis']);
            exit;
        }

        // Validation des données de promotion
        if ($estEnPromotion) {
            if (empty($nomPromotion)) {
                http_response_code(400);
                echo json_encode(['error' => 'Le nom de la promotion est obligatoire']);
                exit;
            }

            if (!$prixPromotion || !is_numeric($prixPromotion) || floatval($prixPromotion) <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Le prix promotionnel doit être un nombre valide supérieur à 0']);
                exit;
            }

            if (floatval($prixPromotion) >= floatval($prix)) {
                http_response_code(400);
                echo json_encode(['error' => 'Le prix promotionnel doit être inférieur au prix normal']);
                exit;
            }

            if ($dateDebutPromo && $dateFinPromo) {
                try {
                    $debut = new DateTime($dateDebutPromo);
                    $fin = new DateTime($dateFinPromo);
                    if ($debut >= $fin) {
                        http_response_code(400);
                        echo json_encode(['error' => 'La date de fin doit être après la date de début']);
                        exit;
                    }
                } catch (Exception $e) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Format de date invalide']);
                    exit;
                }
            }

            if (!$expiration) {
                http_response_code(400);
                echo json_encode(['error' => 'La date et heure de fin de promotion sont obligatoires']);
                exit;
            }

            try {
                $expirationDate = new DateTime($expiration);
                if ($expirationDate <= new DateTime()) {
                    http_response_code(400);
                    echo json_encode(['error' => 'La date de fin de promotion doit être dans le futur']);
                    exit;
                }
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => 'Format de date expiration invalide']);
                exit;
            }
        } else {
            // Si pas de promotion, on vide les champs
            $nomPromotion = null;
            $prixPromotion = null;
            $dateDebutPromo = null;
            $dateFinPromo = null;
            $expiration = null;
        }

        // Vérifier que l'utilisateur est propriétaire de la formation
        if ($vendeurId) {
            $stmt = $pdo->prepare("SELECT vendeurId FROM Produit WHERE id = ?");
            $stmt->execute([$id]);
            $currentVendeurId = $stmt->fetchColumn();

            if ($currentVendeurId !== $vendeurId) {
                http_response_code(403);
                echo json_encode(['error' => 'Vous n\'êtes pas autorisé à modifier cette formation']);
                exit;
            }
        }

        // Construire la requête UPDATE
        $updateFields = [
            'titre = ?', 'description = ?', 'prix = ?', 'categorie = ?',
            'estEnPromotion = ?', 'nomPromotion = ?', 'prixPromotion = ?', 
            'dateDebutPromo = ?', 'dateFinPromo = ?', 'expiration = ?'
        ];
        
        $params = [
            $titre, $description, $prix, $categorie,
            $estEnPromotion, $nomPromotion, $prixPromotion, 
            $dateDebutPromo, $dateFinPromo, $expiration
        ];

        $params[] = $id;

        $sql = "UPDATE Produit SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'message' => 'Formation modifiée avec succès'
        ]);

    } catch (PDOException $e) {
        error_log("Erreur POST: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de l\'opération', 'details' => $e->getMessage()]);
    }
    exit;
}

// ------------------------
// ✏️ PUT - Modifier une formation (méthode alternative)
// ------------------------
if ($method === 'PUT') {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'ID requis']);
        exit;
    }

    $id = intval($_GET['id']);
    $input = getJsonInput();

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Données invalides']);
        exit;
    }

    try {
        $titre = trim($input['titre'] ?? '');
        $description = trim($input['description'] ?? '');
        $prix = $input['prix'] ?? '';
        $vendeurId = isset($input['vendeurId']) ? intval($input['vendeurId']) : null;

        if (!$titre || !$prix || !is_numeric($prix)) {
            http_response_code(400);
            echo json_encode(['error' => 'Titre et prix valides requis']);
            exit;
        }

        // Vérifier que l'utilisateur est propriétaire de la formation
        if ($vendeurId) {
            $stmt = $pdo->prepare("SELECT vendeurId FROM Produit WHERE id = ?");
            $stmt->execute([$id]);
            $currentVendeurId = $stmt->fetchColumn();

            if ($currentVendeurId !== $vendeurId) {
                http_response_code(403);
                echo json_encode(['error' => 'Vous n\'êtes pas autorisé à modifier cette formation']);
                exit;
            }
        }

        $stmt = $pdo->prepare("
            UPDATE Produit 
            SET titre = ?, description = ?, prix = ? 
            WHERE id = ?
        ");
        $stmt->execute([$titre, $description, $prix, $id]);

        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Formation modifiée avec succès'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => 'Aucune modification effectuée'
            ]);
        }
    } catch (PDOException $e) {
        error_log("Erreur PUT: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur modification', 'details' => $e->getMessage()]);
    }
    exit;
}

// ------------------------
// 🗑️ DELETE - Supprimer une formation
// ------------------------
if ($method === 'DELETE') {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'ID requis']);
        exit;
    }

    $id = intval($_GET['id']);
    $vendeurId = isset($_GET['vendeurId']) ? intval($_GET['vendeurId']) : null;

    try {
        // Vérifier que l'utilisateur est propriétaire de la formation
        if ($vendeurId) {
            $stmt = $pdo->prepare("SELECT vendeurId FROM Produit WHERE id = ?");
            $stmt->execute([$id]);
            $currentVendeurId = $stmt->fetchColumn();

            if ($currentVendeurId !== $vendeurId) {
                http_response_code(403);
                echo json_encode(['error' => 'Vous n\'êtes pas autorisé à supprimer cette formation']);
                exit;
            }
        }

        // Récupérer l'image pour la supprimer
        $stmt = $pdo->prepare("SELECT imageUrl FROM Produit WHERE id = ?");
        $stmt->execute([$id]);
        $imageUrl = $stmt->fetchColumn();

        if ($imageUrl === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Formation introuvable']);
            exit;
        }

        // Supprimer l'image si elle existe
        if ($imageUrl && file_exists(__DIR__ . '/' . $imageUrl)) {
            unlink(__DIR__ . '/' . $imageUrl);
        }

        // Vérifier si les colonnes preview existent dans la table video
        $checkColumns = $pdo->query("DESCRIBE video");
        $columns = $checkColumns->fetchAll(PDO::FETCH_COLUMN);
        $hasPreviewUrl = in_array('preview_url', $columns);

        // Supprimer les vidéos associées
        $selectFields = "url";
        if ($hasPreviewUrl) {
            $selectFields .= ", preview_url";
        }

        $stmt = $pdo->prepare("SELECT $selectFields FROM video WHERE produitId = ?");
        $stmt->execute([$id]);
        $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($videos as $video) {
            // Supprimer fichier vidéo principal
            if (!empty($video['url']) && !preg_match('/^https?:\/\//', $video['url'])) {
                $localFile = __DIR__ . '/' . ltrim($video['url'], '/');
                if (file_exists($localFile)) {
                    unlink($localFile);
                }
            }

            // Supprimer preview si la colonne existe
            if ($hasPreviewUrl && !empty($video['preview_url']) && !preg_match('/^https?:\/\//', $video['preview_url'])) {
                $filename = basename($video['preview_url']);
                $localPreview = __DIR__ . '/video/preview/' . $filename;
                if (file_exists($localPreview)) {
                    unlink($localPreview);
                }
            }
        }

        // Supprimer les entrées vidéo de la BD
        $stmt = $pdo->prepare("DELETE FROM video WHERE produitId = ?");
        $stmt->execute([$id]);

        // Supprimer la formation
        $stmt = $pdo->prepare("DELETE FROM Produit WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Formation supprimée avec succès (y compris ses vidéos)'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la suppression']);
        }
    } catch (PDOException $e) {
        error_log("Erreur DELETE: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur suppression', 'details' => $e->getMessage()]);
    }
    exit;
}

// Méthode non autorisée
http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée']);
exit;