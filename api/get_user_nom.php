<?php
/**
 * get_user_name.php - Récupérer le nom d'un utilisateur par ID
 * Version avec connexion PostgreSQL via config.php
 */

// 📦 Inclusion de la configuration (connexion PDO PostgreSQL)
require_once 'config.php';

// 🚦 Configuration des headers
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 📤 Gestion de la pré-requête OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // 💾 Vérification que la connexion PDO est bien disponible
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Connexion à la base de données non disponible");
    }

    // 📥 Récupération et validation de l'ID
    $id = $_GET['id'] ?? null;
    
    if (!$id || !filter_var($id, FILTER_VALIDATE_INT)) {
        error_log("❌ ID utilisateur invalide ou manquant: " . $id);
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'ID utilisateur manquant ou invalide',
            'details' => 'L\'ID doit être un nombre entier valide'
        ]);
        exit;
    }

    $id = intval($id);
    error_log("🔍 Recherche nom utilisateur ID: $id");

    // 👤 Recherche de l'utilisateur
    $stmt = $pdo->prepare("SELECT id, nom, email FROM Utilisateur WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        error_log("✅ Utilisateur trouvé - ID: $id, Nom: " . $row['nom']);
        
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => (int)$row['id'],
                'nom' => $row['nom'],
                'email' => $row['email']
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        error_log("⚠️ Utilisateur non trouvé - ID: $id");
        
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $id,
                'nom' => 'Inconnu',
                'email' => null
            ],
            'warning' => 'Utilisateur non trouvé dans la base de données',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

} catch (PDOException $e) {
    // ❌ Erreur de base de données
    error_log("❌ ERREUR PDO GET_USER_NAME: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur de base de données',
        'debug' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // ❌ Autres erreurs
    error_log("❌ ERREUR GET_USER_NAME: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>