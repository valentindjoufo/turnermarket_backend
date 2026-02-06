<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require 'config.php';

// Gérer les requêtes OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Fonction pour envoyer une notification push
function sendFollowPushNotification($followerId, $followingId, $followerName) {
    global $pdo;
    
    try {
        // 1. Récupérer les tokens push de l'utilisateur qui est suivi
        $stmt = $pdo->prepare("SELECT token FROM push_tokens WHERE userId = ?");
        $stmt->execute([$followingId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tokens)) {
            error_log("Aucun token push trouvé pour l'utilisateur $followingId");
            return false;
        }
        
        // 2. Préparer le message de notification
        $title = "Nouvel abonné";
        $body = "$followerName vous suit";
        $data = [
            'type' => 'follow',
            'followerId' => (string)$followerId,
            'followingId' => (string)$followingId,
            'screen' => 'Profile',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // 3. Envoyer la notification via Expo (si vous utilisez Expo)
        $expoApiUrl = 'https://exp.host/--/api/v2/push/send';
        
        $messages = [];
        foreach ($tokens as $token) {
            $messages[] = [
                'to' => $token,
                'sound' => 'default',
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'channelId' => 'follow-notifications'
            ];
        }
        
        // Envoyer par batch (max 100 par requête)
        $chunks = array_chunk($messages, 100);
        $allSuccess = true;
        
        foreach ($chunks as $chunk) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $expoApiUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($chunk));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Accept-encoding: gzip, deflate'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Timeout de 10 secondes
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($httpCode !== 200) {
                error_log("Erreur Expo API: HTTP $httpCode - " . $response);
                $allSuccess = false;
            }
            
            curl_close($ch);
        }
        
        return $allSuccess;
        
    } catch (Exception $e) {
        error_log("Erreur sendFollowPushNotification: " . $e->getMessage());
        return false;
    }
}

