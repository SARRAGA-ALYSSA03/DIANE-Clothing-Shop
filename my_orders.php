
<?php
/**
 * DIANE CLOTHING SHOP
 * Customer Order History and Tracking
 */

date_default_timezone_set('Asia/Manila');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/supabase.php';

// ==========================================
// 1. VERIFY CUSTOMER SESSION
// ==========================================

if (
    empty($_SESSION['customer_id']) ||
    empty($_SESSION['customer_access_token'])
) {
    header('Location: login.php');
    exit;
}

$userId = (string) $_SESSION['customer_id'];

// ==========================================
// 2. REFRESH SUPABASE TOKEN
// ==========================================

$refreshToken = trim(
    (string) ($_SESSION['customer_refresh_token'] ?? '')
);

if ($refreshToken === '') {
    unset(
        $_SESSION['customer_id'],
        $_SESSION['customer_access_token'],
        $_SESSION['customer_refresh_token'],
        $_SESSION['customer_name'],
        $_SESSION['customer_email'],
        $_SESSION['customer_csrf']
    );

    header('Location: login.php?expired=1');
    exit;
}

$refreshResult = supabaseRequest(
    '/auth/v1/token?grant_type=refresh_token',
    'POST',
    [
        'refresh_token' => $refreshToken
    ]
);

$refreshData = $refreshResult['data'] ?? null;

if (
    empty($refreshResult['success']) ||
    !is_array($refreshData) ||
    empty($refreshData['access_token']) ||
    empty($refreshData['refresh_token'])
) {
    unset(
        $_SESSION['customer_id'],
        $_SESSION['customer_access_token'],
        $_SESSION['customer_refresh_token'],
        $_SESSION['customer_name'],
        $_SESSION['customer_email'],
        $_SESSION['customer_csrf']
    );

    header('Location: login.php?expired=1');
    exit;
}

$refreshedUserId = $refreshData['user']['id'] ?? '';

if (
    $refreshedUserId !== '' &&
    $refreshedUserId !== $userId
) {
    unset(
        $_SESSION['customer_id'],
        $_SESSION['customer_access_token'],
        $_SESSION['customer_refresh_token'],
        $_SESSION['customer_name'],
        $_SESSION['customer_email'],
        $_SESSION['customer_csrf']
    );

    header('Location: login.php?expired=1');
    exit;
}

$_SESSION['customer_access_token'] = $refreshData['access_token'];
$_SESSION['customer_refresh_token'] = $refreshData['refresh_token'];

$accessToken = (string) $_SESSION['customer_access_token'];

// ==========================================
// 3. CUSTOMER INFORMATION
// ==========================================

$fullName = trim(
    (string) ($_SESSION['customer_name'] ?? '')
);

$nameParts = preg_split('/\s+/', $fullName);

$firstName = (
    is_array($nameParts) && !empty($nameParts[0])
)
    ? $nameParts[0]
    : 'Customer';

