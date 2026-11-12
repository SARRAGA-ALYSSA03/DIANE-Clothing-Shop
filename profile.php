
<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/supabase.php';

// Check customer login
if (
    empty($_SESSION['customer_id']) ||
    empty($_SESSION['customer_access_token'])
) {
    header('Location: login.php');
    exit;
}

$customerId = (string) $_SESSION['customer_id'];
$accessToken = (string) $_SESSION['customer_access_token'];

if (empty($_SESSION['customer_csrf'])) {
    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['customer_csrf'];

$message = '';
$messageType = '';

function escapeProfile($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Upload customer profile picture to Supabase Storage
function uploadCustomerProfileImage(
    array $file,
    string $customerId,
    string $accessToken
): array {

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
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

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return [
            'success' => false,
            'error' => 'Image must not exceed 5 MB.'
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'error' => 'PHP cURL is not enabled.'
        ];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (
        !isset($allowed[$mime]) ||
        @getimagesize($file['tmp_name']) === false
    ) {
        return [
            'success' => false,
            'error' => 'Only valid JPG, PNG, or WEBP images are allowed.'
        ];
    }

    $contents = file_get_contents($file['tmp_name']);

    if ($contents === false) {
        return [
            'success' => false,
            'error' => 'Unable to read the selected image.'
        ];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $path = $customerId . '/' . $filename;

    $encodedPath = str_replace('%2F', '/', rawurlencode($path));

    $url = rtrim(SUPABASE_URL, '/')
        . '/storage/v1/object/customer-profiles/'
        . $encodedPath;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $contents,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $mime,
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . $accessToken,
            'x-upsert: false'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {

        $errorMessage = 'Unable to upload your profile picture.';

        if ($response !== false) {
            $decoded = json_decode($response, true);

            if (is_array($decoded)) {
                $errorMessage = $decoded['message']
                    ?? $decoded['error']
                    ?? $errorMessage;
            }
        } elseif ($curlError !== '') {
            $errorMessage = $curlError;
        }

        return [
            'success' => false,
            'error' => $errorMessage
        ];
    }

    return [
        'success' => true,
        'path' => $path
    ];
}

// Generate a temporary URL for a private profile picture
function getCustomerProfileImageUrl(
    string $path,
    string $accessToken
): string {

    if ($path === '' || !function_exists('curl_init')) {
        return '';
    }

    $encodedPath = str_replace('%2F', '/', rawurlencode($path));

    $url = rtrim(SUPABASE_URL, '/')
        . '/storage/v1/object/sign/customer-profiles/'
        . $encodedPath;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'expiresIn' => 3600
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        return '';
    }

    $result = json_decode($response, true);
    $signedPath = $result['signedURL'] ?? '';

    if (!is_string($signedPath) || $signedPath === '') {
        return '';
    }

    if (strpos($signedPath, 'http') === 0) {
        return $signedPath;
    }

    if (strpos($signedPath, '/storage/v1/') === 0) {
        return rtrim(SUPABASE_URL, '/') . $signedPath;
    }

    return rtrim(SUPABASE_URL, '/') . '/storage/v1/' . ltrim($signedPath, '/');
}

// UPDATE PROFILE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid request. Please refresh the page.');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));

    if ($name === '') {

        $message = 'Please enter your full name.';
        $messageType = 'error';

    } elseif (strlen($name) > 80) {

        $message = 'Name must not exceed 80 characters.';
        $messageType = 'error';

    } elseif (
        $phone !== '' &&
        !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)
    ) {

        $message = 'Please enter a valid contact number.';
        $messageType = 'error';

    } else {

        $updateData = [
            'name' => $name,
            'phone' => $phone
        ];

        // Check whether a new profile picture was selected
        $hasSelectedImage = isset($_FILES['avatar']) &&
            ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE)
            !== UPLOAD_ERR_NO_FILE;

        if ($hasSelectedImage) {

            $uploadResult = uploadCustomerProfileImage(
                $_FILES['avatar'],
                $customerId,
                $accessToken
            );

            if (empty($uploadResult['success'])) {

                $message = 'Profile picture upload failed: '
                    . ($uploadResult['error'] ?? 'Please try again.');

                $messageType = 'error';

            } else {

                // Save the image object path in the profiles table
                $updateData['avatar_url'] = $uploadResult['path'];
            }
        }

        if ($messageType !== 'error') {

            $updateResult = supabaseRequest(
                '/rest/v1/profiles?id=eq.' . rawurlencode($customerId),
                'PATCH',
                $updateData,
                $accessToken
            );

            if (!empty($updateResult['success'])) {

                $_SESSION['customer_name'] = $name;

                $message = 'Profile updated successfully!';
                $messageType = 'success';

            } else {

                $message = 'Unable to update profile: '
                    . ($updateResult['error'] ?? 'Please try again.');

                $messageType = 'error';
            }
        }
    }
}

