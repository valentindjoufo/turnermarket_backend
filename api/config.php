<?php
/**
 * config.php — Connexion PostgreSQL pour Render
 * Version finale prête à déploiement
 */

// 📋 Récupération automatique de DATABASE_URL (Render)
$url = parse_url(getenv("DATABASE_URL"));

$host   = $url["host"] ?? 'localhost';
$dbname = ltrim($url["path"] ?? '/defaultdb', '/');
$user   = $url["user"] ?? 'user';
$password = $url["pass"] ?? '';
$port   = $url["port"] ?? 5432;

// ⏱️ Log du début de connexion
error_log("🔌 Tentative de connexion à la base PostgreSQL...");
$startTime = microtime(true);

try {
    // 🚀 Connexion PDO PostgreSQL
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $password,
        [
            PDO::ATTR_TIMEOUT => 3,                   // Timeout connexion
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Exceptions sur erreurs
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Fetch par défaut
            PDO::ATTR_EMULATE_PREPARES => false,      // Pas d'émulation des requêtes préparées
            // PDO::ATTR_PERSISTENT => true,          // Optionnel : Connexion persistante
        ]
    );

    // ✅ Connexion réussie
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    error_log("✅ Connexion BDD PostgreSQL réussie en {$duration}ms");

} catch (PDOException $e) {
    // ❌ Échec de connexion
    $duration = round((microtime(true) - $startTime) * 1000, 2);

    // 📝 Log détaillé
    error_log("❌ ERREUR BDD PostgreSQL après {$duration}ms:");
    error_log("Code: " . $e->getCode());
    error_log("Message: " . $e->getMessage());
    error_log("Host: $host");
    error_log("Database: $dbname");
    error_log("User: $user");

    // 🚨 Réponse JSON pour le client
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
