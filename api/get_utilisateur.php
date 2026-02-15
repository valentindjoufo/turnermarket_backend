<?php
/**
 * gestion_utilisateurs.php - CRUD complet pour les utilisateurs
 * Version avec intégration Cloudflare R2 pour les photos de profil
 */

require_once 'config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/cloudflare-config.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ Initialisation du client R2 (utile pour les suppressions)
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

// Fonction pour envoyer une réponse JSON standardisée
function sendJsonResponse($data, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// Fonction pour valider et décoder les données JSON
function getJsonInput()
{
    $input = file_get_contents("php://input");
    if (empty($input)) {
        return [];
    }
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendJsonResponse(['success' => false, 'error' => 'Format JSON invalide: ' . json_last_error_msg()], 400);
    }
    return $data ?: [];
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    // ==================== GET : RÉCUPÉRATION ====================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            if ($id <= 0) {
                sendJsonResponse(['success' => false, 'error' => 'ID utilisateur invalide'], 400);
            }
            $currentUserId = isset($_GET['currentUserId']) ? intval($_GET['currentUserId']) : 0;

            // ✅ On inclut désormais photo_key dans la sélection
            $stmt = $pdo->prepare("
                SELECT 
                    u.id, 
                    u.matricule, 
                    u.nom, 
                    u.sexe, 
                    u.nationalite, 
                    u.telephone, 
                    u.email, 
                    u.role, 
                    u.etat, 
                    u.photoProfil, 
                    u.photo_key,
                    u.dateCreation,
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
                    u.noteVendeur,
                    u.soldeVendeur,
                    u.nbVentes,
                    u.statutVendeur,
                    u.identiteVerifiee,
                    u.emailVerifie,
                    u.telephoneVerifie,
                    (
                        SELECT COUNT(*) 
                        FROM Produit 
                        WHERE vendeurId = u.id
                    ) as nombreFormations,
                    (
                        SELECT COUNT(*) 
                        FROM Vente 
                        WHERE utilisateurId = u.id
                    ) as nombreAchats,
                    (
                        SELECT COUNT(*) 
                        FROM Commentaire 
                        WHERE utilisateurId = u.id
                    ) as nombreCommentaires,
                    CASE 
                        WHEN ? > 0 AND EXISTS (
                            SELECT 1 
                            FROM Follow 
                            WHERE followerId = ? 
                            AND followingId = u.id
                        ) THEN 1
                        ELSE 0
                    END as isFollowing
                FROM Utilisateur u
                WHERE u.id = ?
            ");
            $stmt->execute([$currentUserId, $currentUserId, $id]);
            $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$utilisateur) {
                sendJsonResponse(['success' => false, 'error' => 'Utilisateur non trouvé'], 404);
            }

            // Nettoyer les valeurs nulles
            $utilisateur = array_map(function ($value) {
                return $value === null ? '' : $value;
            }, $utilisateur);

            // ✅ Construction de l'URL publique à partir de la clé R2
            if (!empty($utilisateur['photo_key'])) {
                $utilisateur['photoProfilUrl'] = generateCloudflareUrl($utilisateur['photo_key']);
            } else {
                // Fallback : si photoProfil contient déjà une URL (ancien système)
                if (!empty($utilisateur['photoProfil']) && preg_match('/^https?:\/\//', $utilisateur['photoProfil'])) {
                    $utilisateur['photoProfilUrl'] = $utilisateur['photoProfil'];
                } else {
                    $utilisateur['photoProfilUrl'] = null;
                }
            }

            // ✅ Formater les nombres avec vérification d'existence
            $utilisateur['nombreFollowers'] = intval($utilisateur['nombreFollowers'] ?? 0);
            $utilisateur['nombreFollowing'] = intval($utilisateur['nombreFollowing'] ?? 0);
            $utilisateur['nombreFormations'] = intval($utilisateur['nombreFormations'] ?? 0);
            $utilisateur['nombreAchats'] = intval($utilisateur['nombreAchats'] ?? 0);
            $utilisateur['nombreCommentaires'] = intval($utilisateur['nombreCommentaires'] ?? 0);
            $utilisateur['isFollowing'] = boolval($utilisateur['isFollowing'] ?? false);
            $utilisateur['noteVendeur'] = floatval($utilisateur['noteVendeur'] ?? 0);
            $utilisateur['soldeVendeur'] = floatval($utilisateur['soldeVendeur'] ?? 0);
            $utilisateur['nbVentes'] = intval($utilisateur['nbVentes'] ?? 0);

            error_log("✅ Utilisateur récupéré - ID: $id, Nom: " . $utilisateur['nom']);

            sendJsonResponse([
                'success' => true,
                'utilisateur' => $utilisateur,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } else {
            // Liste des utilisateurs
            $stmt = $pdo->query("
                SELECT 
                    u.id, 
                    u.matricule, 
                    u.nom, 
                    u.sexe, 
                    u.nationalite, 
                    u.telephone, 
                    u.email, 
                    u.role, 
                    u.etat, 
                    u.photoProfil, 
                    u.photo_key,
                    u.dateCreation,
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
                    u.noteVendeur,
                    u.nbVentes,
                    u.statutVendeur,
                    (
                        SELECT COUNT(*) 
                        FROM Produit 
                        WHERE vendeurId = u.id
                    ) as nombreFormations
                FROM Utilisateur u
                ORDER BY u.dateCreation DESC
            ");
            $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            error_log("✅ Liste utilisateurs récupérée - Total: " . count($utilisateurs));

            $utilisateurs = array_map(function ($utilisateur) {
                $utilisateur = array_map(function ($value) {
                    return $value === null ? '' : $value;
                }, $utilisateur);

                // ✅ Construction de l'URL publique
                if (!empty($utilisateur['photo_key'])) {
                    $utilisateur['photoProfilUrl'] = generateCloudflareUrl($utilisateur['photo_key']);
                } else {
                    if (!empty($utilisateur['photoProfil']) && preg_match('/^https?:\/\//', $utilisateur['photoProfil'])) {
                        $utilisateur['photoProfilUrl'] = $utilisateur['photoProfil'];
                    } else {
                        $utilisateur['photoProfilUrl'] = null;
                    }
                }

                // ✅ CORRECTION : Ajout de ?? 0 pour éviter les avertissements
                $utilisateur['nombreFollowers'] = intval($utilisateur['nombreFollowers'] ?? 0);
                $utilisateur['nombreFollowing'] = intval($utilisateur['nombreFollowing'] ?? 0);
                $utilisateur['nombreFormations'] = intval($utilisateur['nombreFormations'] ?? 0);
                $utilisateur['noteVendeur'] = floatval($utilisateur['noteVendeur'] ?? 0);
                $utilisateur['nbVentes'] = intval($utilisateur['nbVentes'] ?? 0);

                return $utilisateur;
            }, $utilisateurs);

            sendJsonResponse([
                'success' => true,
                'utilisateurs' => $utilisateurs,
                'count' => count($utilisateurs),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }

    // ==================== POST : CRÉATION / ACTIONS ====================
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = getJsonInput();

        // 🆕 CRÉATION d'un nouvel utilisateur
        if (isset($data['action']) && $data['action'] === 'creer') {
            error_log("🆕 Création d'un nouvel utilisateur");

            $champsRequis = ['matricule', 'nom', 'email', 'telephone', 'role'];
            foreach ($champsRequis as $champ) {
                if (!isset($data[$champ]) || empty(trim($data[$champ]))) {
                    sendJsonResponse(['success' => false, 'error' => "Le champ '$champ' est requis"], 400);
                }
            }

            $matricule = trim($data['matricule']);
            $nom = trim($data['nom']);
            $email = trim($data['email']);
            $telephone = trim($data['telephone']);
            $role = trim($data['role']);
            $sexe = isset($data['sexe']) ? trim($data['sexe']) : 'Non spécifié';
            $nationalite = isset($data['nationalite']) ? trim($data['nationalite']) : 'Non spécifié';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendJsonResponse(['success' => false, 'error' => 'Format d\'email invalide'], 400);
            }

            // Vérifier unicité email
            $stmt = $pdo->prepare("SELECT id FROM Utilisateur WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                sendJsonResponse(['success' => false, 'error' => 'Cet email est déjà utilisé'], 409);
            }

            // Vérifier unicité matricule
            $stmt = $pdo->prepare("SELECT id FROM Utilisateur WHERE matricule = ?");
            $stmt->execute([$matricule]);
            if ($stmt->fetch()) {
                sendJsonResponse(['success' => false, 'error' => 'Ce matricule est déjà utilisé'], 409);
            }

            // Insertion (sans photo)
            $stmt = $pdo->prepare("
                INSERT INTO Utilisateur 
                (matricule, nom, sexe, nationalite, telephone, email, role, etat, photoProfil, photo_key,
                 nombreFollowers, nombreFollowing, noteVendeur, soldeVendeur, nbVentes, statutVendeur) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'actif', NULL, NULL, 0, 0, 0, 0, 0, 'nouveau')
            ");

            $success = $stmt->execute([
                $matricule,
                $nom,
                $sexe,
                $nationalite,
                $telephone,
                $email,
                $role
            ]);

            if ($success) {
                $newId = $pdo->lastInsertId();
                error_log("✅ Utilisateur créé avec succès - ID: $newId, Nom: $nom");

                sendJsonResponse([
                    'success' => true,
                    'message' => 'Utilisateur créé avec succès',
                    'id' => $newId,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                error_log("❌ Erreur lors de la création de l'utilisateur");
                sendJsonResponse(['success' => false, 'error' => 'Erreur lors de la création'], 500);
            }
        }

        // 🔁 ACTIVATION/DÉSACTIVATION
        elseif (isset($data['etat']) && isset($data['id'])) {
            $id = intval($data['id']);
            $etat = trim($data['etat']);

            if ($id <= 0) {
                sendJsonResponse(['success' => false, 'error' => 'ID utilisateur invalide'], 400);
            }
            if (!in_array($etat, ['actif', 'inactif'])) {
                sendJsonResponse(['success' => false, 'error' => "Valeur de 'etat' invalide"], 400);
            }

            $stmt = $pdo->prepare("UPDATE Utilisateur SET etat = ? WHERE id = ?");
            $success = $stmt->execute([$etat, $id]);

            if ($success && $stmt->rowCount() > 0) {
                error_log("✅ État utilisateur mis à jour - ID: $id, État: $etat");
                sendJsonResponse([
                    'success' => true,
                    'message' => "État mis à jour en '$etat'",
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                sendJsonResponse(['success' => false, 'error' => "Utilisateur non trouvé ou pas de changement"], 404);
            }
        }

        // ❌ SUPPRESSION (via action POST)
        elseif (isset($data['action']) && $data['action'] === 'supprimer' && isset($data['id'])) {
            $id = intval($data['id']);
            if ($id <= 0) {
                sendJsonResponse(['success' => false, 'error' => 'ID utilisateur invalide'], 400);
            }

            // Récupérer photo_key avant suppression
            $stmt = $pdo->prepare("SELECT photo_key FROM Utilisateur WHERE id = ?");
            $stmt->execute([$id]);
            $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

            // Supprimer la photo de R2 si elle existe
            if ($utilisateur && !empty($utilisateur['photo_key'])) {
                try {
                    $s3Client->deleteObject([
                        'Bucket' => CLOUDFLARE_BUCKET,
                        'Key' => $utilisateur['photo_key']
                    ]);
                    error_log("✅ Photo R2 supprimée: " . $utilisateur['photo_key']);
                } catch (AwsException $e) {
                    error_log("⚠️ Erreur suppression R2: " . $e->getMessage());
                }
            }

            // Supprimer l'utilisateur
            $stmt = $pdo->prepare("DELETE FROM Utilisateur WHERE id = ?");
            $success = $stmt->execute([$id]);

            if ($success && $stmt->rowCount() > 0) {
                error_log("✅ Utilisateur supprimé - ID: $id");
                sendJsonResponse([
                    'success' => true,
                    'message' => "Utilisateur supprimé avec succès",
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                sendJsonResponse(['success' => false, 'error' => "Utilisateur non trouvé"], 404);
            }
        } else {
            sendJsonResponse(['success' => false, 'error' => "Action invalide ou paramètres manquants"], 400);
        }
    }

    // ==================== PUT : MODIFICATION (sans photo) ====================
    elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $data = getJsonInput();

        if (!isset($data['id'])) {
            sendJsonResponse(['success' => false, 'error' => "Paramètre 'id' requis"], 400);
        }

        $id = intval($data['id']);
        if ($id <= 0) {
            sendJsonResponse(['success' => false, 'error' => 'ID utilisateur invalide'], 400);
        }

        // Vérifier si l'utilisateur existe
        $stmt = $pdo->prepare("SELECT * FROM Utilisateur WHERE id = ?");
        $stmt->execute([$id]);
        $utilisateurExistant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$utilisateurExistant) {
            sendJsonResponse(['success' => false, 'error' => 'Utilisateur non trouvé'], 404);
        }

        // Vérifier unicité email si modifié
        if (isset($data['email']) && $data['email'] !== $utilisateurExistant['email']) {
            $email = trim($data['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendJsonResponse(['success' => false, 'error' => 'Format d\'email invalide'], 400);
            }
            $stmt = $pdo->prepare("SELECT id FROM Utilisateur WHERE email = ? AND id != ?");
            $stmt->execute([$email, $id]);
            if ($stmt->fetch()) {
                sendJsonResponse(['success' => false, 'error' => 'Cet email est déjà utilisé'], 409);
            }
        }

        // Vérifier unicité matricule si modifié
        if (isset($data['matricule']) && $data['matricule'] !== $utilisateurExistant['matricule']) {
            $matricule = trim($data['matricule']);
            $stmt = $pdo->prepare("SELECT id FROM Utilisateur WHERE matricule = ? AND id != ?");
            $stmt->execute([$matricule, $id]);
            if ($stmt->fetch()) {
                sendJsonResponse(['success' => false, 'error' => 'Ce matricule est déjà utilisé'], 409);
            }
        }

        // Champs modifiables (sans photo, gérée ailleurs)
        $champsModifiables = [
            'matricule',
            'nom',
            'sexe',
            'nationalite',
            'telephone',
            'email',
            'role',
            'nombreFollowers',
            'nombreFollowing',
            'noteVendeur',
            'soldeVendeur',
            'nbVentes',
            'statutVendeur'
        ];
        $updates = [];
        $params = [];

        foreach ($champsModifiables as $champ) {
            if (isset($data[$champ])) {
                $updates[] = "$champ = ?";
                if (in_array($champ, ['nombreFollowers', 'nombreFollowing', 'nbVentes'])) {
                    $params[] = intval($data[$champ]);
                } elseif (in_array($champ, ['noteVendeur', 'soldeVendeur'])) {
                    $params[] = floatval($data[$champ]);
                } else {
                    $params[] = trim($data[$champ]);
                }
            }
        }

        if (empty($updates)) {
            sendJsonResponse(['success' => false, 'error' => 'Aucune donnée à mettre à jour'], 400);
        }

        $params[] = $id;
        $sql = "UPDATE Utilisateur SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute($params);

        if ($success) {
            error_log("✅ Utilisateur mis à jour - ID: $id");
            sendJsonResponse([
                'success' => true,
                'message' => 'Utilisateur mis à jour avec succès',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Erreur lors de la mise à jour'], 500);
        }
    }

    // ==================== DELETE : SUPPRESSION (par GET) ====================
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        if (!isset($_GET['id'])) {
            sendJsonResponse(['success' => false, 'error' => "Paramètre 'id' requis"], 400);
        }

        $id = intval($_GET['id']);
        if ($id <= 0) {
            sendJsonResponse(['success' => false, 'error' => 'ID utilisateur invalide'], 400);
        }

        // Récupérer photo_key
        $stmt = $pdo->prepare("SELECT photo_key FROM Utilisateur WHERE id = ?");
        $stmt->execute([$id]);
        $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

        // Supprimer la photo de R2
        if ($utilisateur && !empty($utilisateur['photo_key'])) {
            try {
                $s3Client->deleteObject([
                    'Bucket' => CLOUDFLARE_BUCKET,
                    'Key' => $utilisateur['photo_key']
                ]);
                error_log("✅ Photo R2 supprimée: " . $utilisateur['photo_key']);
            } catch (AwsException $e) {
                error_log("⚠️ Erreur suppression R2: " . $e->getMessage());
            }
        }

        // Supprimer l'utilisateur
        $stmt = $pdo->prepare("DELETE FROM Utilisateur WHERE id = ?");
        $success = $stmt->execute([$id]);

        if ($success && $stmt->rowCount() > 0) {
            error_log("✅ Utilisateur supprimé - ID: $id");
            sendJsonResponse([
                'success' => true,
                'message' => "Utilisateur supprimé avec succès",
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            sendJsonResponse(['success' => false, 'error' => "Utilisateur non trouvé"], 404);
        }
    } else {
        sendJsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
    }

} catch (PDOException $e) {
    error_log("❌ ERREUR PDO GESTION UTILISATEURS: " . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'error' => 'Erreur de base de données',
        'debug' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], 500);
} catch (Exception $e) {
    error_log("❌ ERREUR GÉNÉRALE GESTION UTILISATEURS: " . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'error' => 'Erreur interne du serveur',
        'timestamp' => date('Y-m-d H:i:s')
    ], 500);
}
?>