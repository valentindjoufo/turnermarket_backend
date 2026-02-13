<?php
// api/login.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // À restreindre en prod
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php'; // Connexion à la DB

try {
    // Lire les données JSON brutes
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'JSON invalide ou vide']);
        exit;
    }

    // Récupérer et nettoyer les champs
    $email = isset($data['email']) ? trim($data['email']) : '';
    $motDePasse = isset($data['motDePasse']) ? trim($data['motDePasse']) : '';

    if (!$email || !$motDePasse) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Email et mot de passe obligatoires']);
        exit;
    }

    // Préparer et exécuter la requête sécurisée
    $stmt = $db->prepare("SELECT id, email, nom, role, mot_de_passe, telephone, nationalite, actif 
                          FROM utilisateurs WHERE email = :email LIMIT 1");
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Email ou mot de passe incorrect']);
        exit;
    }

    // Vérifier si le compte est actif
    if ((int)$user['actif'] === 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Votre compte a été désactivé',
            'utilisateur' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'nom' => $user['nom']
            ]
        ]);
        exit;
    }

    // Vérifier le mot de passe (assume password_hash côté serveur)
    if (!password_verify($motDePasse, $user['mot_de_passe'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Email ou mot de passe incorrect']);
        exit;
    }

    // Connexion réussie
    echo json_encode([
        'success' => true,
        'message' => 'Connexion réussie',
        'utilisateur' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'nom' => $user['nom'],
            'role' => $user['role'],
            'telephone' => $user['telephone'],
            'nationalite' => $user['nationalite']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()]);
}
?>
    