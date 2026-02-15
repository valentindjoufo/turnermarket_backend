<?php
// Inclure les configurations
require_once 'config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/cloudflare-config.php';

use Aws\S3\S3Client;

// Headers pour CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// 📝 LOG : Point d'entrée
error_log("========================================");
error_log("🎬 API Videos.php appelée");
error_log("Méthode: " . $_SERVER['REQUEST_METHOD']);
error_log("URL: " . $_SERVER['REQUEST_URI']);
error_log("========================================");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ FONCTION : Supprimer les fichiers d'une vidéo (Cloudflare R2 + local)
function cleanVideoFiles($videoUrl, $previewUrl = null) {
    error_log("🗑️ Nettoyage fichiers pour vidéo");
    
    // Supprimer de Cloudflare R2
    try {
        if (!empty($videoUrl)) {
            $videoDeleted = deleteFromCloudflareR2($videoUrl);
            error_log($videoDeleted ? "✅ Vidéo supprimée de Cloudflare R2" : "⚠️ Échec suppression vidéo R2");
        }
        
        if (!empty($previewUrl)) {
            $previewDeleted = deleteFromCloudflareR2($previewUrl);
            error_log($previewDeleted ? "✅ Preview supprimé de Cloudflare R2" : "⚠️ Échec suppression preview R2");
        }
    } catch (Exception $e) {
        error_log("⚠️ Erreur lors de la suppression Cloudflare: " . $e->getMessage());
    }
    
    // Supprimer localement (pour compatibilité descendante)
    if (!empty($videoUrl) && !preg_match('/^https?:\/\//', $videoUrl)) {
        $localFile = __DIR__ . '/../' . ltrim($videoUrl, '/');
        if (file_exists($localFile)) {
            unlink($localFile);
            error_log("✅ Fichier local supprimé");
        }
    }
    
    if (!empty($previewUrl) && !preg_match('/^https?:\/\//', $previewUrl)) {
        $localPreviewFile = __DIR__ . '/../' . ltrim($previewUrl, '/');
        if (file_exists($localPreviewFile)) {
            unlink($localPreviewFile);
            error_log("✅ Preview local supprimé");
        }
    }
}

// ✅ FONCTION : Formater la durée en format lisible
function formatDuration($seconds) {
    if (empty($seconds)) return '00:00';
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    } else {
        return sprintf("%02d:%02d", $minutes, $seconds);
    }
}

// ✅ FONCTION : Détecter si une vidéo est segmentée
function isVideoSegmented($title) {
    return preg_match('/ - (Partie|Aperçu gratuit Partie) \d+$/', $title);
}

// ✅ FONCTION : Extraire le titre de base d'une vidéo segmentée
function getBaseTitle($title) {
    return preg_replace('/ - (Partie|Aperçu gratuit Partie) \d+$/', '', $title);
}

// ✅ FONCTION : Extraire le numéro de segment
function getSegmentNumber($title) {
    preg_match('/Partie (\d+)$/', $title, $matches);
    return isset($matches[1]) ? intval($matches[1]) : 1;
}

// ✅ FONCTION : Générer une URL Cloudflare R2
function generateCloudflareVideoUrl($objectKey) {
    return generateCloudflareUrl($objectKey);
}

