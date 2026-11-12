
<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// Supabase credentials
define('SUPABASE_URL', rtrim($_ENV['SUPABASE_URL'] ?? '', '/'));
define('SUPABASE_KEY', $_ENV['SUPABASE_KEY'] ?? '');

if (SUPABASE_URL === '' || SUPABASE_KEY === '') {
    throw new RuntimeException(
        'Supabase configuration is missing. Please check your .env file.'
    );
}

// Supabase API request function
function supabaseRequest(
    string $endpoint,
    string $method = 'GET',
    ?array $data = null,
    ?string $accessToken = null
): array {

    $url = SUPABASE_URL . $endpoint;

    // Always send the publishable key as the API key
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY
    ];

    // Only send Authorization when a real user access token exists
    if ($accessToken !== null && $accessToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20
    ];

    if ($data !== null) {
        $jsonData = json_encode($data);

        if ($jsonData === false) {
            return [
                'success' => false,
                'status' => 0,
                'data' => null,
                'error' => 'Unable to encode request data.'
            ];
        }

        $options[CURLOPT_POSTFIELDS] = $jsonData;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'status' => 0,
            'data' => null,
            'error' => $error
        ];
    }

    $decoded = json_decode($response, true);

    $success = $status >= 200 && $status < 300;

    $errorMessage = null;

    if (!$success) {
        if (is_array($decoded)) {
            $errorMessage =
                $decoded['msg'] ??
                $decoded['message'] ??
                $decoded['error_description'] ??
                $decoded['error'] ??
                'Supabase returned an error.';
        } else {
            $errorMessage = 'Supabase returned HTTP ' . $status;
        }
    }

    return [
        'success' => $success,
        'status' => $status,
        'data' => $decoded,
        'error' => $errorMessage
    ];
}


/**
 * Upload a product image directly to Supabase Storage.
 * Only PNG, JPG, and WEBP files up to 5 MB are accepted.
 */
function supabaseUploadProductImage(
    array $file,
    string $accessToken
): array {

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'error' => 'PHP cURL extension is not enabled.'
        ];
    }

    if (
        empty($file) ||
        ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    ) {
        return [
            'success' => false,
            'error' => 'Please select a valid image.'
        ];
    }

    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        return [
            'success' => false,
            'error' => 'Invalid uploaded file.'
        ];
    }

    // Maximum file size: 5 MB
    if ($file['size'] > 5 * 1024 * 1024) {
        return [
            'success' => false,
            'error' => 'Image must not exceed 5 MB.'
        ];
    }

    // Detect actual image MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowedTypes[$mimeType])) {
        return [
            'success' => false,
            'error' => 'Only JPG, PNG, and WEBP images are allowed.'
        ];
    }

    // Verify that the file is a valid image
    if (@getimagesize($file['tmp_name']) === false) {
        return [
            'success' => false,
            'error' => 'The uploaded file is not a valid image.'
        ];
    }

    // Read image contents
    $imageContents = file_get_contents($file['tmp_name']);

    if ($imageContents === false) {
        return [
            'success' => false,
            'error' => 'Unable to read the uploaded image.'
        ];
    }

    // Generate a unique filename
    $filename = 'product-' . bin2hex(random_bytes(12))
        . '.' . $allowedTypes[$mimeType];

    $url = SUPABASE_URL
        . '/storage/v1/object/product-images/'
        . rawurlencode($filename);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $imageContents,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $mimeType,
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . $accessToken,
            'x-upsert: false'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'error' => 'Image upload failed: ' . $curlError
        ];
    }

    $responseData = json_decode($response, true);

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return [
            'success' => false,
            'error' => $responseData['message']
                ?? $responseData['error']
                ?? 'Supabase rejected the image upload.'
        ];
    }

    return [
        'success' => true,
        'filename' => $filename,
        'url' => SUPABASE_URL
            . '/storage/v1/object/public/product-images/'
            . rawurlencode($filename)
    ];
}

/**
 * Upload a customer's profile picture to Supabase Storage.
 * Bucket: customer-profiles
 * Folder: customer's Supabase user ID
 */
function supabaseUploadCustomerAvatar(
    array $file,
    string $customerId,
    string $accessToken
): array {

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'error' => 'PHP cURL extension is not enabled.'
        ];
    }

    if (
        empty($file) ||
        ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    ) {
        return [
            'success' => false,
            'error' => 'Please select a profile picture.'
        ];
    }

    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        return [
            'success' => false,
            'error' => 'Invalid uploaded file.'
        ];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return [
            'success' => false,
            'error' => 'Profile picture must not exceed 5 MB.'
        ];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowedTypes[$mimeType])) {
        return [
            'success' => false,
            'error' => 'Only JPG, PNG, and WEBP images are allowed.'
        ];
    }

    if (@getimagesize($file['tmp_name']) === false) {
        return [
            'success' => false,
            'error' => 'Invalid image file.'
        ];
    }

    $imageContents = file_get_contents($file['tmp_name']);

    if ($imageContents === false) {
        return [
            'success' => false,
            'error' => 'Unable to read the image.'
        ];
    }

    $extension = $allowedTypes[$mimeType];

    // Each customer has their own folder.
    $filename = $customerId . '/avatar.' . $extension;

    $url = SUPABASE_URL
        . '/storage/v1/object/customer-profiles/'
        . implode('/', array_map('rawurlencode', explode('/', $filename)));

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $imageContents,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $mimeType,
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . $accessToken,
            'x-upsert: true'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'error' => 'Upload failed: ' . $error
        ];
    }

    if ($status < 200 || $status >= 300) {
        $decoded = json_decode($response, true);

        return [
            'success' => false,
            'error' => $decoded['message']
                ?? $decoded['error']
                ?? 'Supabase rejected the upload.'
        ];
    }

    return [
        'success' => true,
        'avatar_url' => $filename
    ];
}