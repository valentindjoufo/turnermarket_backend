<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';

if ($_POST['action'] === 'delete' && isset($_POST['fileName'])) {
    $fileName = basename($_POST['fileName']); // Sécuriser le nom de fichier
    $uploadDir = '../uploads/';
    $filePath = $uploadDir . $fileName;
    
    if (file_exists($filePath)) {
        if (unlink($filePath)) {
            echo json_encode(['success' => true, 'message' => 'Image supprimée']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Impossible de supprimer le fichier']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Fichier non trouvé']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Paramètres manquants']);
}
?>