// Récupération des colonnes de la table video (pour les vérifications dynamiques)
function getVideoTableColumns($pdo) {
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'video'");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$method = $_SERVER['REQUEST_METHOD'];

// ========== GET - Récupérer les vidéos ==========
if ($method === 'GET') {
    if (!isset($_GET['produitId'])) {
        error_log("❌ Paramètre produitId manquant");
        http_response_code(400);
        echo json_encode(['error' => 'Paramètre produitId manquant']);
        exit;
    }

    $produitId = intval($_GET['produitId']);
    error_log("📋 Recherche vidéos pour produitId: $produitId");
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                titre,
                url,
                preview_url,
                duree,
                description,
                ordre,
                produitId,
                dateCreation
            FROM video 
            WHERE produitId = ?
            ORDER BY ordre ASC, id ASC
        ");
        
        $stmt->execute([$produitId]);
        $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("📊 Vidéos trouvées en BD: " . count($videos));
        
        if (empty($videos)) {
            error_log("⚠️ Aucune vidéo trouvée pour produitId: $produitId");
            echo json_encode([]);
            exit;
        }
        
        // Convertir les URLs Cloudflare si nécessaire
        foreach ($videos as &$video) {
            if (!empty($video['url']) && !preg_match('/^https?:\/\//', $video['url'])) {
                $video['url'] = generateCloudflareVideoUrl($video['url']);
            }
            if (!empty($video['preview_url']) && !preg_match('/^https?:\/\//', $video['preview_url'])) {
                $video['preview_url'] = generateCloudflareVideoUrl($video['preview_url']);
            }
        }
        
        // Grouper les vidéos par titre de base (même logique que l'original)
        $groupedVideos = [];
        
        foreach ($videos as $video) {
            $baseTitle = getBaseTitle($video['titre']);
            $isSegmented = isVideoSegmented($video['titre']);
            $segmentNumber = getSegmentNumber($video['titre']);
            $isPreview = strpos($video['titre'], 'Aperçu gratuit') !== false;
            
            if (!$isSegmented || !isset($groupedVideos[$baseTitle])) {
                $groupedVideos[$baseTitle] = [
                    'id' => $video['id'],
                    'titre' => $baseTitle,
                    'url' => $video['url'],
                    'preview_url' => $video['preview_url'],
                    'duree' => $video['duree'],
                    'description' => $video['description'],
                    'ordre' => $video['ordre'],
                    'produitId' => $video['produitId'],
                    'is_segmented' => $isSegmented,
                    'segments' => [],
                    'segment_count' => 1,
                    'has_preview' => !empty($video['preview_url'])
                ];
            }
            
            if ($isSegmented) {
                $segmentData = [
                    'id' => $video['id'],
                    'titre' => $video['titre'],
                    'url' => $video['url'],
                    'preview_url' => $video['preview_url'],
                    'segment_number' => $segmentNumber,
                    'is_preview' => $isPreview,
                    'duree' => $video['duree'],
                    'ordre' => $video['ordre']
                ];
                $groupedVideos[$baseTitle]['segments'][] = $segmentData;
                $groupedVideos[$baseTitle]['segment_count'] = count($groupedVideos[$baseTitle]['segments']);
            }
        }
        
        // Traiter chaque groupe pour trier les segments et définir les propriétés
        $finalVideos = [];
        foreach ($groupedVideos as $baseTitle => $videoData) {
            if ($videoData['is_segmented'] && !empty($videoData['segments'])) {
                usort($videoData['segments'], function($a, $b) {
                    return $a['segment_number'] - $b['segment_number'];
                });
                
                $hasPreviewSegments = false;
                $hasFullSegments = false;
                foreach ($videoData['segments'] as $segment) {
                    if ($segment['is_preview']) {
                        $hasPreviewSegments = true;
                    } else {
                        $hasFullSegments = true;
                    }
                }
                $videoData['has_preview'] = $hasPreviewSegments;
                
                // Définir l'URL principale comme premier segment non-preview
                $mainUrlSet = false;
                foreach ($videoData['segments'] as $segment) {
                    if (!$segment['is_preview']) {
                        $videoData['url'] = $segment['url'];
                        $mainUrlSet = true;
                        break;
                    }
                }
                if (!$mainUrlSet && !empty($videoData['segments'])) {
                    $videoData['url'] = $videoData['segments'][0]['url'];
                }
                
                // Calculer la durée totale
                $totalDuration = 0;
                foreach ($videoData['segments'] as $segment) {
                    if (!$segment['is_preview'] && !empty($segment['duree'])) {
                        if (strpos($segment['duree'], ':') !== false) {
                            $parts = explode(':', $segment['duree']);
                            if (count($parts) == 2) {
                                $totalDuration += ($parts[0] * 60) + $parts[1];
                            } elseif (count($parts) == 3) {
                                $totalDuration += ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
                            }
                        } elseif (is_numeric($segment['duree'])) {
                            $totalDuration += intval($segment['duree']);
                        }
                    }
                }
                if ($totalDuration > 0) {
                    $videoData['duree'] = formatDuration($totalDuration);
                }
            }
            $finalVideos[] = $videoData;
        }
        
        // Trier les vidéos finales par ordre
        usort($finalVideos, function($a, $b) {
            return $a['ordre'] - $b['ordre'];
        });
        
        error_log("✅ Envoi de " . count($finalVideos) . " vidéo(s) au client");
        echo json_encode($finalVideos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (PDOException $e) {
        error_log("❌ Erreur SQL: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la récupération des vidéos', 
            'details' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        error_log("❌ Erreur générale: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur serveur', 'details' => $e->getMessage()]);
    }
    exit;
}

// ========== DELETE - Supprimer une vidéo ==========
if ($method === 'DELETE') {
    if (!isset($_GET['id'])) {
        error_log("❌ ID vidéo manquant pour suppression");
        http_response_code(400);
        echo json_encode(['error' => 'ID de la vidéo requis']);
        exit;
    }

    $id = intval($_GET['id']);
    error_log("🗑️ Tentative suppression vidéo ID: $id");

    try {
        // Récupérer les informations de la vidéo à supprimer
        $stmt = $pdo->prepare("SELECT titre, url, preview_url, produitId FROM video WHERE id = ?");
        $stmt->execute([$id]);
        $video = $stmt->fetch();

        if (!$video) {
            error_log("❌ Vidéo ID $id introuvable");
            http_response_code(404);
            echo json_encode(['error' => 'Vidéo introuvable']);
            exit;
        }

        $titre = $video['titre'];
        $produitId = $video['produitId'];
        $baseTitle = getBaseTitle($titre);
        
        // Récupérer tous les segments liés (si vidéo segmentée)
        // En PostgreSQL, on utilise split_part pour extraire la partie avant " - "
        // et LIKE avec concaténation via ||
        $allSegmentsStmt = $pdo->prepare("
            SELECT id, titre, url, preview_url 
            FROM video 
            WHERE produitId = ? 
            AND (
                split_part(titre, ' - ', 1) = ?
                OR titre LIKE ? || ' - %'
                OR titre = ?
            )
        ");
        
        $allSegmentsStmt->execute([$produitId, $baseTitle, $baseTitle, $titre]);
        $allSegments = $allSegmentsStmt->fetchAll();
        
        error_log("📊 Recherche segments pour: '$baseTitle', trouvés: " . count($allSegments));
        
        // Supprimer tous les fichiers physiques
        foreach ($allSegments as $segment) {
            cleanVideoFiles($segment['url'], $segment['preview_url']);
        }
        
        // Supprimer toutes les entrées en BDD
        $deleteStmt = $pdo->prepare("
            DELETE FROM video 
            WHERE produitId = ? 
            AND (
                split_part(titre, ' - ', 1) = ?
                OR titre LIKE ? || ' - %'
                OR titre = ?
            )
        ");
        
        $deleteStmt->execute([$produitId, $baseTitle, $baseTitle, $titre]);
        $deletedCount = $deleteStmt->rowCount();
        
        error_log("✅ $deletedCount vidéo(s) supprimée(s) de la BD");
        
        echo json_encode([
            'success' => true, 
            'message' => 'Vidéo et tous ses segments supprimés avec succès',
            'deleted_count' => $deletedCount,
            'video_title' => $titre
        ]);
        
    } catch (PDOException $e) {
        error_log("❌ Erreur suppression: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de la suppression', 'details' => $e->getMessage()]);
    }
    exit;
}

// ========== PUT - Modifier une vidéo ==========
if ($method === 'PUT') {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de la vidéo requis']);
        exit;
    }

    $id = intval($_GET['id']);
    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input || !isset($input['titre'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Données invalides']);
        exit;
    }

    try {
        // Récupérer la vidéo originale
        $stmt = $pdo->prepare("SELECT titre, produitId FROM video WHERE id = ?");
        $stmt->execute([$id]);
        $originalVideo = $stmt->fetch();
        
        if (!$originalVideo) {
            http_response_code(404);
            echo json_encode(['error' => 'Vidéo introuvable']);
            exit;
        }
        
        $originalTitle = $originalVideo['titre'];
        $produitId = $originalVideo['produitId'];
        $isSegmented = isVideoSegmented($originalTitle);
        
        if ($isSegmented) {
            // C'est un segment, mettre à jour tous les segments
            $baseOriginalTitle = getBaseTitle($originalTitle);
            $newBaseTitle = trim($input['titre']);
            
            // Récupérer tous les segments
            $allSegmentsStmt = $pdo->prepare("
                SELECT id, titre 
                FROM video 
                WHERE produitId = ? 
                AND (
                    split_part(titre, ' - ', 1) = ?
                    OR titre LIKE ? || ' - %'
                )
                ORDER BY 
                    CASE 
                        WHEN titre LIKE '%Aperçu gratuit%' THEN 1
                        ELSE 0 
                    END,
                    CAST(split_part(split_part(titre, 'Partie ', 2), ' ', 1) AS INTEGER)
            ");
            
            $allSegmentsStmt->execute([$produitId, $baseOriginalTitle, $baseOriginalTitle]);
            $allSegments = $allSegmentsStmt->fetchAll();
            
            error_log("📊 Mise à jour de " . count($allSegments) . " segments");
            
            // Mettre à jour chaque segment
            foreach ($allSegments as $segment) {
                $segmentId = $segment['id'];
                $segmentTitle = $segment['titre'];
                
                // Déterminer le numéro et le type du segment
                preg_match('/ - (Partie|Aperçu gratuit Partie) (\d+)$/', $segmentTitle, $segmentMatches);
                $segmentNumber = isset($segmentMatches[2]) ? intval($segmentMatches[2]) : 1;
                $segmentType = isset($segmentMatches[1]) ? $segmentMatches[1] : 'Partie';
                
                // Construire le nouveau titre
                if ($segmentNumber > 1) {
                    $newSegmentTitle = $newBaseTitle . " - $segmentType $segmentNumber";
                } else {
                    $newSegmentTitle = $newBaseTitle;
                }
                
                $updateStmt = $pdo->prepare("UPDATE video SET titre = ? WHERE id = ?");
                $updateStmt->execute([$newSegmentTitle, $segmentId]);
                
                error_log("   Segment $segmentId -> $newSegmentTitle");
            }
            
            error_log("✅ Tous les segments mis à jour");
            echo json_encode(['success' => true, 'message' => 'Tous les segments de la vidéo mis à jour avec succès']);
            
        } else {
            // Vidéo normale (non segmentée)
            $titre = trim($input['titre']);
            
            // Récupérer les colonnes de la table
            $columns = getVideoTableColumns($pdo);
            
            $updateFields = ['titre = ?'];
            $params = [$titre];
            
            if (in_array('preview_url', $columns) && isset($input['preview_url'])) {
                $updateFields[] = 'preview_url = ?';
                $params[] = trim($input['preview_url']);
            }
            if (in_array('preview_duration', $columns) && isset($input['preview_duration'])) {
                $updateFields[] = 'preview_duration = ?';
                $params[] = intval($input['preview_duration']);
            }
            if (in_array('duree', $columns) && isset($input['duree'])) {
                $updateFields[] = 'duree = ?';
                $params[] = trim($input['duree']);
            }
            if (in_array('description', $columns) && isset($input['description'])) {
                $updateFields[] = 'description = ?';
                $params[] = trim($input['description']);
            }
            if (in_array('ordre', $columns) && isset($input['ordre'])) {
                $updateFields[] = 'ordre = ?';
                $params[] = intval($input['ordre']);
            }

            $params[] = $id;
            $sql = "UPDATE video SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            error_log("✅ Vidéo ID $id modifiée");
            echo json_encode(['success' => true, 'message' => 'Vidéo modifiée avec succès']);
        }
        
    } catch (PDOException $e) {
        error_log("❌ Erreur modification: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de la modification', 'details' => $e->getMessage()]);
    }
    exit;
}

// ========== POST - Ajouter une vidéo (Cloudflare R2) ==========
if ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    
    if (!$input || !isset($input['titre']) || !isset($input['produitId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Données requises manquantes']);
        exit;
    }

    try {
        $columns = getVideoTableColumns($pdo);
        
        $titre = trim($input['titre']);
        $produitId = intval($input['produitId']);
        $ordre = isset($input['ordre']) ? intval($input['ordre']) : 0;
        
        // Si on reçoit directement une URL R2
        if (isset($input['url'])) {
            $url = trim($input['url']);
            $objectKey = extractObjectKeyFromUrl($url);
            $urlToStore = $objectKey ?: $url;
        } else {
            $urlToStore = null;
        }
        
        $insertFields = ["titre", "ordre", "produitId"];
        $insertPlaceholders = ["?", "?", "?"];
        $params = [$titre, $ordre, $produitId];
        
        // Ajouter l'URL si elle existe
        if ($urlToStore) {
            $insertFields[] = "url";
            $insertPlaceholders[] = "?";
            $params[] = $urlToStore;
        }
        
        // Ajouter le preview_url si fourni
        if (in_array('preview_url', $columns) && isset($input['preview_url'])) {
            $previewUrl = trim($input['preview_url']);
            $previewKey = extractObjectKeyFromUrl($previewUrl);
            $insertFields[] = "preview_url";
            $insertPlaceholders[] = "?";
            $params[] = $previewKey ?: $previewUrl;
        }
        
        if (in_array('duree', $columns) && isset($input['duree'])) {
            $insertFields[] = "duree";
            $insertPlaceholders[] = "?";
            $params[] = trim($input['duree']);
        }
        if (in_array('description', $columns) && isset($input['description'])) {
            $insertFields[] = "description";
            $insertPlaceholders[] = "?";
            $params[] = trim($input['description']);
        }

        $insertFieldsStr = implode(", ", $insertFields);
        $insertPlaceholdersStr = implode(", ", $insertPlaceholders);

        $sql = "INSERT INTO video ($insertFieldsStr) VALUES ($insertPlaceholdersStr)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $newId = $pdo->lastInsertId();
        error_log("✅ Nouvelle vidéo ajoutée - ID: $newId");
        
        // Récupérer l'URL complète pour la réponse
        $fullUrl = $urlToStore ? generateCloudflareVideoUrl($urlToStore) : null;
        $fullPreviewUrl = isset($previewUrl) ? generateCloudflareVideoUrl($previewKey ?: $previewUrl) : null;
        
        echo json_encode([
            'success' => true, 
            'message' => 'Vidéo ajoutée avec succès', 
            'id' => $newId,
            'cloudflare_url' => $fullUrl,
            'cloudflare_preview_url' => $fullPreviewUrl,
            'data' => [
                'id' => $newId,
                'titre' => $titre,
                'url' => $fullUrl,
                'preview_url' => $fullPreviewUrl,
                'ordre' => $ordre,
                'produitId' => $produitId
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("❌ Erreur ajout: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de l\'ajout', 'details' => $e->getMessage()]);
    }
    exit;
}

// ========== Méthode non autorisée ==========
http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée']);
?>