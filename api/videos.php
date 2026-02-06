<?php
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

// Répondre aux requêtes pré-vol CORS (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Connexion à la base de données
$host = 'localhost';
$dbname = 'gestvente';
$user = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    error_log("✅ Connexion BD réussie");
} catch (PDOException $e) {
    error_log("❌ Erreur connexion BD: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion à la base de données', 'details' => $e->getMessage()]);
    exit;
}

// ✅ FONCTION : Nettoyer les fichiers d'une vidéo (vidéo + segments)
function cleanVideoFiles($videoUrl, $previewUrl = null) {
    // Supprimer la vidéo principale
    if (!empty($videoUrl) && !preg_match('/^https?:\/\//', $videoUrl)) {
        $localFile = __DIR__ . '/../' . ltrim($videoUrl, '/');
        error_log("🗑️ Suppression fichier: $localFile");
        if (file_exists($localFile)) {
            unlink($localFile);
            error_log("✅ Fichier supprimé");
        } else {
            error_log("⚠️ Fichier non trouvé: $localFile");
        }
    }

    // Supprimer le preview
    if (!empty($previewUrl) && !preg_match('/^https?:\/\//', $previewUrl)) {
        $localPreviewFile = __DIR__ . '/../' . ltrim($previewUrl, '/');
        error_log("🗑️ Suppression preview: $localPreviewFile");
        if (file_exists($localPreviewFile)) {
            unlink($localPreviewFile);
            error_log("✅ Preview supprimé");
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

// Méthode de la requête
$method = $_SERVER['REQUEST_METHOD'];

// ========== GET - Récupérer les vidéos ==========
if ($method === 'GET') {
    // Vérifie que produitId est présent
    if (!isset($_GET['produitId'])) {
        error_log("❌ Paramètre produitId manquant");
        http_response_code(400);
        echo json_encode(['error' => 'Paramètre produitId manquant']);
        exit;
    }

    $produitId = intval($_GET['produitId']);
    error_log("📋 Recherche vidéos pour produitId: $produitId");
    
    try {
        // 1. Récupérer toutes les vidéos du produit
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
        
        // 2. Grouper les vidéos par titre de base
        $groupedVideos = [];
        
        foreach ($videos as $video) {
            $baseTitle = getBaseTitle($video['titre']);
            $isSegmented = isVideoSegmented($video['titre']);
            $segmentNumber = getSegmentNumber($video['titre']);
            $isPreview = strpos($video['titre'], 'Aperçu gratuit') !== false;
            
            // Si c'est une vidéo simple ou la première occurrence d'une vidéo segmentée
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
            
            // Si c'est un segment, l'ajouter à la liste des segments
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
        
        // 3. Traiter chaque groupe pour trier les segments et définir les propriétés
        $finalVideos = [];
        
        foreach ($groupedVideos as $baseTitle => $videoData) {
            // Si c'est une vidéo segmentée avec des segments
            if ($videoData['is_segmented'] && !empty($videoData['segments'])) {
                // Trier les segments par numéro
                usort($videoData['segments'], function($a, $b) {
                    return $a['segment_number'] - $b['segment_number'];
                });
                
                // Déterminer si la vidéo a des aperçus
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
                // Si tous les segments sont des previews, utiliser le premier segment
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
                
                // Calculer la durée totale (somme de toutes les durées non-preview)
                $totalDuration = 0;
                foreach ($videoData['segments'] as $segment) {
                    if (!$segment['is_preview'] && !empty($segment['duree'])) {
                        // Convertir la durée en secondes si nécessaire
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
        
        // 4. Trier les vidéos finales par ordre
        usort($finalVideos, function($a, $b) {
            return $a['ordre'] - $b['ordre'];
        });
        
        error_log("✅ Envoi de " . count($finalVideos) . " vidéo(s) au client");
        
        // 5. Retourner les données
        echo json_encode($finalVideos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (PDOException $e) {
        error_log("❌ Erreur SQL: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode([
            'error' => 'Erreur lors de la récupération des vidéos', 
            'details' => $e->getMessage(),
            'sql_error' => true
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
        // 1. Récupérer les informations de la vidéo à supprimer
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
        
        // 2. Récupérer tous les segments liés (si vidéo segmentée)
        $allSegmentsStmt = $pdo->prepare("
            SELECT id, titre, url, preview_url 
            FROM video 
            WHERE produitId = ? 
            AND (
                TRIM(SUBSTRING_INDEX(titre, ' - ', 1)) = ?
                OR titre LIKE CONCAT(?, ' - %')
                OR titre = ?
            )
        ");
        
        $allSegmentsStmt->execute([$produitId, $baseTitle, $baseTitle, $titre]);
        $allSegments = $allSegmentsStmt->fetchAll();
        
        error_log("📊 Recherche segments pour: '$baseTitle', trouvés: " . count($allSegments));
        
        // 3. Supprimer tous les fichiers physiques
        foreach ($allSegments as $segment) {
            cleanVideoFiles($segment['url'], $segment['preview_url']);
        }
        
        // 4. Supprimer toutes les entrées en BDD
        $deleteStmt = $pdo->prepare("
            DELETE FROM video 
            WHERE produitId = ? 
            AND (
                TRIM(SUBSTRING_INDEX(titre, ' - ', 1)) = ?
                OR titre LIKE CONCAT(?, ' - %')
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
                    TRIM(SUBSTRING_INDEX(titre, ' - ', 1)) = ?
                    OR titre LIKE CONCAT(?, ' - %')
                )
                ORDER BY 
                    CASE 
                        WHEN titre LIKE '%Aperçu gratuit%' THEN 1
                        ELSE 0 
                    END,
                    CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(titre, 'Partie ', -1), ' ', 1) AS UNSIGNED)
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
                
                // Mettre à jour ce segment
                $updateStmt = $pdo->prepare("UPDATE video SET titre = ? WHERE id = ?");
                $updateStmt->execute([$newSegmentTitle, $segmentId]);
                
                error_log("   Segment $segmentId -> $newSegmentTitle");
            }
            
            error_log("✅ Tous les segments mis à jour");
            echo json_encode(['success' => true, 'message' => 'Tous les segments de la vidéo mis à jour avec succès']);
            
        } else {
            // Vidéo normale (non segmentée)
            $titre = trim($input['titre']);
            
            // Vérifier quelles colonnes existent dans la table
            $checkColumns = $pdo->query("DESCRIBE video");
            $columns = $checkColumns->fetchAll(PDO::FETCH_COLUMN);
            
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

            $params[] = $id; // Pour le WHERE id = ?
            
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

// ========== POST - Ajouter une vidéo ==========
if ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    
    if (!$input || !isset($input['titre']) || !isset($input['url']) || !isset($input['produitId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Données requises manquantes']);
        exit;
    }

    try {
        // Vérifier quelles colonnes existent dans la table
        $checkColumns = $pdo->query("DESCRIBE video");
        $columns = $checkColumns->fetchAll(PDO::FETCH_COLUMN);
        
        $titre = trim($input['titre']);
        $url = trim($input['url']);
        $produitId = intval($input['produitId']);
        $ordre = isset($input['ordre']) ? intval($input['ordre']) : 0;

        $insertFields = ["titre", "url", "ordre", "produitId"];
        $insertValues = ["?", "?", "?", "?"];
        $params = [$titre, $url, $ordre, $produitId];

        if (in_array('preview_url', $columns) && isset($input['preview_url'])) {
            $insertFields[] = "preview_url";
            $insertValues[] = "?";
            $params[] = trim($input['preview_url']);
        }
        if (in_array('preview_duration', $columns) && isset($input['preview_duration'])) {
            $insertFields[] = "preview_duration";
            $insertValues[] = "?";
            $params[] = intval($input['preview_duration']);
        }
        if (in_array('duree', $columns) && isset($input['duree'])) {
            $insertFields[] = "duree";
            $insertValues[] = "?";
            $params[] = trim($input['duree']);
        }
        if (in_array('description', $columns) && isset($input['description'])) {
            $insertFields[] = "description";
            $insertValues[] = "?";
            $params[] = trim($input['description']);
        }

        $insertFieldsStr = implode(", ", $insertFields);
        $insertValuesStr = implode(", ", $insertValues);

        $sql = "INSERT INTO video ($insertFieldsStr) VALUES ($insertValuesStr)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $newId = $pdo->lastInsertId();
        error_log("✅ Nouvelle vidéo ajoutée - ID: $newId");
        
        echo json_encode([
            'success' => true, 
            'message' => 'Vidéo ajoutée avec succès', 
            'id' => $newId
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