
<?php

/**
 * DIANE CLOTHING SHOP
 * Admin Order Management
 * Order tracking: Processing, Confirmed, Shipped, Delivered, Cancelled.
 */

date_default_timezone_set('Asia/Manila');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/admin_navigation.php';

// ==========================================
// 1. VERIFY ADMIN SESSION
// ==========================================

if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['access_token'])
) {
    header('Location: login.php');
    exit;
}

$adminId = (string) $_SESSION['admin_id'];
$accessToken = (string) $_SESSION['access_token'];

// ==========================================
// 2. VERIFY ADMIN ROLE
// ==========================================

$profile = supabaseRequest(
    '/rest/v1/profiles?select=id,role&id=eq.' .
    rawurlencode($adminId),
    'GET',
    null,
    $accessToken
);

$admin = (
    !empty($profile['success']) &&
    !empty($profile['data'][0]) &&
    is_array($profile['data'][0])
)
    ? $profile['data'][0]
    : null;

if (!$admin || ($admin['role'] ?? '') !== 'admin') {

    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    header('Location: login.php');
    exit;
}

// ==========================================
// 3. CSRF PROTECTION
// ==========================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

// ==========================================
// 4. ALLOWED ORDER STATUSES
// ==========================================

$allowedStatuses = [
    'Processing',
    'Confirmed',
    'Shipped',
    'Delivered',
    'Cancelled'
];

// ==========================================
// 5. NOTIFICATIONS
// ==========================================

$message = '';
$messageType = '';

if (($_GET['updated'] ?? '') === '1') {
    $message = 'Order status and tracking information updated successfully.';
    $messageType = 'success';
}

// ==========================================
// ==========================================
// 6. HANDLE ORDER STATUS UPDATE
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid request. Please refresh the page and try again.');
    }

    $orderId = (
        isset($_POST['order_id']) &&
        is_string($_POST['order_id'])
    )
        ? trim($_POST['order_id'])
        : '';

    $newStatus = (
        isset($_POST['status']) &&
        is_string($_POST['status'])
    )
        ? trim($_POST['status'])
        : '';

    if (
        $orderId === '' ||
        !in_array($newStatus, $allowedStatuses, true)
    ) {
        $message = 'Invalid order information or status.';
        $messageType = 'error';

    } else {

        // Use the database function for status transitions.
        // It updates tracking timestamps and restores stock once
        // when a newly stock-deducted order is cancelled.
        $statusResult = supabaseRequest(
            '/rest/v1/rpc/admin_update_order_status',
            'POST',
            [
                'p_order_id' => $orderId,
                'p_new_status' => $newStatus
            ],
            $accessToken
        );

        $rpcData = $statusResult['data'] ?? null;

        // PostgREST normally returns the JSONB object as an array.
        // These guards also handle a JSON string or one-row array.
        if (is_string($rpcData)) {
            $decodedData = json_decode($rpcData, true);
            if (is_array($decodedData)) {
                $rpcData = $decodedData;
            }
        }

        if (
            is_array($rpcData) &&
            isset($rpcData[0]) &&
            is_array($rpcData[0])
        ) {
            $rpcData = $rpcData[0];
        }

        if (
            !empty($statusResult['success']) &&
            is_array($rpcData) &&
            !empty($rpcData['success'])
        ) {
            header('Location: orders.php?updated=1');
            exit;

        } else {
            $rpcMessage = is_array($rpcData)
                ? (string) ($rpcData['message'] ?? '')
                : '';

            $message = $rpcMessage !== ''
                ? $rpcMessage
                : (string) (
                    $statusResult['error'] ??
                    'Unable to update the order. Please try again.'
                );

            $messageType = 'error';

            error_log(
                'DIANE admin order update failed: ' . $message
            );
        }
    }
}

// 7. LOAD ALL ORDERS
// ==========================================

$result = supabaseRequest(
    '/rest/v1/orders?select=id,user_id,full_name,total,status,payment_method,payment_status,created_at,confirmed_at,shipped_at,delivered_at,cancelled_at' .
    '&order=created_at.desc&limit=1000',
    'GET',
    null,
    $accessToken
);

