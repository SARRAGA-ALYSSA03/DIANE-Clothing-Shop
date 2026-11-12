
<?php
date_default_timezone_set('Asia/Manila');

session_start();

require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/admin_navigation.php';

// 1. Verify admin session
if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['access_token'])
) {
    header('Location: login.php');
    exit;
}

$accessToken = $_SESSION['access_token'];

// Admin role was already checked during login. Keep the active session here.

// 3. Generate a signed URL for a private profile image
function getUserAvatarUrl($path, $accessToken)
{
    if (
        empty($path) ||
        !is_string($path) ||
        !function_exists('curl_init')
    ) {
        return '';
    }

    $encodedPath = str_replace(
        '%2F',
        '/',
        rawurlencode($path)
    );

    $url = rtrim(SUPABASE_URL, '/') .
        '/storage/v1/object/sign/customer-profiles/' .
        $encodedPath;

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

    if (
        $response === false ||
        $status < 200 ||
        $status >= 300
    ) {
        return '';
    }

    $data = json_decode($response, true);
    $signedPath = $data['signedURL'] ?? '';

    if (
        !is_string($signedPath) ||
        $signedPath === ''
    ) {
        return '';
    }

    if (strpos($signedPath, 'http') === 0) {
        return $signedPath;
    }

    if (strpos($signedPath, '/storage/v1/') === 0) {
        return rtrim(SUPABASE_URL, '/') . $signedPath;
    }

    return rtrim(SUPABASE_URL, '/') .
        '/storage/v1/' . ltrim($signedPath, '/');
}

// 4. Retrieve all user profiles, including avatar_url
$result = supabaseRequest(
    '/rest/v1/profiles?select=id,name,email,role,created_at,avatar_url&order=created_at.desc&limit=1000',
    'GET',
    null,
    $accessToken
);

$users = (
    !empty($result['success']) &&
    isset($result['data']) &&
    is_array($result['data'])
)
    ? $result['data']
    : [];

$error = empty($result['success'])
    ? 'Unable to load user accounts. Please check your Supabase connection and permissions.'
    : '';

// 5. Prepare profile picture URLs
foreach ($users as &$user) {

    $user['avatar_display'] = '';

    if (!empty($user['avatar_url'])) {

        $user['avatar_display'] = getUserAvatarUrl(
            $user['avatar_url'],
            $accessToken
        );
    }
}

unset($user);

// 6. Count user types
$totalUsers = count($users);
$totalAdmins = 0;
$totalCustomers = 0;

