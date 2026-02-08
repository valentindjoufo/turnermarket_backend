<?php
/**
 * gestion_utilisateurs.php - CRUD complet pour les utilisateurs
 * Version avec connexion PostgreSQL via config.php
 */

// 📦 Inclusion de la configuration (connexion PDO PostgreSQL)
require_once 'config.php';

// 🚦 Configuration CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// ✅ Gestion pré-vol OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ CORRIGÉ : Configuration de l'URL de base pour les photos
define('BASE_URL', '/api/');  // ✅ Chemin relatif - fonctionne avec ngrok ET localhost

// Fonction pour envoyer une réponse JSON standardisée
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// Fonction pour valider et décoder les données JSON
function getJsonInput() {
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
    // 💾 Vérification que la connexion PDO est bien disponible
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    // ==================== GET : RÉCUPÉRATION ====================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        
        // ✅ GET avec id : un seul utilisateur AVEC VRAIS COMPTEURS
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            if ($id <= 0) {
                sendJsonResponse(['success' => false, 'error' => 'ID utilisateur invalide'], 400);
            }
            
            // 🆕 PARAMÈTRE OPTIONNEL : currentUserId pour vérifier si on suit cet utilisateur
            $currentUserId = isset($_GET['currentUserId']) ? intval($_GET['currentUserId']) : 0;
            
            // ✅ CORRIGÉ : REQUÊTE AVEC VRAIS COMPTEURS CALCULÉS EN DIRECT
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
                    u.dateCreation,
                    -- ✅ COMPTEURS EXACTS (calculés en direct depuis la table Follow)
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
                    u.noteVendeur,          -- Note du vendeur
                    u.soldeVendeur,         -- Solde vendeur
                    u.nbVentes,             -- Nombre de ventes
                    u.statutVendeur,        -- Statut vendeur
                    u.identiteVerifiee,     -- Identité vérifiée
                    u.emailVerifie,         -- Email vérifié
                    u.telephoneVerifie,     -- Téléphone vérifié
                    -- ✅ Statistiques complémentaires
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
                    -- ✅ Vérifier si l'utilisateur connecté suit cet utilisateur
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

            // Nettoyer et formater les données
            $utilisateur = array_map(function($value) {
                return $value === null ? '' : $value;
            }, $utilisateur);

            // ✅ CORRIGÉ : Ajouter l'URL relative de la photo
            if (!empty($utilisateur['photoProfil'])) {
                $filePath = __DIR__ . '/' . $utilisateur['photoProfil'];
                if (file_exists($filePath)) {
                    // Retourner le chemin relatif
                    $utilisateur['photoProfilUrl'] = BASE_URL . $utilisateur['photoProfil'];
                } else {
                    $utilisateur['photoProfilUrl'] = null;
                    $utilisateur['photoProfil'] = null;
                }
            } else {
                $utilisateur['photoProfilUrl'] = null;
            }
            
            // ✅ Formater les nombres correctement
            $utilisateur['nombreFollowers'] = intval($utilisateur['nombreFollowers']);
            $utilisateur['nombreFollowing'] = intval($utilisateur['nombreFollowing']);
            $utilisateur['nombreFormations'] = intval($utilisateur['nombreFormations']);
            $utilisateur['nombreAchats'] = intval($utilisateur['nombreAchats']);
            $utilisateur['nombreCommentaires'] = intval($utilisateur['nombreCommentaires']);
            $utilisateur['isFollowing'] = boolval($utilisateur['isFollowing']);
            $utilisateur['noteVendeur'] = floatval($utilisateur['noteVendeur']);
            $utilisateur['soldeVendeur'] = floatval($utilisateur['soldeVendeur']);
            $utilisateur['nbVentes'] = intval($utilisateur['nbVentes']);

            error_log("✅ Utilisateur récupéré - ID: $id, Nom: " . $utilisateur['nom']);

            // ✅ Retourner encapsulé dans 'utilisateur'
            sendJsonResponse([
                'success' => true,
                'utilisateur' => $utilisateur,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } else {
            // ✅ GET sans id : liste des utilisateurs AVEC VRAIS COMPTEURS
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
                    u.dateCreation,
                    -- ✅ COMPTEURS EXACTS pour la liste aussi
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

            // Nettoyer et formater les données
            $utilisateurs = array_map(function($utilisateur) {
                $utilisateur = array_map(function($value) {
                    return $value === null ? '' : $value;
                }, $utilisateur);
                
                // ✅ CORRIGÉ : Ajouter les URLs relatives pour toutes les photos
                if (!empty($utilisateur['photoProfil'])) {
                    $filePath = __DIR__ . '/' . $utilisateur['photoProfil'];
                    if (file_exists($filePath)) {
                        $utilisateur['photoProfilUrl'] = BASE_URL . $utilisateur['photoProfil'];
                    } else {
                        $utilisateur['photoProfilUrl'] = null;
                        $utilisateur['photoProfil'] = null;
                    }
                } else {
                    $utilisateur['photoProfilUrl'] = null;
                }
                
                // ✅ Formater les nombres
                $utilisateur['nombreFollowers'] = intval($utilisateur['nombreFollowers']);
                $utilisateur['nombreFollowing'] = intval($utilisateur['nombreFollowing']);
                $utilisateur['nombreFormations'] = intval($utilisateur['nombreFormations']);
                $utilisateur['noteVendeur'] = floatval($utilisateur['noteVendeur']);
                $utilisateur['nbVentes'] = intval($utilisateur['nbVentes']);
                
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
    
    // ==================== POST : CRÉATION ====================
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = getJsonInput();

        // 🆕 CRÉATION d'un nouvel utilisateur
        if (isset($data['action']) && $data['action'] === 'creer') {
            error_log("🆕 Création d'un nouvel utilisateur");
            
            // Validation des champs requis
            $champsRequis = ['matricule', 'nom', 'email', 'telephone', 'role'];
            foreach ($champsRequis as $champ) {
                if (!isset($data[$champ]) || empty(trim($data[$champ]))) {
                    sendJsonResponse(['success' => false, 'error' => "Le champ '$champ' est requis"], 400);
                }
            }

            // Nettoyer les données
            $matricule = trim($data['matricule']);
            $nom = trim($data['nom']);
            $email = trim($data['email']);
            $telephone = trim($data['telephone']);
            $role = trim($data['role']);
            $sexe = isset($data['sexe']) ? trim($data['sexe']) : 'Non spécifié';
            $nationalite = isset($data['nationalite']) ? trim($data['nationalite']) : 'Non spécifié';

            // Validation email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendJsonResponse(['success' => false, 'error' => 'Format d\'email invalide'], 400);
            }

            // Vérifier si l'email existe déjà
            $stmt = $pdo->prepare("SELECT id FROM Utilisateur WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                sendJsonResponse(['success' => false, 'error' => 'Cet email est déjà utilisé'], 409);
            }

            // Vérifier si le matricule existe déjà
            $stmt = $pdo->prepare("SELECT id FROM Utilisateur WHERE matricule = ?");
            $stmt->execute([$matricule]);
            if ($stmt->fetch()) {
                sendJsonResponse(['success' => false, 'error' => 'Ce matricule est déjà utilisé'], 409);
            }

            // Insertion du nouvel utilisateur
            $stmt = $pdo->prepare("
                INSERT INTO Utilisateur 
                (matricule, nom, sexe, nationalite, telephone, email, role, etat, photoProfil,
                 nombreFollowers, nombreFollowing, noteVendeur, soldeVendeur, nbVentes, statutVendeur) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'actif', NULL, 0, 0, 0, 0, 0, 'nouveau')
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
                sendJsonResponse(['success' => false, 'error' => 'Erreur lors de la création de l\'utilisateur'], 500);
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
                error_log("⚠️ Utilisateur non trouvé ou pas de changement - ID: $id");
                sendJsonResponse(['success' => false, 'error' => "Utilisateur non trouvé ou pas de changement"], 404);
            }
        }
        
        // ❌ SUPPRESSION
        elseif (isset($data['action']) && $data['action'] === 'supprimer' && isset($data['id'])) {
            $id = intval($data['id']);
            
            if ($id <= 0) {
                sendJsonResponse(['success' => false, 'error' => 'ID utilisateur invalide'], 400);
            }

            // Récupérer la photo avant suppression
            $stmt = $pdo->prepare("SELECT photoProfil FROM Utilisateur WHERE id = ?");
            $stmt->execute([$id]);
            $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

            // Supprimer le fichier photo s'il existe
            if ($utilisateur && !empty($utilisateur['photoProfil'])) {
                $filePath = __DIR__ . '/' . $utilisateur['photoProfil'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                    error_log("🗑️ Photo utilisateur supprimée - Chemin: $filePath");
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
                error_log("❌ Utilisateur non trouvé pour suppression - ID: $id");
                sendJsonResponse(['success' => false, 'error' => "Utilisateur non trouvé"], 404);
            }
        }
        
        else {
            sendJsonResponse(['success' => false, 'error' => "Action invalide ou paramètres manquants"], 400);
        }
    }
    
    // ==================== PUT : MODIFICATION ====================
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

        // Vérifier l'unicité de l'email si modifié
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

        // Vérifier l'unicité du matricule si modifié
        if (isset($data['matricule']) && $data['matricule'] !== $utilisateurExistant['matricule']) {
            $matricule = trim($data['matricule']);
            $stmt = $pdo->prepare("SELECT id FROM Utilisateur WHERE matricule = ? AND id != ?");
            $stmt->execute([$matricule, $id]);
            if ($stmt->fetch()) {
                sendJsonResponse(['success' => false, 'error' => 'Ce matricule est déjà utilisé'], 409);
            }
        }

        // Construction dynamique de la requête UPDATE
        $champsModifiables = [
            'matricule', 'nom', 'sexe', 'nationalite', 'telephone', 'email', 'role',
            'nombreFollowers', 'nombreFollowing', 'noteVendeur', 'soldeVendeur', 'nbVentes', 'statutVendeur'
        ];
        $updates = [];
        $params = [];

        foreach ($champsModifiables as $champ) {
            if (isset($data[$champ])) {
                $updates[] = "$champ = ?";
                
                // Convertir les nombres
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
            error_log("❌ Erreur lors de la mise à jour utilisateur - ID: $id");
            sendJsonResponse(['success' => false, 'error' => 'Erreur lors de la mise à jour'], 500);
        }
    }
    
    // ==================== DELETE : SUPPRESSION ====================
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        if (!isset($_GET['id'])) {
            sendJsonResponse(['success' => false, 'error' => "Paramètre 'id' requis"], 400);
        }

        $id = intval($_GET['id']);
        if ($id <= 0) {
            sendJsonResponse(['success' => false, 'error' => 'ID utilisateur invalide'], 400);
        }

        // Récupérer la photo avant suppression
        $stmt = $pdo->prepare("SELECT photoProfil FROM Utilisateur WHERE id = ?");
        $stmt->execute([$id]);
        $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

        // Supprimer le fichier photo s'il existe
        if ($utilisateur && !empty($utilisateur['photoProfil'])) {
            $filePath = __DIR__ . '/' . $utilisateur['photoProfil'];
            if (file_exists($filePath)) {
                unlink($filePath);
                error_log("🗑️ Photo utilisateur supprimée - Chemin: $filePath");
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
            error_log("❌ Utilisateur non trouvé pour suppression - ID: $id");
            sendJsonResponse(['success' => false, 'error' => "Utilisateur non trouvé"], 404);
        }
    }
    
    // ==================== MÉTHODE NON AUTORISÉE ====================
    else {
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