// GET CUSTOMER PROFILE
$profileResult = supabaseRequest(
    '/rest/v1/profiles?select=id,name,email,role,phone,avatar_url&id=eq.'
    . rawurlencode($customerId) . '&limit=1',
    'GET',
    null,
    $accessToken
);

$profile = null;

if (
    !empty($profileResult['success']) &&
    is_array($profileResult['data']) &&
    !empty($profileResult['data'][0])
) {
    $profile = $profileResult['data'][0];
}

if (!$profile) {

    $message = 'Unable to load your profile. Please sign in again.';
    $messageType = 'error';

} elseif (($profile['role'] ?? '') !== 'customer') {

    http_response_code(403);
    exit('This page is for customer accounts only.');

} else {

    $_SESSION['customer_name'] = $profile['name'] ?? '';
    $_SESSION['customer_email'] = $profile['email'] ?? '';
}

// Get profile picture viewing URL
$profileImageUrl = '';

if ($profile && !empty($profile['avatar_url'])) {

    $profileImageUrl = getCustomerProfileImageUrl(
        (string) $profile['avatar_url'],
        $accessToken
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>My Profile | DIANE</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f5f5f3;
    color: #111;
    font-family: "Helvetica Neue", Arial, sans-serif;
}

header {
    background: white;
    padding: 20px 6%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #ddd;
}

.logo {
    font-family: Georgia, serif;
    font-size: 30px;
    letter-spacing: 5px;
    text-decoration: none;
    color: #111;
}

.back-link {
    text-decoration: none;
    color: #111;
    font-size: 11px;
    letter-spacing: 1px;
}

main {
    width: 90%;
    max-width: 700px;
    margin: 55px auto;
}

.eyebrow {
    font-size: 10px;
    letter-spacing: 3px;
    color: #777;
}

h1 {
    font-family: Georgia, serif;
    font-size: 40px;
    font-weight: normal;
    margin: 12px 0;
}

.subtitle {
    color: #777;
    font-size: 13px;
    line-height: 1.7;
}

.profile-card {
    background: white;
    padding: 35px;
    margin-top: 30px;
    border: 1px solid #e5e5e5;
}

/* PROFILE PICTURE */

.avatar-section {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 32px;
    padding-bottom: 25px;
    border-bottom: 1px solid #eee;
}

.avatar-wrap {
    position: relative;
    width: 105px;
    height: 105px;
    flex-shrink: 0;
}

.avatar-image,
.avatar-placeholder {
    position: absolute;
    inset: 0;
    width: 105px;
    height: 105px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid #ddd;
    background: #f0f0ee;
}

.avatar-image[hidden] {
    display: none !important;
}

.avatar-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
}

.avatar-upload-label {
    position: absolute;
    right: 0;
    bottom: 0;
    width: 35px;
    height: 35px;
    border: 2px solid white;
    border-radius: 50%;
    background: #111;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.avatar-upload-label svg {
    width: 17px;
    height: 17px;
}

#avatar {
    display: none;
}

.avatar-copy strong {
    display: block;
    margin-bottom: 7px;
    font-size: 13px;
    letter-spacing: .5px;
}

.avatar-copy span {
    display: block;
    color: #777;
    font-size: 11px;
    line-height: 1.7;
}

.form-group {
    margin-bottom: 23px;
}

.field-label {
    display: block;
    font-size: 11px;
    font-weight: bold;
    margin-bottom: 9px;
    letter-spacing: 1px;
}

.form-group input {
    width: 100%;
    height: 46px;
    padding: 12px;
    border: 1px solid #ccc;
    background: white;
    font-size: 13px;
}

.form-group input:focus {
    outline: 1px solid black;
}

.form-group input[readonly] {
    background: #f2f2f2;
    color: #777;
}

.hint {
    font-size: 11px;
    color: #777;
    margin-top: 7px;
}

.save-button {
    width: 100%;
    height: 48px;
    background: #111;
    color: white;
    border: none;
    cursor: pointer;
    font-size: 11px;
    letter-spacing: 2px;
}

.save-button:hover {
    background: #333;
}

.message {
    padding: 13px;
    margin-top: 20px;
    font-size: 12px;
    line-height: 1.6;
}

.success {
    background: #e8f2e9;
    color: #245b31;
}

.error {
    background: #faeaea;
    color: #8b2929;
}

