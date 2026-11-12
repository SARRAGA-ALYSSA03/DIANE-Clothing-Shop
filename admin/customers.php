
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

// 2. Verify admin role
$profile = supabaseRequest(
    '/rest/v1/profiles?select=id,role&id=eq.' .
    urlencode($_SESSION['admin_id']),
    'GET',
    null,
    $_SESSION['access_token']
);

$admin = $profile['data'][0] ?? null;

if (
    !$profile['success'] ||
    !$admin ||
    $admin['role'] !== 'admin'
) {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

// 3. Retrieve customer profiles, including avatar_url
$result = supabaseRequest(
    '/rest/v1/profiles?select=id,name,email,role,created_at,avatar_url&role=eq.customer&order=created_at.desc&limit=1000',
    'GET',
    null,
    $_SESSION['access_token']
);

$customers = $result['success'] && is_array($result['data'])
    ? $result['data']
    : [];

$error = !$result['success']
    ? 'Unable to load customer accounts. Please check your Supabase connection and permissions.'
    : '';

// 4. Count customers
$totalCustomers = count($customers);

// 5. Generate signed URLs for customer profile pictures
foreach ($customers as &$customer) {

    $customer['avatar_display'] = '';

    $avatarPath = trim($customer['avatar_url'] ?? '');

    if ($avatarPath !== '') {

        $encodedPath = implode(
            '/',
            array_map('rawurlencode', explode('/', $avatarPath))
        );

        $avatarResult = supabaseRequest(
            '/storage/v1/object/sign/customer-profiles/' . $encodedPath,
            'POST',
            ['expiresIn' => 3600],
            $_SESSION['access_token']
        );

        if (
            $avatarResult['success'] &&
            !empty($avatarResult['data']['signedURL'])
        ) {

            $signedURL = $avatarResult['data']['signedURL'];

            if (strpos($signedURL, 'http') === 0) {
                $customer['avatar_display'] = $signedURL;
            } else {
                $customer['avatar_display'] =
                    rtrim(SUPABASE_URL, '/') .
                    '/storage/v1/' .
                    ltrim($signedURL, '/');
            }
        }
    }
}

unset($customer);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Customers | Diane Clothing Shop</title>

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
            min-width: 850px;
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

        .customer-id {
            font-family: monospace;
            color: #777;
            font-size: 11px;
            word-break: break-all;
            max-width: 190px;
        }

        .customer-name {
            font-weight: bold;
        }

        .customer-email {
            color: #555;
            word-break: break-word;
        }

        .customer-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid #e5e5e5;
            background: #f2f2f2;
            display: block;
        }

        .customer-avatar-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eeeeee;
            color: #555;
            font-size: 17px;
        }

        .role-badge {
            display: inline-block;
            padding: 7px 11px;
            background: #eee;
            color: #333;
            font-size: 10px;
            letter-spacing: 1px;
        }

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

    <section class="page-heading">

        <div class="eyebrow">
            DIANE CLOTHING SHOP / ACCOUNTS
        </div>

        <h1>Customer Management</h1>

        <p>
            View and monitor customers registered in your clothing shop.
        </p>

    </section>

    <?php if ($error !== ''): ?>

        <div class="notification">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php else: ?>

        <section class="summary-grid">

            <div class="summary-card">
                <div class="summary-label">
                    TOTAL CUSTOMERS
                </div>

                <div class="summary-value">
                    <?= number_format($totalCustomers) ?>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-label">
                    ACCOUNT TYPE
                </div>

                <div class="summary-value">
                    CUSTOMER
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-label">
                    ACCOUNT DATABASE
                </div>

                <div class="summary-value">
                    SUPABASE
                </div>
            </div>

        </section>

        <?php if (empty($customers)): ?>

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

                <h3>No Customers Found</h3>

                <p>
                    Registered customer accounts will appear here
                    once customers create their accounts.
                </p>

            </section>

        <?php else: ?>

            <section class="table-panel">

                <div class="table-heading">

                    <h2>Registered Customers</h2>

                    <span>
                        <?= count($customers) ?> customers displayed
                    </span>

                </div>

                <div class="table-scroll">

                    <table>

                        <thead>
                            <tr>
                                <th>Profile</th>
                                <th>Customer ID</th>
                                <th>Full Name</th>
                                <th>Email Address</th>
                                <th>Account Type</th>
                                <th>Date Registered</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($customers as $customer): ?>

                                <?php
                                $date = $customer['created_at'] ?? '';

                                $registrationDate =
                                    $date && strtotime($date)
                                        ? date('M d, Y', strtotime($date))
                                        : 'N/A';

                                $customerName = $customer['name'] ?? 'Customer';

                                $initial = strtoupper(
                                    substr(trim($customerName), 0, 1)
                                );
                                ?>

                                <tr>

                                    <!-- Customer Profile Picture -->
                                    <td>
                                        <?php if (!empty($customer['avatar_display'])): ?>

                                            <img
                                                src="<?= htmlspecialchars($customer['avatar_display'], ENT_QUOTES, 'UTF-8') ?>"
                                                alt="Customer Profile"
                                                class="customer-avatar"
                                                loading="lazy"
                                            >

                                        <?php else: ?>

                                            <div class="customer-avatar-placeholder">
                                                <?= htmlspecialchars($initial) ?>
                                            </div>

                                        <?php endif; ?>
                                    </td>

                                    <td class="customer-id">
                                        <?= htmlspecialchars($customer['id'] ?? '') ?>
                                    </td>

                                    <td class="customer-name">
                                        <?= htmlspecialchars($customerName) ?>
                                    </td>

                                    <td class="customer-email">
                                        <?= htmlspecialchars($customer['email'] ?? '') ?>
                                    </td>

                                    <td>
                                        <span class="role-badge">
                                            CUSTOMER
                                        </span>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($registrationDate) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        <?php endif; ?>

    <?php endif; ?>

</main>

</body>
</html>
