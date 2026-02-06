<?php
// enregistrer_vente_avec_commission.php - VERSION FINALE AVEC NOTIFICATIONS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration
define('COMMISSION_ADMIN_POURCENTAGE', 15);
define('PAYUNIT_API_KEY', 'sand_T4nATpvLqMU0pvxf4CNlkmv0XDAtvy');
define('PAYUNIT_SITE_ID', '9119c933-688d-4db9-9d5f-5b2050a88ad2');
define('PAYUNIT_API_URL', 'https://api-sandbox.payunit.net/v1/payments');
define('MODE_TEST', true);
define('API_NOTIFIER', 'http://10.97.71.236/gestvente/api/notifier.php');

/**
 * 🔔 Envoyer une notification via l'API
 */
function envoyerNotification($conn, $userId, $titre, $message, $type = 'info', $lien = null) {
    try {
        $postData = json_encode([
            'utilisateurId' => $userId,
            'titre' => $titre,
            'message' => $message,
            'type' => $type,
            'lien' => $lien
        ]);
        
        $ch = curl_init(API_NOTIFIER);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            error_log("✅ Notification envoyée à l'utilisateur $userId: $titre");
            return true;
        } else {
            error_log("⚠️ Échec notification user $userId (HTTP $httpCode): $response");
            return false;
        }
    } catch (Exception $e) {
        error_log("❌ Erreur notification: " . $e->getMessage());
        return false;
    }
}

/**
 * 🔔 Notifier toutes les parties d'une transaction
 */
function notifierPartiesTransaction($transactionId, $type, $extraData = []) {
    try {
        $postData = array_merge([
            'action' => 'parties',
            'transactionId' => $transactionId,
            'type' => $type
        ], $extraData);
        
        $ch = curl_init(API_NOTIFIER);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            error_log("✅ Notifications parties envoyées pour transaction $transactionId (type: $type)");
            return true;
        } else {
            error_log("⚠️ Échec notifications parties (HTTP $httpCode): $response");
            return false;
        }
    } catch (Exception $e) {
        error_log("❌ Erreur notifications parties: " . $e->getMessage());
        return false;
    }
}

/**
 * Effectue un paiement Mobile Money via API PayUnit
 */
function effectuerPaiementPayUnitAPI($montant, $numeroTel, $transactionId, $nomClient) {
    $numeroTelFormate = preg_replace('/[^0-9]/', '', $numeroTel);
    if (strlen($numeroTelFormate) === 9) {
        $numeroTelFormate = '237' . $numeroTelFormate;
    }

    $postData = [
        'site_id' => PAYUNIT_SITE_ID,
        'transaction_id' => $transactionId,
        'amount' => (int)$montant,
        'currency' => 'XAF',
        'description' => 'Achat formation en ligne',
        'customer' => [
            'name' => $nomClient,
            'phone_number' => $numeroTelFormate
        ],
        'notify_url' => "http://10.97.71.236/notify.php",
        'return_url' => "http://10.97.71.236/confirmation.php"
    ];

    error_log("=== REQUÊTE API PAYUNIT ===");
    error_log("Data: " . json_encode($postData, JSON_PRETTY_PRINT));

    $ch = curl_init(PAYUNIT_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . PAYUNIT_API_KEY,
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    error_log("HTTP Code: " . $httpCode);
    error_log("Response: " . $response);
    
    if ($curlError) {
        throw new Exception("Erreur de connexion à PayUnit: " . $curlError);
    }

    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new Exception("Erreur API PayUnit (Code $httpCode): " . $response);
    }

    $responseData = json_decode($response, true);
    
    if (!$responseData) {
        throw new Exception("Réponse API PayUnit invalide");
    }

    if (isset($responseData['status']) && $responseData['status'] === 'success') {
        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'payment_token' => $responseData['data']['payment_token'] ?? null,
            'status' => 'PENDING',
            'message' => 'Paiement initié avec succès'
        ];
    }

    if (isset($responseData['data']['payment_url'])) {
        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'payment_url' => $responseData['data']['payment_url'],
            'status' => 'PENDING',
            'message' => 'Confirmez le paiement sur votre téléphone'
        ];
    }

    throw new Exception("Réponse inattendue de PayUnit: " . json_encode($responseData));
}

