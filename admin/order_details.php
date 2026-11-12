
<?php

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

$accessToken = (string) $_SESSION['access_token'];
$adminId = (string) $_SESSION['admin_id'];

// ==========================================
// 2. VERIFY ADMIN ROLE
// ==========================================

$profile = supabaseRequest(
    '/rest/v1/profiles?select=id,role&id=eq.' . rawurlencode($adminId),
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
    session_destroy();

    header('Location: login.php');
    exit;
}

// ==========================================
// 3. GET ORDER ID
// ==========================================

$orderId = isset($_GET['id']) && is_string($_GET['id'])
    ? trim($_GET['id'])
    : '';

if ($orderId === '') {
    header('Location: orders.php');
    exit;
}

// ==========================================
// 4. LOAD ORDER INFORMATION
// ==========================================

$orderResult = supabaseRequest(
    '/rest/v1/orders?select=id,user_id,total,status,full_name,address,city,zip,phone,payment_method,payment_status,created_at,confirmed_at,shipped_at,delivered_at,cancelled_at,customer_received_at' .
    '&id=eq.' . rawurlencode($orderId) .
    '&limit=1',
    'GET',
    null,
    $accessToken
);

$order = (
    !empty($orderResult['success']) &&
    !empty($orderResult['data'][0]) &&
    is_array($orderResult['data'][0])
)
    ? $orderResult['data'][0]
    : null;

$error = '';

if (empty($orderResult['success'])) {
    $error = $orderResult['error'] ??
        'Unable to retrieve order information. Please try again.';
} elseif (!$order) {
    $error = 'The requested order could not be found.';
}

// ==========================================
// 5. LOAD ORDER ITEMS
// ==========================================

$orderItems = [];
$itemError = '';

if ($order) {

    $itemsResult = supabaseRequest(
        '/rest/v1/order_items?select=id,product_id,size,quantity,price&order_id=eq.' .
        rawurlencode($orderId),
        'GET',
        null,
        $accessToken
    );

    if (
        !empty($itemsResult['success']) &&
        is_array($itemsResult['data'])
    ) {
        $orderItems = $itemsResult['data'];
    } else {
        $itemError = 'Unable to load the products included in this order.';
    }
}

// ==========================================
// 6. LOAD PRODUCT DETAILS
// ==========================================

foreach ($orderItems as $index => $item) {

    $productId = (string) ($item['product_id'] ?? '');

    $productResult = supabaseRequest(
        '/rest/v1/products?select=id,name,image,category&id=eq.' .
        rawurlencode($productId) .
        '&limit=1',
        'GET',
        null,
        $accessToken
    );

    $orderItems[$index]['product'] = (
        !empty($productResult['success']) &&
        !empty($productResult['data'][0]) &&
        is_array($productResult['data'][0])
    )
        ? $productResult['data'][0]
        : null;
}

// ==========================================
// 7. LOAD CUSTOMER PROFILE
// ==========================================

$customer = null;

if ($order && !empty($order['user_id'])) {

    $customerResult = supabaseRequest(
        '/rest/v1/profiles?select=id,name,email&id=eq.' .
        rawurlencode((string) $order['user_id']) .
        '&limit=1',
        'GET',
        null,
        $accessToken
    );

    if (
        !empty($customerResult['success']) &&
        !empty($customerResult['data'][0])
    ) {
        $customer = $customerResult['data'][0];
    }
}

// ==========================================
// 8. CALCULATE ITEM SUBTOTAL
// ==========================================

$itemsSubtotal = 0;

foreach ($orderItems as $item) {
    $itemsSubtotal +=
        (float) ($item['price'] ?? 0) *
        (int) ($item['quantity'] ?? 0);
}

// ==========================================
// 9. HELPER FUNCTIONS
// ==========================================

function orderDetailsEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function orderDetailsDate($value): string
{
    if (empty($value)) {
        return 'Not available';
    }

    $timestamp = strtotime((string) $value);

    if ($timestamp === false) {
        return 'Date unavailable';
    }

    return date('F d, Y · h:i A', $timestamp);
}

function orderDetailsPaymentStatus($status): string
{
    $labels = [
        'pending' => 'Pending',
        'unpaid_on_delivery' => 'Unpaid on Delivery',
        'pending_verification' => 'Pending Verification',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'refunded' => 'Refunded'
    ];

    $key = strtolower(trim((string) $status));

    return $labels[$key] ??
        ucwords(str_replace('_', ' ', $key ?: 'Not available'));
}

// ==========================================
// 10. PREPARE DISPLAY VALUES
// ==========================================

