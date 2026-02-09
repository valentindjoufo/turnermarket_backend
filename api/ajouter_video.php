<?php
// 📁 Inclure les configurations
require_once 'config.php';
require_once 'cloudflare-config.php';

use Aws\S3\S3Client;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// Gestion CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Fonction de log
function logDebug($message) {
    $logFile = __DIR__ . '/../logs/video_upload.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Fonction : Upload vers Cloudflare R2 et retourner l'URL
function uploadToR2AndGetUrl($filePath, $originalName, $produitId, $type = 'video', $segmentNum = null) {
    try {
        // Générer une clé d'objet pour R2
        $objectKey = generateR2ObjectKey($originalName, $produitId, $type, $segmentNum);
        
        logDebug("📤 Upload vers Cloudflare R2: $objectKey");
        
        // Upload vers Cloudflare R2
        $publicUrl = uploadToCloudflareR2($filePath, $objectKey);
        
        logDebug("✅ Upload réussi: $publicUrl");
        
        // Retourner la clé (pour stockage en BD) et l'URL publique
        return [
            'object_key' => $objectKey,
            'public_url' => $publicUrl
        ];
        
    } catch (Exception $e) {
        logDebug("❌ Erreur upload R2: " . $e->getMessage());
        throw new Exception("Erreur lors de l'upload vers Cloudflare R2: " . $e->getMessage());
    }
}

// Fonction : Découpage vidéo avec FFmpeg
function couperVideo($videoPath, $outputDir, $ffmpegPath, $segmentTime = 900) {
    if (!is_dir($outputDir)) {
        if (!mkdir($outputDir, 0755, true)) {
            throw new Exception("Impossible de créer le dossier: $outputDir");
        }
    }

    // Nettoyer segments existants
    $existingSegments = glob($outputDir . "/*.mp4");
    foreach ($existingSegments as $segment) {
        unlink($segment);
    }

    // Commande FFmpeg
    $cmd = "\"$ffmpegPath\" -i \"$videoPath\" -c copy -map 0 -segment_time $segmentTime -f segment -reset_timestamps 1 \"$outputDir/part_%03d.mp4\" 2>&1";

    logDebug("Commande FFmpeg: " . $cmd);
    
    exec($cmd, $output, $return_var);
    
    logDebug("Sortie FFmpeg: " . implode("\n", $output));
    logDebug("Code retour: $return_var");

    if ($return_var !== 0) {
        throw new Exception("Erreur découpage: " . implode("\n", $output));
    }

    // Récupérer fichiers créés
    $segments = glob($outputDir . "/part_*.mp4");
    sort($segments);
    
    if (empty($segments)) {
        throw new Exception("Aucun segment créé");
    }

    logDebug("Segments créés (" . count($segments) . "): " . implode(", ", $segments));
    
    return $segments;
}

// Fonction : Gestion découpage vidéos avec upload vers R2
function gererDecoupageEtUploadR2($videoPath, $previewPath, $videoFilename, $previewFilename, $ffmpegPath, $segmentsBaseDir, $produitId, $isFree = false) {
    $result = [
        'videoSegments' => [],
        'previewSegments' => [],
        'videoUrls' => [], // URLs Cloudflare R2 pour chaque segment
        'previewUrls' => [], // URLs Cloudflare R2 pour chaque segment preview
        'videoUrl' => '', // URL du premier segment (ou vidéo complète)
        'previewUrl' => '' // URL du premier segment preview (ou preview complet)
    ];
    
    // Découper vidéo principale
    $segmentsDir = $segmentsBaseDir . pathinfo($videoFilename, PATHINFO_FILENAME);
    
    if (!file_exists($ffmpegPath)) {
        logDebug("ATTENTION: FFmpeg non trouvé: $ffmpegPath");
        
        // Uploader la vidéo complète vers R2
        $uploadResult = uploadToR2AndGetUrl($videoPath, $videoFilename, $produitId, 'video');
        $result['videoSegments'] = [$videoPath];
        $result['videoUrls'] = [$uploadResult['object_key']];
        $result['videoUrl'] = $uploadResult['object_key'];
        
        // Supprimer le fichier local après upload
        if (file_exists($videoPath)) {
            unlink($videoPath);
            logDebug("Vidéo locale supprimée après upload R2");
        }
        
    } else {
        logDebug("FFmpeg trouvé, découpage vidéo principale...");
        
        try {
            $videoSegments = couperVideo($videoPath, $segmentsDir, $ffmpegPath, 900);
            
            // Uploader chaque segment vers R2
            $segmentIndex = 1;
            foreach ($videoSegments as $segment) {
                $uploadResult = uploadToR2AndGetUrl($segment, $videoFilename, $produitId, 'video', $segmentIndex);
                $result['videoSegments'][] = $segment;
                $result['videoUrls'][] = $uploadResult['object_key'];
                
                // Supprimer le segment local après upload
                unlink($segment);
                
                if ($segmentIndex === 1) {
                    $result['videoUrl'] = $uploadResult['object_key'];
                }
                
                $segmentIndex++;
            }
            
            // Supprimer le dossier des segments locaux
            if (is_dir($segmentsDir)) {
                rmdir($segmentsDir);
            }
            
            // Supprimer vidéo originale après découpage et upload
            if (file_exists($videoPath)) {
                unlink($videoPath);
                logDebug("Vidéo originale supprimée");
            }
            
            logDebug("Vidéo découpée et uploadée vers R2: " . count($videoSegments) . " segments");
            
        } catch (Exception $e) {
            logDebug("Erreur découpage: " . $e->getMessage());
            
            // Fallback: uploader la vidéo complète
            $uploadResult = uploadToR2AndGetUrl($videoPath, $videoFilename, $produitId, 'video');
            $result['videoSegments'] = [$videoPath];
            $result['videoUrls'] = [$uploadResult['object_key']];
            $result['videoUrl'] = $uploadResult['object_key'];
            
            // Supprimer le fichier local après upload
            if (file_exists($videoPath)) {
                unlink($videoPath);
            }
        }
    }
    
    // Découper vidéo d'aperçu
    if ($isFree && $previewPath && file_exists($previewPath)) {
        $previewSegmentsDir = $segmentsBaseDir . 'preview_' . pathinfo($previewFilename, PATHINFO_FILENAME);
        
        if (!file_exists($ffmpegPath)) {
            logDebug("FFmpeg non trouvé, upload preview complet");
            
            // Uploader le preview complet vers R2
            $uploadResult = uploadToR2AndGetUrl($previewPath, $previewFilename, $produitId, 'preview');
            $result['previewSegments'] = [$previewPath];
            $result['previewUrls'] = [$uploadResult['object_key']];
            $result['previewUrl'] = $uploadResult['object_key'];
            
            // Supprimer le fichier local après upload
            if (file_exists($previewPath)) {
                unlink($previewPath);
                logDebug("Preview local supprimé après upload R2");
            }
            
        } else {
            logDebug("Découpage vidéo d'aperçu...");
            
            try {
                $previewSegments = couperVideo($previewPath, $previewSegmentsDir, $ffmpegPath, 900);
                
                // Uploader chaque segment preview vers R2
                $segmentIndex = 1;
                foreach ($previewSegments as $segment) {
                    $uploadResult = uploadToR2AndGetUrl($segment, $previewFilename, $produitId, 'preview', $segmentIndex);
                    $result['previewSegments'][] = $segment;
                    $result['previewUrls'][] = $uploadResult['object_key'];
                    
                    // Supprimer le segment local après upload
                    unlink($segment);
                    
                    if ($segmentIndex === 1) {
                        $result['previewUrl'] = $uploadResult['object_key'];
                    }
                    
                    $segmentIndex++;
                }
                
                // Supprimer le dossier des segments locaux
                if (is_dir($previewSegmentsDir)) {
                    rmdir($previewSegmentsDir);
                }
                
                // Supprimer preview original après découpage et upload
                if (file_exists($previewPath)) {
                    unlink($previewPath);
                    logDebug("Preview original supprimé");
                }
                
                logDebug("Preview découpé et uploadé vers R2: " . count($previewSegments) . " segments");
                
            } catch (Exception $e) {
                logDebug("Erreur découpage preview: " . $e->getMessage());
                
                // Fallback: uploader le preview complet
                $uploadResult = uploadToR2AndGetUrl($previewPath, $previewFilename, $produitId, 'preview');
                $result['previewSegments'] = [$previewPath];
                $result['previewUrls'] = [$uploadResult['object_key']];
                $result['previewUrl'] = $uploadResult['object_key'];
                
                // Supprimer le fichier local après upload
                if (file_exists($previewPath)) {
                    unlink($previewPath);
                }
            }
        }
    } elseif ($previewPath && file_exists($previewPath)) {
        // Uploader le preview complet vers R2 (pas de découpage)
        $uploadResult = uploadToR2AndGetUrl($previewPath, $previewFilename, $produitId, 'preview');
        $result['previewSegments'] = [$previewPath];
        $result['previewUrls'] = [$uploadResult['object_key']];
        $result['previewUrl'] = $uploadResult['object_key'];
        
        // Supprimer le fichier local après upload
        if (file_exists($previewPath)) {
            unlink($previewPath);
        }
    }
    
    return $result;
}

try {
    // ACTIVER L'AFFICHAGE DES ERREURS POUR DÉBOGUER
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    logDebug("=== DÉBUT UPLOAD VIDÉO CLOUDFLARE R2 ===");
    logDebug("Méthode: " . $_SERVER['REQUEST_METHOD']);
    logDebug("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'Non défini'));
    
    // LOGS DÉTAILLÉS POUR DÉBOGUER LES DONNÉES REÇUES
    logDebug("Données POST reçues: " . print_r($_POST, true));
    logDebug("Fichiers reçus: " . print_r($_FILES, true));

    // 1. Validation données POST avec débogage détaillé
    $titre = isset($_POST['titre']) ? trim($_POST['titre']) : '';
    
    // Log détaillé pour le titre
    logDebug("Titre brut reçu: '" . ($_POST['titre'] ?? 'NON DÉFINI') . "'");
    logDebug("Titre après trim: '$titre'");
    logDebug("Titre vide? " . (empty($titre) ? 'OUI' : 'NON'));
    
    // Vérifier si le titre est vide ou null
    if ($titre === null || $titre === '' || empty($titre)) {
        logDebug("ERREUR: Titre est null, chaîne vide ou contient uniquement des espaces");
        throw new Exception('Le titre est obligatoire');
    }
    
    $produitId = isset($_POST['produitId']) ? intval($_POST['produitId']) : 0;
    $ordre = isset($_POST['ordre']) ? intval($_POST['ordre']) : 1;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $userId = isset($_POST['userId']) ? intval($_POST['userId']) : 0;
    $is_free = isset($_POST['is_free']) ? intval($_POST['is_free']) : 0;
    
    logDebug("Données - Titre: '$titre', ProduitID: $produitId, Ordre: $ordre, UserID: $userId, is_free: $is_free");
    logDebug("Description: '$description'");
    
    if ($produitId <= 0) {
        throw new Exception('ID formation invalide');
    }
    
    if ($userId <= 0) {
        throw new Exception('ID utilisateur invalide');
    }

    // ✅ Connexion PostgreSQL déjà établie via config.php
    if (!isset($pdo) && isset($conn)) {
        $pdo = $conn; // Utiliser $conn si $pdo n'est pas défini
    }
    
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Connexion à la base de données non disponible');
    }

    // 3. Vérification formation
    $stmt = $pdo->prepare("SELECT id, vendeurId FROM Produit WHERE id = ?");
    $stmt->execute([$produitId]);
    $formation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$formation) {
        throw new Exception('Formation non trouvée');
    }
    
    if ($formation['vendeurId'] != $userId) {
        throw new Exception('Non autorisé à ajouter des vidéos à cette formation');
    }
    
    logDebug("Formation validée pour l'utilisateur $userId");

    // 4. Vérification limite vidéos gratuites
    if (isset($_FILES['preview_video']) && $_FILES['preview_video']['error'] === UPLOAD_ERR_OK) {
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM Video WHERE produitId = ? AND preview_url IS NOT NULL AND preview_url != ''");
        $stmtCount->execute([$produitId]);
        $freeVideosCount = $stmtCount->fetchColumn();
        
        logDebug("Nombre de vidéos gratuites existantes: $freeVideosCount");
        
        if ($freeVideosCount >= 3) {
            throw new Exception('Maximum 3 vidéos gratuites par formation.');
        }
    }

    // 5. Vérification fichiers uploadés
    if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        $errorCode = isset($_FILES['video']) ? $_FILES['video']['error'] : 'fichier non présent';
        $errorMessages = [
            0 => 'Aucune erreur',
            1 => 'Fichier trop volumineux (upload_max_filesize)',
            2 => 'Fichier trop volumineux (MAX_FILE_SIZE)',
            3 => 'Fichier partiellement uploadé',
            4 => 'Aucun fichier uploadé',
            6 => 'Dossier temporaire manquant',
            7 => 'Échec écriture disque',
            8 => 'Extension PHP arrêtée'
        ];
        
        $errorMessage = $errorMessages[$errorCode] ?? "Erreur inconnue ($errorCode)";
        throw new Exception("Vidéo principale manquante: $errorMessage");
    }

    $hasPreviewVideo = isset($_FILES['preview_video']) && $_FILES['preview_video']['error'] === UPLOAD_ERR_OK;

    $videoFile = $_FILES['video'];
    $previewFile = $hasPreviewVideo ? $_FILES['preview_video'] : null;
    
    logDebug("Vidéo principale: " . $videoFile['name'] . " (" . $videoFile['size'] . " bytes)");
    logDebug("Vidéo aperçu fournie: " . ($hasPreviewVideo ? "OUI" : "NON"));

    // 6. Validation taille fichiers
    $maxFileSize = CLOUDFLARE_MAX_FILE_SIZE;
    if ($videoFile['size'] > $maxFileSize) {
        throw new Exception('Vidéo principale trop volumineuse (max 500 MB)');
    }
    if ($hasPreviewVideo && $previewFile['size'] > $maxFileSize) {
        throw new Exception('Vidéo d\'aperçu trop volumineuse (max 500 MB)');
    }

    // 7. Validation type MIME
    if (!in_array($videoFile['type'], CLOUDFLARE_ALLOWED_MIMES)) {
        logDebug("Type MIME vidéo non accepté: " . $videoFile['type']);
        // Ne pas bloquer, juste logger
    }
    if ($hasPreviewVideo && !in_array($previewFile['type'], CLOUDFLARE_ALLOWED_MIMES)) {
        logDebug("Type MIME preview non accepté: " . $previewFile['type']);
        // Ne pas bloquer, juste logger
    }

    // 8. Création dossiers temporaires locaux
    $tempDir = __DIR__ . '/../temp_uploads/';
    if (!is_dir($tempDir)) {
        if (!mkdir($tempDir, 0755, true)) {
            throw new Exception('Impossible de créer dossier temporaire');
        }
        logDebug("Dossier temporaire créé");
    }
    
    $segmentsBaseDir = $tempDir . 'segments/';
    if (!is_dir($segmentsBaseDir)) {
        if (!mkdir($segmentsBaseDir, 0755, true)) {
            throw new Exception('Impossible de créer dossier segments');
        }
        logDebug("Dossier segments créé");
    }

    // 9. Génération nom fichier sécurisé
    function generateSecureFilename($originalName, $prefix = 'video') {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension);
        
        if (empty($extension) || !in_array($extension, ['mp4', 'mov', 'avi', 'wmv', 'mkv', 'webm', 'flv', '3gp', 'm4v'])) {
            $extension = 'mp4';
        }
        
        $timestamp = time();
        $random = bin2hex(random_bytes(8));
        return $prefix . '_' . $timestamp . '_' . $random . '.' . $extension;
    }

    // 10. Noms fichiers temporaires
    $videoFilename = generateSecureFilename($videoFile['name'], 'video');
    $previewFilename = $hasPreviewVideo ? generateSecureFilename($previewFile['name'], 'preview') : null;
    
    logDebug("Noms temporaires - Vidéo: $videoFilename, Preview: " . ($previewFilename ?: 'NULL'));

    // 11. Chemins complets temporaires
    $videoPath = $tempDir . $videoFilename;
    $previewPath = $previewFilename ? $tempDir . $previewFilename : null;

    // 12. Déplacement vidéo principale vers dossier temporaire
    logDebug("Déplacement vidéo vers temporaire: $videoPath");
    if (!move_uploaded_file($videoFile['tmp_name'], $videoPath)) {
        throw new Exception('Erreur déplacement vidéo principale');
    }
    logDebug("Vidéo déplacée vers temporaire");

    // 13. Déplacement vidéo d'aperçu vers dossier temporaire
    if ($hasPreviewVideo && $previewFilename && $previewPath) {
        logDebug("Déplacement preview vers temporaire: $previewPath");
        if (!move_uploaded_file($previewFile['tmp_name'], $previewPath)) {
            if (file_exists($videoPath)) {
                unlink($videoPath);
            }
            throw new Exception('Erreur déplacement vidéo d\'aperçu');
        }
        logDebug("Preview déplacé vers temporaire");
    }

    // 14. Vérification fichiers temporaires
    if (!file_exists($videoPath) || filesize($videoPath) === 0) {
        throw new Exception('Vidéo principale non enregistrée');
    }
    if ($hasPreviewVideo && $previewPath && (!file_exists($previewPath) || filesize($previewPath) === 0)) {
        if (file_exists($videoPath)) unlink($videoPath);
        throw new Exception('Vidéo d\'aperçu non enregistrée');
    }

    // ✅ Chemin FFmpeg - Détection automatique
    $ffmpegPath = "/usr/bin/ffmpeg"; // Chemin par défaut pour Linux
    
    // Vérifier si FFmpeg existe
    if (!file_exists($ffmpegPath)) {
        logDebug("ATTENTION: FFmpeg non trouvé à: $ffmpegPath");
        // Essayer de trouver FFmpeg via which
        exec("which ffmpeg 2>/dev/null", $output, $return_var);
        if ($return_var === 0 && !empty($output[0])) {
            $ffmpegPath = trim($output[0]);
            logDebug("FFmpeg trouvé via which: $ffmpegPath");
        } else {
            logDebug("FFmpeg non disponible sur le système");
        }
    } else {
        logDebug("FFmpeg trouvé à: $ffmpegPath");
    }
    
    // ✅ Gestion découpage et upload vers Cloudflare R2
    logDebug("Début traitement FFmpeg et upload Cloudflare R2...");
    $decoupageResult = gererDecoupageEtUploadR2(
        $videoPath,
        $previewPath,
        $videoFilename,
        $previewFilename,
        $ffmpegPath,
        $segmentsBaseDir,
        $produitId,
        $is_free
    );
    
    $videoSegments = $decoupageResult['videoSegments'];
    $previewSegments = $decoupageResult['previewSegments'];
    $videoUrls = $decoupageResult['videoUrls'];
    $previewUrls = $decoupageResult['previewUrls'];
    $videoUrl = $decoupageResult['videoUrl']; // Clé R2 du premier segment
    $previewUrl = $decoupageResult['previewUrl']; // Clé R2 du premier segment preview
    
    logDebug("Résultat - Vidéo segments: " . count($videoSegments) . 
             ", URLs R2: " . count($videoUrls) .
             ", Preview segments: " . count($previewSegments) .
             ", URLs R2 Preview: " . count($previewUrls));

    // 15. Vérifier colonnes table Video (PostgreSQL)
    try {
        $checkColumns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'video'");
        $existingColumns = $checkColumns->fetchAll(PDO::FETCH_COLUMN, 0);
        $hasDescription = in_array('description', $existingColumns);
        
        logDebug("Colonne 'description' existe: " . ($hasDescription ? 'OUI' : 'NON'));
    } catch (Exception $e) {
        logDebug("Erreur vérification colonnes: " . $e->getMessage());
        $hasDescription = false;
    }

    // ✅ Insertion segments BDD avec URLs Cloudflare R2
    try {
        $pdo->beginTransaction();
        
        $firstVideoId = null;
        $totalSegments = count($videoUrls);
        
        // Insertion segments vidéo principale
        foreach ($videoUrls as $index => $r2ObjectKey) {
            $segmentTitre = $titre;
            if ($totalSegments > 1) {
                $segmentTitre .= " - Partie " . ($index + 1);
            }
            
            $segmentUrl = $r2ObjectKey; // Stocker la clé R2, pas l'URL complète
            
            $segmentOrdre = $ordre + $index;
            
            $segmentPreviewUrl = null;
            // Associer le preview au premier segment seulement
            if ($index === 0 && !empty($previewUrl)) {
                $segmentPreviewUrl = $previewUrl; // Stocker la clé R2
            }
            
            if ($hasDescription) {
                $sql = "INSERT INTO Video (produitId, titre, url, ordre, preview_url, description, dateCreation) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW()) RETURNING id";
                $params = [
                    $produitId,
                    $segmentTitre,
                    $segmentUrl,
                    $segmentOrdre,
                    $segmentPreviewUrl,
                    $description
                ];
            } else {
                $sql = "INSERT INTO Video (produitId, titre, url, ordre, preview_url, dateCreation) 
                        VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id";
                $params = [
                    $produitId,
                    $segmentTitre,
                    $segmentUrl,
                    $segmentOrdre,
                    $segmentPreviewUrl
                ];
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $videoId = $stmt->fetchColumn();
            
            if ($index === 0) {
                $firstVideoId = $videoId;
            }
            
            logDebug("Segment " . ($index + 1) . " inséré - ID: $videoId, Ordre: $segmentOrdre, URL R2: $segmentUrl");
        }
        
        $pdo->commit();
        
        logDebug("Total segments: " . $totalSegments . 
                 ", Total aperçus: " . count($previewUrls) . 
                 ", ID première vidéo: $firstVideoId");
        
        // Générer les URLs publiques pour la réponse
        $publicVideoUrl = generateCloudflareUrl($videoUrl);
        $publicPreviewUrl = !empty($previewUrl) ? generateCloudflareUrl($previewUrl) : null;
        
        // Réponse succès
        $response = [
            'success' => true,
            'message' => $previewUrl 
                ? 'Vidéo et aperçu uploadés vers Cloudflare R2 avec succès' 
                : 'Vidéo uploadée vers Cloudflare R2 avec succès',
            'id' => $firstVideoId,
            'segments_count' => $totalSegments,
            'preview_segments_count' => count($previewUrls),
            'cloudflare' => [
                'video_url' => $publicVideoUrl,
                'preview_url' => $publicPreviewUrl,
                'video_object_key' => $videoUrl,
                'preview_object_key' => $previewUrl
            ],
            'data' => [
                'video_url' => $publicVideoUrl,
                'preview_url' => $publicPreviewUrl,
                'titre' => $titre,
                'ordre' => $ordre,
                'produitId' => $produitId,
                'is_free' => $previewUrl !== null
            ]
        ];
        
        if ($hasDescription) {
            $response['data']['description'] = $description;
        }
        
        logDebug("=== UPLOAD R2 RÉUSSI ===");
        echo json_encode($response);
        
    } catch (Exception $dbError) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        // Nettoyage fichiers R2 en cas d'erreur
        logDebug("Nettoyage fichiers R2 suite à erreur...");
        try {
            // Supprimer les vidéos uploadées vers R2
            foreach ($videoUrls as $r2Key) {
                deleteFromCloudflareR2($r2Key);
            }
            foreach ($previewUrls as $r2Key) {
                deleteFromCloudflareR2($r2Key);
            }
            logDebug("Fichiers R2 nettoyés");
        } catch (Exception $e) {
            logDebug("Erreur lors du nettoyage R2: " . $e->getMessage());
        }
        
        throw new Exception('Erreur BDD: ' . $dbError->getMessage());
    } finally {
        // Nettoyage des fichiers temporaires locaux
        if (file_exists($videoPath)) {
            unlink($videoPath);
            logDebug("Fichier vidéo temporaire supprimé");
        }
        if ($previewPath && file_exists($previewPath)) {
            unlink($previewPath);
            logDebug("Fichier preview temporaire supprimé");
        }
        
        // Nettoyer segments locaux restants
        $videoSegmentsDir = $segmentsBaseDir . pathinfo($videoFilename, PATHINFO_FILENAME);
        if (is_dir($videoSegmentsDir)) {
            $segmentFiles = glob($videoSegmentsDir . "/*.mp4");
            foreach ($segmentFiles as $segment) {
                if (file_exists($segment)) unlink($segment);
            }
            @rmdir($videoSegmentsDir);
            logDebug("Segments vidéo temporaires nettoyés");
        }
        
        if ($hasPreviewVideo) {
            $previewSegmentsDir = $segmentsBaseDir . 'preview_' . pathinfo($previewFilename, PATHINFO_FILENAME);
            if (is_dir($previewSegmentsDir)) {
                $previewSegmentFiles = glob($previewSegmentsDir . "/*.mp4");
                foreach ($previewSegmentFiles as $segment) {
                    if (file_exists($segment)) unlink($segment);
                }
                @rmdir($previewSegmentsDir);
                logDebug("Segments preview temporaires nettoyés");
            }
        }
    }
    
} catch (Exception $e) {
    logDebug("ERREUR: " . $e->getMessage());
    logDebug("=== FIN UPLOAD R2 (ÉCHEC) ===");
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => $e->getMessage()
    ]);
}
?>