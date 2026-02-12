<?php
/**
 * upload-to-cloudflare.php
 * Proxy d'upload pour Cloudflare R2 (compatible S3)
 * 
 * Endpoint attendu par l'application React Native :
 * - Méthode : POST
 * - Paramètres : file (fichier vidéo), type ('video' ou 'preview')
 * 
 * Retourne un JSON : { success: bool, url: string, object_key: string, ... }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

// Pré‑vol CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ------------------------------------------------------------------
// 1. Vérifications préliminaires
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ------------------------------------------------------------------
// 2. Configuration Cloudflare R2 (depuis variables d'environnement)
// ------------------------------------------------------------------
$accountId       = getenv('CLOUDFLARE_ACCOUNT_ID');
$accessKeyId     = getenv('CLOUDFLARE_R2_ACCESS_KEY_ID');
$accessKeySecret = getenv('CLOUDFLARE_R2_SECRET_ACCESS_KEY');
$bucketName      = getenv('CLOUDFLARE_R2_BUCKET_NAME');
$publicUrlBase   = getenv('CLOUDFLARE_R2_PUBLIC_URL'); // ex: https://pub-xxxx.r2.dev

if (!$accountId || !$accessKeyId || !$accessKeySecret || !$bucketName || !$publicUrlBase) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Cloudflare R2 configuration missing']);
    exit;
}

// ------------------------------------------------------------------
// 3. Lecture des paramètres POST et du fichier
// ------------------------------------------------------------------
$type = $_POST['type'] ?? '';
if (!in_array($type, ['video', 'preview'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid type parameter']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload',
    ];
    $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = $errors[$code] ?? 'Unknown upload error';
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$file = $_FILES['file'];
$tmpPath   = $file['tmp_name'];
$origName  = $file['name'];
$fileSize  = $file['size'];
$mimeType  = $file['type'];

// ------------------------------------------------------------------
// 4. Validations supplémentaires
// ------------------------------------------------------------------
define('MAX_FILE_SIZE', 500 * 1024 * 1024); // 500 Mo
if ($fileSize > MAX_FILE_SIZE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File too large (max 500MB)']);
    exit;
}

$allowedMime = [
    'video/mp4', 'video/quicktime', 'video/x-msvideo',
    'video/x-ms-wmv', 'video/x-matroska', 'video/webm',
    'video/x-flv', 'video/3gpp'
];
if (!in_array($mimeType, $allowedMime)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only video files allowed.']);
    exit;
}

// ------------------------------------------------------------------
// 5. Génération d'un nom unique pour l'objet R2
// ------------------------------------------------------------------
$ext = pathinfo($origName, PATHINFO_EXTENSION);
$ext = $ext ?: 'mp4';
$objectKey = $type . '/' . uniqid() . '_' . date('Ymd') . '.' . $ext;
$publicUrl = rtrim($publicUrlBase, '/') . '/' . $objectKey;

// ------------------------------------------------------------------
// 6. Upload vers Cloudflare R2 (signature AWS Signature V4)
// ------------------------------------------------------------------
$region    = 'auto';
$service   = 's3';
$timestamp = gmdate('Ymd\THis\Z');
$date      = gmdate('Ymd');

// --- Construction de la requête canonique ---
$canonicalRequest = implode("\n", [
    'PUT',
    "/{$bucketName}/{$objectKey}",
    '', // pas de query string
    'host:' . $accountId . '.r2.cloudflarestorage.com',
    'x-amz-content-sha256:UNSIGNED-PAYLOAD',
    'x-amz-date:' . $timestamp,
    '',
    'host;x-amz-content-sha256;x-amz-date',
    'UNSIGNED-PAYLOAD'
]);

$hashedCanonical = hash('sha256', $canonicalRequest);

// --- String to sign ---
$algorithm      = 'AWS4-HMAC-SHA256';
$credentialScope = "{$date}/{$region}/{$service}/aws4_request";
$stringToSign   = implode("\n", [
    $algorithm,
    $timestamp,
    $credentialScope,
    $hashedCanonical
]);

// --- Calcul de la signature ---
function hmac($key, $data) {
    return hash_hmac('sha256', $data, $key, true);
}

$dateKey      = hmac('AWS4' . $accessKeySecret, $date);
$regionKey    = hmac($dateKey, $region);
$serviceKey   = hmac($regionKey, $service);
$signingKey   = hmac($serviceKey, 'aws4_request');

$signature    = hash_hmac('sha256', $stringToSign, $signingKey);

// --- En‑tête d'autorisation ---
$authorizationHeader = "{$algorithm} Credential={$accessKeyId}/{$credentialScope}, " .
                       "SignedHeaders=host;x-amz-content-sha256;x-amz-date, " .
                       "Signature={$signature}";

// --- URL de l'objet (endpoint S3) ---
$s3Url = "https://{$accountId}.r2.cloudflarestorage.com/{$bucketName}/{$objectKey}";

// --- Lecture du contenu du fichier ---
$fileContent = file_get_contents($tmpPath);
if ($fileContent === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to read uploaded file']);
    exit;
}

// --- Requête cURL PUT signée ---
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $s3Url);
curl_setopt($ch, CURLOPT_PUT, true);

// On place le contenu dans un flux mémoire
$fp = fopen('php://memory', 'r+');
fwrite($fp, $fileContent);
rewind($fp);

curl_setopt($ch, CURLOPT_INFILE, $fp);
curl_setopt($ch, CURLOPT_INFILESIZE, strlen($fileContent));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Host: ' . $accountId . '.r2.cloudflarestorage.com',
    'x-amz-content-sha256: UNSIGNED-PAYLOAD',
    'x-amz-date: ' . $timestamp,
    'Authorization: ' . $authorizationHeader,
    'Content-Type: ' . $mimeType,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ------------------------------------------------------------------
// 7. Gestion de la réponse de Cloudflare
// ------------------------------------------------------------------
if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Cloudflare R2 upload failed',
        'debug'   => $response  // à retirer en production
    ]);
    exit;
}

// ------------------------------------------------------------------
// 8. Succès – retour des informations au client
// ------------------------------------------------------------------
echo json_encode([
    'success'   => true,
    'message'   => 'File uploaded successfully to Cloudflare R2',
    'url'       => $publicUrl,
    'object_key'=> $objectKey,
    'cloudflare' => [
        'video_url'        => $publicUrl,
        'video_object_key' => $objectKey,
    ],
    'type'      => $type,
    'size'      => $fileSize
]);