.note {
    font-size: 11px;
    color: #777;
    line-height: 1.7;
    margin-top: 20px;
}

@media(max-width: 500px) {

    main {
        margin: 35px auto;
    }

    .profile-card {
        padding: 22px;
    }

    h1 {
        font-size: 32px;
    }

    .avatar-section {
        gap: 15px;
    }
}

</style>

</head>

<body>

<header>

    <a href="index.php" class="logo">
        DIANE
    </a>

    <a href="index.php" class="back-link">
        BACK TO SHOP
    </a>

</header>

<main>

    <p class="eyebrow">
        DIANE CLOTHING SHOP / ACCOUNT
    </p>

    <h1>My Profile</h1>

    <p class="subtitle">
        Manage your personal information
        and keep your contact details updated.
    </p>

    <section class="profile-card">

        <?php if ($profile): ?>

        <form
            method="POST"
            action="profile.php"
            enctype="multipart/form-data"
            id="profileForm"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeProfile($csrfToken) ?>"
            >

            <!-- PROFILE PICTURE UPLOAD -->

            <div class="avatar-section">

                <div class="avatar-wrap">

                    <?php if ($profileImageUrl !== ''): ?>

                        <img
                            src="<?= escapeProfile($profileImageUrl) ?>"
                            alt="Your profile picture"
                            class="avatar-image"
                            id="avatarPreview"
                        >

                    <?php else: ?>

                        <div
                            class="avatar-placeholder"
                            id="avatarPlaceholder"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <circle cx="12" cy="8" r="4"></circle>
                                <path d="M4 21c0-4.2 3.6-7 8-7s8 2.8 8 7"></path>
                            </svg>
                        </div>

                        <img
                            src=""
                            alt="Your profile picture"
                            class="avatar-image"
                            id="avatarPreview"
                            hidden
                        >

                    <?php endif; ?>

                    <label
                        class="avatar-upload-label"
                        for="avatar"
                        title="Change profile picture"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path>
                        </svg>
                    </label>

                </div>

                <div class="avatar-copy">

                    <strong>Profile Picture</strong>

                    <span>
                        Choose a photo that represents you.
                    </span>

                    <span>
                        JPG, PNG, or WEBP · Maximum 5 MB
                    </span>

                </div>

                <input
                    type="file"
                    id="avatar"
                    name="avatar"
                    accept="image/jpeg,image/png,image/webp"
                >

            </div>

            <!-- FULL NAME -->

            <div class="form-group">

                <label class="field-label" for="name">
                    FULL NAME
                </label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="80"
                    required
                    value="<?= escapeProfile($profile['name'] ?? '') ?>"
                >

            </div>

            <!-- EMAIL -->

            <div class="form-group">

                <label class="field-label" for="email">
                    EMAIL ADDRESS
                </label>

                <input
                    type="email"
                    id="email"
                    readonly
                    value="<?= escapeProfile($profile['email'] ?? '') ?>"
                >

                <p class="hint">
                    Email changes require verification.
                </p>

            </div>

            <!-- CONTACT NUMBER -->

            <div class="form-group">

                <label class="field-label" for="phone">
                    CONTACT NUMBER
                </label>

                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    maxlength="20"
                    placeholder="09XXXXXXXXX"
                    value="<?= escapeProfile($profile['phone'] ?? '') ?>"
                >

            </div>

            <button type="submit" class="save-button">
                SAVE CHANGES
            </button>

        </form>

        <?php endif; ?>

        <?php if ($message !== ''): ?>

            <div class="message <?= escapeProfile($messageType) ?>">
                <?= escapeProfile($message) ?>
            </div>

        <?php endif; ?>

        <p class="note">
            Your account ID and account role are protected.
            You cannot change your customer privileges
            through this page.
        </p>

    </section>

</main>

<script>

// Profile picture preview
const avatarInput = document.getElementById('avatar');
const avatarPreview = document.getElementById('avatarPreview');
const avatarPlaceholder = document.getElementById('avatarPlaceholder');

if (avatarInput) {

    avatarInput.addEventListener('change', function () {

        const file = this.files && this.files[0];

        if (!file) {
            return;
        }

        const allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];

        if (!allowedTypes.includes(file.type)) {
            alert('Please choose a JPG, PNG, or WEBP image.');
            this.value = '';
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            alert('The image must not exceed 5 MB.');
            this.value = '';
            return;
        }

        const previewUrl = URL.createObjectURL(file);

        avatarPreview.src = previewUrl;
        avatarPreview.hidden = false;

        if (avatarPlaceholder) {
            avatarPlaceholder.hidden = true;
        }

    });

}

</script>

</body>
</html>
