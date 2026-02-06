<?php
// api_retrait_immediat.php - Version corrigée avec gestion d'erreurs améliorée
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Désactiver l'affichage des erreurs PHP (importantes pour éviter l'erreur HTML)
ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration
define('MONTANT_MINIMUM_RETRAIT', 1000);
define('FRAIS_RETRAIT_POURCENTAGE', 2);
define('FRAIS_RETRAIT_FIXE', 100);

// Variable pour la connexion
$conn = null;

try {
    $conn = new PDO("mysql:host=localhost;dbname=gestvente;charset=utf8", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $method = $_SERVER['REQUEST_METHOD'];

    // ========================================
    // POST - CRÉER UNE DEMANDE DE RETRAIT IMMÉDIAT
    // ========================================
    if ($method === 'POST') {
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Données JSON invalides: " . json_last_error_msg());
        }

        // Validation des données
        if (!isset($data['utilisateurId']) || !isset($data['montant'])) {
            throw new Exception("Données incomplètes : utilisateurId et montant requis");
        }

        $utilisateurId = (int) $data['utilisateurId'];
        $montantDemande = (float) $data['montant'];
        $methodePaiement = $data['methodePaiement'] ?? 'orange_money';
        $numeroCompte = $data['numeroCompte'] ?? null;

        // Validation du montant
        if ($montantDemande < MONTANT_MINIMUM_RETRAIT) {
            throw new Exception("Montant minimum : " . number_format(MONTANT_MINIMUM_RETRAIT, 0, ',', ' ') . " FCFA");
        }

        if ($montantDemande <= 0) {
            throw new Exception("Montant invalide");
        }

        if (empty($numeroCompte)) {
            throw new Exception("Numéro de compte requis");
        }

        // Récupérer les informations utilisateur et son solde
        $stmt = $conn->prepare("
            SELECT 
                u.id, 
                u.nom, 
                u.email, 
                u.role,
                u.telephone,
                CASE 
                    WHEN u.role = 'admin' THEN (
                        SELECT COALESCE(SUM(CASE WHEN statut = 'paye' THEN montantAdmin ELSE 0 END), 0)
                        FROM Commission
                    )
                    ELSE (
                        SELECT COALESCE(SUM(CASE WHEN statut = 'paye' THEN montantVendeur ELSE 0 END), 0)
                        FROM Commission
                        WHERE vendeurId = :userId
                    )
                END as soldeDisponible
            FROM Utilisateur u
            WHERE u.id = :userId
        ");
        $stmt->execute(['userId' => $utilisateurId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception("Utilisateur introuvable");
        }

        $soldeDisponible = (float) $user['soldeDisponible'];

        // Calculer les frais
        $fraisCalcules = ceil(($montantDemande * FRAIS_RETRAIT_POURCENTAGE / 100) + FRAIS_RETRAIT_FIXE);
        $montantTotal = $montantDemande + $fraisCalcules;

        // Vérifier le solde suffisant
        if ($soldeDisponible < $montantTotal) {
            throw new Exception(
                "Solde insuffisant. Disponible : " . number_format($soldeDisponible, 0, ',', ' ') . 
                " FCFA. Requis : " . number_format($montantTotal, 0, ',', ' ') . 
                " FCFA (dont " . number_format($fraisCalcules, 0, ',', ' ') . " FCFA de frais)"
            );
        }

        // ✅ DÉMARRER LA TRANSACTION
        $conn->beginTransaction();

        try {
            // 1️⃣ Créer la demande de retrait
            $stmt = $conn->prepare("
                INSERT INTO DemandeRetrait (
                    utilisateurId, 
                    montant, 
                    methodePaiement, 
                    numeroCompte, 
                    statut, 
                    dateDemande,
                    dateTraitement,
                    commentaire
                )
                VALUES (:userId, :montant, :methode, :numero, 'paye', NOW(), NOW(), 'Retrait immédiat automatique')
            ");
            $stmt->execute([
                'userId' => $utilisateurId,
                'montant' => $montantDemande,
                'methode' => $methodePaiement,
                'numero' => $numeroCompte
            ]);
            $demandeId = $conn->lastInsertId();

            // 2️⃣ DÉBITER LE SOLDE - Marquer les commissions comme "retire"
            $montantRestant = $montantTotal;
            
            if ($user['role'] === 'admin') {
                // Pour l'admin : récupérer et débiter ses commissions
                $stmt = $conn->prepare("
                    SELECT id, montantAdmin 
                    FROM Commission 
                    WHERE statut = 'paye'
                    ORDER BY dateCreation ASC
                ");
                $stmt->execute();
                $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $stmtUpdate = $conn->prepare("
                    UPDATE Commission 
                    SET statut = 'retire', dateTraitement = NOW() 
                    WHERE id = :commId
                ");
                
                foreach ($commissions as $commission) {
                    if ($montantRestant <= 0) break;
                    
                    if ($commission['montantAdmin'] <= $montantRestant) {
                        $stmtUpdate->execute(['commId' => $commission['id']]);
                        $montantRestant -= $commission['montantAdmin'];
                    } else {
                        // Cas où on doit débiter partiellement (rare mais possible)
                        $stmtUpdate->execute(['commId' => $commission['id']]);
                        break;
                    }
                }
                
            } else {
                // Pour un vendeur : récupérer et débiter ses commissions
                $stmt = $conn->prepare("
                    SELECT id, montantVendeur 
                    FROM Commission 
                    WHERE vendeurId = :userId AND statut = 'paye'
                    ORDER BY dateCreation ASC
                ");
                $stmt->execute(['userId' => $utilisateurId]);
                $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $stmtUpdate = $conn->prepare("
                    UPDATE Commission 
                    SET statut = 'retire', dateTraitement = NOW() 
                    WHERE id = :commId
                ");
                
                foreach ($commissions as $commission) {
                    if ($montantRestant <= 0) break;
                    
                    if ($commission['montantVendeur'] <= $montantRestant) {
                        $stmtUpdate->execute(['commId' => $commission['id']]);
                        $montantRestant -= $commission['montantVendeur'];
                    } else {
                        $stmtUpdate->execute(['commId' => $commission['id']]);
                        break;
                    }
                }
            }

            // 3️⃣ Créer une notification
            $stmt = $conn->prepare("
                INSERT INTO Notification (utilisateurId, titre, message, type, dateCreation, estLu)
                VALUES (:userId, :titre, :message, 'success', NOW(), 0)
            ");
            $message = "✅ Votre retrait de " . number_format($montantDemande, 0, ',', ' ') . 
                       " FCFA a été effectué avec succès via " . ucfirst(str_replace('_', ' ', $methodePaiement)) . 
                       ". Vous le recevrez sous 24-48h au " . $numeroCompte . ".";
            
            $stmt->execute([
                'userId' => $utilisateurId,
                'titre' => 'Retrait effectué',
                'message' => $message
            ]);

            // 4️⃣ VALIDER LA TRANSACTION
            $conn->commit();

            // Récupérer le nouveau solde
            $stmt = $conn->prepare("
                SELECT 
                    CASE 
                        WHEN role = 'admin' THEN (
                            SELECT COALESCE(SUM(CASE WHEN statut = 'paye' THEN montantAdmin ELSE 0 END), 0)
                            FROM Commission
                        )
                        ELSE (
                            SELECT COALESCE(SUM(CASE WHEN statut = 'paye' THEN montantVendeur ELSE 0 END), 0)
                            FROM Commission
                            WHERE vendeurId = :userId
                        )
                    END as nouveauSolde
                FROM Utilisateur 
                WHERE id = :userId
            ");
            $stmt->execute(['userId' => $utilisateurId]);
            $nouveauSolde = (float) $stmt->fetchColumn();

            error_log("✅ RETRAIT IMMÉDIAT RÉUSSI - User: $utilisateurId, Montant: $montantDemande FCFA");

            echo json_encode([
                "success" => true,
                "message" => "Retrait effectué avec succès !",
                "data" => [
                    "demande_id" => $demandeId,
                    "montant_retire" => $montantDemande,
                    "frais" => $fraisCalcules,
                    "montant_total_debite" => $montantTotal,
                    "ancien_solde" => $soldeDisponible,
                    "nouveau_solde" => $nouveauSolde,
                    "methode_paiement" => $methodePaiement,
                    "numero_compte" => $numeroCompte,
                    "date_retrait" => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (Exception $e) {
            // Rollback en cas d'erreur durant la transaction
            $conn->rollBack();
            throw $e;
        }

    // ========================================
    // GET - RÉCUPÉRER L'HISTORIQUE DES RETRAITS
    // ========================================
    } elseif ($method === 'GET') {
        $utilisateurId = $_GET['userId'] ?? null;
        $role = $_GET['role'] ?? 'client';

        if (!$utilisateurId) {
            throw new Exception("ID utilisateur requis");
        }

        if ($role === 'admin') {
            $stmt = $conn->query("
                SELECT 
                    dr.*,
                    u.nom as utilisateurNom,
                    u.email as utilisateurEmail,
                    u.role
                FROM DemandeRetrait dr
                INNER JOIN Utilisateur u ON dr.utilisateurId = u.id
                ORDER BY dr.dateDemande DESC
                LIMIT 100
            ");
            $retraits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $conn->prepare("
                SELECT * FROM DemandeRetrait 
                WHERE utilisateurId = :userId 
                ORDER BY dateDemande DESC
                LIMIT 50
            ");
            $stmt->execute(['userId' => $utilisateurId]);
            $retraits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            "success" => true,
            "data" => $retraits
        ]);

    } else {
        throw new Exception("Méthode HTTP non supportée");
    }

} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("❌ ERREUR PDO : " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Erreur de base de données",
        "error_details" => $e->getMessage()
    ]);
    
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("❌ ERREUR API RETRAIT : " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>