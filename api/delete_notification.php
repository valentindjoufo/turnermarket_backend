<?php
/**
 * delete_notification.php - Suppression des notifications
 * Version avec connexion PostgreSQL via config.php
 */

// 📦 Inclusion de la configuration (connexion PDO PostgreSQL)
require_once 'config.php';

// 🚦 Configuration des headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // 📥 Récupération des données JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception("Données JSON invalides ou manquantes");
    }

    $notificationId = $data['notificationId'] ?? null;
    $notificationIds = $data['notificationIds'] ?? null;

    if ($notificationId) {
        // 🗑️ Suppression d'une seule notification
        $stmt = $pdo->prepare("DELETE FROM Notification WHERE id = ?");
        $stmt->execute([$notificationId]);
        $rowsDeleted = $stmt->rowCount();
        
        if ($rowsDeleted === 0) {
            throw new Exception("Notification non trouvée");
        }
        
        $message = "Notification supprimée";
        
    } elseif ($notificationIds && is_array($notificationIds)) {
        // 🗑️ Suppression multiple de notifications
        if (empty($notificationIds)) {
            throw new Exception("Aucune notification spécifiée");
        }
        
        // Création des placeholders pour la requête IN
        $placeholders = str_repeat('?,', count($notificationIds) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM Notification WHERE id IN ($placeholders)");
        $stmt->execute($notificationIds);
        $rowsDeleted = $stmt->rowCount();
        
        if ($rowsDeleted === 0) {
            throw new Exception("Aucune notification trouvée avec les IDs fournis");
        }
        
        $message = $rowsDeleted . " notification(s) supprimée(s)";
        
    } else {
        throw new Exception("Aucune notification à supprimer");
    }

    // ✅ Réponse de succès
    echo json_encode([
        "success" => true, 
        "message" => $message,
        "deleted_count" => $rowsDeleted ?? 1
    ]);

} catch (PDOException $e) {
    // ❌ Erreur de base de données
    error_log("❌ ERREUR PDO DELETE NOTIFICATION: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "error" => "Erreur de base de données",
        "debug" => $e->getMessage()
    ]);
    
} catch (Exception $e) {
    // ❌ Autres erreurs
    error_log("❌ ERREUR DELETE NOTIFICATION: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        "success" => false, 
        "error" => $e->getMessage()
    ]);
}
?>