foreach ($users as $user) {

    if (($user['role'] ?? '') === 'admin') {
        $totalAdmins++;
    } elseif (($user['role'] ?? '') === 'customer') {
        $totalCustomers++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management | Diane Clothing Shop</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f4f2;
            color: #171717;
            font-family: Arial, Helvetica, sans-serif;
        }

        .page-container {
            max-width: 1450px;
            margin: auto;
            padding: 38px 42px 55px;
        }

        .eyebrow {
            color: #777;
            font-size: 10px;
            letter-spacing: 2.5px;
            font-weight: bold;
            margin-bottom: 12px;
        }

        .page-heading {
            margin-bottom: 30px;
        }

        h1 {
            margin: 0 0 9px;
            font-size: 34px;
            font-weight: 500;
            letter-spacing: -1px;
        }

        .page-heading p {
            margin: 0;
            color: #777;
            font-size: 14px;
            line-height: 1.6;
        }

        /* Summary cards */

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .summary-card {
            background: white;
            border: 1px solid #e6e6e6;
            padding: 23px;
        }

        .summary-label {
            margin-bottom: 12px;
            color: #777;
            font-size: 11px;
            letter-spacing: 1.3px;
        }

        .summary-value {
            font-size: 29px;
            font-weight: 500;
        }

        /* User table */

        .table-panel {
            background: white;
            border: 1px solid #e6e6e6;
            overflow: hidden;
        }

        .table-heading {
            padding: 23px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            border-bottom: 1px solid #eee;
        }

        .table-heading h2 {
            margin: 0;
            font-size: 17px;
            font-weight: 500;
        }

        .table-heading span {
            color: #777;
            font-size: 12px;
        }

        .table-scroll {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
        }

        th {
            padding: 17px 16px;
            background: #f8f8f7;
            color: #666;
            text-align: left;
            font-size: 11px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        td {
            padding: 17px 16px;
            border-top: 1px solid #eee;
            font-size: 13px;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #fafafa;
        }

        /* Profile pictures */

        .user-profile {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            object-fit: cover;
            display: block;
            border: 1px solid #e5e5e5;
            background: #f0f0f0;
        }

        .user-avatar-placeholder {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: #ededed;
            color: #555;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 16px;
            font-weight: 500;
            border: 1px solid #e5e5e5;
        }

        .user-id {
            max-width: 190px;
            font-family: monospace;
            color: #777;
            font-size: 11px;
            word-break: break-all;
        }

        .user-name {
            font-weight: bold;
        }

        .user-email {
            color: #555;
            word-break: break-word;
        }

        .role-badge {
            display: inline-block;
            padding: 7px 11px;
            background: #eee;
            color: #333;
            font-size: 10px;
            letter-spacing: 1px;
        }

        .role-badge.admin {
            background: #111;
            color: white;
        }

        .role-badge.customer {
            background: #e9eef7;
            color: #34537f;
        }

        /* Empty and error states */

        .empty {
            text-align: center;
            background: white;
            padding: 60px 20px;
        }

        .empty-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 18px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f3f3f3;
            border-radius: 50%;
            color: #555;
        }

        .empty-icon svg {
            width: 30px;
            height: 30px;
        }

        .empty h3 {
            margin: 0 0 10px;
            font-size: 19px;
            font-weight: 500;
        }

        .empty p {
            color: #777;
            font-size: 14px;
            line-height: 1.6;
        }

        .notification {
            padding: 16px 18px;
            margin-bottom: 20px;
            background: #f9e8e8;
            color: #982b2b;
            border: 1px solid #edcece;
            font-size: 14px;
            line-height: 1.6;
        }

        .note {
            margin-top: 18px;
            padding: 15px 18px;
            background: white;
            border: 1px solid #e6e6e6;
            color: #777;
            font-size: 12px;
            line-height: 1.7;
        }

        @media (max-width: 850px) {

            .summary-grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 600px) {

            .page-container {
                padding: 27px 16px 40px;
            }

            h1 {
                font-size: 28px;
            }

            .table-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .empty {
                padding: 45px 18px;
            }

        }

    </style>

</head>

<body>

<?php renderAdminNavigation(); ?>

<main class="page-container">

    <!-- Page heading -->

    <section class="page-heading">

        <div class="eyebrow">
            DIANE CLOTHING SHOP / ACCOUNTS
        </div>

        <h1>User Management</h1>

        <p>
            View and monitor registered accounts in your clothing shop.
        </p>

    </section>

    <?php if ($error !== ''): ?>

        <div class="notification">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php else: ?>

        <!-- Account summaries -->

        <section class="summary-grid">

            <div class="summary-card">

                <div class="summary-label">
                    TOTAL REGISTERED USERS
                </div>

                <div class="summary-value">
                    <?= number_format($totalUsers) ?>
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-label">
                    ADMIN ACCOUNTS
                </div>

                <div class="summary-value">
                    <?= number_format($totalAdmins) ?>
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-label">
                    CUSTOMER ACCOUNTS
                </div>

                <div class="summary-value">
                    <?= number_format($totalCustomers) ?>
                </div>

            </div>

        </section>

        <?php if (empty($users)): ?>

            <!-- Empty state -->

            <section class="empty">

                <div class="empty-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>

                </div>

                <h3>No User Accounts Found</h3>

                <p>
                    Registered administrator and customer accounts
                    will appear here.
                </p>

            </section>

        <?php else: ?>

            <!-- Registered users table -->

            <section class="table-panel">

                <div class="table-heading">

                    <h2>Registered User Accounts</h2>

                    <span>
                        <?= count($users) ?> users displayed
                    </span>

                </div>

                <div class="table-scroll">

                    <table>

                        <thead>

                            <tr>
                                <th>Profile</th>
                                <th>User ID</th>
                                <th>Full Name</th>
                                <th>Email Address</th>
                                <th>Account Role</th>
                                <th>Date Registered</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($users as $user): ?>

                                <?php

                                $role = $user['role'] ?? 'unassigned';

                                $roleClass = strtolower($role);

                                $date = $user['created_at'] ?? '';

                                $registrationDate =
                                    $date && strtotime($date)
                                        ? date('M d, Y', strtotime($date))
                                        : 'N/A';

                                $displayName = trim($user['name'] ?? '');

                                $initial = $displayName !== ''
                                    ? strtoupper(substr($displayName, 0, 1))
                                    : 'U';

                                ?>

                                <tr>

                                    <!-- Profile picture -->

                                    <td>

                                        <?php if (!empty($user['avatar_display'])): ?>

                                            <img
                                                src="<?= htmlspecialchars(
                                                    $user['avatar_display'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                alt="User profile"
                                                class="user-profile"
                                                loading="lazy"
                                            >

                                        <?php else: ?>

                                            <div class="user-avatar-placeholder">
                                                <?= htmlspecialchars(
                                                    $initial,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </div>

                                        <?php endif; ?>

                                    </td>

                                    <!-- User ID -->

                                    <td class="user-id">
                                        <?= htmlspecialchars(
                                            $user['id'] ?? '',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>

                                    <!-- Full Name -->

                                    <td class="user-name">
                                        <?= htmlspecialchars(
                                            $displayName !== '' ? $displayName : 'No name',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>

                                    <!-- Email -->

                                    <td class="user-email">
                                        <?= htmlspecialchars(
                                            $user['email'] ?? '',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>

                                    <!-- Account Role -->

                                    <td>

                                        <span class="role-badge <?= htmlspecialchars(
                                            $roleClass,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>">

                                            <?= htmlspecialchars(
                                                strtoupper($role),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </span>

                                    </td>

                                    <!-- Registration Date -->

                                    <td>
                                        <?= htmlspecialchars(
                                            $registrationDate,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        <?php endif; ?>

        <div class="note">
            User account information is currently read-only.
            Role changes and account deletion are not enabled.
            This helps prevent accidental changes to administrator access.
        </div>

    <?php endif; ?>

</main>

</body>
</html>
