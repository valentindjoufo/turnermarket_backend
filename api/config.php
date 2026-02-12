<?php
// config.php – Initialisation de la connexion PDO, sans sortie lors de l'inclusion
error_reporting(E_ALL);
ini_set('display_errors', 1); // À désactiver en production

header("Content-Type: application/json; charset=UTF-8");

$databaseUrl = getenv("DATABASE_URL");
if (!$databaseUrl) {
    if (basename($_SERVER['PHP_SELF']) === 'config.php') {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'DATABASE_URL non définie']);
        exit;
    } else {
        throw new Exception('DATABASE_URL non définie');
    }
}

$parsed = parse_url($databaseUrl);
$host   = $parsed['host'] ?? 'localhost';
$port   = $parsed['port'] ?? 5432;
$user   = $parsed['user'] ?? 'postgres';
$pass   = $parsed['pass'] ?? '';
$db     = ltrim($parsed['path'] ?? '/defaultdb', '/');

try {
    $conn = new PDO(
        "pgsql:host=$host;port=$port;dbname=$db",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    if (basename($_SERVER['PHP_SELF']) === 'config.php') {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'Service temporairement indisponible',
            'message' => 'Impossible de se connecter à la base de données',
            'debug' => [
                'error' => $e->getMessage(),
                'host' => $host,
                'database' => $db,
                'user' => $user,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ]);
        exit;
    } else {
        throw $e;
    }
}

$pdo = $conn;

// Si exécuté directement, confirmer la connexion
if (basename($_SERVER['PHP_SELF']) === 'config.php') {
    echo json_encode([
        'success' => true,
        'message' => 'Connexion à la base de données réussie',
        'database' => $db,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}
?>