$orders = (
    !empty($result['success']) &&
    is_array($result['data'] ?? null)
)
    ? $result['data']
    : [];

$error = empty($result['success'])
    ? (
        $result['error'] ??
        'Unable to load orders. Please check your Supabase connection and permissions.'
    )
    : '';

// ==========================================
// 8. CALCULATE DASHBOARD SUMMARIES
// ==========================================

$totalOrders = count($orders);
$totalOrderValue = 0;

$statusCounts = [
    'Processing' => 0,
    'Confirmed' => 0,
    'Shipped' => 0,
    'Delivered' => 0,
    'Cancelled' => 0
];

foreach ($orders as $order) {

    $totalOrderValue += (float) ($order['total'] ?? 0);

    $status = $order['status'] ?? 'Processing';

    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

// ==========================================
// 9. HELPER FUNCTIONS
// ==========================================

function orderEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function orderStatusClass($status): string
{
    $classes = [
        'Processing' => 'processing',
        'Confirmed' => 'confirmed',
        'Shipped' => 'shipped',
        'Delivered' => 'delivered',
        'Cancelled' => 'cancelled'
    ];

    return $classes[$status] ?? 'processing';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Orders | Diane Clothing Shop</title>

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

        /* =====================================
           PAGE LAYOUT
        ===================================== */

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
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
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
            font-size: 13px;
        }

        /* =====================================
           SUMMARY CARDS
        ===================================== */

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .summary-card {
            background: white;
            border: 1px solid #e6e6e6;
            padding: 22px;
        }

        .summary-label {
            color: #777;
            font-size: 10px;
            letter-spacing: 1.5px;
            margin-bottom: 12px;
        }

        .summary-value {
            font-size: 27px;
            font-weight: 500;
        }

        /* =====================================
           STATUS SUMMARY
        ===================================== */

        .status-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 28px;
        }

        .status-card {
            background: white;
            border: 1px solid #e6e6e6;
            padding: 17px;
        }

        .status-card span {
            display: block;
            color: #777;
            font-size: 11px;
            margin-bottom: 9px;
        }

        .status-card strong {
            font-size: 21px;
            font-weight: 500;
        }

        /* =====================================
           NOTIFICATIONS
        ===================================== */

        .notification {
            padding: 15px 18px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .notification.success {
            background: #e5f1e8;
            color: #245c35;
            border: 1px solid #cce2d1;
        }

        .notification.error {
            background: #f9e8e8;
            color: #982b2b;
            border: 1px solid #edcece;
        }

        /* =====================================
           ORDERS TABLE
        ===================================== */

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
            border-bottom: 1px solid #eee;
        }

        .table-heading h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 500;
        }

        .table-heading span {
            color: #888;
            font-size: 11px;
        }

        .table-scroll {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1100px;
            border-collapse: collapse;
        }

        th {
            padding: 17px 15px;
            background: #f8f8f7;
            color: #666;
            text-align: left;
            font-size: 10px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        td {
            padding: 16px 15px;
            border-top: 1px solid #eee;
            font-size: 12px;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #fafafa;
        }

        .order-id {
            font-family: monospace;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
        }

        .customer-name {
            font-weight: bold;
        }

        .customer-id {
            display: block;
            margin-top: 5px;
            color: #888;
            font-size: 10px;
            font-family: monospace;
        }

        .order-total {
            white-space: nowrap;
            font-weight: bold;
        }

        /* =====================================
           STATUS BADGES
        ===================================== */

        .status-badge {
            display: inline-block;
            padding: 7px 10px;
            background: #eee;
            font-size: 10px;
            white-space: nowrap;
        }

        .status-badge.processing {
            background: #f3eee0;
            color: #795e20;
        }

        .status-badge.confirmed {
            background: #e9eef7;
            color: #34537f;
        }

        .status-badge.shipped {
            background: #e8edf3;
            color: #34537f;
        }

        .status-badge.delivered {
            background: #e5f1e8;
            color: #245c35;
        }

        .status-badge.cancelled {
            background: #f9e8e8;
            color: #982b2b;
        }

        /* =====================================
           PAYMENT
        ===================================== */

        .payment-label {
            font-size: 11px;
            color: #666;
            line-height: 1.8;
        }

        /* =====================================
           STATUS UPDATE FORM
        ===================================== */

        .status-form {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .status-form select {
            min-width: 130px;
            padding: 8px;
            border: 1px solid #ddd;
            background: white;
            font-size: 11px;
        }

        .update-button {
            padding: 9px 11px;
            background: #111;
            color: white;
            border: 0;
            font-size: 10px;
            cursor: pointer;
            transition: .2s ease;
        }

        .update-button:hover {
            background: #444;
        }

        .details-link {
            display: inline-block;
            padding: 9px 12px;
            color: #111;
            border: 1px solid #ddd;
            text-decoration: none;
            font-size: 10px;
            white-space: nowrap;
        }

        .details-link:hover {
            background: #111;
            color: white;
        }

        /* =====================================
           EMPTY STATE
        ===================================== */

        .empty {
            text-align: center;
            background: white;
            padding: 60px 20px;
        }

        .empty-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 18px;
            background: #f3f3f3;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 25px;
        }

        .empty h3 {
            margin: 0 0 10px;
            font-size: 18px;
            font-weight: 500;
        }

        .empty p {
            color: #777;
            font-size: 13px;
        }

        .back-link {
            display: inline-block;
            margin-top: 25px;
            color: #555;
            text-decoration: none;
            font-size: 11px;
            letter-spacing: 1px;
        }

        .back-link:hover {
            color: #000;
        }

        /* =====================================
           RESPONSIVE
        ===================================== */

        @media (max-width: 1050px) {

            .status-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

        }

        @media (max-width: 850px) {

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .status-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

        }

        @media (max-width: 600px) {

            .page-container {
                padding: 27px 16px 40px;
            }

            .page-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            h1 {
                font-size: 28px;
            }

            .status-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .table-heading {
                align-items: flex-start;
                flex-direction: column;
                gap: 8px;
            }

        }

    </style>

</head>

<body>

<?php renderAdminNavigation(); ?>

<main class="page-container">

    <!-- PAGE HEADING -->

    <section class="page-heading">

        <div>

            <div class="eyebrow">
                DIANE CLOTHING SHOP / SALES
            </div>

            <h1>
                Order Management
            </h1>

            <p>
                Review customer purchases and manage their order tracking status.
            </p>

        </div>

    </section>

    <!-- NOTIFICATIONS -->

    <?php if ($message !== ''): ?>

        <div class="notification <?= orderEscape($messageType) ?>">

            <?= orderEscape($message) ?>

        </div>

    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div class="notification error">

            <?= orderEscape($error) ?>

        </div>

    <?php endif; ?>

    <?php if ($error === ''): ?>

        <!-- ==================================
             ORDER SUMMARIES
        ================================== -->

        <section class="summary-grid">

            <div class="summary-card">

                <div class="summary-label">
                    TOTAL ORDERS
                </div>

                <div class="summary-value">
                    <?= number_format($totalOrders) ?>
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-label">
                    TOTAL ORDER VALUE
                </div>

                <div class="summary-value">
                    ₱<?= number_format($totalOrderValue, 2) ?>
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-label">
                    PENDING PROCESSING
                </div>

                <div class="summary-value">
                    <?= number_format($statusCounts['Processing']) ?>
                </div>

            </div>

        </section>

        <!-- ==================================
             STATUS SUMMARY
        ================================== -->

        <section class="status-grid">

            <?php foreach ($statusCounts as $statusName => $count): ?>

                <div class="status-card">

                    <span>
                        <?= orderEscape($statusName) ?>
                    </span>

                    <strong>
                        <?= number_format($count) ?>
                    </strong>

                </div>

            <?php endforeach; ?>

        </section>

        <!-- ==================================
             CUSTOMER ORDERS
        ================================== -->

        <?php if (empty($orders)): ?>

            <section class="empty">

                <div class="empty-icon">
                    ♧
                </div>

                <h3>
                    No Orders Found
                </h3>

                <p>
                    Customer orders will appear here when customers place purchases.
                </p>

            </section>

        <?php else: ?>

            <section class="table-panel">

                <div class="table-heading">

                    <h2>
                        Customer Orders
                    </h2>

                    <span>
                        <?= count($orders) ?> orders displayed
                    </span>

                </div>

                <div class="table-scroll">

                    <table>

                        <thead>

                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Order Date</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Update Status</th>
                                <th>Details</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($orders as $order): ?>

                                <?php

                                    $status = $order['status'] ?? 'Processing';

                                    if (!in_array($status, $allowedStatuses, true)) {
                                        $status = 'Processing';
                                    }

                                    $statusClass = orderStatusClass($status);

                                    $customerName = !empty($order['full_name'])
                                        ? $order['full_name']
                                        : 'Customer';

                                    $date = !empty($order['created_at'])
                                        ? date(
                                            'M d, Y',
                                            strtotime($order['created_at'])
                                        )
                                        : 'N/A';

                                    $orderId = (string) ($order['id'] ?? '');

                                ?>

                                <tr>

                                    <!-- ORDER ID -->

                                    <td class="order-id">

                                        <?= orderEscape($orderId) ?>

                                    </td>

                                    <!-- CUSTOMER -->

                                    <td>

                                        <span class="customer-name">

                                            <?= orderEscape($customerName) ?>

                                        </span>

                                        <small class="customer-id">

                                            <?= orderEscape(
                                                $order['user_id'] ?? ''
                                            ) ?>

                                        </small>

                                    </td>

                                    <!-- DATE -->

                                    <td>

                                        <?= orderEscape($date) ?>

                                    </td>

                                    <!-- TOTAL -->

                                    <td class="order-total">

                                        ₱<?= number_format(
                                            (float)($order['total'] ?? 0),
                                            2
                                        ) ?>

                                    </td>

                                    <!-- PAYMENT -->

                                    <td>

                                        <span class="payment-label">

                                            <?= orderEscape(
                                                $order['payment_method'] ?? 'N/A'
                                            ) ?>

                                            <br>

                                            <?= orderEscape(
                                                ucwords(str_replace(
                                                    '_',
                                                    ' ',
                                                    $order['payment_status'] ?? 'N/A'
                                                ))
                                            ) ?>

                                        </span>

                                    </td>

                                    <!-- CURRENT STATUS -->

                                    <td>

                                        <span class="status-badge <?= orderEscape(
                                            $statusClass
                                        ) ?>">

                                            <?= orderEscape($status) ?>

                                        </span>

                                    </td>

                                    <!-- UPDATE STATUS -->

                                    <td>

                                        <form
                                            method="POST"
                                            action="orders.php"
                                            class="status-form"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= orderEscape($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="order_id"
                                                value="<?= orderEscape($orderId) ?>"
                                            >

                                            <select
                                                name="status"
                                                aria-label="Update order status"
                                                required
                                            >

                                                <?php foreach (
                                                    $allowedStatuses as $option
                                                ): ?>

                                                    <option
                                                        value="<?= orderEscape($option) ?>"
                                                        <?= $status === $option
                                                            ? 'selected'
                                                            : ''
                                                        ?>
                                                    >

                                                        <?= orderEscape($option) ?>

                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                            <button
                                                type="submit"
                                                class="update-button"
                                            >
                                                SAVE
                                            </button>

                                        </form>

                                    </td>

                                    <!-- ORDER DETAILS -->

                                    <td>

                                        <a
                                            class="details-link"
                                            href="order_details.php?id=<?= rawurlencode(
                                                $orderId
                                            ) ?>"
                                        >
                                            VIEW
                                        </a>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        <?php endif; ?>

    <?php endif; ?>

    <a href="dashboard.php" class="back-link">
        ← BACK TO DASHBOARD
    </a>

</main>

</body>
</html>
