<?php
/**
 * formations.php - Gestion des formations (CRUD complet)
 * Version avec intégration Cloudflare R2 pour les images
 */

require_once 'config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/cloudflare-config.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Initialisation du client S3 pour R2
$s3Client = new S3Client([
    'region' => CLOUDFLARE_REGION,
    'version' => 'latest',
    'endpoint' => CLOUDFLARE_ENDPOINT,
    'credentials' => [
        'key' => CLOUDFLARE_ACCESS_KEY,
        'secret' => CLOUDFLARE_SECRET_KEY,
    ],
    'use_path_style_endpoint' => true,
    'signature_version' => 'v4',
]);

$publicBase = rtrim(CLOUDFLARE_PUBLIC_URL, '/');

$method = $_SERVER['REQUEST_METHOD'];

function getJsonInput() {
    $data = json_decode(file_get_contents("php://input"), true);
    return is_array($data) ? $data : [];
}

function formatDateForDisplay($dateString) {
    if (empty($dateString)) return null;
    try {
        $date = new DateTime($dateString);
        return $date->format('d/m/Y');
    } catch (Exception $e) {
        return null;
    }
}

// ==================== GET ====================
if ($method === 'GET') {
    try {
        $userId = isset($_GET['userId']) ? intval($_GET['userId']) : 0;
        $vendeurId = isset($_GET['vendeurId']) ? intval($_GET['vendeurId']) : null;
        $mode = isset($_GET['mode']) ? $_GET['mode'] : 'all';
        
        // Vérifier si les tables existent
        $tablesExist = [];
        $checkTables = ['follow', 'venteproduit', 'vente', 'produitreaction'];
        
        foreach ($checkTables as $table) {
            $stmt = $pdo->query("SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = '$table'
            )");
            $tablesExist[$table] = $stmt->fetchColumn();
        }
        
        // MODE "FOLLOWING"
        if ($mode === 'following') {
            if ($userId <= 0) {
                echo json_encode([]);
                exit;
            }
            
            if (!$tablesExist['follow']) {
                error_log("⚠️ Table 'follow' n'existe pas");
                echo json_encode([]);
                exit;
            }
            
            $sql = "
                SELECT p.*, 
                    u.nom as vendeur_nom, 
                    u.email as vendeur_email, 
                    u.photoprofil as vendeur_photo,
                    u.id as vendeurid,
                    (
                        SELECT COUNT(*) 
                        FROM follow f 
                        WHERE f.followingid = u.id
                    ) as nombrefollowers,
                    (
                        SELECT COUNT(*) 
                        FROM follow f 
                        WHERE f.followerid = u.id
                    ) as nombrefollowing,
                    1 as isfollowing,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM venteproduit vp 
                            JOIN vente v ON v.id = vp.venteid 
                            WHERE vp.produitid = p.id AND v.utilisateurid = :userId AND vp.achetee = TRUE
                        ) THEN 1 ELSE 0
                    END AS achetee,
                    COALESCE(r.likes, 0) AS likes,
                    COALESCE(r.pouces, 0) AS pouces,
                    CASE 
                        WHEN p.estenpromotion = TRUE AND p.prix > 0 AND p.prixpromotion > 0 
                        THEN ROUND(((p.prix - p.prixpromotion) / p.prix) * 100)
                        ELSE 0
                    END AS pourcentagereduction
                FROM produit p
                LEFT JOIN utilisateur u ON p.vendeurid = u.id
                LEFT JOIN produitreaction r ON p.id = r.produitid
                INNER JOIN follow f ON p.vendeurid = f.followingid
                WHERE f.followerid = :followerId
                ORDER BY p.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['userId' => $userId, 'followerId' => $userId]);
            $formations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($formations as &$formation) {
                if ($formation['datedebutpromo']) {
                    $formation['dateDebutPromoDisplay'] = formatDateForDisplay($formation['datedebutpromo']);
                }
                if ($formation['datefinpromo']) {
                    $formation['dateFinPromoDisplay'] = formatDateForDisplay($formation['datefinpromo']);
                }
                if ($formation['expiration']) {
                    $formation['expirationDisplay'] = formatDateForDisplay($formation['expiration']);
                }
            }
            unset($formation);

            echo json_encode($formations);
            exit;
        }
        
        // MODE "POPULAR"
        if ($mode === 'popular') {
            $sql = "
                SELECT p.*, 
                    u.nom as vendeur_nom, 
                    u.email as vendeur_email, 
                    u.photoprofil as vendeur_photo,
                    u.id as vendeurid,
                    ";
            
            if ($tablesExist['follow']) {
                $sql .= "
                    (
                        SELECT COUNT(*) 
                        FROM follow f 
                        WHERE f.followingid = u.id
                    ) as nombrefollowers,
                    (
                        SELECT COUNT(*) 
                        FROM follow f 
                        WHERE f.followerid = u.id
                    ) as nombrefollowing,
                    CASE 
                        WHEN :userId > 0 AND EXISTS (
                            SELECT 1 FROM follow 
                            WHERE followerid = :userId AND followingid = p.vendeurid
                        ) THEN 1 ELSE 0
                    END AS isfollowing,
                ";
            } else {
                $sql .= "
                    0 as nombrefollowers,
                    0 as nombrefollowing,
                    0 as isfollowing,
                ";
            }
            
            $sql .= "
                    0 AS achetee,
                    COALESCE(r.likes, 0) AS likes,
                    COALESCE(r.pouces, 0) AS pouces,
                    CASE 
                        WHEN p.estenpromotion = TRUE AND p.prix > 0 AND p.prixpromotion > 0 
                        THEN ROUND(((p.prix - p.prixpromotion) / p.prix) * 100)
                        ELSE 0
                    END AS pourcentagereduction
                FROM produit p
                LEFT JOIN utilisateur u ON p.vendeurid = u.id
                LEFT JOIN produitreaction r ON p.id = r.produitid
                ORDER BY (COALESCE(r.likes, 0) + COALESCE(r.pouces, 0)) DESC, p.id DESC
                LIMIT 50
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['userId' => $userId]);
            $formations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($formations as &$formation) {
                if (isset($formation['datedebutpromo'])) {
                    $formation['dateDebutPromoDisplay'] = formatDateForDisplay($formation['datedebutpromo']);
                }
                if (isset($formation['datefinpromo'])) {
                    $formation['dateFinPromoDisplay'] = formatDateForDisplay($formation['datefinpromo']);
                }
                if (isset($formation['expiration'])) {
                    $formation['expirationDisplay'] = formatDateForDisplay($formation['expiration']);
                }
            }
            unset($formation);

            echo json_encode($formations);
            exit;
        }

        // MODE "ALL" - Par défaut
        $sql = "
            SELECT p.*, 
                u.nom as vendeur_nom, 
                u.email as vendeur_email, 
                u.photoprofil as vendeur_photo,
                u.id as vendeurid,
                0 as nombrefollowers,
                0 as nombrefollowing,
                0 as isfollowing,
                0 AS achetee,
                COALESCE(r.likes, 0) AS likes,
                COALESCE(r.pouces, 0) AS pouces,
                CASE 
                    WHEN p.estenpromotion = TRUE AND p.prix > 0 AND p.prixpromotion > 0 
                    THEN ROUND(((p.prix - p.prixpromotion) / p.prix) * 100)
                    ELSE 0
                END AS pourcentagereduction
            FROM produit p
            LEFT JOIN utilisateur u ON p.vendeurid = u.id
            LEFT JOIN produitreaction r ON p.id = r.produitid
        ";
        
        if ($vendeurId !== null) {
            $sql .= " WHERE p.vendeurid = :vendeurId";
        }
        
        if (isset($_GET['id'])) {
            $sql .= " WHERE p.id = :id";
        }
        
        $sql .= " ORDER BY p.id DESC";

        $stmt = $pdo->prepare($sql);
        
        if ($vendeurId !== null) {
            $stmt->execute(['vendeurId' => $vendeurId]);
        } else if (isset($_GET['id'])) {
            $stmt->execute(['id' => intval($_GET['id'])]);
        } else {
            $stmt->execute();
        }
        
        if (isset($_GET['id'])) {
            $formation = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$formation) {
                http_response_code(404);
                echo json_encode(['error' => 'Formation introuvable']);
                exit;
            }
            // Pour compatibilité, on peut générer l'URL complète si on a la clé
            if (!empty($formation['image_key']) && empty($formation['imageUrl'])) {
                $formation['imageUrl'] = generateCloudflareUrl($formation['image_key']);
            }
            echo json_encode($formation);
        } else {
            $formations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Générer les URLs complètes si nécessaire
            foreach ($formations as &$f) {
                if (!empty($f['image_key']) && empty($f['imageUrl'])) {
                    $f['imageUrl'] = generateCloudflareUrl($f['image_key']);
                }
            }
            echo json_encode($formations);
        }

    } catch (PDOException $e) {
        error_log("❌ ERREUR GET FORMATIONS: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur récupération', 'details' => $e->getMessage()]);
    }
    exit;
}


