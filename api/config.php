<?php
// 📋 Configuration
$host = 'localhost';
$dbname = 'gestvente';
$user = 'root';
$password = '';

// ⏱️ Log du début de connexion
error_log("🔌 Tentative de connexion à la base de données...");
$startTime = microtime(true);

try {
    // 🚀 Connexion PDO avec options optimisées
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4", // utf8mb4 pour les emojis
        $user,
        $password,
        [
            // 🔥 CRITIQUE: Timeout de connexion (3 secondes)
            PDO::ATTR_TIMEOUT => 3,
            
            // ⚡ Mode d'erreur: Exceptions
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            
            // 📦 Mode de récupération par défaut
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            
            // 🔄 Désactiver l'émulation des requêtes préparées
            PDO::ATTR_EMULATE_PREPARES => false,
            
            // 🌐 Charset UTF-8
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            
            // ⚡ Connexion persistante (optionnel, à tester)
            // PDO::ATTR_PERSISTENT => true,
        ]
    );
    
    // ✅ Connexion réussie
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    error_log("✅ Connexion BDD réussie en {$duration}ms");
    
} catch (PDOException $e) {
    // ❌ Échec de connexion
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    // 📝 Log détaillé de l'erreur
    error_log("❌ ERREUR BDD après {$duration}ms:");
    error_log("Code: " . $e->getCode());
    error_log("Message: " . $e->getMessage());
    error_log("Host: $host");
    error_log("Database: $dbname");
    error_log("User: $user");
    
    // 🚨 Réponse HTTP 503 (Service Unavailable)
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'Service temporairement indisponible',
        'message' => 'Impossible de se connecter à la base de données',
        'debug' => [
            'duration' => $duration . 'ms',
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    exit;
}
?>