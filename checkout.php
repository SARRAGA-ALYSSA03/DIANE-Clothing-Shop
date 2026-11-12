<?php

/**

 * DIANE CLOTHING SHOP

 * Checkout with Supabase

 * Payment methods: COD, GCash, Maya

 */

if (session_status() !== PHP_SESSION_ACTIVE) {

    session_start();

}

require_once __DIR__ . '/includes/supabase.php';

// ==========================================

// 1. CUSTOMER LOGIN

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

// 2. REFRESH SUPABASE ACCESS TOKEN

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

// Save the new tokens.

$_SESSION['customer_access_token'] = $refreshData['access_token'];

$_SESSION['customer_refresh_token'] = $refreshData['refresh_token'];

$accessToken = (string) $_SESSION['customer_access_token'];

// ==========================================

// 3. CUSTOMER INFORMATION

// ==========================================

$customerName = trim(

    (string) ($_SESSION['customer_name'] ?? '')

);

$nameParts = preg_split('/\s+/', $customerName);

$firstName = $nameParts[0] ?? 'Customer';

if (empty($_SESSION['customer_csrf'])) {

    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));

}

$csrfToken = $_SESSION['customer_csrf'];

function checkoutEscape($value): string

{

    return htmlspecialchars(

        (string) $value,

        ENT_QUOTES,

        'UTF-8'

    );

}

// ==========================================

// 4. LOAD CUSTOMER CART

// ==========================================

function loadCheckoutCart(

    string $userId,

    string $accessToken

): array {

    $result = supabaseRequest(

        '/rest/v1/cart_items?select=id,product_id,size,quantity' .

        '&user_id=eq.' . rawurlencode($userId) .

        '&order=id.asc',

        'GET',

        null,

        $accessToken

    );

    if (empty($result['success'])) {

        return [

            'success' => false,

            'error' => $result['error'] ?? 'Unable to load your cart.',

            'items' => []

        ];

    }

    if (!is_array($result['data'] ?? null)) {

        return [

            'success' => false,

            'error' => 'Unexpected response from Supabase.',

            'items' => []

        ];

    }

    $items = [];

    foreach ($result['data'] as $row) {

        $productId = (string) ($row['product_id'] ?? '');

        if ($productId === '') {

            return [

                'success' => false,

                'error' => 'A cart item has an invalid product ID.',

                'items' => []

            ];

        }

        $productResult = supabaseRequest(

            '/rest/v1/products?select=id,name,price,image,images,stock,category,sizes' .

            '&id=eq.' . rawurlencode($productId) .

            '&limit=1',

            'GET',

            null,

            $accessToken

        );

        if (

            empty($productResult['success']) ||

            !is_array($productResult['data'] ?? null)

        ) {

            return [

                'success' => false,

                'error' => $productResult['error']

                    ?? 'Unable to retrieve product information.',

                'items' => []

            ];

        }

        $product = $productResult['data'][0] ?? null;

        if (!is_array($product)) {

            return [

                'success' => false,

                'error' => 'A product in your shopping bag could not be found.',

                'items' => []

            ];

        }

        if (

            empty($product['image']) &&

            !empty($product['images']) &&

            is_array($product['images'])

        ) {

            $product['image'] = $product['images'][0] ?? '';

        }

        $row['product'] = $product;

        $items[] = $row;

    }

    return [

        'success' => true,

        'error' => '',

        'items' => $items

    ];

}

// ==========================================

// 5. FORM VALUES AND MESSAGES

// ==========================================

$values = [

    'full_name' => $customerName,

    'phone' => '',

    'address' => '',

    'city' => '',

    'zip' => ''

];

$selectedPayment = 'Cash on Delivery';

$error = '';

$success = false;

$orderDetails = null;

// ==========================================

// 6. LOAD ORDER CONFIRMATION

// ==========================================