// CSRF token for the customer receipt-confirmation form.
if (empty($_SESSION['customer_csrf'])) {
    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['customer_csrf'];

// ==========================================
// 4. HELPER FUNCTIONS
// ==========================================

function myOrdersEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function myOrdersFormatDate($value): string
{
    if (empty($value)) {
        return 'Waiting for update';
    }

    $timestamp = strtotime((string) $value);

    if ($timestamp === false) {
        return 'Date unavailable';
    }

    return date('M d, Y · h:i A', $timestamp);
}

function myOrdersPaymentLabel($status): string
{
    $status = strtolower(trim((string) $status));

    $labels = [
        'pending' => 'Pending',
        'unpaid_on_delivery' => 'Unpaid on Delivery',
        'pending_verification' => 'Pending Verification',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'refunded' => 'Refunded'
    ];

    return $labels[$status] ?? 'Not recorded';
}

function myOrdersStatusClass($status): string
{
    $status = strtolower(trim((string) $status));

    $classes = [
        'processing' => 'processing',
        'confirmed' => 'confirmed',
        'shipped' => 'shipped',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled'
    ];

    return $classes[$status] ?? 'processing';
}

// ==========================================
// 5. LOAD CUSTOMER ORDERS
// ==========================================

// RLS and the explicit user_id filter ensure
// each customer sees only their own orders.

$orderResult = supabaseRequest(
    '/rest/v1/orders?select=id,user_id,full_name,phone,address,city,zip,total,status,payment_method,payment_status,created_at,confirmed_at,shipped_at,delivered_at,cancelled_at,customer_received_at' .
    '&user_id=eq.' . rawurlencode($userId) .
    '&order=created_at.desc',
    'GET',
    null,
    $accessToken
);

$orders = [];
$orderLoadError = '';

if (empty($orderResult['success'])) {

    $orderLoadError = $orderResult['error']
        ?? 'Unable to load your order history.';

} elseif (is_array($orderResult['data'] ?? null)) {

    $orders = $orderResult['data'];

} else {

    $orderLoadError = 'Unexpected response from Supabase.';

}

// ==========================================
// 6. LOAD ORDER ITEMS
// ==========================================

$displayOrders = [];

if ($orderLoadError === '') {

    foreach ($orders as $order) {

        $orderId = (string) ($order['id'] ?? '');

        if ($orderId === '') {
            continue;
        }

        $itemResult = supabaseRequest(
            '/rest/v1/order_items?select=id,order_id,product_id,size,quantity,price' .
            '&order_id=eq.' . rawurlencode($orderId),
            'GET',
            null,
            $accessToken
        );

        $orderItems = [];
        $itemError = '';

        if (empty($itemResult['success'])) {

            $itemError = $itemResult['error']
                ?? 'Unable to load order items.';

        } elseif (is_array($itemResult['data'] ?? null)) {

            foreach ($itemResult['data'] as $item) {

                $productId = (string) (
                    $item['product_id'] ?? ''
                );

                $product = null;

                if ($productId !== '') {

                    $productResult = supabaseRequest(
                        '/rest/v1/products?select=id,name,image,images,category' .
                        '&id=eq.' . rawurlencode($productId) .
                        '&limit=1',
                        'GET',
                        null,
                        $accessToken
                    );

                    if (
                        !empty($productResult['success']) &&
                        !empty($productResult['data'][0]) &&
                        is_array($productResult['data'][0])
                    ) {
                        $product = $productResult['data'][0];
                    }
                }

                if (!is_array($product)) {

                    $product = [
                        'id' => $productId,
                        'name' => 'Product ' . $productId,
                        'image' => '',
                        'category' => ''
                    ];
                }

                if (
                    empty($product['image']) &&
                    !empty($product['images']) &&
                    is_array($product['images'])
                ) {
                    $product['image'] = $product['images'][0] ?? '';
                }

                $item['product'] = $product;

                $orderItems[] = $item;
            }

        } else {

            $itemError = 'Unexpected order item response.';

        }

        $order['items'] = $orderItems;
        $order['item_error'] = $itemError;

        $displayOrders[] = $order;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>My Orders | DIANE Clothing Shop</title>

    <style>

        :root {
            --black: #111111;
            --white: #ffffff;
            --gray: #777777;
            --light: #f5f5f3;
            --border: #e5e5e5;
            --serif: Georgia, "Times New Roman", serif;
            --sans: "Helvetica Neue", Arial, sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #ffffff;
            color: #111111;
            font-family: var(--sans);
        }

        /* =====================================
           HEADER
        ===================================== */

        .diane-header {
            min-height: 86px;
            padding: 12px 5%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
            background: #ffffff;
            border-bottom: 1px solid var(--border);
        }

        .diane-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: var(--black);
            text-decoration: none;
        }

        .diane-brand-name {
            font-family: var(--serif);
            font-size: 34px;
            font-weight: normal;
            letter-spacing: 2px;
            line-height: 1;
        }

        .diane-brand-subtitle {
            margin-top: 6px;
            font-size: 8px;
            font-weight: 600;
            letter-spacing: 4px;
        }

        .header-right,
        .navigation,
        .account-links {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .header-right {
            justify-content: flex-end;
        }

        .navigation a,
        .account-links a {
            color: var(--black);
            font-size: 10px;
            font-weight: 500;
            letter-spacing: 1px;
            text-decoration: none;
            white-space: nowrap;
        }

        .navigation a:hover,
        .account-links a:hover {
            opacity: .55;
        }

        .greeting {
            color: var(--gray);
            font-size: 11px;
            white-space: nowrap;
        }

        /* CUSTOMER PROFILE ICON */

        .diane-profile-icon {
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--black);
            border: 1px solid #dddddd;
            border-radius: 50%;
            text-decoration: none;
            transition: background .2s ease, color .2s ease, border-color .2s ease;
        }

        .account-links .diane-profile-icon {
            font-size: initial;
            letter-spacing: normal;
        }

        .diane-profile-icon svg {
            width: 16px;
            height: 16px;
            display: block;
        }

        .diane-profile-icon:hover,
        .account-links .diane-profile-icon:hover {
            background: var(--black);
            color: #ffffff;
            border-color: var(--black);
            opacity: 1;
        }

        .diane-profile-icon:focus-visible {
            outline: 2px solid var(--black);
            outline-offset: 3px;
        }

        /* =====================================
           MAIN CONTENT
        ===================================== */

        .orders-page {
            max-width: 1250px;
            margin: auto;
            padding: 65px 5% 100px;
        }

        .breadcrumb {
            margin: 0 0 20px;
            color: var(--gray);
            font-size: 9px;
            letter-spacing: 1px;
        }

        .page-heading {
            margin: 0;
            font-family: var(--serif);
            font-size: clamp(36px, 4vw, 48px);
            font-weight: normal;
        }

        .page-subtitle {
            margin: 12px 0 35px;
            color: var(--gray);
            font-size: 12px;
            line-height: 1.8;
        }

        /* =====================================
           NOTIFICATIONS
        ===================================== */

        .notice {
            margin: 25px 0;
            padding: 17px 19px;
            background: var(--light);
            color: #333333;
            font-size: 12px;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        .notice.error {
            color: #a21d1d;
        }

        .receipt-confirmation {
            padding: 26px 25px;
            background: #f5f5f3;
            border-bottom: 1px solid var(--border);
        }

        .receipt-confirmation h3 {
            margin: 0 0 9px;
            font-family: var(--serif);
            font-size: 22px;
            font-weight: normal;
        }

        .receipt-confirmation p {
            margin: 8px 0 17px;
            color: #777777;
            font-size: 11px;
            line-height: 1.8;
        }

        .receipt-button {
            min-height: 48px;
            padding: 0 23px;
            background: #111111;
            color: #ffffff;
            border: 1px solid #111111;
            border-radius: 0;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            cursor: pointer;
            transition: .2s ease;
        }

        .receipt-button:hover {
            background: #ffffff;
            color: #111111;
        }

        .receipt-success {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .receipt-check {
            width: 40px;
            height: 40px;
            flex: 0 0 auto;
            display: grid;
            place-items: center;
            background: #111111;
            color: #ffffff;
            border-radius: 50%;
            font-size: 18px;
        }

        .receipt-success strong {
            font-size: 13px;
        }

        /* =====================================
           DIANE RECEIPT CONFIRMATION DIALOG
        ===================================== */

        .receipt-modal[hidden] {
            display: none !important;
        }

        .receipt-modal {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 22px;
            background: rgba(0, 0, 0, .58);
        }

        .receipt-modal-panel {
            width: min(100%, 455px);
            padding: 38px 36px 32px;
            background: #ffffff;
            color: #111111;
            border: 1px solid #dedede;
            box-shadow: 0 22px 70px rgba(0, 0, 0, .22);
            text-align: center;
            animation: receiptModalIn .18s ease-out;
        }

        @keyframes receiptModalIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .receipt-modal-brand {
            margin: 0 0 22px;
            font-family: var(--serif);
            font-size: 27px;
            letter-spacing: 2px;
        }

        .receipt-modal-kicker {
            margin: 0 0 10px;
            color: #777777;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 2px;
        }

        .receipt-modal-title {
            margin: 0;
            font-family: var(--serif);
            font-size: 27px;
            font-weight: normal;
            line-height: 1.25;
        }

        .receipt-modal-copy {
            margin: 13px 0 27px;
            color: #666666;
            font-size: 12px;
            line-height: 1.8;
        }

        .receipt-modal-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .receipt-modal-actions button {
            min-height: 45px;
            padding: 0 19px;
            border: 1px solid #111111;
            border-radius: 0;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1px;
            cursor: pointer;
            transition: background .2s ease, color .2s ease;
        }

        .receipt-modal-cancel {
            background: #ffffff;
            color: #111111;
        }

        .receipt-modal-cancel:hover {
            background: #eeeeee;
        }

        .receipt-modal-confirm {
            background: #111111;
            color: #ffffff;
        }

        .receipt-modal-confirm:hover {
            background: #ffffff;
            color: #111111;
        }

        @media (max-width: 480px) {
            .receipt-modal-panel {
                padding: 32px 22px 25px;
            }

            .receipt-modal-actions {
                flex-direction: column-reverse;
            }

            .receipt-modal-actions button {
                width: 100%;
            }
        }

        .receipt-success p {
            margin-bottom: 0;
        }

        /* =====================================
           ORDER CARDS
        ===================================== */

        .orders-list {
            display: grid;
            gap: 30px;
        }

        .order-card {
            border: 1px solid var(--border);
            background: #ffffff;
        }

        .order-card-header {
            padding: 22px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            background: var(--light);
            border-bottom: 1px solid var(--border);
        }

        .order-number-label {
            margin: 0 0 7px;
            color: var(--gray);
            font-size: 9px;
            letter-spacing: 1px;
        }

        .order-number {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .5px;
        }

        .order-date {
            margin-top: 7px;
            color: var(--gray);
            font-size: 10px;
        }

        .order-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 9px 13px;
            border: 1px solid #cccccc;
            background: #ffffff;
            color: #333333;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .7px;
            text-transform: uppercase;
        }

        .status-processing {
            border-color: #c9c9c9;
        }

        .status-confirmed {
            border-color: #888888;
        }

        .status-shipped {
            border-color: #888888;
            background: #eeeeee;
        }

        .status-delivered {
            border-color: #111111;
            background: #111111;
            color: #ffffff;
        }

        .status-cancelled {
            border-color: #a21d1d;
            color: #a21d1d;
        }

        /* =====================================
           TRACKING TIMELINE
        ===================================== */

        .tracking-section {
            padding: 28px 25px 30px;
            border-bottom: 1px solid var(--border);
        }

        .tracking-title {
            margin: 0 0 25px;
            font-family: var(--serif);
            font-size: 23px;
            font-weight: normal;
        }

        .tracking-timeline {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            position: relative;
        }

        .tracking-step {
            position: relative;
            min-width: 0;
            text-align: center;
        }

        .tracking-step::before {
            content: "";
            position: absolute;
            top: 13px;
            left: -50%;
            width: 100%;
            height: 1px;
            background: #dddddd;
        }

        .tracking-step:first-child::before {
            display: none;
        }

        .tracking-dot {
            position: relative;
            z-index: 2;
            width: 27px;
            height: 27px;
            margin: 0 auto 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            border: 1px solid #cccccc;
            border-radius: 50%;
            color: #999999;
            font-size: 11px;
        }

        .tracking-step.completed .tracking-dot {
            background: #111111;
            border-color: #111111;
            color: #ffffff;
        }

        .tracking-step.completed::before {
            background: #111111;
        }

        .tracking-step.current .tracking-dot {
            border: 2px solid #111111;
            color: #111111;
            font-weight: 700;
        }

        .tracking-step.completed.current .tracking-dot {
            background: #111111;
            border: 1px solid #111111;
            color: #ffffff;
            font-weight: 700;
        }

        .tracking-step-label {
            display: block;
            margin-bottom: 6px;
            color: #999999;
            font-size: 10px;
            font-weight: 600;
        }

        .tracking-step.completed .tracking-step-label,
        .tracking-step.current .tracking-step-label {
            color: #111111;
        }

        .tracking-step-date {
            display: block;
            padding: 0 4px;
            color: #999999;
            font-size: 9px;
            line-height: 1.6;
        }

        .tracking-step.current .tracking-step-date,
        .tracking-step.completed .tracking-step-date {
            color: #666666;
        }

        .tracking-cancelled {
            padding: 18px 20px;
            background: #f8eeee;
            border: 1px solid #ead5d5;
            color: #8c2525;
            font-size: 12px;
            line-height: 1.8;
        }

        /* =====================================
           ORDER BODY
        ===================================== */

        .order-card-body {
            padding: 24px 25px;
        }

        .order-items-heading {
            margin: 0 0 17px;
            color: var(--gray);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.3px;
        }

        .order-item {
            display: grid;
            grid-template-columns: 85px minmax(0, 1fr) auto;
            align-items: center;
            gap: 17px;
            padding: 15px 0;
            border-bottom: 1px solid var(--border);
        }

        .order-item-image {
            display: block;
            width: 85px;
            height: 105px;
            background: var(--light);
            object-fit: contain;
        }

        .order-item-name {
            margin: 0 0 8px;
            font-family: var(--serif);
            font-size: 17px;
            font-weight: normal;
            line-height: 1.4;
        }

        .order-item-detail {
            margin: 5px 0;
            color: var(--gray);
            font-size: 10px;
        }

        .order-item-price {
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        /* =====================================
           ORDER INFORMATION
        ===================================== */

        .order-info-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .order-info-box {
            min-width: 0;
        }

        .order-info-label {
            margin: 0 0 8px;
            color: var(--gray);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .order-info-value {
            margin: 0;
            color: var(--black);
            font-size: 11px;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        /* =====================================
           ORDER TOTAL
        ===================================== */
.view-order-button:hover {
            background: #ffffff;
            color: #111111;
        }

        .order-total-label {
            color: var(--gray);
            font-size: 10px;
            letter-spacing: 1px;
        }

        .order-total-amount {
            font-family: var(--serif);
            font-size: 23px;
        }

        /* =====================================
           EMPTY ORDERS
        ===================================== */

        .empty-orders {
            padding: 90px 20px;
            background: var(--light);
            text-align: center;
        }

        .empty-orders-symbol {
            margin-bottom: 18px;
            font-family: var(--serif);
            font-size: 42px;
        }

        .empty-orders h2 {
            margin: 0;
            font-family: var(--serif);
            font-size: 30px;
            font-weight: normal;
        }

        .empty-orders p {
            margin: 14px auto 25px;
            color: var(--gray);
            font-size: 12px;
            line-height: 1.8;
        }

        .shop-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 25px;
            background: var(--black);
            color: var(--white);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-decoration: none;
        }

        .shop-button:hover {
            background: #333333;
        }

        /* =====================================
           FOOTER
        ===================================== */

/* =====================================
   FOOTER
===================================== */

.diane-footer {
    padding: 55px 5% 25px;
    background: #111111;
    color: #ffffff;
}

.footer-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 30px;
    padding-bottom: 35px;
    border-bottom: 1px solid #3a3a3a;
}

.footer-brand {
    color: #ffffff;
    text-decoration: none;
}

.footer-brand strong {
    display: block;
    font-family: var(--serif);
    font-size: 31px;
    font-weight: normal;
    letter-spacing: 2px;
}

.footer-brand span {
    display: block;
    margin-top: 7px;
    font-size: 8px;
    letter-spacing: 4px;
}

.footer-links {
    display: flex;
    flex-wrap: wrap;
    gap: 22px;
}

.footer-links a {
    color: #dddddd;
    font-size: 10px;
    letter-spacing: .8px;
    text-decoration: none;
}

.footer-bottom {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding-top: 22px;
    color: #aaaaaa;
    font-size: 10px;
}

/* =====================================
   ORDER TOTAL
===================================== */

.order-card-footer {
    padding: 20px 25px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    background: #ffffff;
    border-top: 1px solid var(--border);
    box-sizing: border-box;
}

.order-total-label {
    color: var(--gray);
    font-size: 10px;
    letter-spacing: 1px;
}

.order-total-amount {
    margin: 0;
    color: #111111;
    font-family: var(--serif);
    font-size: 23px;
    font-weight: 600;
    white-space: nowrap;
}

/* =====================================
   RESPONSIVE - TABLET
===================================== */

@media (max-width: 800px) {

    .diane-header {
        flex-wrap: wrap;
        justify-content: center;
    }

    .header-right {
        width: 100%;
        justify-content: center;
        flex-wrap: wrap;
    }

    .order-info-grid {
        grid-template-columns: 1fr 1fr;
    }

}

/* =====================================
   RESPONSIVE - MOBILE
===================================== */

@media (max-width: 600px) {

    .diane-header {
        padding: 18px 4%;
        flex-direction: column;
        gap: 16px;
    }

    .header-right {
        flex-direction: column;
        gap: 14px;
    }

    .navigation,
    .account-links {
        justify-content: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .orders-page {
        padding: 40px 5% 65px;
    }

    .order-card-header {
        padding: 18px;
    }

    .order-card-body {
        padding: 18px;
    }

    /* ORDER TOTAL AND VIEW ORDER */

    .order-card-footer {
        flex-direction: column;
        align-items: stretch;
        padding: 20px 18px;
        gap: 16px;
    }

    .order-total-group {
        align-items: flex-start;
    }

    .order-total-amount {
        font-size: 22px;
    }
.tracking-section {
        padding: 23px 13px;
    }

    .tracking-title {
        font-size: 20px;
    }

    .tracking-step-label {
        font-size: 9px;
    }

    .tracking-step-date {
        font-size: 8px;
        overflow-wrap: anywhere;
    }

    .tracking-dot {
        width: 24px;
        height: 24px;
    }

    .tracking-step::before {
        top: 12px;
    }

    .order-item {
        grid-template-columns: 70px minmax(0, 1fr);
        gap: 12px;
    }

    .order-item-image {
        width: 70px;
        height: 90px;
    }

    .order-item-price {
        grid-column: 2;
    }

    .order-info-grid {
        grid-template-columns: 1fr;
        gap: 18px;
    }

    .footer-top,
    .footer-bottom {
        flex-direction: column;
    }

}

        
        /* =====================================
        RESPONSIVE
        ===================================== */

        @media (max-width: 800px) {

            .diane-header {
                flex-wrap: wrap;
                justify-content: center;
            }

            .header-right {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }

            .order-info-grid {
                grid-template-columns: 1fr 1fr;
            }

        }

        @media (max-width: 600px) {

            .diane-header {
                padding: 18px 4%;
                flex-direction: column;
                gap: 16px;
            }

            .header-right {
                flex-direction: column;
                gap: 14px;
            }

            .navigation,
            .account-links {
                justify-content: center;
                flex-wrap: wrap;
                gap: 12px;
            }

            .orders-page {
                padding: 40px 5% 65px;
            }

            .order-card-header {
                padding: 18px;
            }

            .order-card-body {
                padding: 18px;
            }

            /* ORDER TOTAL AND VIEW ORDER */
            .order-card-footer {
                padding: 18px;
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
            }

            .order-total-group {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }

            .order-total-amount {
                font-size: 22px;
            }
.tracking-section {
                padding: 23px 13px;
            }

            .tracking-title {
                font-size: 20px;
            }

            .tracking-step-label {
                font-size: 9px;
            }

            .tracking-step-date {
                font-size: 8px;
                overflow-wrap: anywhere;
            }

            .tracking-dot {
                width: 24px;
                height: 24px;
            }

            .tracking-step::before {
                top: 12px;
            }

            .order-item {
                grid-template-columns: 70px minmax(0, 1fr);
                gap: 12px;
            }

            .order-item-image {
                width: 70px;
                height: 90px;
            }

            .order-item-price {
                grid-column: 2;
            }

            .order-info-grid {
                grid-template-columns: 1fr;
                gap: 18px;
            }

            .footer-top,
            .footer-bottom {
                flex-direction: column;
            }

        }
            
        </style>

<!-- =====================================
     HEADER
===================================== -->

<header class="diane-header">

    <a class="diane-brand" href="index.php">

        <span class="diane-brand-name">
            DIANE
        </span>

        <span class="diane-brand-subtitle">
            CLOTHING SHOP
        </span>

    </a>

    <div class="header-right">

        <nav class="navigation">

            <a href="index.php">HOME</a>

            <a href="index.php#collection">COLLECTION</a>

            <a href="index.php#try-on">VIRTUAL TRY-ON</a>

        </nav>

        <div class="account-links">

            <a href="cart.php">MY BAG</a>

            <a href="my_orders.php">MY ORDERS</a>

            <a href="logout.php">LOG OUT</a>

            <span class="greeting">
                Hi, <?= myOrdersEscape($firstName) ?>
            </span>

            <!-- CUSTOMER PROFILE ICON -->
            <a
                class="diane-profile-icon"
                href="profile.php"
                aria-label="Open my profile"
                title="My profile"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="8" r="3.5"></circle>
                    <path d="M5 20c.7-3.3 3.2-5 7-5s6.3 1.7 7 5"></path>
                </svg>
            </a>

        </div>

    </div>

</header>

<!-- =====================================
     CUSTOMER ORDER HISTORY
===================================== -->

<main class="orders-page">

    <p class="breadcrumb">
        DIANE / MY ACCOUNT / MY ORDERS
    </p>

    <h1 class="page-heading">
        My Orders
    </h1>

    <p class="page-subtitle">
        View your purchases and follow the progress of your orders.
    </p>

    <?php if (($_GET['received'] ?? '') === '1'): ?>

        <div class="notice" role="status">
            Thank you! You have confirmed that you received your order.
        </div>

    <?php elseif (($_GET['receipt_error'] ?? '') === '1'): ?>

        <div class="notice error" role="alert">
            We could not confirm your order receipt. Please refresh the page and try again.
        </div>

    <?php endif; ?>

    <?php if ($orderLoadError !== ''): ?>

        <div class="notice error">

            <strong>
                Unable to load your order history.
            </strong>

            <br>

            <?= myOrdersEscape($orderLoadError) ?>

        </div>

    <?php elseif (empty($displayOrders)): ?>

        <section class="empty-orders">

            <div class="empty-orders-symbol">
                DIANE
            </div>

            <h2>
                No orders yet.
            </h2>

            <p>
                Your future purchases will appear here.
                <br>
                Discover something special from our collection.
            </p>

            <a
                href="index.php#collection"
                class="shop-button"
            >
                EXPLORE COLLECTION
            </a>

        </section>

    <?php else: ?>

        <section class="orders-list">

            <?php foreach ($displayOrders as $order): ?>

                <?php

                    $orderId = (string) ($order['id'] ?? '');

                    $orderStatus = (string) (
                        $order['status'] ?? 'Processing'
                    );

                    $paymentMethod = (string) (
                        $order['payment_method'] ?? 'Not recorded'
                    );

                    $paymentStatus = (string) (
                        $order['payment_status'] ?? 'pending'
                    );

                    $createdAt = $order['created_at'] ?? '';

                    $orderDate = $createdAt
                        ? date('F d, Y', strtotime($createdAt))
                        : 'Date unavailable';

                    $orderTotal = (float) (
                        $order['total'] ?? 0
                    );

                    // Determine current tracking step.

                    $trackingSteps = [

                        [
                            'label' => 'Processing',
                            'date' => $order['created_at'] ?? null
                        ],

                        [
                            'label' => 'Confirmed',
                            'date' => $order['confirmed_at'] ?? null
                        ],

                        [
                            'label' => 'Shipped',
                            'date' => $order['shipped_at'] ?? null
                        ],

                        [
                            'label' => 'Delivered',
                            'date' => $order['delivered_at'] ?? null
                        ]

                    ];

                    $statusOrder = [
                        'Processing' => 0,
                        'Confirmed' => 1,
                        'Shipped' => 2,
                        'Delivered' => 3
                    ];

                    $currentStep = $statusOrder[$orderStatus] ?? -1;

                    $isCancelled = ($orderStatus === 'Cancelled');

                    $statusClass = myOrdersStatusClass($orderStatus);

                ?>

                <article class="order-card">

                    <!-- ORDER HEADER -->

                    <div class="order-card-header">

                        <div>

                            <p class="order-number-label">
                                ORDER NUMBER
                            </p>

                            <p class="order-number">
                                <?= myOrdersEscape($orderId) ?>
                            </p>

                            <p class="order-date">
                                Placed on <?= myOrdersEscape($orderDate) ?>
                            </p>

                        </div>

                        <span class="order-status <?= myOrdersEscape(
                            $statusClass
                        ) ?>">

                            <?= myOrdersEscape($orderStatus) ?>

                        </span>

                    </div>

                    <!-- ==================================
                         TRACKING TIMELINE
                    ================================== -->

                    <section class="tracking-section">

                        <h2 class="tracking-title">
                            Order Tracking
                        </h2>

                        <?php if ($isCancelled): ?>

                            <div class="tracking-cancelled">

                                <strong>
                                    This order has been cancelled.
                                </strong>

                                <br>

                                <?php if (!empty($order['cancelled_at'])): ?>

                                    Cancelled on:

                                    <?= myOrdersEscape(
                                        myOrdersFormatDate(
                                            $order['cancelled_at']
                                        )
                                    ) ?>

                                <?php else: ?>

                                    Please contact the shop for more information.

                                <?php endif; ?>

                            </div>

                        <?php else: ?>

                            <div class="tracking-timeline">

                                <?php foreach (
                                    $trackingSteps as $index => $step
                                ): ?>

                                    <?php

                                        $stepCompleted = (
                                            $step['date'] !== null &&
                                            $step['date'] !== ''
                                        );

                                        // Also support orders whose status was
                                        // updated without a previous timestamp.

                                        if (
                                            $currentStep >= 0 &&
                                            $index <= $currentStep
                                        ) {
                                            $stepCompleted = true;
                                        }

                                        $stepCurrent = (
                                            $index === $currentStep
                                        );

                                        $stepClass = '';

                                        if ($stepCompleted) {
                                            $stepClass = 'completed';
                                        }

                                        if ($stepCurrent) {
                                            $stepClass .= ' current';
                                        }

                                    ?>

                                    <div class="tracking-step <?= myOrdersEscape(
                                        trim($stepClass)
                                    ) ?>">

                                        <div class="tracking-dot">
                                            <?= $stepCompleted ? '✓' : ($index + 1) ?>
                                        </div>

                                        <span class="tracking-step-label">

                                            <?= myOrdersEscape(
                                                $step['label']
                                            ) ?>

                                        </span>

                                        <span class="tracking-step-date">

                                            <?php if ($step['date'] !== null): ?>

                                                <?= myOrdersEscape(
                                                    myOrdersFormatDate(
                                                        $step['date']
                                                    )
                                                ) ?>

                                            <?php elseif ($stepCurrent): ?>

                                                In progress

                                            <?php else: ?>

                                                Awaiting update

                                            <?php endif; ?>

                                        </span>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </section>

                    <!-- CUSTOMER ORDER RECEIPT CONFIRMATION -->

                    <?php if (strcasecmp(trim($orderStatus), 'Delivered') === 0): ?>

                        <section class="receipt-confirmation">

                            <?php if (!empty($order['customer_received_at'])): ?>

                                <div class="receipt-success">

                                    <span class="receipt-check">✓</span>

                                    <div>

                                        <strong>ORDER RECEIVED CONFIRMED</strong>

                                        <p>
                                            You confirmed that you received this order on
                                            <?= myOrdersEscape(myOrdersFormatDate($order['customer_received_at'])) ?>.
                                        </p>

                                    </div>

                                </div>

                            <?php else: ?>

                                <h3>Have you received your order?</h3>

                                <p>
                                    Please confirm once your package has actually arrived.
                                    This confirmation will be saved to your order record.
                                </p>

                                <form
                                    method="POST"
                                    action="confirm_order_received.php"
                                    class="receipt-confirm-form"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= myOrdersEscape($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="order_id"
                                        value="<?= myOrdersEscape($orderId) ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="receipt-button"
                                    >
                                        CONFIRM ORDER RECEIVED
                                    </button>

                                </form>

                            <?php endif; ?>

                        </section>

                    <?php endif; ?>

                    <!-- ==================================
                         ORDER ITEMS
                    ================================== -->

                    <div class="order-card-body">

                        <p class="order-items-heading">
                            ORDER ITEMS
                        </p>

                        <?php if ($order['item_error'] !== ''): ?>

                            <div class="notice error">

                                <?= myOrdersEscape(
                                    $order['item_error']
                                ) ?>

                            </div>

                        <?php elseif (empty($order['items'])): ?>

                            <p class="order-item-detail">
                                No product items found for this order.
                            </p>

                        <?php else: ?>

                            <?php foreach ($order['items'] as $item): ?>

                                <?php

                                    $product = $item['product'];

                                    $productName = (string) (
                                        $product['name'] ?? 'Product'
                                    );

                                    $productImage = (string) (
                                        $product['image'] ?? ''
                                    );

                                    $size = (string) (
                                        $item['size'] ?? ''
                                    );

                                    $quantity = (int) (
                                        $item['quantity'] ?? 0
                                    );

                                    $price = (float) (
                                        $item['price'] ?? 0
                                    );

                                    $itemTotal = $quantity * $price;

                                ?>

                                <div class="order-item">

                                    <?php if ($productImage !== ''): ?>

                                        <img
                                            class="order-item-image"
                                            src="<?= myOrdersEscape(
                                                $productImage
                                            ) ?>"
                                            alt="<?= myOrdersEscape(
                                                $productName
                                            ) ?>"
                                            loading="lazy"
                                        >

                                    <?php else: ?>

                                        <div class="order-item-image"></div>

                                    <?php endif; ?>

                                    <div>

                                        <p class="order-item-name">

                                            <?= myOrdersEscape(
                                                $productName
                                            ) ?>

                                        </p>

                                        <p class="order-item-detail">
                                            Size:
                                            <?= myOrdersEscape($size) ?>
                                        </p>

                                        <p class="order-item-detail">
                                            Quantity:
                                            <?= $quantity ?>
                                        </p>

                                        <p class="order-item-detail">
                                            Price per item:
                                            ₱<?= number_format(
                                                $price,
                                                2
                                            ) ?>
                                        </p>

                                    </div>

                                    <div class="order-item-price">

                                        ₱<?= number_format(
                                            $itemTotal,
                                            2
                                        ) ?>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        <!-- DELIVERY AND PAYMENT INFORMATION -->

                        <div class="order-info-grid">

                            <div class="order-info-box">

                                <p class="order-info-label">
                                    DELIVERY ADDRESS
                                </p>

                                <p class="order-info-value">

                                    <?= myOrdersEscape(
                                        $order['full_name'] ?? ''
                                    ) ?>

                                    <br>

                                    <?= myOrdersEscape(
                                        $order['address'] ?? ''
                                    ) ?>

                                    <br>

                                    <?= myOrdersEscape(
                                        $order['city'] ?? ''
                                    ) ?>

                                    <?= myOrdersEscape(
                                        $order['zip'] ?? ''
                                    ) ?>

                                </p>

                            </div>

                            <div class="order-info-box">

                                <p class="order-info-label">
                                    CONTACT NUMBER
                                </p>

                                <p class="order-info-value">

                                    <?= myOrdersEscape(
                                        $order['phone'] ?? 'Not provided'
                                    ) ?>

                                </p>

                            </div>

                            <div class="order-info-box">

                                <p class="order-info-label">
                                    PAYMENT METHOD
                                </p>

                                <p class="order-info-value">

                                    <?= myOrdersEscape($paymentMethod) ?>

                                </p>

                            </div>

                            <div class="order-info-box">

                                <p class="order-info-label">
                                    PAYMENT STATUS
                                </p>

                                <p class="order-info-value">

                                    <?= myOrdersEscape(
                                        myOrdersPaymentLabel($paymentStatus)
                                    ) ?>

                                </p>

                            </div>

                        </div>

                    </div>
                
                    <!-- ORDER TOTAL -->
                    <div class="order-card-footer">
                        <span class="order-total-label">ORDER TOTAL</span>
                        <strong class="order-total-amount">
                            ₱<?= number_format($orderTotal, 2) ?>
                        </strong>
                    </div>
                </article>

            <?php endforeach; ?>

        </section>

    <?php endif; ?>

</main>

<!-- =====================================
     FOOTER
===================================== -->

<footer class="diane-footer">

    <div class="footer-top">

        <a href="index.php" class="footer-brand">

            <strong>DIANE</strong>

            <span>CLOTHING SHOP</span>

        </a>

        <div class="footer-links">

            <a href="index.php">HOME</a>

            <a href="index.php#collection">COLLECTION</a>

            <a href="index.php#try-on">VIRTUAL TRY-ON</a>

            <a href="cart.php">MY BAG</a>

            <a href="my_orders.php">MY ORDERS</a>

        </div>

    </div>

    <div class="footer-bottom">

        <span>
            DIANE CLOTHING SHOP &copy; <?= date('Y') ?>
        </span>

        <span>
            SIMPLE. TIMELESS. YOU.
        </span>

    </div>

</footer>

<!-- =====================================
     DIANE CUSTOM RECEIPT CONFIRMATION
===================================== -->

<div
    class="receipt-modal"
    id="receiptConfirmModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="receiptModalTitle"
    aria-describedby="receiptModalDescription"
    hidden
>

    <div class="receipt-modal-panel">

        <p class="receipt-modal-brand">DIANE</p>

        <p class="receipt-modal-kicker">
            ORDER DELIVERY CONFIRMATION
        </p>

        <h2 class="receipt-modal-title" id="receiptModalTitle">
            Have you received your order?
        </h2>

        <p class="receipt-modal-copy" id="receiptModalDescription">
            Please confirm only if your package has actually arrived.
            Your confirmation will be saved to your order history.
        </p>

        <div class="receipt-modal-actions">

            <button
                type="button"
                class="receipt-modal-cancel"
                id="receiptModalCancel"
            >
                NOT YET
            </button>

            <button
                type="button"
                class="receipt-modal-confirm"
                id="receiptModalConfirm"
            >
                YES, I RECEIVED IT
            </button>

        </div>

    </div>

</div>

<script>
(function () {

    const modal = document.getElementById('receiptConfirmModal');

    const cancelButton = document.getElementById('receiptModalCancel');

    const confirmButton = document.getElementById('receiptModalConfirm');

    if (!modal || !cancelButton || !confirmButton) {
        return;
    }

    let activeForm = null;
    let previousFocus = null;

    function openModal(form) {

        activeForm = form;

        previousFocus = document.activeElement;

        modal.hidden = false;

        document.body.style.overflow = 'hidden';

        cancelButton.focus();

    }

    function closeModal() {

        modal.hidden = true;

        document.body.style.overflow = '';

        activeForm = null;

        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }

    }

    document.querySelectorAll('.receipt-confirm-form').forEach(function (form) {

        form.addEventListener('submit', function (event) {

            event.preventDefault();

            openModal(form);

        });

    });

    cancelButton.addEventListener('click', closeModal);

    confirmButton.addEventListener('click', function () {

        if (!activeForm) {
            return;
        }

        confirmButton.disabled = true;

        confirmButton.textContent = 'CONFIRMING...';

        activeForm.submit();

    });

    modal.addEventListener('click', function (event) {

        if (event.target === modal) {
            closeModal();
        }

    });

    document.addEventListener('keydown', function (event) {

        if (!modal.hidden && event.key === 'Escape') {
            closeModal();
        }

    });

})();
</script>

</body>
</html>