if ($order) {

    $status = (string) ($order['status'] ?? 'Processing');

    $statusClass = strtolower(
        preg_replace('/[^a-z0-9_-]/i', '', $status)
    );

    $orderDate = orderDetailsDate(
        $order['created_at'] ?? null
    );

    $customerName = !empty($order['full_name'])
        ? $order['full_name']
        : ($customer['name'] ?? 'Customer');

    $customerEmail = $customer['email'] ?? 'Not available';

    $phone = trim((string) ($order['phone'] ?? ''));

    $phone = $phone !== ''
        ? $phone
        : 'Not provided';

    $fullAddress = implode(', ', array_filter([
        $order['address'] ?? '',
        $order['city'] ?? '',
        $order['zip'] ?? ''
    ], function ($value) {
        return trim((string) $value) !== '';
    }));

    $paymentMethod = $order['payment_method'] ?? 'Not provided';

    $paymentStatus = orderDetailsPaymentStatus(
        $order['payment_status'] ?? ''
    );

    $customerReceivedAt = $order['customer_received_at'] ?? null;

    $customerReceivedDate = !empty($customerReceivedAt)
        ? orderDetailsDate($customerReceivedAt)
        : null;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Order Details | Diane Clothing Shop</title>

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
            max-width: 1250px;
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

        .subtitle {
            margin: 0;
            color: #777;
            font-size: 13px;
        }

        .order-reference {
            text-align: right;
            color: #777;
            font-size: 11px;
        }

        .order-reference strong {
            display: block;
            margin-top: 7px;
            color: #111;
            font-family: monospace;
            font-size: 14px;
        }

        .notification {
            padding: 17px 20px;
            background: #f9e8e8;
            color: #982b2b;
            border: 1px solid #edcece;
            font-size: 13px;
        }

        /* =====================================
           STATUS PANEL
        ===================================== */

        .status-panel {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            background: #fff;
            border: 1px solid #e6e6e6;
            padding: 23px 26px;
            margin-bottom: 22px;
        }

        .status-panel span {
            color: #777;
            font-size: 11px;
            letter-spacing: 1.2px;
        }

        .status-badge {
            display: inline-block;
            margin-top: 9px;
            padding: 8px 13px;
            background: #f3eee0;
            color: #795e20;
            font-size: 11px;
        }

        .status-badge.confirmed {
            background: #e9eef7;
            color: #34537f;
        }

        .status-badge.shipped {
            background: #e9eef7;
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

        .status-total {
            text-align: right;
        }

        .status-total strong {
            display: block;
            margin-top: 7px;
            font-size: 25px;
            font-weight: 500;
        }

        /* =====================================
           INFORMATION PANELS
        ===================================== */

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 22px;
        }

        .detail-panel {
            background: white;
            border: 1px solid #e6e6e6;
            padding: 25px;
        }

        .detail-panel h2,
        .receipt-panel h2 {
            margin: 0 0 22px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
            font-size: 15px;
            font-weight: 500;
        }

        .detail-row {
            margin-bottom: 19px;
        }

        .detail-row:last-child {
            margin-bottom: 0;
        }

        .detail-label {
            display: block;
            margin-bottom: 6px;
            color: #777;
            font-size: 11px;
        }

        .detail-value {
            color: #222;
            font-size: 13px;
            line-height: 1.6;
            word-break: break-word;
        }

        /* =====================================
           CUSTOMER RECEIPT CONFIRMATION
        ===================================== */

        .receipt-panel {
            background: #fff;
            border: 1px solid #e6e6e6;
            padding: 25px;
            margin-bottom: 22px;
        }

        .receipt-state {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 18px;
            background: #f5f5f3;
            border: 1px solid #e8e8e5;
        }

        .receipt-state.confirmed {
            background: #eaf3ec;
            border-color: #d5e7d9;
        }

        .receipt-icon {
            width: 36px;
            height: 36px;
            flex: 0 0 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #111;
            color: white;
            border-radius: 50%;
            font-size: 16px;
        }

        .receipt-state.confirmed .receipt-icon {
            background: #245c35;
        }

        .receipt-state strong {
            display: block;
            margin: 2px 0 7px;
            font-size: 13px;
            font-weight: 600;
        }

        .receipt-state p {
            margin: 0;
            color: #666;
            font-size: 12px;
            line-height: 1.7;
        }

        /* =====================================
           PURCHASED PRODUCTS
        ===================================== */

        .items-panel {
            background: white;
            border: 1px solid #e6e6e6;
            overflow: hidden;
        }

        .items-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 23px 25px;
            border-bottom: 1px solid #eee;
        }

        .items-heading h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 500;
        }

        .items-heading span {
            color: #777;
            font-size: 11px;
        }

        .table-scroll {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 650px;
            border-collapse: collapse;
        }

        th {
            background: #f8f8f7;
            padding: 16px;
            text-align: left;
            font-size: 10px;
            letter-spacing: 1px;
            color: #666;
            text-transform: uppercase;
        }

        td {
            padding: 16px;
            border-top: 1px solid #eee;
            font-size: 12px;
            vertical-align: middle;
        }

        .product-cell {
            display: flex;
            align-items: center;
            gap: 13px;
            min-width: 220px;
        }

        .product-image {
            width: 54px;
            height: 65px;
            object-fit: contain;
            background: #f1f1f1;
        }

        .product-name {
            font-weight: bold;
            line-height: 1.5;
        }

        .product-category {
            display: block;
            margin-top: 5px;
            color: #777;
            font-size: 10px;
        }

        .price,
        .item-total {
            white-space: nowrap;
        }

        .item-total {
            font-weight: bold;
        }

        /* =====================================
           ORDER SUMMARY
        ===================================== */

        .summary-panel {
            background: white;
            border: 1px solid #e6e6e6;
            padding: 25px;
            margin-top: 20px;
            margin-left: auto;
            max-width: 430px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 11px 0;
            color: #666;
            font-size: 13px;
        }

        .summary-row.total {
            border-top: 1px solid #ddd;
            margin-top: 8px;
            padding-top: 19px;
            color: #111;
            font-size: 16px;
            font-weight: bold;
        }

        .empty-items {
            padding: 35px;
            text-align: center;
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
            color: #111;
        }

        /* =====================================
           RESPONSIVE
        ===================================== */

        @media (max-width: 700px) {

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

            .order-reference {
                text-align: left;
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            .status-panel {
                align-items: flex-start;
                flex-direction: column;
            }

            .status-total {
                text-align: left;
            }

            .items-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .summary-panel {
                max-width: 100%;
            }

        }

    </style>

</head>

<body>

<?php renderAdminNavigation(); ?>

<main class="page-container">

    <?php if ($error !== ''): ?>

        <div class="eyebrow">
            DIANE CLOTHING SHOP / SALES
        </div>

        <h1>Order Details</h1>

        <div class="notification">
            <?= orderDetailsEscape($error) ?>
        </div>

    <?php elseif ($order): ?>

        <!-- PAGE HEADING -->

        <section class="page-heading">

            <div>

                <div class="eyebrow">
                    DIANE CLOTHING SHOP / SALES
                </div>

                <h1>Order Details</h1>

                <p class="subtitle">
                    Review customer information and purchased items.
                </p>

            </div>

            <div class="order-reference">

                ORDER REFERENCE

                <strong>
                    <?= orderDetailsEscape($order['id']) ?>
                </strong>

            </div>

        </section>

        <!-- ORDER STATUS AND TOTAL -->

        <section class="status-panel">

            <div>

                <span>ORDER STATUS</span>

                <div>

                    <span class="status-badge <?= orderDetailsEscape($statusClass) ?>">

                        <?= orderDetailsEscape($status) ?>

                    </span>

                </div>

            </div>

            <div class="status-total">

                <span>ORDER TOTAL</span>

                <strong>
                    ₱<?= number_format((float) $order['total'], 2) ?>
                </strong>

            </div>

        </section>

        <!-- CUSTOMER AND ORDER INFORMATION -->

        <section class="details-grid">

            <div class="detail-panel">

                <h2>Customer Information</h2>

                <div class="detail-row">

                    <span class="detail-label">
                        Full Name
                    </span>

                    <div class="detail-value">
                        <?= orderDetailsEscape($customerName) ?>
                    </div>

                </div>

                <div class="detail-row">

                    <span class="detail-label">
                        Email Address
                    </span>

                    <div class="detail-value">
                        <?= orderDetailsEscape($customerEmail) ?>
                    </div>

                </div>

                <div class="detail-row">

                    <span class="detail-label">
                        Customer ID
                    </span>

                    <div class="detail-value">
                        <?= orderDetailsEscape($order['user_id'] ?? '') ?>
                    </div>

                </div>

                <div class="detail-row">

                    <span class="detail-label">
                        Contact Number
                    </span>

                    <div class="detail-value">
                        <?= orderDetailsEscape($phone) ?>
                    </div>

                </div>

            </div>

            <div class="detail-panel">

                <h2>Order Information</h2>

                <div class="detail-row">

                    <span class="detail-label">
                        Order Date
                    </span>

                    <div class="detail-value">
                        <?= orderDetailsEscape($orderDate) ?>
                    </div>

                </div>

                <div class="detail-row">

                    <span class="detail-label">
                        Delivery Address
                    </span>

                    <div class="detail-value">
                        <?= orderDetailsEscape($fullAddress ?: 'Not provided') ?>
                    </div>

                </div>

                <div class="detail-row">

                    <span class="detail-label">
                        Payment Method
                    </span>

                    <div class="detail-value">
                        <?= orderDetailsEscape($paymentMethod) ?>
                    </div>

                </div>

                <div class="detail-row">

                    <span class="detail-label">
                        Payment Status
                    </span>

                    <div class="detail-value">
                        <?= orderDetailsEscape($paymentStatus) ?>
                    </div>

                </div>

            </div>

        </section>

        <!-- CUSTOMER RECEIPT CONFIRMATION -->

        <section class="receipt-panel">

            <h2>
                Customer Receipt Confirmation
            </h2>

            <?php if (
                !empty($customerReceivedAt) &&
                $customerReceivedDate !== null
            ): ?>

                <div class="receipt-state confirmed">

                    <div class="receipt-icon">
                        ✓
                    </div>

                    <div>

                        <strong>
                            ORDER RECEIVED CONFIRMED
                        </strong>

                        <p>
                            The customer confirmed receiving this order on
                            <?= orderDetailsEscape($customerReceivedDate) ?>.
                        </p>

                    </div>

                </div>

            <?php elseif ($status === 'Delivered'): ?>

                <div class="receipt-state">

                    <div class="receipt-icon">
                        …
                    </div>

                    <div>

                        <strong>
                            AWAITING CUSTOMER CONFIRMATION
                        </strong>

                        <p>
                            This order is marked as Delivered.
                            The customer has not yet confirmed receiving the package
                            through the My Orders page.
                        </p>

                    </div>

                </div>

            <?php else: ?>

                <div class="receipt-state">

                    <div class="receipt-icon">
                        —
                    </div>

                    <div>

                        <strong>
                            NOT AVAILABLE YET
                        </strong>

                        <p>
                            Customer receipt confirmation will become available
                            after the order is marked as Delivered.
                        </p>

                    </div>

                </div>

            <?php endif; ?>

        </section>

        <!-- PURCHASED PRODUCTS -->

        <section class="items-panel">

            <div class="items-heading">

                <h2>
                    Purchased Products
                </h2>

                <span>
                    <?= count($orderItems) ?> item records
                </span>

            </div>

            <?php if ($itemError !== ''): ?>

                <div class="notification">
                    <?= orderDetailsEscape($itemError) ?>
                </div>

            <?php elseif (empty($orderItems)): ?>

                <div class="empty-items">
                    No product details were found for this order.
                </div>

            <?php else: ?>

                <div class="table-scroll">

                    <table>

                        <thead>

                            <tr>
                                <th>Product</th>
                                <th>Product ID</th>
                                <th>Size</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Subtotal</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($orderItems as $item): ?>

                                <?php

                                    $itemProduct = $item['product'] ?? null;

                                    $productName = $itemProduct['name']
                                        ?? 'Product unavailable';

                                    $quantity = (int) ($item['quantity'] ?? 0);

                                    $unitPrice = (float) ($item['price'] ?? 0);

                                    $itemSubtotal = $quantity * $unitPrice;

                                ?>

                                <tr>

                                    <td>

                                        <div class="product-cell">

                                            <?php if (!empty($itemProduct['image'])): ?>

                                                <img
                                                    class="product-image"
                                                    src="<?= orderDetailsEscape($itemProduct['image']) ?>"
                                                    alt="<?= orderDetailsEscape($productName) ?>"
                                                >

                                            <?php else: ?>

                                                <div class="product-image"></div>

                                            <?php endif; ?>

                                            <div>

                                                <div class="product-name">
                                                    <?= orderDetailsEscape($productName) ?>
                                                </div>

                                                <?php if (!empty($itemProduct['category'])): ?>

                                                    <span class="product-category">
                                                        <?= orderDetailsEscape($itemProduct['category']) ?>
                                                    </span>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </td>

                                    <td>
                                        <?= orderDetailsEscape($item['product_id'] ?? '') ?>
                                    </td>

                                    <td>
                                        <?= orderDetailsEscape($item['size'] ?? 'N/A') ?>
                                    </td>

                                    <td>
                                        <?= number_format($quantity) ?>
                                    </td>

                                    <td class="price">
                                        ₱<?= number_format($unitPrice, 2) ?>
                                    </td>

                                    <td class="item-total">
                                        ₱<?= number_format($itemSubtotal, 2) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

        <!-- ORDER SUMMARY -->

        <section class="summary-panel">

            <div class="summary-row">

                <span>Items Subtotal</span>

                <span>
                    ₱<?= number_format($itemsSubtotal, 2) ?>
                </span>

            </div>

            <div class="summary-row total">

                <span>Order Total</span>

                <span>
                    ₱<?= number_format((float) $order['total'], 2) ?>
                </span>

            </div>

        </section>

    <?php endif; ?>

    <a href="orders.php" class="back-link">
        ← BACK TO ORDERS
    </a>

</main>

</body>
</html>