if (($_GET['success'] ?? '') === '1') {

    $orderId = (

        isset($_GET['order']) &&

        is_string($_GET['order'])

    )

        ? trim($_GET['order'])

        : '';

    if ($orderId !== '') {

        $orderResult = supabaseRequest(

            '/rest/v1/orders?select=id,total,status,created_at,full_name,phone,payment_method,payment_status' .

            '&id=eq.' . rawurlencode($orderId) .

            '&user_id=eq.' . rawurlencode($userId) .

            '&limit=1',

            'GET',

            null,

            $accessToken

        );

        if (

            !empty($orderResult['success']) &&

            !empty($orderResult['data'][0])

        ) {

            $orderDetails = $orderResult['data'][0];

            $success = true;

        }

    }

    if (!$success) {

        $error = 'We could not find that order. Please check your order history.';

    }

}

// ==========================================

// 7. PROCESS CHECKOUT

// ==========================================

if (

    $_SERVER['REQUEST_METHOD'] === 'POST' &&

    !$success

) {

    $submittedToken = $_POST['csrf_token'] ?? '';

    $values['full_name'] = trim(

        (string) ($_POST['full_name'] ?? '')

    );

    // NEW: SAVE CONTACT NUMBER INPUT

    $values['phone'] = trim(

        (string) ($_POST['phone'] ?? '')

    );

    $values['address'] = trim(

        (string) ($_POST['address'] ?? '')

    );

    $values['city'] = trim(

        (string) ($_POST['city'] ?? '')

    );

    $values['zip'] = trim(

        (string) ($_POST['zip'] ?? '')

    );

    $selectedPayment = (

        isset($_POST['payment_method']) &&

        is_string($_POST['payment_method'])

    )

        ? $_POST['payment_method']

        : '';

    $allowedPayments = [

        'Cash on Delivery',

        'GCash',

        'Maya'

    ];

    // ======================================

    // VALIDATE CUSTOMER INFORMATION

    // ======================================

    if (

        !is_string($submittedToken) ||

        !hash_equals($csrfToken, $submittedToken)

    ) {

        $error = 'Your form session has expired. Please refresh the page.';

    } elseif (

        $values['full_name'] === '' ||

        $values['phone'] === '' ||

        $values['address'] === '' ||

        $values['city'] === '' ||

        $values['zip'] === ''

    ) {

        $error = 'Please complete all required delivery fields.';

    } elseif (

        strlen($values['full_name']) > 120 ||

        strlen($values['phone']) > 20 ||

        strlen($values['address']) > 250 ||

        strlen($values['city']) > 100 ||

        strlen($values['zip']) > 20

    ) {

        $error = 'Please check the length of your delivery information.';

    } elseif (

        !preg_match('/^[0-9+()\s.\\-]{7,20}$/', $values['phone']) ||

        strlen(preg_replace('/\D/', '', $values['phone'])) < 7 ||

        strlen(preg_replace('/\D/', '', $values['phone'])) > 15

    ) {

        $error = 'Please enter a valid contact number using 7 to 15 digits.';

    } elseif (!in_array($selectedPayment, $allowedPayments, true)) {

        $error = 'Please select a valid payment method.';

    } else {

        // Reload the latest cart and product prices.

        // Do not trust totals supplied by the browser.

        $freshCart = loadCheckoutCart($userId, $accessToken);

        if (empty($freshCart['success'])) {

            $error = $freshCart['error'];

        } elseif (empty($freshCart['items'])) {

            $error = 'Your shopping bag is empty. Please add a product first.';

        } else {

            $subtotal = 0;

            $orderItems = [];

            $stockError = '';

            foreach ($freshCart['items'] as $item) {

                $product = $item['product'];

                $quantity = (int) ($item['quantity'] ?? 0);

                $size = trim((string) ($item['size'] ?? ''));

                $stock = max(

                    0,

                    (int) ($product['stock'] ?? 0)

                );

                $price = (float) ($product['price'] ?? 0);

                $availableSizes = is_array($product['sizes'] ?? null)

                    ? $product['sizes']

                    : [];

                if ($quantity < 1 || $quantity > $stock) {

                    $stockError = (

                        $product['name'] ?? 'A product'

                    ) . ' does not have enough stock.';

                    break;

                }

                if (

                    $size === '' ||

                    !in_array($size, $availableSizes, true)

                ) {

                    $stockError = (

                        $product['name'] ?? 'A product'

                    ) . ' has an invalid or unavailable size.';

                    break;

                }

                if ($price < 0) {

                    $stockError = 'A product has an invalid price.';

                    break;

                }

                $subtotal += $price * $quantity;

                $orderItems[] = [

                    'product_id' => (string) $product['id'],

                    'size' => $size,

                    'quantity' => $quantity,

                    'price' => $price

                ];

            }

            if ($stockError !== '') {

                $error = $stockError;

            } else {

                // ==================================

                // 8. PAYMENT STATUS

                // ==================================

                if ($selectedPayment === 'Cash on Delivery') {

                    $paymentStatus = 'unpaid_on_delivery';

                } else {

                    // GCash and Maya are not automatically verified.

                    $paymentStatus = 'pending_verification';

                }

                // ==================================

                // 9. CREATE ORDER

                // ==================================

                $newOrderId = 'ORD-' . strtoupper(

                    bin2hex(random_bytes(4))

                );

                $orderPayload = [

                    'id' => $newOrderId,

                    'user_id' => $userId,

                    'total' => round($subtotal, 2),

                    'status' => 'Processing',

                    'full_name' => $values['full_name'],

                    'phone' => $values['phone'],

                    'address' => $values['address'],

                    'city' => $values['city'],

                    'zip' => $values['zip'],

                    'payment_method' => $selectedPayment,

                    'payment_status' => $paymentStatus

                ];

                // ==================================

                // 10. CREATE ORDER + SAVE ITEMS + DEDUCT STOCK

                // ==================================

                $checkoutResult = supabaseRequest(

                    '/rest/v1/rpc/checkout_create_order',

                    'POST',

                    [

                        'p_order' => $orderPayload,

                        'p_items' => $orderItems

                    ],

                    $accessToken

                );

                $checkoutData = $checkoutResult['data'] ?? null;

                $createdOrderId = (

                    is_array($checkoutData) &&

                    !empty($checkoutData['order_id'])

                )

                    ? (string) $checkoutData['order_id']

                    : '';

                if (

                    empty($checkoutResult['success']) ||

                    !is_array($checkoutData) ||

                    empty($checkoutData['success']) ||

                    $createdOrderId === ''

                ) {

                    $error = (

                        is_array($checkoutData)

                            ? (string) ($checkoutData['message'] ?? '')

                            : ''

                    );

                    if ($error === '') {

                        $error = (string) (

                            $checkoutResult['error'] ??

                            'Unable to complete checkout. Your cart and stock were not changed.'

                        );

                    }

                } else {

                    header(

                        'Location: checkout.php?success=1&order=' .

                        rawurlencode($createdOrderId)

                    );

                    exit;

                }

            }

        }

    }

}

