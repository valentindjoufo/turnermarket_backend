<?php
// 📁 Inclure la configuration de connexion PostgreSQL (même répertoire)
require_once 'config.php';

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

// Fonction : Découpage vidéo avec FFmpeg - CORRECTION DE L'ORDRE DES PARAMÈTRES
function couperVideo($videoPath, $outputDir, $ffmpegPath, $segmentTime = 900) {  // $segmentTime en dernier
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

// Fonction : Gestion découpage vidéos - CORRECTION DES APPELS À couperVideo
function gererDecoupageVideos($videoPath, $previewPath, $videoFilename, $previewFilename, $ffmpegPath, $segmentsBaseDir, $isFree = false) {
    $result = [
        'videoSegments' => [],
        'previewSegments' => [],
        'videoUrl' => '',
        'previewUrl' => ''
    ];
    
    // Découper vidéo principale
    $segmentsDir = $segmentsBaseDir . pathinfo($videoFilename, PATHINFO_FILENAME);
    
    if (!file_exists($ffmpegPath)) {
        logDebug("ATTENTION: FFmpeg non trouvé: $ffmpegPath");
        $result['videoSegments'] = [$videoPath];
        $result['videoUrl'] = 'video/' . $videoFilename;
    } else {
        logDebug("FFmpeg trouvé, découpage vidéo principale...");
        
        try {
            // CORRECTION : Appel correct avec les paramètres dans le bon ordre
            $videoSegments = couperVideo($videoPath, $segmentsDir, $ffmpegPath, 900);
            $result['videoSegments'] = $videoSegments;
            $result['videoUrl'] = 'video/segments/' . pathinfo($videoFilename, PATHINFO_FILENAME) . '/' . basename($videoSegments[0]);
            logDebug("Vidéo découpée en " . count($videoSegments) . " segments");
            
            // Supprimer vidéo originale après découpage
            if (file_exists($videoPath)) {
                unlink($videoPath);
                logDebug("Vidéo originale supprimée");
            }
        } catch (Exception $e) {
            logDebug("Erreur découpage: " . $e->getMessage());
            $result['videoSegments'] = [$videoPath];
            $result['videoUrl'] = 'video/' . $videoFilename;
        }
    }
    
    // Découper vidéo d'aperçu
    if ($isFree && $previewPath && file_exists($previewPath)) {
        $previewSegmentsDir = $segmentsBaseDir . 'preview_' . pathinfo($previewFilename, PATHINFO_FILENAME);
        
        if (!file_exists($ffmpegPath)) {
            logDebug("FFmpeg non trouvé, preview non découpé");
            $result['previewSegments'] = [$previewPath];
            $result['previewUrl'] = 'video/preview/' . $previewFilename;
        } else {
            logDebug("Découpage vidéo d'aperçu...");
            
            try {
                // CORRECTION : Appel correct avec les paramètres dans le bon ordre
                $previewSegments = couperVideo($previewPath, $previewSegmentsDir, $ffmpegPath, 900);
                $result['previewSegments'] = $previewSegments;
                $result['previewUrl'] = 'video/segments/preview_' . pathinfo($previewFilename, PATHINFO_FILENAME) . '/' . basename($previewSegments[0]);
                logDebug("Preview découpé en " . count($previewSegments) . " segments");
                
                // Supprimer preview original après découpage
                if (file_exists($previewPath)) {
                    unlink($previewPath);
                    logDebug("Preview original supprimé");
                }
            } catch (Exception $e) {
                logDebug("Erreur découpage preview: " . $e->getMessage());
                $result['previewSegments'] = [$previewPath];
                $result['previewUrl'] = 'video/preview/' . $previewFilename;
            }
        }
    } elseif ($previewPath && file_exists($previewPath)) {
        $result['previewSegments'] = [$previewPath];
        $result['previewUrl'] = 'video/preview/' . $previewFilename;
    }
    
    return $result;
}

try {
    // ACTIVER L'AFFICHAGE DES ERREURS POUR DÉBOGUER
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    logDebug("=== DÉBUT UPLOAD VIDÉO ===");
    logDebug("Méthode: " . $_SERVER['REQUEST_METHOD']);
    
    // 1. Validation données POST
    $titre = isset($_POST['titre']) ? trim($_POST['titre']) : '';
    $produitId = isset($_POST['produitId']) ? intval($_POST['produitId']) : 0;
    $ordre = isset($_POST['ordre']) ? intval($_POST['ordre']) : 1;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $userId = isset($_POST['userId']) ? intval($_POST['userId']) : 0;
    $is_free = isset($_POST['is_free']) ? intval($_POST['is_free']) : 0;
    
    logDebug("Données - Titre: $titre, ProduitID: $produitId, Ordre: $ordre, UserID: $userId, is_free: $is_free");
    
    if (empty($titre)) {
        throw new Exception('Le titre est obligatoire');
    }
    
    if ($produitId <= 0) {
        throw new Exception('ID formation invalide');
    }
    
    if ($userId <= 0) {
        throw new Exception('ID utilisateur invalide');
    }

    // ✅ Connexion PostgreSQL déjà établie via config.php
    // VÉRIFIER QUE $pdo EST DÉFINI
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
    logDebug("Fichiers reçus: " . print_r(array_keys($_FILES), true));
    
    if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Vidéo principale manquante (erreur: ' . 
            (isset($_FILES['video']) ? $_FILES['video']['error'] : 'non défini') . ')');
    }

    $hasPreviewVideo = isset($_FILES['preview_video']) && $_FILES['preview_video']['error'] === UPLOAD_ERR_OK;

    $videoFile = $_FILES['video'];
    $previewFile = $hasPreviewVideo ? $_FILES['preview_video'] : null;
    
    logDebug("Vidéo principale: " . $videoFile['name'] . " (" . $videoFile['size'] . " bytes)");
    logDebug("Vidéo aperçu fournie: " . ($hasPreviewVideo ? "OUI" : "NON"));

    // 6. Validation taille fichiers
    $maxFileSize = 500 * 1024 * 1024; // 500 MB
    if ($videoFile['size'] > $maxFileSize) {
        throw new Exception('Vidéo principale trop volumineuse (max 500 MB)');
    }
    if ($hasPreviewVideo && $previewFile['size'] > $maxFileSize) {
        throw new Exception('Vidéo d\'aperçu trop volumineuse (max 500 MB)');
    }

    // 7. Validation type MIME
    $allowedMimes = [
        'video/mp4', 'video/quicktime', 'video/x-msvideo', 
        'video/x-ms-wmv', 'video/x-matroska', 'video/webm',
        'video/x-flv', 'video/3gpp', 'application/octet-stream'
    ];
    
    if (!in_array($videoFile['type'], $allowedMimes)) {
        logDebug("Type MIME vidéo non accepté: " . $videoFile['type']);
    }
    if ($hasPreviewVideo && !in_array($previewFile['type'], $allowedMimes)) {
        logDebug("Type MIME preview non accepté: " . $previewFile['type']);
    }

    // 8. Création dossiers
    $videoDir = __DIR__ . '/../video/';
    $previewDir = __DIR__ . '/../video/preview/';
    $segmentsBaseDir = __DIR__ . '/../video/segments/';
    
    if (!is_dir($videoDir)) {
        if (!mkdir($videoDir, 0755, true)) {
            throw new Exception('Impossible de créer dossier video');
        }
        logDebug("Dossier video créé");
    }
    
    if (!is_dir($previewDir)) {
        if (!mkdir($previewDir, 0755, true)) {
            throw new Exception('Impossible de créer dossier preview');
        }
        logDebug("Dossier preview créé");
    }
    
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

    // 10. Noms fichiers
    $videoFilename = generateSecureFilename($videoFile['name'], 'video');
    $previewFilename = $hasPreviewVideo ? generateSecureFilename($previewFile['name'], 'preview') : null;
    
    logDebug("Noms - Vidéo: $videoFilename, Preview: " . ($previewFilename ?: 'NULL'));

    // 11. Chemins complets
    $videoPath = $videoDir . $videoFilename;
    $previewPath = $previewFilename ? $previewDir . $previewFilename : null;

    // 12. Déplacement vidéo principale
    logDebug("Déplacement vidéo vers: $videoPath");
    if (!move_uploaded_file($videoFile['tmp_name'], $videoPath)) {
        throw new Exception('Erreur déplacement vidéo principale');
    }
    logDebug("Vidéo déplacée");

    // 13. Déplacement vidéo d'aperçu
    if ($hasPreviewVideo && $previewFilename && $previewPath) {
        logDebug("Déplacement preview vers: $previewPath");
        if (!move_uploaded_file($previewFile['tmp_name'], $previewPath)) {
            if (file_exists($videoPath)) {
                unlink($videoPath);
            }
            throw new Exception('Erreur déplacement vidéo d\'aperçu');
        }
        logDebug("Preview déplacé");
    }

    // 14. Vérification fichiers
    if (!file_exists($videoPath) || filesize($videoPath) === 0) {
        throw new Exception('Vidéo principale non enregistrée');
    }
    if ($hasPreviewVideo && $previewPath && (!file_exists($previewPath) || filesize($previewPath) === 0)) {
        if (file_exists($videoPath)) unlink($videoPath);
        throw new Exception('Vidéo d\'aperçu non enregistrée');
    }

    // ✅ Chemin FFmpeg - ADAPTEZ À VOTRE SYSTÈME
    // $ffmpegPath = "C:\\ffmpeg\\bin\\ffmpeg.exe"; // Windows
    $ffmpegPath = "/usr/bin/ffmpeg"; // Linux
    // $ffmpegPath = "/opt/homebrew/bin/ffmpeg"; // Mac avec Homebrew
    
    if (!file_exists($ffmpegPath)) {
        logDebug("ATTENTION: FFmpeg non trouvé à: $ffmpegPath");
        // Essayer de trouver FFmpeg via which
        exec("which ffmpeg", $output, $return_var);
        if ($return_var === 0 && !empty($output[0])) {
            $ffmpegPath = trim($output[0]);
            logDebug("FFmpeg trouvé via which: $ffmpegPath");
        }
    }
    
    // ✅ Gestion découpage
    logDebug("Début traitement FFmpeg...");
    $decoupageResult = gererDecoupageVideos(
        $videoPath,
        $previewPath,
        $videoFilename,
        $previewFilename,
        $ffmpegPath,
        $segmentsBaseDir,
        $is_free
    );
    
    $videoSegments = $decoupageResult['videoSegments'];
    $previewSegments = $decoupageResult['previewSegments'];
    $videoUrl = $decoupageResult['videoUrl'];
    $previewUrl = $decoupageResult['previewUrl'];
    
    logDebug("Résultat - Vidéo segments: " . count($videoSegments) . 
             ", Preview segments: " . count($previewSegments));

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

    // ✅ Insertion segments BDD
    try {
        $pdo->beginTransaction();
        
        $firstVideoId = null;
        
        // Insertion segments vidéo principale
        foreach ($videoSegments as $index => $segment) {
            $segmentTitre = $titre;
            if (count($videoSegments) > 1) {
                $segmentTitre .= " - Partie " . ($index + 1);
            }
            
            if (count($videoSegments) > 1) {
                $segmentUrl = 'video/segments/' . pathinfo($videoFilename, PATHINFO_FILENAME) . '/' . basename($segment);
            } else {
                $segmentUrl = $videoUrl;
            }
            
            $segmentOrdre = $ordre + $index;
            
            $segmentPreviewUrl = null;
            if ($index === 0 && !empty($previewSegments)) {
                $segmentPreviewUrl = $previewUrl;
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
            
            logDebug("Segment " . ($index + 1) . " inséré - ID: $videoId, Ordre: $segmentOrdre, URL: $segmentUrl");
        }
        
        $pdo->commit();
        
        logDebug("Total segments: " . count($videoSegments) . 
                 ", Total aperçus: " . count($previewSegments) . 
                 ", ID première vidéo: $firstVideoId");
        
        // Réponse succès
        $response = [
            'success' => true,
            'message' => $previewUrl 
                ? 'Vidéo et aperçu enregistrés avec succès' 
                : 'Vidéo enregistrée avec succès',
            'id' => $firstVideoId,
            'segments_count' => count($videoSegments),
            'preview_segments_count' => count($previewSegments),
            'data' => [
                'video_url' => $videoUrl,
                'preview_url' => $previewUrl,
                'titre' => $titre,
                'ordre' => $ordre,
                'produitId' => $produitId,
                'is_free' => $previewUrl !== null
            ]
        ];
        
        if ($hasDescription) {
            $response['data']['description'] = $description;
        }
        
        logDebug("=== UPLOAD RÉUSSI ===");
        echo json_encode($response);
        
    } catch (Exception $dbError) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        // Nettoyage fichiers
        if (file_exists($videoPath)) {
            unlink($videoPath);
            logDebug("Fichier vidéo supprimé après erreur");
        }
        if ($previewPath && file_exists($previewPath)) {
            unlink($previewPath);
            logDebug("Fichier preview supprimé après erreur");
        }
        
        // Nettoyer segments
        $videoSegmentsDir = $segmentsBaseDir . pathinfo($videoFilename, PATHINFO_FILENAME);
        if (is_dir($videoSegmentsDir)) {
            $segmentFiles = glob($videoSegmentsDir . "/*.mp4");
            foreach ($segmentFiles as $segment) {
                unlink($segment);
            }
            rmdir($videoSegmentsDir);
            logDebug("Segments vidéo nettoyés");
        }
        
        if ($hasPreviewVideo) {
            $previewSegmentsDir = $segmentsBaseDir . 'preview_' . pathinfo($previewFilename, PATHINFO_FILENAME);
            if (is_dir($previewSegmentsDir)) {
                $previewSegmentFiles = glob($previewSegmentsDir . "/*.mp4");
                foreach ($previewSegmentFiles as $segment) {
                    unlink($segment);
                }
                rmdir($previewSegmentsDir);
                logDebug("Segments preview nettoyés");
            }
        }
        
        throw new Exception('Erreur BDD: ' . $dbError->getMessage());
    }
    
} catch (Exception $e) {
    logDebug("ERREUR: " . $e->getMessage());
    logDebug("=== FIN UPLOAD (ÉCHEC) ===");
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => $e->getMessage()
    ]);
}
?>