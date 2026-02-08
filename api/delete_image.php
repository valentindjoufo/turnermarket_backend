<?php
/**
 * delete_image.php - Suppression d'images
 * Version avec inclusion de config.php
 */

// 📦 Inclusion de la configuration (même si non utilisée ici, pour la cohérence)
require_once 'config.php';

// 🚦 Configuration des headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

try {
    // 📥 Vérification de l'action et du nom de fichier
    $action = $_POST['action'] ?? '';
    $fileName = $_POST['fileName'] ?? null;

    if ($action !== 'delete' || !$fileName) {
        throw new Exception('Paramètres manquants ou invalides');
    }

    // 🔒 Sécurisation du nom de fichier
    $fileName = basename($fileName);
    
    // 📁 Définition du chemin du fichier
    $uploadDir = '../uploads/';
    
    // 🛡️ Validation du chemin (empêche les traversées de répertoires)
    $filePath = realpath($uploadDir . $fileName);
    $baseDir = realpath($uploadDir);
    
    if (!$filePath || strpos($filePath, $baseDir) !== 0) {
        throw new Exception('Chemin de fichier invalide');
    }

    // 🗑️ Vérification et suppression du fichier
    if (!file_exists($filePath)) {
        throw new Exception('Fichier non trouvé');
    }

    if (!is_writable($filePath)) {
        throw new Exception('Permission de suppression refusée');
    }

    if (unlink($filePath)) {
        // ✅ Succès
        error_log("✅ Image supprimée: " . $fileName);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Image supprimée avec succès',
            'file_name' => $fileName
        ]);
    } else {
        throw new Exception('Impossible de supprimer le fichier');
    }

} catch (Exception $e) {
    // ❌ Gestion des erreurs
    error_log("❌ ERREUR SUPPRESSION IMAGE: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
?>