if ($method === 'POST') {
    try {
        // 🆕 CRÉATION (POST normal)
        if (!isset($_GET['id'])) {
            $titre = $_POST['titre'] ?? '';
            $description = $_POST['description'] ?? '';
            $prix = $_POST['prix'] ?? '';
            $categorie = strtolower($_POST['categorie'] ?? 'cuisine');
            $vendeurId = isset($_POST['vendeurId']) ? intval($_POST['vendeurId']) : null;

            // 🏷️ Gestion de la promotion
            $estEnPromotion = isset($_POST['estEnPromotion']) ? ($_POST['estEnPromotion'] === '1' || $_POST['estEnPromotion'] === 'true') : false;
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

            // Validation des données de promotion (inchangé)
            // ... (code existant)

            // 🖼️ Gérer l'upload d'image vers R2
            $imageKey = null;
            $imageUrl = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $tmpFile = $_FILES['image']['tmp_name'];
                $originalName = $_FILES['image']['name'];
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $extension = $extension ?: 'jpg';
                
                // Générer une clé unique pour R2
                $imageKey = 'formations/' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                
                try {
                    // Upload vers R2
                    $s3Client->putObject([
                        'Bucket' => CLOUDFLARE_BUCKET,
                        'Key'    => $imageKey,
                        'SourceFile' => $tmpFile,
                        'ContentType' => mime_content_type($tmpFile) ?: 'image/jpeg',
                    ]);
                    
                    $imageUrl = generateCloudflareUrl($imageKey);
                    error_log("✅ Image uploadée vers R2: $imageKey");
                } catch (AwsException $e) {
                    error_log("❌ Erreur upload R2: " . $e->getMessage());
                    http_response_code(500);
                    echo json_encode(['error' => "Échec de l'upload de l'image vers le cloud"]);
                    exit;
                }
            }

            // Insérer en base (avec image_key)
            $stmt = $pdo->prepare("INSERT INTO Produit 
                (titre, description, prix, imageUrl, image_key, categorie, date_ajout, 
                 estEnPromotion, nomPromotion, prixPromotion, dateDebutPromo, dateFinPromo, expiration, vendeurId) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $titre, $description, $prix, $imageUrl, $imageKey, $categorie,
                $estEnPromotion, $nomPromotion, $prixPromotion, $dateDebutPromo, $dateFinPromo, $expiration, $vendeurId
            ]);

            $newId = $pdo->lastInsertId();
            
            error_log("✅ Formation créée - ID: $newId, Vendeur: $vendeurId");
            
            echo json_encode([
                'success' => true,
                'message' => 'Formation créée avec succès',
                'id' => $newId
            ]);
            exit;
        }

        // 📝 MODIFICATION (POST avec id)
        $id = intval($_GET['id']);
        $input = getJsonInput();

        $titre = trim($input['titre'] ?? '');
        $description = trim($input['description'] ?? '');
        $prix = $input['prix'] ?? '';
        $categorie = strtolower(trim($input['categorie'] ?? ''));
        $vendeurId = isset($input['vendeurId']) ? intval($input['vendeurId']) : null;

        // 🏷️ Gestion de la promotion (inchangé)
        // ...

        // Récupérer l'ancienne image pour éventuelle suppression
        $stmt = $pdo->prepare("SELECT image_key FROM Produit WHERE id = ?");
        $stmt->execute([$id]);
        $oldImageKey = $stmt->fetchColumn();

        // Gérer l'upload d'image si fournie (via le même champ image dans $_FILES)
        // Note: pour une modification avec upload, le client doit envoyer en multipart/form-data
        $imageKey = $oldImageKey; // conserver l'ancienne par défaut
        $imageUrl = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $tmpFile = $_FILES['image']['tmp_name'];
            $originalName = $_FILES['image']['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) ?: 'jpg';
            $newImageKey = 'formations/' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            
            try {
                // Upload nouvelle image
                $s3Client->putObject([
                    'Bucket' => CLOUDFLARE_BUCKET,
                    'Key'    => $newImageKey,
                    'SourceFile' => $tmpFile,
                    'ContentType' => mime_content_type($tmpFile) ?: 'image/jpeg',
                ]);
                
                // Si ancienne image existe, la supprimer
                if ($oldImageKey) {
                    try {
                        $s3Client->deleteObject([
                            'Bucket' => CLOUDFLARE_BUCKET,
                            'Key'    => $oldImageKey
                        ]);
                        error_log("✅ Ancienne image supprimée de R2: $oldImageKey");
                    } catch (AwsException $e) {
                        error_log("⚠️ Erreur suppression ancienne image R2: " . $e->getMessage());
                    }
                }
                
                $imageKey = $newImageKey;
                $imageUrl = generateCloudflareUrl($newImageKey);
            } catch (AwsException $e) {
                error_log("❌ Erreur upload R2 lors modification: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => "Échec de l'upload de la nouvelle image"]);
                exit;
            }
        }

        // Construire la requête UPDATE
        $updateFields = [
            'titre = ?', 'description = ?', 'prix = ?', 'categorie = ?',
            'estEnPromotion = ?', 'nomPromotion = ?', 'prixPromotion = ?', 
            'dateDebutPromo = ?', 'dateFinPromo = ?', 'expiration = ?',
            'imageUrl = ?', 'image_key = ?'
        ];
        
        $params = [
            $titre, $description, $prix, $categorie,
            $estEnPromotion, $nomPromotion, $prixPromotion, 
            $dateDebutPromo, $dateFinPromo, $expiration,
            $imageUrl, $imageKey
        ];

        $params[] = $id;

        $sql = "UPDATE Produit SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        error_log("✅ Formation modifiée - ID: $id");

        echo json_encode([
            'success' => true,
            'message' => 'Formation modifiée avec succès'
        ]);

    } catch (PDOException $e) {
        error_log("❌ ERREUR POST FORMATION: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de l\'opération', 'details' => $e->getMessage()]);
    }
    exit;
}

