<?php
// Inclure la configuration de connexion à la base de données
require_once 'config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Gestion des requêtes pré-vol OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // La variable $pdo est déjà définie dans config.php

    // Lire l'ID utilisateur depuis les paramètres GET
    $utilisateurId = isset($_GET['id']) ? intval($_GET['id']) : null;

    if (!$utilisateurId) {
        throw new Exception("ID utilisateur manquant.");
    }

    // Récupérer le nom de l'utilisateur
    $stmtUser = $pdo->prepare("SELECT nom FROM Utilisateur WHERE id = ?");
    $stmtUser->execute([$utilisateurId]);
    $utilisateur = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$utilisateur) {
        throw new Exception("Utilisateur non trouvé.");
    }

    // Récupérer les ventes de cet utilisateur
    $stmt = $pdo->prepare("SELECT id, total, date FROM Vente WHERE utilisateurId = ? ORDER BY date DESC");
    $stmt->execute([$utilisateurId]);
    $ventes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultat = [];

    foreach ($ventes as $vente) {
        // Récupérer les produits achetés pour cette vente
        $stmtProduits = $pdo->prepare("
            SELECT p.titre, vp.quantite, vp.prixUnitaire
            FROM VenteProduit vp
            JOIN Produit p ON p.id = vp.produitId
            WHERE vp.venteId = ?
        ");
        $stmtProduits->execute([$vente['id']]);
        $produits = $stmtProduits->fetchAll(PDO::FETCH_ASSOC);

        $vente['produits'] = $produits;
        $resultat[] = $vente;
    }

    // Retourner aussi le nom de l'utilisateur
    echo json_encode([
        "success" => true,
        "utilisateurNom" => $utilisateur['nom'],
        "ventes" => $resultat
    ]);
} catch (Exception $e) {
    error_log("❌ Erreur dans get_user_sales.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Erreur : " . $e->getMessage()
    ]);
}
?>