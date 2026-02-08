<?php
/**
 * get_utilisateur.php - Récupération d'un utilisateur par ID
 * Version avec connexion PostgreSQL via config.php
 */

// 📦 Inclusion de la configuration (connexion PDO PostgreSQL)
require_once 'config.php';

// 🚦 Configuration des headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');

// 📤 Gère la pré-requête OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // 💾 Vérification que la connexion PDO est bien disponible
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    // 📍 GET - Récupération d'un utilisateur par ID
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            $id = intval($_GET['id']);
            
            error_log("🔍 Récupération utilisateur ID: $id");
            
            $stmt = $pdo->prepare("SELECT id, etat FROM Utilisateur WHERE id = ?");
            $stmt->execute([$id]);
            $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($utilisateur) {
                error_log("✅ Utilisateur trouvé - ID: $id, État: " . $utilisateur['etat']);
                
                echo json_encode([
                    'success' => true,
                    'utilisateur' => $utilisateur
                ]);
            } else {
                error_log("❌ Utilisateur non trouvé - ID: $id");
                
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Utilisateur non trouvé'
                ]);
            }
        } else {
            error_log("❌ Paramètre ID invalide ou manquant");
            
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Paramètre id invalide ou manquant',
                'details' => 'L\'ID doit être un nombre valide'
            ]);
        }
    } else {
        // ❌ Méthode non autorisée
        error_log("❌ Méthode non autorisée: " . $_SERVER['REQUEST_METHOD']);
        
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Méthode non autorisée',
            'allowed_methods' => ['GET']
        ]);
    }

} catch (PDOException $e) {
    // ❌ Erreur de base de données
    error_log("❌ ERREUR PDO GET UTILISATEUR: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur de base de données',
        'debug' => $e->getMessage()
    ]);
    
} catch (Exception $e) {
    // ❌ Autres erreurs
    error_log("❌ ERREUR GET UTILISATEUR: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>