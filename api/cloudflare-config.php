<?php
// cloudflare-config.php

// Configuration Cloudflare R2
define('CLOUDFLARE_ACCOUNT_ID', 'd85dd046b7af773ca9d71be765231df1');
define('CLOUDFLARE_BUCKET', 'turnermarket-videos');
define('CLOUDFLARE_REGION', 'auto');
define('CLOUDFLARE_ENDPOINT', 'https://' . CLOUDFLARE_ACCOUNT_ID . '.r2.cloudflarestorage.com');

// Clés d'accès S3 (à générer dans le dashboard Cloudflare)
define('CLOUDFLARE_ACCESS_KEY', 'n7vkNsrFfFhNY4Ks86BLsRWMHl8e48QIq_K_VTdI'); // Votre token API
define('CLOUDFLARE_SECRET_KEY', ''); // Laissez vide si vous utilisez le token API

// URL publique pour les vidéos
define('CLOUDFLARE_PUBLIC_URL', 'https://pub-' . substr(CLOUDFLARE_ACCOUNT_ID, 0, 8) . '.r2.dev');

// Taille maximale pour l'upload (500MB)
define('CLOUDFLARE_MAX_FILE_SIZE', 500 * 1024 * 1024);

// Types MIME autorisés
define('CLOUDFLARE_ALLOWED_MIMES', [
    'video/mp4', 'video/quicktime', 'video/x-msvideo', 
    'video/x-ms-wmv', 'video/x-matroska', 'video/webm',
    'video/x-flv', 'video/3gpp', 'application/octet-stream'
]);

// Initialisation du client S3 pour Cloudflare R2
use Aws\S3\S3Client;

function getCloudflareS3Client() {
    return new S3Client([
        'version' => 'latest',
        'region' => CLOUDFLARE_REGION,
        'endpoint' => CLOUDFLARE_ENDPOINT,
        'credentials' => [
            'key' => CLOUDFLARE_ACCESS_KEY,
            'secret' => CLOUDFLARE_SECRET_KEY,
        ],
        'use_path_style_endpoint' => true,
        'signature_version' => 'v4',
    ]);
}

// Fonction pour générer une URL Cloudflare R2
function generateCloudflareUrl($objectKey) {
    if (empty($objectKey)) return '';
    
    // Si c'est déjà une URL complète
    if (strpos($objectKey, 'http') === 0) {
        return $objectKey;
    }
    
    // Générer l'URL Cloudflare
    return CLOUDFLARE_PUBLIC_URL . '/' . CLOUDFLARE_BUCKET . '/' . $objectKey;
}

// Fonction pour uploader un fichier vers Cloudflare R2
function uploadToCloudflareR2($filePath, $objectKey, $contentType = null) {
    try {
        $client = getCloudflareS3Client();
        
        if (!file_exists($filePath)) {
            throw new Exception("Fichier non trouvé: $filePath");
        }
        
        // Déterminer le type de contenu
        if (!$contentType) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'mp4' => 'video/mp4',
                'mov' => 'video/quicktime',
                'avi' => 'video/x-msvideo',
                'wmv' => 'video/x-ms-wmv',
                'mkv' => 'video/x-matroska',
                'webm' => 'video/webm',
                'flv' => 'video/x-flv',
                '3gp' => 'video/3gpp',
                'm4v' => 'video/mp4',
            ];
            $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
        }
        
        // Upload vers R2
        $result = $client->putObject([
            'Bucket' => CLOUDFLARE_BUCKET,
            'Key' => $objectKey,
            'SourceFile' => $filePath,
            'ContentType' => $contentType,
            'ACL' => 'public-read', // R2 ne supporte pas ACL, mais on le garde pour compatibilité
        ]);
        
        return generateCloudflareUrl($objectKey);
        
    } catch (Exception $e) {
        error_log("❌ Erreur upload Cloudflare R2: " . $e->getMessage());
        throw $e;
    }
}

// Fonction pour supprimer un fichier de Cloudflare R2
function deleteFromCloudflareR2($objectKey) {
    try {
        $client = getCloudflareS3Client();
        
        // Si c'est une URL complète, extraire la clé
        $key = extractObjectKeyFromUrl($objectKey);
        
        $client->deleteObject([
            'Bucket' => CLOUDFLARE_BUCKET,
            'Key' => $key
        ]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Erreur suppression Cloudflare R2: " . $e->getMessage());
        // Ne pas lever d'exception pour éviter de bloquer l'opération
        return false;
    }
}

// Fonction pour extraire la clé d'objet depuis une URL
function extractObjectKeyFromUrl($url) {
    if (empty($url)) return null;
    
    // Si c'est déjà une clé (pas une URL complète)
    if (!preg_match('/^https?:\/\//', $url)) {
        return $url;
    }
    
    // Extraire la clé depuis l'URL Cloudflare
    $parsedUrl = parse_url($url);
    $path = $parsedUrl['path'] ?? '';
    
    // Retirer le nom du bucket du chemin
    $path = ltrim($path, '/');
    
    // Si l'URL contient le bucket, le retirer
    if (strpos($path, CLOUDFLARE_BUCKET . '/') === 0) {
        return substr($path, strlen(CLOUDFLARE_BUCKET) + 1);
    }
    
    // Sinon, retourner le chemin complet
    return $path;
}

// Fonction pour générer un nom de fichier sécurisé pour R2
function generateR2ObjectKey($originalName, $produitId, $type = 'video', $segmentNum = null) {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]/', '', $extension);
    
    if (empty($extension) || !in_array($extension, ['mp4', 'mov', 'avi', 'wmv', 'mkv', 'webm', 'flv', '3gp', 'm4v'])) {
        $extension = 'mp4';
    }
    
    $timestamp = time();
    $random = bin2hex(random_bytes(4));
    
    $key = "produits/{$produitId}/{$type}_{$timestamp}_{$random}";
    
    if ($segmentNum !== null) {
        $key .= "_segment{$segmentNum}";
    }
    
    return $key . '.' . $extension;
}