// ==========================================

// 12. LOAD CART FOR CHECKOUT DISPLAY

// ==========================================

$cartResult = loadCheckoutCart($userId, $accessToken);

$cartItems = $cartResult['success']

    ? $cartResult['items']

    : [];

$cartLoadError = $cartResult['success']

    ? ''

    : $cartResult['error'];

$subtotal = 0;

$totalItems = 0;

foreach ($cartItems as $item) {

    $quantity = (int) ($item['quantity'] ?? 0);

    $price = (float) ($item['product']['price'] ?? 0);

    $subtotal += $price * $quantity;

    $totalItems += $quantity;

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Checkout | DIANE Clothing Shop</title>

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

            color: var(--black);

            background: var(--white);

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

            background: #fff;

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

        .header-right {

            display: flex;

            align-items: center;

            justify-content: flex-end;

            gap: 28px;

        }

        .navigation,

        .account-links {

            display: flex;

            align-items: center;

            gap: 20px;

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

                    /* Profile icon beside customer name */

            .diane-profile-icon {

                width: 30px;

                height: 30px;

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

            .diane-profile-icon:hover {

                background: var(--black);

                color: #ffffff;

                border-color: var(--black);

            }

            .diane-profile-icon:focus-visible {

                outline: 2px solid var(--black);

                outline-offset: 3px;

            }

        .greeting {

            color: var(--gray);

            font-size: 11px;

            white-space: nowrap;

        }

        /* =====================================

           MAIN

        ===================================== */

        .checkout-page {

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

            line-height: 1.7;

        }

        /* =====================================

           CHECKOUT GRID

        ===================================== */

        .checkout-layout {

            display: grid;

            grid-template-columns: minmax(0, 1fr) 380px;

            gap: 55px;

            align-items: start;

        }

        .checkout-section {

            margin-bottom: 35px;

        }

        .section-title {

            margin: 0 0 23px;

            padding-bottom: 16px;

            border-bottom: 1px solid var(--border);

            font-family: var(--serif);

            font-size: 26px;

            font-weight: normal;

        }

        .section-description {

            margin: -10px 0 22px;

            color: var(--gray);

            font-size: 11px;

            line-height: 1.7;

        }

        /* =====================================

           DELIVERY FORM

        ===================================== */

        .form-group {

            margin-bottom: 20px;

        }

        .form-group label {

            display: block;

            margin-bottom: 9px;

            font-size: 10px;

            font-weight: 700;

            letter-spacing: .8px;

        }

        .form-group input,

        .form-group textarea {

            display: block;

            width: 100%;

            min-height: 49px;

            padding: 13px 14px;

            background: #fff;

            border: 1px solid #dddddd;

            border-radius: 0;

            color: var(--black);

            font-family: var(--sans);

            font-size: 12px;

        }

        .form-group textarea {

            min-height: 105px;

            resize: vertical;

        }

        .form-group input:focus,

        .form-group textarea:focus {

            outline: none;

            border-color: var(--black);

        }

        .form-row {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 15px;

        }

        /* =====================================

           PAYMENT METHODS

        ===================================== */

        .payment-options {

            display: grid;

            gap: 12px;

        }

        .payment-option {

            display: flex;

            align-items: center;

            gap: 14px;

            padding: 17px;

            background: #ffffff;

            border: 1px solid #dddddd;

            cursor: pointer;

            transition: border-color .2s ease;

        }

        .payment-option:has(input:checked) {

            border: 1px solid #111111;

            background: #f7f7f5;

        }

        .payment-option input {

            width: 16px;

            height: 16px;

            accent-color: #111111;

            flex: 0 0 auto;

        }

        .payment-option-content {

            flex: 1;

            min-width: 0;

        }

        .payment-option-title {

            display: block;

            margin-bottom: 5px;

            font-size: 12px;

            font-weight: 700;

        }

        .payment-option-description {

            display: block;

            color: #777777;

            font-size: 10px;

            line-height: 1.6;

        }

        .payment-tag {

            color: #777777;

            font-size: 9px;

            letter-spacing: .6px;

            white-space: nowrap;

        }

        .payment-notice {

            margin-top: 15px;

            padding: 15px;

            background: #f5f5f3;

            color: #666666;

            font-size: 10px;

            line-height: 1.8;

        }

        /* =====================================

           ORDER SUMMARY

        ===================================== */

        .summary-card {

            position: sticky;

            top: 25px;

            padding: 28px;

            background: var(--light);

        }

        .summary-title {

            margin: 0 0 23px;

            font-family: var(--serif);

            font-size: 25px;

            font-weight: normal;

        }

        .summary-products {

            display: grid;

            gap: 18px;

            margin-bottom: 24px;

        }

        .summary-product {

            display: grid;

            grid-template-columns: 72px minmax(0, 1fr);

            gap: 13px;

        }

        .summary-product img {

            width: 72px;

            height: 90px;

            background: #eeeeec;

            object-fit: contain;

        }

        .summary-product-name {

            margin: 3px 0 7px;

            font-size: 11px;

            line-height: 1.5;

        }

        .summary-product-detail {

            margin: 4px 0;

            color: var(--gray);

            font-size: 10px;

        }

        .summary-product-price {

            margin-top: 8px;

            font-size: 11px;

            font-weight: 600;

        }

        .summary-line {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 14px;

            padding: 13px 0;

            border-top: 1px solid #dddddd;

            color: #666666;

            font-size: 11px;

        }

        .summary-line strong {

            color: var(--black);

        }

        .summary-total {

            padding-top: 20px;

            color: var(--black);

            font-weight: 700;

        }

        .summary-total strong {

            font-size: 19px;

        }

        .summary-note {

            margin: 17px 0 0;

            color: var(--gray);

            font-size: 10px;

            line-height: 1.8;

        }

        /* =====================================

           BUTTONS

        ===================================== */

        .place-order-button {

            width: 100%;

            min-height: 54px;

            margin-top: 23px;

            padding: 12px 18px;

            background: var(--black);

            color: var(--white);

            border: 1px solid var(--black);

            border-radius: 0;

            font-size: 10px;

            font-weight: 700;

            letter-spacing: 1.3px;

            cursor: pointer;

            transition: .2s ease;

        }

        .place-order-button:hover {

            background: var(--white);

            color: var(--black);

        }

        .place-order-button:disabled {

            opacity: .45;

            cursor: not-allowed;

        }

        .continue-link {

            display: block;

            margin-top: 22px;

            color: #555555;

            font-size: 10px;

            letter-spacing: .8px;

            text-align: center;

            text-decoration: underline;

            text-underline-offset: 4px;

        }

        /* =====================================

           MESSAGES

        ===================================== */

        .notice {

            margin-bottom: 25px;

            padding: 17px 19px;

            background: var(--light);

            color: #333333;

            font-size: 12px;

            line-height: 1.8;

        }

        .notice.error {

            color: #a21d1d;

        }

        /* =====================================

           ORDER CONFIRMATION

        ===================================== */

        .confirmation {

            max-width: 720px;

            margin: 50px auto 80px;

            padding: 55px 35px;

            background: var(--light);

            text-align: center;

        }

        .confirmation-symbol {

            width: 58px;

            height: 58px;

            margin: 0 auto 24px;

            display: grid;

            place-items: center;

            border: 1px solid var(--black);

            border-radius: 50%;

            font-size: 25px;

        }

        .confirmation h1 {

            margin: 0;

            font-family: var(--serif);

            font-size: 38px;

            font-weight: normal;

        }

        .confirmation > p {

            margin: 14px auto;

            color: var(--gray);

            font-size: 12px;

            line-height: 1.8;

        }

        .order-number {

            margin: 27px 0;

            padding: 19px;

            background: #fff;

            border: 1px solid var(--border);

        }

        .order-number span {

            display: block;

            margin-bottom: 8px;

            color: var(--gray);

            font-size: 9px;

            letter-spacing: 1.2px;

        }

        .order-number strong {

            font-size: 16px;

            letter-spacing: 1px;

        }

        .confirmation-total {

            margin: 20px 0;

            font-family: var(--serif);

            font-size: 26px;

        }

        .confirmation-button {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-height: 49px;

            margin-top: 15px;

            padding: 0 27px;

            background: var(--black);

            color: #fff;

            font-size: 10px;

            font-weight: 700;

            letter-spacing: 1px;

            text-decoration: none;

        }

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

            color: #fff;

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

            color: #ddd;

            font-size: 10px;

            letter-spacing: .8px;

            text-decoration: none;

        }

        .footer-bottom {

            display: flex;

            justify-content: space-between;

            gap: 15px;

            padding-top: 22px;

            color: #aaa;

            font-size: 10px;

        }

        /* =====================================

           RESPONSIVE DESIGN

        ===================================== */

        @media (max-width: 900px) {

            .checkout-layout {

                grid-template-columns: 1fr;

                gap: 30px;

            }

            .summary-card {

                position: static;

            }

        }

        @media (max-width: 650px) {

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

            .checkout-page {

                padding: 40px 5% 65px;

            }

            .form-row {

                grid-template-columns: 1fr;

                gap: 0;

            }

            .summary-card {

                padding: 22px;

            }

            .confirmation {

                margin: 30px 0 60px;

                padding: 40px 20px;

            }

            .footer-top,

            .footer-bottom {

                flex-direction: column;

            }

        }

    </style>

</head>

<body>

<!-- =====================================

     HEADER

\===================================== -->

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

            <a href="logout.php">LOG OUT</a>

       <span class="greeting">

                Hi, <?= checkoutEscape($firstName) ?>

            </span>

            <a

                class="diane-profile-icon"

                href="profile.php"

                aria-label="Open my profile"

                title="My profile"

            >

                <svg

                    xmlns="http://www\.w3.org/2000/svg"

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

     MAIN CHECKOUT

\===================================== -->

<main class="checkout-page">

    <p class="breadcrumb">

        DIANE / SHOPPING BAG / CHECKOUT

    </p>

    <?php if ($success && is_array($orderDetails)): ?>

        <!-- ORDER CONFIRMATION -->

        <section class="confirmation">

            <div class="confirmation-symbol">✓</div>

            <p class="breadcrumb">

                DIANE CLOTHING SHOP

            </p>

            <h1>

                Thank you for your order.

            </h1>

            <p>

                Your order has been successfully placed.

                We appreciate your support.

            </p>

            <div class="order-number">

                <span>

                    YOUR ORDER NUMBER

                </span>

                <strong>

                    <?= checkoutEscape($orderDetails['id']) ?>

                </strong>

            </div>

            <p>

                Order status:

                <strong>

                    <?= checkoutEscape(

                        $orderDetails['status'] ?? 'Processing'

                    ) ?>

                </strong>

            </p>

            <p>

                Payment method:

                <strong>

                    <?= checkoutEscape(

                        $orderDetails['payment_method'] ?? 'Not recorded'

                    ) ?>

                </strong>

            </p>

            <p>

                Payment status:

                <strong>

                    <?= checkoutEscape(

                        ucwords(str_replace(

                            '_',

                            ' ',

                            $orderDetails['payment_status'] ?? 'pending'

                        ))

                    ) ?>

                </strong>

            </p>

            <div class="confirmation-total">

                ₱<?= number_format(

                    (float) $orderDetails['total'],

                    2

                ) ?>

            </div>

            <?php if (

                in_array(

                    $orderDetails['payment_method'] ?? '',

                    ['GCash', 'Maya'],

                    true

                )

            ): ?>

                <div class="notice">

                    Your e-wallet payment method has been recorded

                    as pending verification.

                    No payment has been collected or verified by this website.

                    Please follow the shop's confirmed payment instructions.

                </div>

            <?php else: ?>

                <div class="notice">

                    Please prepare payment when your order is delivered.

                </div>

            <?php endif; ?>

            <a

                href="index.php#collection"

                class="confirmation-button"

            >

                CONTINUE SHOPPING

            </a>

        </section>

    <?php else: ?>

        <h1 class="page-heading">

            Checkout

        </h1>

        <p class="page-subtitle">

            Complete your delivery and payment information.

        </p>

        <?php if ($error !== ''): ?>

            <div class="notice error" role="alert">

                <?= checkoutEscape($error) ?>

            </div>

        <?php endif; ?>

        <?php if ($cartLoadError !== ''): ?>

            <div class="notice error" role="alert">

                We could not load your shopping bag.

                <br>

                <?= checkoutEscape($cartLoadError) ?>

            </div>

        <?php elseif (empty($cartItems)): ?>

            <div class="notice">

                Your shopping bag is empty. Please add a product first.

            </div>

            <a

                href="index.php#collection"

                class="confirmation-button"

            >

                EXPLORE COLLECTION

            </a>

        <?php else: ?>

            <form method="POST" action="checkout.php">

                <input

                    type="hidden"

                    name="csrf_token"

                    value="<?= checkoutEscape($csrfToken) ?>"

                >

                <div class="checkout-layout">

                    <!-- DELIVERY INFORMATION -->

                    <section>

                        <div class="checkout-section">

                            <h2 class="section-title">

                                Delivery Information

                            </h2>

                            <p class="section-description">

                                Enter the details where you want your order delivered.

                            </p>

                            <!-- FULL NAME -->

                            <div class="form-group">

                                <label for="full_name">

                                    FULL NAME *

                                </label>

                                <input

                                    type="text"

                                    id="full_name"

                                    name="full_name"

                                    value="<?= checkoutEscape(

                                        $values['full_name']

                                    ) ?>"

                                    placeholder="Enter your full name"

                                    maxlength="120"

                                    autocomplete="name"

                                    required

                                >

                            </div>

                            <!-- NEW CONTACT NUMBER FIELD -->

                            <div class="form-group">

                                <label for="phone">

                                    CONTACT NUMBER *

                                </label>

                                <input

                                    type="tel"

                                    id="phone"

                                    name="phone"

                                    value="<?= checkoutEscape($values['phone']) ?>"

                                    placeholder="e.g. 0917 123 4567"

                                    maxlength="20"

                                    autocomplete="tel"

                                    inputmode="tel"

                                    pattern="[0-9+() .-]{7,20}"

                                    title="Enter a valid phone number using 7 to 15 digits."

                                    required

                                >

                            </div>

                            <!-- DELIVERY ADDRESS -->

                            <div class="form-group">

                                <label for="address">

                                    COMPLETE DELIVERY ADDRESS *

                                </label>

                                <textarea

                                    id="address"

                                    name="address"

                                    placeholder="House number, street, barangay, landmark"

                                    maxlength="250"

                                    autocomplete="street-address"

                                    required

                                ><?= checkoutEscape($values['address']) ?></textarea>

                            </div>

                            <!-- CITY AND ZIP CODE -->

                            <div class="form-row">

                                <div class="form-group">

                                    <label for="city">

                                        CITY / MUNICIPALITY *

                                    </label>

                                    <input

                                        type="text"

                                        id="city"

                                        name="city"

                                        value="<?= checkoutEscape(

                                            $values['city']

                                        ) ?>"

                                        placeholder="Enter city"

                                        maxlength="100"

                                        autocomplete="address-level2"

                                        required

                                    >

                                </div>

                                <div class="form-group">

                                    <label for="zip">

                                        ZIP CODE *

                                    </label>

                                    <input

                                        type="text"

                                        id="zip"

                                        name="zip"

                                        value="<?= checkoutEscape(

                                            $values['zip']

                                        ) ?>"

                                        placeholder="Enter ZIP code"

                                        maxlength="20"

                                        autocomplete="postal-code"

                                        required

                                    >

                                </div>

                            </div>

                        </div>

                        <!-- PAYMENT METHOD -->

                        <div class="checkout-section">

                            <h2 class="section-title">

                                Payment Method

                            </h2>

                            <p class="section-description">

                                Choose your preferred payment method.

                            </p>

                            <div class="payment-options">

                                <!-- CASH ON DELIVERY -->

                                <label class="payment-option">

                                    <input

                                        type="radio"

                                        name="payment_method"

                                        value="Cash on Delivery"

                                        <?= $selectedPayment === 'Cash on Delivery'

                                            ? 'checked'

                                            : ''

                                        ?>

                                        required

                                    >

                                    <span class="payment-option-content">

                                        <span class="payment-option-title">

                                            Cash on Delivery

                                        </span>

                                        <span class="payment-option-description">

                                            Pay when your order arrives.

                                        </span>

                                    </span>

                                    <span class="payment-tag">

                                        COD

                                    </span>

                                </label>

                                <!-- GCASH -->

                                <label class="payment-option">

                                    <input

                                        type="radio"

                                        name="payment_method"

                                        value="GCash"

                                        <?= $selectedPayment === 'GCash'

                                            ? 'checked'

                                            : ''

                                        ?>

                                        required

                                    >

                                    <span class="payment-option-content">

                                        <span class="payment-option-title">

                                            GCash

                                        </span>

                                        <span class="payment-option-description">

                                            Choose GCash as your intended payment method.

                                        </span>

                                    </span>

                                    <span class="payment-tag">

                                        E-WALLET

                                    </span>

                                </label>

                                <!-- MAYA -->

                                <label class="payment-option">

                                    <input

                                        type="radio"

                                        name="payment_method"

                                        value="Maya"

                                        <?= $selectedPayment === 'Maya'

                                            ? 'checked'

                                            : ''

                                        ?>

                                        required

                                    >

                                    <span class="payment-option-content">

                                        <span class="payment-option-title">

                                            Maya

                                        </span>

                                        <span class="payment-option-description">

                                            Choose Maya as your intended payment method.

                                        </span>

                                    </span>

                                    <span class="payment-tag">

                                        E-WALLET

                                    </span>

                                </label>

                            </div>

                            <div class="payment-notice">

                                <strong>

                                    Payment information

                                </strong>

                                <br>

                                Cash on Delivery will be recorded as unpaid until delivery.

                                GCash and Maya will be recorded as pending verification.

                                This website does not yet process e-wallet payments

                                or verify transfers.

                            </div>

                        </div>

                    </section>

                    <!-- ORDER SUMMARY -->

                    <aside class="summary-card">

                        <h2 class="summary-title">

                            Your Order

                        </h2>

                        <div class="summary-products">

                            <?php foreach ($cartItems as $item): ?>

                                <?php

                                    $product = $item['product'];

                                    $quantity = (int) (

                                        $item['quantity'] ?? 0

                                    );

                                    $price = (float) (

                                        $product['price'] ?? 0

                                    );

                                    $lineTotal = $price * $quantity;

                                ?>

                                <div class="summary-product">

                                    <?php if (!empty($product['image'])): ?>

                                        <img

                                            src="<?= checkoutEscape(

                                                $product['image']

                                            ) ?>"

                                            alt="<?= checkoutEscape(

                                                $product['name']

                                            ) ?>"

                                        >

                                    <?php else: ?>

                                        <div></div>

                                    <?php endif; ?>

                                    <div>

                                        <p class="summary-product-name">

                                            <?= checkoutEscape(

                                                $product['name']

                                            ) ?>

                                        </p>

                                        <p class="summary-product-detail">

                                            Size:

                                            <?= checkoutEscape(

                                                $item['size'] ?? ''

                                            ) ?>

                                        </p>

                                        <p class="summary-product-detail">

                                            Quantity:

                                            <?= $quantity ?>

                                        </p>

                                        <p class="summary-product-price">

                                            ₱<?= number_format(

                                                $lineTotal,

                                                2

                                            ) ?>

                                        </p>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                        <div class="summary-line">

                            <span>

                                Total items

                            </span>

                            <strong>

                                <?= $totalItems ?>

                            </strong>

                        </div>

                        <div class="summary-line">

                            <span>

                                Subtotal

                            </span>

                            <strong>

                                ₱<?= number_format($subtotal, 2) ?>

                            </strong>

                        </div>

                        <div class="summary-line">

                            <span>

                                Shipping

                            </span>

                            <strong>

                                Confirmed separately

                            </strong>

                        </div>

                        <div class="summary-line summary-total">

                            <span>

                                ORDER TOTAL

                            </span>

                            <strong>

                                ₱<?= number_format($subtotal, 2) ?>

                            </strong>

                        </div>

                        <p class="summary-note">

                            This total covers the products only.

                            Any delivery charge must be confirmed separately.

                        </p>

                        <button

                            type="submit"

                            class="place-order-button"

                            <?= empty($cartItems) ? 'disabled' : '' ?>

                        >

                            PLACE ORDER

                        </button>

                        <a

                            href="cart.php"

                            class="continue-link"

                        >

                            BACK TO SHOPPING BAG

                        </a>

                    </aside>

                </div>

            </form>

        <?php endif; ?>

    <?php endif; ?>

</main>

<!-- =====================================

     FOOTER

\===================================== -->

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

</body>

</html>