// Fonction pour créer une notification dans la base de données
function createFollowNotification($followerId, $followingId, $followerName) {
    global $pdo;
    
    try {
        $title = "Nouvel abonné";
        $message = "$followerName vous suit";
        $lien = "/profile?vendeurId=$followerId";
        
        $stmt = $pdo->prepare("
            INSERT INTO Notification (utilisateurId, titre, message, type, lien, estLu, dateCreation)
            VALUES (?, ?, ?, 'info', ?, 0, NOW())
        ");
        
        $stmt->execute([$followingId, $title, $message, $lien]);
        $notificationId = $pdo->lastInsertId();
        
        // Mettre à jour la table Follow avec l'ID de notification
        $updateStmt = $pdo->prepare("
            UPDATE Follow 
            SET notification_sent = 1, notification_id = ?
            WHERE followerId = ? AND followingId = ?
            ORDER BY dateCreation DESC 
            LIMIT 1
        ");
        
        $updateStmt->execute([$notificationId, $followerId, $followingId]);
        
        return $notificationId;
        
    } catch (Exception $e) {
        error_log("Erreur createFollowNotification: " . $e->getMessage());
        return null;
    }
}

// Fonction pour récupérer le nom de l'utilisateur
function getUserName($userId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT nom FROM Utilisateur WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user ? $user['nom'] : "Un utilisateur";
    } catch (Exception $e) {
        error_log("Erreur getUserName: " . $e->getMessage());
        return "Un utilisateur";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $action = $data['action'] ?? '';
    $userId = intval($data['userId'] ?? 0);
    $vendeurId = intval($data['vendeurId'] ?? 0);

    // Validation
    if ($userId === 0 || $vendeurId === 0) {
        echo json_encode(['success' => false, 'error' => 'IDs invalides']);
        exit();
    }

    if ($userId === $vendeurId) {
        echo json_encode(['success' => false, 'error' => 'Impossible de se suivre soi-même']);
        exit();
    }

    try {
        // 🔒 DÉMARRER UNE TRANSACTION pour garantir la cohérence
        $pdo->beginTransaction();

        if ($action === 'follow') {
            // Vérifier si l'utilisateur suit déjà ce vendeur
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM Follow WHERE followerId = ? AND followingId = ?");
            $checkStmt->execute([$userId, $vendeurId]);
            $alreadyFollowing = $checkStmt->fetchColumn() > 0;

            if ($alreadyFollowing) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false, 
                    'error' => 'Vous suivez déjà ce vendeur',
                    'isFollowing' => true
                ]);
                exit();
            }

            // 1. Insérer la relation de suivi
            $stmt = $pdo->prepare("INSERT INTO Follow (followerId, followingId, dateCreation) VALUES (?, ?, NOW())");
            $result = $stmt->execute([$userId, $vendeurId]);

            if (!$result) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erreur lors du suivi']);
                exit();
            }

            // 🆕 CORRECTION : METTRE À JOUR MANUELLEMENT LES COMPTEURS (pas de triggers)
            // Incrémenter le following de l'utilisateur
            $updateFollowing = $pdo->prepare("UPDATE Utilisateur SET nombreFollowing = nombreFollowing + 1 WHERE id = ?");
            $updateFollowing->execute([$userId]);
            
            // Incrémenter les followers du vendeur
            $updateFollowers = $pdo->prepare("UPDATE Utilisateur SET nombreFollowers = nombreFollowers + 1 WHERE id = ?");
            $updateFollowers->execute([$vendeurId]);

            // Récupérer les nouveaux compteurs pour confirmation
            $getCountStmt = $pdo->prepare("
                SELECT nombreFollowers, nombreFollowing 
                FROM Utilisateur 
                WHERE id = ?
            ");
            
            // Compteur du vendeur (celui qui est suivi)
            $getCountStmt->execute([$vendeurId]);
            $vendeurData = $getCountStmt->fetch(PDO::FETCH_ASSOC);
            $newFollowersCount = $vendeurData['nombreFollowers'] ?? 0;
            $newFollowingCount = $vendeurData['nombreFollowing'] ?? 0;

            // ==================== ENVOYER NOTIFICATION PUSH ====================
            // Récupérer le nom de l'utilisateur qui suit
            $followerName = getUserName($userId);
            
            // Créer une notification dans la base de données
            $notificationId = createFollowNotification($userId, $vendeurId, $followerName);
            
            // Envoyer une notification push (en arrière-plan - ne pas bloquer)
            // Note: On utilise register_shutdown_function pour ne pas retarder la réponse
            register_shutdown_function(function() use ($userId, $vendeurId, $followerName) {
                try {
                    sendFollowPushNotification($userId, $vendeurId, $followerName);
                } catch (Exception $e) {
                    // Log l'erreur mais ne pas interrompre
                    error_log("Erreur notification push en arrière-plan: " . $e->getMessage());
                }
            });
            
            // ===================================================================

            $pdo->commit();

            echo json_encode([
                'success' => true, 
                'message' => 'Vous suivez maintenant ce vendeur',
                'isFollowing' => true,
                'nombreFollowers' => intval($newFollowersCount),
                'nombreFollowing' => intval($newFollowingCount),
                'notificationSent' => $notificationId !== null
            ]);

        } elseif ($action === 'unfollow') {
            // Vérifier si l'utilisateur suit bien ce vendeur
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM Follow WHERE followerId = ? AND followingId = ?");
            $checkStmt->execute([$userId, $vendeurId]);
            $isFollowing = $checkStmt->fetchColumn() > 0;

            if (!$isFollowing) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false, 
                    'error' => 'Vous ne suivez pas ce vendeur',
                    'isFollowing' => false
                ]);
                exit();
            }

            // 1. Supprimer la relation de suivi
            $stmt = $pdo->prepare("DELETE FROM Follow WHERE followerId = ? AND followingId = ?");
            $result = $stmt->execute([$userId, $vendeurId]);

            if (!$result || $stmt->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Erreur lors du désabonnement']);
                exit();
            }

            // 🆕 CORRECTION : METTRE À JOUR MANUELLEMENT LES COMPTEURS (pas de triggers)
            // Décrémenter le following de l'utilisateur (ne pas descendre en dessous de 0)
            $updateFollowing = $pdo->prepare("UPDATE Utilisateur SET nombreFollowing = GREATEST(0, nombreFollowing - 1) WHERE id = ?");
            $updateFollowing->execute([$userId]);
            
            // Décrémenter les followers du vendeur (ne pas descendre en dessous de 0)
            $updateFollowers = $pdo->prepare("UPDATE Utilisateur SET nombreFollowers = GREATEST(0, nombreFollowers - 1) WHERE id = ?");
            $updateFollowers->execute([$vendeurId]);

            // Récupérer les nouveaux compteurs
            $getCountStmt = $pdo->prepare("
                SELECT nombreFollowers, nombreFollowing 
                FROM Utilisateur 
                WHERE id = ?
            ");
            $getCountStmt->execute([$vendeurId]);
            $vendeurData = $getCountStmt->fetch(PDO::FETCH_ASSOC);
            $newFollowersCount = $vendeurData['nombreFollowers'] ?? 0;
            $newFollowingCount = $vendeurData['nombreFollowing'] ?? 0;

            $pdo->commit();

            echo json_encode([
                'success' => true, 
                'message' => 'Vous ne suivez plus ce vendeur',
                'isFollowing' => false,
                'nombreFollowers' => intval($newFollowersCount),
                'nombreFollowing' => intval($newFollowingCount)
            ]);

        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Action invalide']);
        }

    } catch (PDOException $e) {
        // Annuler la transaction en cas d'erreur
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Si la contrainte UNIQUE est violée
        if ($e->getCode() == 23000) {
            echo json_encode([
                'success' => false, 
                'error' => 'Vous suivez déjà ce vendeur',
                'isFollowing' => true
            ]);
        } else {
            // Log l'erreur pour debug
            error_log("Erreur Follow API: " . $e->getMessage());
            
            echo json_encode([
                'success' => false, 
                'error' => 'Erreur base de données',
                'details' => $e->getMessage() // À retirer en production
            ]);
        }
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $userId = intval($_GET['userId'] ?? 0);
    $vendeurId = intval($_GET['vendeurId'] ?? 0);

    if ($action === 'check') {
        if ($userId === 0 || $vendeurId === 0) {
            echo json_encode(['isFollowing' => false]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Follow WHERE followerId = ? AND followingId = ?");
            $stmt->execute([$userId, $vendeurId]);
            $isFollowing = $stmt->fetchColumn() > 0;
            
            echo json_encode(['isFollowing' => $isFollowing]);
        } catch (PDOException $e) {
            error_log("Erreur check follow: " . $e->getMessage());
            echo json_encode(['isFollowing' => false, 'error' => 'Erreur de vérification']);
        }
    } elseif ($action === 'stats') {
        // 🆕 BONUS : Récupérer les stats d'un utilisateur
        if ($vendeurId === 0) {
            echo json_encode(['error' => 'ID vendeur requis']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("
                SELECT nombreFollowers, nombreFollowing 
                FROM Utilisateur 
                WHERE id = ?
            ");
            $stmt->execute([$vendeurId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'nombreFollowers' => intval($stats['nombreFollowers'] ?? 0),
                'nombreFollowing' => intval($stats['nombreFollowing'] ?? 0)
            ]);
        } catch (PDOException $e) {
            error_log("Erreur stats follow: " . $e->getMessage());
            echo json_encode(['error' => 'Erreur de récupération des stats']);
        }
    } else {
        echo json_encode(['error' => 'Action GET invalide']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
}
?>