// ✏️ PUT - Modifier une formation (méthode alternative)
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

            if ($currentVendeurId != $vendeurId) {
                http_response_code(403);
                echo json_encode(['error' => 'Vous n\'êtes pas autorisé à modifier cette formation']);
                exit;
            }
        }

        // Note: PUT ne gère pas l'upload d'image (utiliser POST avec id)
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
        error_log("❌ ERREUR PUT FORMATION: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur modification', 'details' => $e->getMessage()]);
    }
    exit;
}

// 🗑️ DELETE - Supprimer une formation
if ($method === 'DELETE') {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'ID requis']);
        exit;
    }

    $id = intval($_GET['id']);
    $vendeurId = isset($_GET['vendeurId']) ? intval($_GET['vendeurId']) : null;

    try {
        // 🔐 Vérifier que l'utilisateur est propriétaire de la formation
        if ($vendeurId) {
            $stmt = $pdo->prepare("SELECT vendeurId FROM Produit WHERE id = ?");
            $stmt->execute([$id]);
            $currentVendeurId = $stmt->fetchColumn();

            if ($currentVendeurId != $vendeurId) {
                http_response_code(403);
                echo json_encode(['error' => 'Vous n\'êtes pas autorisé à supprimer cette formation']);
                exit;
            }
        }

        // Récupérer l'image_key de la formation
        $stmt = $pdo->prepare("SELECT image_key FROM Produit WHERE id = ?");
        $stmt->execute([$id]);
        $imageKey = $stmt->fetchColumn();

        if ($imageKey === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Formation introuvable']);
            exit;
        }

        // Supprimer l'image de R2 si elle existe
        if ($imageKey) {
            try {
                $s3Client->deleteObject([
                    'Bucket' => CLOUDFLARE_BUCKET,
                    'Key'    => $imageKey
                ]);
                error_log("✅ Image de formation supprimée de R2: $imageKey");
            } catch (AwsException $e) {
                error_log("⚠️ Erreur suppression image R2: " . $e->getMessage());
                // Ne pas bloquer la suppression
            }
        }

        // Supprimer les vidéos associées (avec leurs objets R2)
        // On a déjà ajouté les colonnes object_key et preview_object_key dans video
        $stmt = $pdo->prepare("SELECT object_key, preview_object_key FROM video WHERE produitId = ?");
        $stmt->execute([$id]);
        $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($videos as $video) {
            // Supprimer la vidéo principale
            if (!empty($video['object_key'])) {
                try {
                    $s3Client->deleteObject([
                        'Bucket' => CLOUDFLARE_BUCKET,
                        'Key'    => $video['object_key']
                    ]);
                    error_log("✅ Vidéo supprimée de R2: " . $video['object_key']);
                } catch (AwsException $e) {
                    error_log("⚠️ Erreur suppression vidéo R2: " . $e->getMessage());
                }
            }
            // Supprimer la preview
            if (!empty($video['preview_object_key'])) {
                try {
                    $s3Client->deleteObject([
                        'Bucket' => CLOUDFLARE_BUCKET,
                        'Key'    => $video['preview_object_key']
                    ]);
                    error_log("✅ Preview vidéo supprimée de R2: " . $video['preview_object_key']);
                } catch (AwsException $e) {
                    error_log("⚠️ Erreur suppression preview R2: " . $e->getMessage());
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
            error_log("✅ Formation supprimée - ID: $id");
            
            echo json_encode([
                'success' => true,
                'message' => 'Formation supprimée avec succès (y compris ses images et vidéos du cloud)'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la suppression']);
        }
    } catch (PDOException $e) {
        error_log("❌ ERREUR DELETE FORMATION: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur suppression', 'details' => $e->getMessage()]);
    }
    exit;
}

// ❌ Méthode non autorisée
http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée']);
exit;
?>