/**
 * Paiement simulé pour les tests
 */
function effectuerPaiementSimule($montant, $numeroTel, $transactionId, $nomClient) {
    error_log("=== PAIEMENT SIMULÉ (MODE TEST) ===");
    error_log("Montant: $montant FCFA, Client: $nomClient, Tel: $numeroTel");
    sleep(1);
    
    return [
        'success' => true,
        'transaction_id' => $transactionId,
        'status' => 'ACCEPTED',
        'message' => 'Paiement simulé réussi (Mode Test)'
    ];
}

/**
 * Calcule les commissions
 */
function calculerCommissions($montantTotal) {
    $commissionAdmin = ($montantTotal * COMMISSION_ADMIN_POURCENTAGE) / 100;
    $montantVendeur = $montantTotal - $commissionAdmin;
    
    return [
        'montant_total' => $montantTotal,
        'commission_admin' => round($commissionAdmin, 2),
        'montant_vendeur' => round($montantVendeur, 2),
        'pourcentage_commission' => COMMISSION_ADMIN_POURCENTAGE
    ];
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=gestvente;charset=utf8", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['utilisateurId']) || !isset($data['total']) || !isset($data['produits'])) {
        throw new Exception("Données incomplètes");
    }

    $acheteurId = (int) $data['utilisateurId'];
    $total = (float) $data['total'];
    $produits = $data['produits'];
    $modePaiement = $data['modePaiement'] ?? 'Mobile Money';
    $pays = $data['pays'] ?? 'CM';

    if ($total < 100) throw new Exception("Montant minimum: 100 FCFA");
    if ($total > 1000000) throw new Exception("Montant maximum: 1,000,000 FCFA");

    // Infos client
    $stmt = $conn->prepare("SELECT id, nom, telephone, email, role FROM Utilisateur WHERE id = ?");
    $stmt->execute([$acheteurId]);
    $acheteurInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$acheteurInfo) throw new Exception("Utilisateur introuvable");

    $numeroTel = $acheteurInfo['telephone'];
    $nomClient = $acheteurInfo['nom'];
    $emailClient = $acheteurInfo['email'];

    if (empty($numeroTel)) throw new Exception("Aucun numéro de téléphone");

    $numeroTelNettoye = preg_replace('/[^0-9]/', '', $numeroTel);
    if (strlen($numeroTelNettoye) < 9) throw new Exception("Numéro invalide");

    // Traitement produits
    $produitsDetails = [];
    $venteursUniques = [];
    $totalVerifie = 0;
    
    foreach ($produits as $prod) {
        if (!isset($prod['produitId']) || !isset($prod['quantite']) || !isset($prod['prixUnitaire'])) {
            throw new Exception("Données produit incomplètes");
        }

        $produitId = (int) $prod['produitId'];
        $quantite = (int) $prod['quantite'];
        $prixUnitaire = (float) $prod['prixUnitaire'];
        
        if ($quantite <= 0) throw new Exception("Quantité invalide");
        if ($prixUnitaire <= 0) throw new Exception("Prix invalide");

        $stmt = $conn->prepare("
            SELECT p.id, p.titre, p.prix, p.vendeurId, u.nom as vendeurNom, u.email as vendeurEmail
            FROM Produit p 
            INNER JOIN Utilisateur u ON p.vendeurId = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$produitId]);
        $produitInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$produitInfo) throw new Exception("Produit ID $produitId non trouvé");
        
        $produitsDetails[] = $produitInfo;
        
        if (!isset($venteursUniques[$produitInfo['vendeurId']])) {
            $venteursUniques[$produitInfo['vendeurId']] = [
                'id' => $produitInfo['vendeurId'],
                'nom' => $produitInfo['vendeurNom'],
                'email' => $produitInfo['vendeurEmail'],
                'montant_brut' => 0,
                'produits' => []
            ];
        }
        
        $montantProduit = $prixUnitaire * $quantite;
        $venteursUniques[$produitInfo['vendeurId']]['montant_brut'] += $montantProduit;
        $venteursUniques[$produitInfo['vendeurId']]['produits'][] = $produitInfo['titre'];
        $totalVerifie += $montantProduit;
    }

    if (abs($total - $totalVerifie) > 1) {
        throw new Exception("Incohérence dans le calcul du total");
    }

    // Calcul commissions
    $commissionsCalculees = [];
    foreach ($venteursUniques as $vendeurId => $vendeurInfo) {
        $commissions = calculerCommissions($vendeurInfo['montant_brut']);
        $commissionsCalculees[$vendeurId] = array_merge($vendeurInfo, $commissions);
    }

    $transactionId = 'PAYUNIT_' . time() . '_' . rand(1000, 9999);

    error_log("=== DÉBUT TRAITEMENT PAIEMENT ===");
    error_log("Client: $nomClient (ID: $acheteurId)");
    error_log("Montant: $total FCFA");
    error_log("Transaction ID: $transactionId");

    // Paiement Mobile Money
    if ($modePaiement === 'Mobile Money') {
        try {
            if (MODE_TEST) {
                $paymentResult = effectuerPaiementSimule($total, $numeroTel, $transactionId, $nomClient);
            } else {
                $paymentResult = effectuerPaiementPayUnitAPI($total, $numeroTel, $transactionId, $nomClient);
            }
            
            if (!isset($paymentResult['success']) || !$paymentResult['success']) {
                throw new Exception("Le paiement a échoué");
            }

            $conn->beginTransaction();

            $statutVente = MODE_TEST ? 'confirme' : 'en_attente';
            
            $stmt = $conn->prepare("
                INSERT INTO Vente (utilisateurId, total, modePaiement, numeroMobile, pays, transactionId, date, statut)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([
                $acheteurId, 
                $total, 
                'Mobile Money - PayUnit', 
                $numeroTel, 
                $pays,
                $transactionId,
                $statutVente
            ]);
            $venteId = $conn->lastInsertId();

            error_log("Vente enregistrée - ID: $venteId (statut: $statutVente)");

            // Enregistrer produits
            $achetee = MODE_TEST ? 1 : 0;
            
            $stmtInsertProduit = $conn->prepare("
                INSERT INTO VenteProduit (venteId, produitId, quantite, prixUnitaire, achetee, vendeurId)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($produits as $prod) {
                $vendeurId = null;
                foreach ($produitsDetails as $detail) {
                    if ($detail['id'] == $prod['produitId']) {
                        $vendeurId = $detail['vendeurId'];
                        break;
                    }
                }
                
                if (!$vendeurId) throw new Exception("Vendeur non trouvé");
                
                $stmtInsertProduit->execute([
                    $venteId,
                    $prod['produitId'],
                    $prod['quantite'],
                    $prod['prixUnitaire'],
                    $achetee,
                    $vendeurId
                ]);
            }

            // ENREGISTREMENT COMMISSIONS
            $stmtCommission = $conn->prepare("
                INSERT INTO Commission (venteId, vendeurId, montantTotal, montantVendeur, montantAdmin, pourcentageCommission, statut, dateCreation, dateTraitement)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");

            $stmtUpdateSolde = $conn->prepare("
                UPDATE Utilisateur 
                SET soldeVendeur = soldeVendeur + ?, nbVentes = nbVentes + 1 
                WHERE id = ?
            ");

            foreach ($commissionsCalculees as $vendeurId => $commission) {
                $statutCommission = MODE_TEST ? 'disponible' : 'en_attente';
                $dateTraitement = MODE_TEST ? date('Y-m-d H:i:s') : null;
                
                $stmtCommission->execute([
                    $venteId,
                    $vendeurId,
                    $commission['montant_total'],
                    $commission['montant_vendeur'],
                    $commission['commission_admin'],
                    COMMISSION_ADMIN_POURCENTAGE,
                    $statutCommission,
                    $dateTraitement
                ]);

                if (MODE_TEST) {
                    $stmtUpdateSolde->execute([
                        $commission['montant_vendeur'],
                        $vendeurId
                    ]);
                    error_log("✅ Vendeur $vendeurId crédité: " . $commission['montant_vendeur'] . " FCFA");
                }
            }

            $conn->commit();

            // 🔔 NOTIFICATIONS AUTOMATIQUES
            if (MODE_TEST && $statutVente === 'confirme') {
                error_log("🔔 Envoi des notifications...");
                notifierPartiesTransaction($transactionId, 'paiement_confirme');
            } else {
                // En production, notifier que le paiement est en attente
                envoyerNotification(
                    $conn,
                    $acheteurId,
                    '⏳ Paiement en cours',
                    "Votre paiement de $total FCFA est en cours de traitement. Vous recevrez une confirmation sous peu.",
                    'info',
                    '/mes-achats'
                );
            }

            error_log("=== PAIEMENT RÉUSSI ===");

            echo json_encode([
                "success" => true,
                "message" => MODE_TEST ? "✅ Paiement simulé réussi ! Notifications envoyées." : "Paiement initié. Confirmez sur votre téléphone.",
                "transaction_id" => $transactionId,
                "vente_id" => $venteId,
                "statut" => $statutVente,
                "mode" => MODE_TEST ? "TEST" : "PRODUCTION",
                "payment_url" => $paymentResult['payment_url'] ?? null,
                "client_info" => [
                    "nom" => $nomClient,
                    "telephone" => $numeroTel,
                    "email" => $emailClient
                ],
                "commissions" => $commissionsCalculees,
                "details" => [
                    "montant_total" => $total,
                    "nombre_produits" => count($produits),
                    "nombre_vendeurs" => count($venteursUniques)
                ]
            ]);

        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("ERREUR PAIEMENT: " . $e->getMessage());
            throw $e;
        }

    } else {
        // Autres modes de paiement
        $conn->beginTransaction();

        $stmt = $conn->prepare("
            INSERT INTO Vente (utilisateurId, total, modePaiement, numeroMobile, pays, transactionId, date, statut)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 'confirme')
        ");
        $stmt->execute([$acheteurId, $total, $modePaiement, $numeroTel, $pays, $transactionId]);
        $venteId = $conn->lastInsertId();

        $stmtInsertProduit = $conn->prepare("
            INSERT INTO VenteProduit (venteId, produitId, quantite, prixUnitaire, achetee, vendeurId)
            VALUES (?, ?, ?, ?, 1, ?)
        ");

        foreach ($produits as $prod) {
            $vendeurId = null;
            foreach ($produitsDetails as $detail) {
                if ($detail['id'] == $prod['produitId']) {
                    $vendeurId = $detail['vendeurId'];
                    break;
                }
            }
            
            $stmtInsertProduit->execute([
                $venteId,
                $prod['produitId'],
                $prod['quantite'],
                $prod['prixUnitaire'],
                $vendeurId
            ]);
        }

        $stmtCommission = $conn->prepare("
            INSERT INTO Commission (venteId, vendeurId, montantTotal, montantVendeur, montantAdmin, pourcentageCommission, statut, dateCreation, dateTraitement)
            VALUES (?, ?, ?, ?, ?, ?, 'disponible', NOW(), NOW())
        ");

        $stmtUpdateSolde = $conn->prepare("
            UPDATE Utilisateur 
            SET soldeVendeur = soldeVendeur + ?, nbVentes = nbVentes + 1 
            WHERE id = ?
        ");

        foreach ($commissionsCalculees as $vendeurId => $commission) {
            $stmtCommission->execute([
                $venteId,
                $vendeurId,
                $commission['montant_total'],
                $commission['montant_vendeur'],
                $commission['commission_admin'],
                COMMISSION_ADMIN_POURCENTAGE
            ]);

            $stmtUpdateSolde->execute([
                $commission['montant_vendeur'],
                $vendeurId
            ]);
        }

        $conn->commit();

        // 🔔 Notifications
        notifierPartiesTransaction($transactionId, 'paiement_confirme');

        echo json_encode([
            "success" => true,
            "message" => "✅ Vente enregistrée avec succès. Notifications envoyées.",
            "transaction_id" => $transactionId,
            "vente_id" => $venteId,
            "commissions" => $commissionsCalculees
        ]);
    }

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("=== ERREUR GLOBALE ===");
    error_log($e->getMessage());
    
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
        "mode" => MODE_TEST ? 'TEST' : 'PRODUCTION'
    ]);
}
?>