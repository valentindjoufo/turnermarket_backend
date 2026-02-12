<?php
// Activer l'affichage des erreurs (à retirer en production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Forcer le type JSON
header("Content-Type: application/json; charset=UTF-8");

// Récupérer l'URL de la base de données depuis l'environnement Render
$databaseUrl = getenv("DATABASE_URL");
if (!$databaseUrl) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DATABASE_URL non définie'
    ]);
    exit;
}

$parsed = parse_url($databaseUrl);

$host = $parsed['host'] ?? 'localhost';
$port = $parsed['port'] ?? 5432;
$user = $parsed['user'] ?? 'postgres';
$pass = $parsed['pass'] ?? '';
$db   = ltrim($parsed['path'] ?? '/defaultdb', '/');

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
}

$pdo = $conn;

// ✅ RÉPONSE DE SUCCÈS – ENVOI D'UN JSON
echo json_encode([
    'success' => true,
    'message' => 'Connexion à la base de données réussie',
    'database' => $db,
    'timestamp' => date('Y-m-d H:i:s')
]);
exit;