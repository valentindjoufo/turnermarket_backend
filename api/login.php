<?php
// ===========================================
// login.php - Réception JSON et connexion
// ===========================================

// Active l'affichage des erreurs (optionnel en dev)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Entêtes pour React Native et JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // à limiter en prod
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

// Gestion des prérequis OPTIONS (React Native fait parfois un preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Connexion DB (modifie selon ton config)
require_once 'config.php'; // contient $db PDO

try {
    // Lire le JSON envoyé depuis React Native
    $input = json_decode(file_get_contents('php://input'), true);

    // Récupération email et mot de passe
    $email = isset($input['email']) ? trim($input['email']) : null;
    $motDePasse = isset($input['motDePasse']) ? trim($input['motDePasse']) : null;

    // Vérifier présence email et mot de passe
    if (!$email || !$motDePasse) {
        echo json_encode([
            "success" => false,
            "message" => "Email et mot de passe requis"
        ]);
        exit;
    }

    // Préparer requête sécurisée
    $stmt = $db->prepare("SELECT id, nom, email, mot_de_passe, role, telephone, nationalite, etat_compte 
                          FROM utilisateurs WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode([
            "success" => false,
            "message" => "Email ou mot de passe incorrect"
        ]);
        exit;
    }

    // Vérifier si compte désactivé
    if ($user['etat_compte'] === 'desactive') {
        echo json_encode([
            "success" => false,
            "message" => "Votre compte a été désactivé",
            "utilisateur" => [
                "id" => $user['id'],
                "email" => $user['email'],
                "nom" => $user['nom']
            ]
        ]);
        exit;
    }

    // Vérifier mot de passe (en supposant hashé avec password_hash)
    if (!password_verify($motDePasse, $user['mot_de_passe'])) {
        echo json_encode([
            "success" => false,
            "message" => "Email ou mot de passe incorrect"
        ]);
        exit;
    }

    // Connexion réussie
    echo json_encode([
        "success" => true,
        "message" => "Connexion réussie",
        "utilisateur" => [
            "id" => $user['id'],
            "email" => $user['email'],
            "nom" => $user['nom'],
            "role" => $user['role'],
            "telephone" => $user['telephone'],
            "nationalite" => $user['nationalite']
        ]
    ]);

} catch (Exception $e) {
    // Gestion des erreurs
    echo json_encode([
        "success" => false,
        "message" => "Erreur serveur: " . $e->getMessage()
    ]);
    exit;
}
