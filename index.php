
<?php
/**
 * DIANE CLOTHING SHOP
 * Luxury black-and-white editorial homepage.
 * PHP + Supabase + Shopping Cart + Virtual Try-On
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/includes/supabase.php';
// ==========================================
// 1. PRODUCT CATEGORIES
// ==========================================
$categories = [
    'All',
    'New In',
    'Dresses',
    'Tops',
    'Bottoms',
    'Outerwear',
    'Knitwear',
    'Basics'
];
$selectedCategory = (
    isset($_GET['category']) && is_string($_GET['category'])
)
    ? trim($_GET['category'])
    : 'All';
if (!in_array($selectedCategory, $categories, true)) {
    $selectedCategory = 'All';
}

// Customer product search. Search is combined with the selected category.
$searchQuery = (
    isset($_GET['search']) && is_string($_GET['search'])
)
    ? trim(substr($_GET['search'], 0, 100))
    : '';
// ==========================================
// 2. CUSTOMER SESSION
// ==========================================
$isCustomerLoggedIn = (
    !empty($_SESSION['customer_id']) &&
    !empty($_SESSION['customer_access_token'])
);
$fullCustomerName = trim(
    (string)($_SESSION['customer_name'] ?? '')
);
$nameParts = preg_split('/\s+/', $fullCustomerName);
$customerName = (
    is_array($nameParts) && !empty($nameParts[0])
)
    ? $nameParts[0]
    : 'Customer';
// Generate CSRF token.
if ($isCustomerLoggedIn && empty($_SESSION['customer_csrf'])) {
    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
}
$customerCsrf = $_SESSION['customer_csrf'] ?? '';
// ==========================================
// 3. SHOPPING BAG COUNT
// ==========================================
$cartCount = 0;
if ($isCustomerLoggedIn) {
    $cartResult = supabaseRequest(
        '/rest/v1/cart_items?select=quantity&user_id=eq.' .
        rawurlencode((string)$_SESSION['customer_id']),
        'GET',
        null,
        (string)$_SESSION['customer_access_token']
    );
    if (
        !empty($cartResult['success']) &&
        is_array($cartResult['data'] ?? null)
    ) {
        foreach ($cartResult['data'] as $item) {
            $cartCount += (int)($item['quantity'] ?? 0);
        }
    }
}
// ==========================================
// 4. LOAD PRODUCTS FROM SUPABASE
// ==========================================
$productResult = supabaseRequest(
    '/rest/v1/products?select=*&order=created_at.desc',
    'GET'
);
$products = [];
$productLoadError = empty($productResult['success']);
if (!$productLoadError && is_array($productResult['data'])) {
    foreach ($productResult['data'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        // Get primary product image.
        $imageUrl = trim((string)($row['image'] ?? ''));
        // Use first gallery image if main image is empty.
        if (
            $imageUrl === '' &&
            !empty($row['images']) &&
            is_array($row['images'])
        ) {
            $imageUrl = trim(
                (string)($row['images'][0] ?? '')
            );
        }
        $products[] = [
            'id' => (string)($row['id'] ?? ''),
            'name' => (string)($row['name'] ?? 'Unnamed Product'),
            'price' => (float)($row['price'] ?? 0),
            'rating' => max(0, min(5, (float)($row['rating'] ?? 0))),
            'review_count' => max(0, (int)($row['review_count'] ?? 0)),
            'original_price' => (
                isset($row['original_price'])
                    ? (float)$row['original_price']
                    : null
            ),
            'category' => (string)($row['category'] ?? ''),
            'image' => $imageUrl,
            // Separate transparent image for Virtual Try-On
            'overlay' => trim((string)($row['tryon_image'] ?? '')),
            'is_new' => filter_var(
                $row['is_new'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),
            'is_featured' => filter_var(
                $row['is_featured'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),
            'description' => (string)($row['description'] ?? ''),
            'sizes' => is_array($row['sizes'] ?? null)
                ? $row['sizes']
                : [],
            'colors' => is_array($row['colors'] ?? null)
                ? $row['colors']
                : [],
            'material' => (string)($row['material'] ?? ''),
            'stock' => max(0, (int)($row['stock'] ?? 0))
        ];
    }
} elseif (!$productLoadError) {
    $productLoadError = true;
}
// ==========================================
// 5. FILTER PRODUCTS BY CATEGORY AND SEARCH
// ==========================================
$visibleProducts = array_values(
    array_filter(
        $products,
        function ($product) use ($selectedCategory, $searchQuery) {
            // Category filter.
            if ($selectedCategory === 'New In') {
                if (empty($product['is_new'])) {
                    return false;
                }
            } elseif (
                $selectedCategory !== 'All' &&
                ($product['category'] ?? '') !== $selectedCategory
            ) {
                return false;
            }

            // Search product name, category, and description.
            if ($searchQuery !== '') {
                $searchableText = implode(' ', [
                    (string)($product['name'] ?? ''),
                    (string)($product['category'] ?? ''),
                    (string)($product['description'] ?? '')
                ]);

                if (stripos($searchableText, $searchQuery) === false) {
                    return false;
                }
            }

            return true;
        }
    )
);
// Featured hero product.
$heroProduct = $products[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DIANE | Clothing Shop</title>
    <meta
        name="description"
        content="Discover timeless clothing and modern essentials at Diane Clothing Shop."
    >
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/tryon.js?v=20261108toggle" defer></script>
    <style>
        /* =========================================
           01. GLOBAL DESIGN
        ========================================= */
        :root {
            --diane-black: #111111;
            --diane-white: #ffffff;
            --diane-gray: #777777;
            --diane-light: #f5f5f3;
            --diane-border: #e5e5e5;
            --diane-serif: Georgia, "Times New Roman", serif;
            --diane-sans: "Helvetica Neue", Arial, sans-serif;
        }
        * {
            box-sizing: border-box;
        }
        html {
            scroll-behavior: smooth;
            scroll-padding-top: 95px;
        }
        body {
            margin: 0;
            background: var(--diane-white);
            color: var(--diane-black);
            font-family: var(--diane-sans);
        }
        a {
            color: inherit;
        }
        button,
        input,
        select {
            font: inherit;
        }
        button,
        a {
            -webkit-tap-highlight-color: transparent;
        }
        /* =========================================
           02. HEADER
        ========================================= */
        .diane-header {
            position: relative;
            z-index: 100;
            width: 100%;
            min-height: 86px;
            padding: 12px 5%;
            background: #ffffff;
            border-bottom: 1px solid var(--diane-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
        }
        /* DIANE WORDMARK */
        .diane-brand {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #111111;
        }
        .diane-brand-name {
            font-family: var(--diane-serif);
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
            line-height: 1.4;
        }
        /* RIGHT SIDE */
        .diane-header-right {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 32px;
            margin-left: auto;
        }
        .diane-navigation {
            display: flex;
            align-items: center;
            gap: 25px;
        }
        .diane-navigation a,
        .diane-account a {
            color: #111111;
            text-decoration: none;
            font-size: 10px;
            font-weight: 500;
            letter-spacing: 1px;
            white-space: nowrap;
            transition: opacity .2s ease;
        }
        .diane-navigation a:hover,
        .diane-account a:hover {
            opacity: .55;
        }
        /* ACCOUNT AREA */
        .diane-account {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 16px;
            white-space: nowrap;
        }
        .diane-bag-link {
            font-weight: 600 !important;
        }
        .diane-greeting {
            color: #666666;
            font-size: 11px;
            letter-spacing: .2px;
            white-space: nowrap;
        }
        /* Small profile shortcut beside the customer's name */
        .diane-profile-icon {
            width: 30px;
            height: 30px;
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            color: #111111;
            border: 1px solid #dddddd;
            border-radius: 50%;
            text-decoration: none;
            transition: background .2s ease, color .2s ease, border-color .2s ease;
        }
        .diane-profile-icon svg {
            width: 16px;
            height: 16px;
            display: block;
        }
        .diane-profile-icon:hover {
            background: #111111;
            color: #ffffff !important;
            border-color: #111111;
            opacity: 1 !important;
        }
        .diane-profile-icon:focus-visible {
            outline: 2px solid #111111;
            outline-offset: 3px;
        }
        /* =========================================
           03. HERO SECTION
        ========================================= */
        .diane-hero {
            min-height: 600px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: #f5f5f3;
        }
        .diane-hero-copy {
            padding: 90px 10% 90px 13%;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
        }
        .diane-eyebrow {
            margin: 0 0 22px;
            color: #777777;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 3px;
            text-transform: uppercase;
        }
        .diane-hero-title {
            max-width: 560px;
            margin: 0;
            font-family: var(--diane-serif);
            font-size: clamp(45px, 5.4vw, 76px);
            font-weight: normal;
            letter-spacing: -2px;
            line-height: 1.06;
        }
        .diane-hero-description {
            max-width: 380px;
            margin: 23px 0 32px;
            color: #666666;
            font-size: 13px;
            line-height: 1.9;
        }
        .diane-primary-button {
            min-height: 49px;
            padding: 0 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #111111;
            color: #ffffff;
            border: 1px solid #111111;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-decoration: none;
            transition: .2s ease;
            cursor: pointer;
        }
        .diane-primary-button:hover {
            background: #ffffff;
            color: #111111;
        }
        .diane-hero-secondary {
            display: inline-block;
            margin-top: 23px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1px;
            text-decoration: underline;
            text-underline-offset: 5px;
        }
        .diane-hero-image {
            position: relative;
            min-height: 600px;
            overflow: hidden;
            background: #eeeeec;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .diane-hero-image img {
            width: 100%;
            height: 100%;
            max-height: 680px;
            position: absolute;
            inset: 0;
            object-fit: contain;
            mix-blend-mode: multiply;
        }
        .diane-hero-image-placeholder {
            color: #888888;
            font-size: 12px;
            letter-spacing: 2px;
        }
        .diane-hero-image-label {
            position: absolute;
            right: 25px;
            bottom: 22px;
            padding: 10px 15px;
            background: #ffffff;
            color: #111111;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 1px;
        }
        /* =========================================
           04. EDITORIAL INTRO
        ========================================= */
        .diane-intro {
            padding: 82px 20px 65px;
            text-align: center;
        }
        .diane-intro h2 {
            margin: 0;
            font-family: var(--diane-serif);
            font-size: clamp(29px, 3.5vw, 45px);
            font-weight: normal;
        }
        .diane-intro p {
            max-width: 490px;
            margin: 15px auto 0;
            color: #777777;
            font-size: 12px;
            line-height: 1.8;
        }
        .diane-intro-line {
            width: 42px;
            height: 1px;
            margin: 25px auto 0;
            background: #111111;
        }
        /* =========================================
           05. COLLECTION SECTION
        ========================================= */
        .diane-collection {
            padding: 25px 5% 100px;
        }
        .diane-section-top {
            margin-bottom: 28px;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
        }
        .diane-section-label {
            margin: 0 0 10px;
            color: #777777;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 2.5px;
        }
        .diane-section-title {
            margin: 0;
            font-family: var(--diane-serif);
            font-size: clamp(29px, 3vw, 39px);
            font-weight: normal;
        }
        .diane-product-count {
            color: #777777;
            font-size: 11px;
            white-space: nowrap;
        }
        /* CATEGORY FILTERS */
        .diane-category-list {
            display: flex;
            align-items: center;
            gap: 26px;
            overflow-x: auto;
            padding: 0 0 15px;
            margin-bottom: 32px;
            border-bottom: 1px solid var(--diane-border);
        }
        .diane-category-list a {
            flex: 0 0 auto;
            padding: 0 0 9px;
            color: #777777;
            font-size: 11px;
            text-decoration: none;
            transition: .2s ease;
        }
        .diane-category-list a:hover,
        .diane-category-list a.active {
            color: #111111;
            border-bottom: 1px solid #111111;
        }
        /* =========================================
           06. PRODUCT GRID AND CARDS
        ========================================= */
        .diane-product-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            column-gap: 22px;
            row-gap: 44px;
        }
        .diane-product-card {
            min-width: 0;
            background: #ffffff;
        }
        .diane-product-visual {
            position: relative;
            width: 100%;
            aspect-ratio: 3 / 4;
            overflow: hidden;
            background: #f3f3f1;
        }
        .diane-product-visual img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
            mix-blend-mode: multiply;
            transition: transform .5s ease;
        }
        .diane-product-card:hover
        .diane-product-visual img {
            transform: scale(1.035);
        }
        .diane-product-badge {
            position: absolute;
            top: 13px;
            left: 13px;
            padding: 7px 10px;
            background: #ffffff;
            color: #111111;
            font-size: 8px;
            font-weight: 700;
            letter-spacing: 1px;
        }
        .diane-product-info {
            padding: 14px 0 0;
        }
        .diane-product-category {
            margin: 0 0 6px;
            color: #888888;
            font-size: 9px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .diane-product-name {
            display: block;
            width: fit-content;
            margin: 0;
            color: #111111;
            font-family: var(--diane-sans);
            font-size: 13px;
            font-weight: 500;
            line-height: 1.5;
            text-decoration: none;
        }
        .diane-product-name:hover {
            text-decoration: underline;
        }
        .diane-price-row {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-top: 8px;
        }
        .diane-product-price {
            color: #111111;
            font-size: 12px;
            font-weight: 600;
        }
        .diane-old-price {
            color: #999999;
            font-size: 11px;
            text-decoration: line-through;
        }
        /* CUSTOMER PRODUCT SEARCH */
        .diane-search-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin: -8px 0 28px;
        }
        .diane-search-form {
            display: flex;
            width: min(100%, 520px);
            min-height: 43px;
            border: 1px solid #dcdcdc;
            background: #ffffff;
        }
        .diane-search-input {
            flex: 1;
            min-width: 0;
            padding: 0 14px;
            border: 0;
            outline: none;
            background: transparent;
            color: #111111;
            font-size: 11px;
        }
        .diane-search-input::placeholder {
            color: #999999;
        }
        .diane-search-input:focus {
            box-shadow: inset 0 0 0 1px #999999;
        }
        .diane-search-button {
            flex: 0 0 auto;
            min-width: 88px;
            padding: 0 16px;
            border: 0;
            background: #111111;
            color: #ffffff;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1px;
            cursor: pointer;
        }
        .diane-search-button:hover {
            background: #333333;
        }
        .diane-search-clear {
            color: #777777;
            font-size: 10px;
            text-decoration: underline;
            white-space: nowrap;
        }
        .diane-product-rating {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 6px;
            min-height: 14px;
            font-size: 10px;
            line-height: 1.4;
        }
        .diane-rating-stars {
            color: #9a7838;
            letter-spacing: 1px;
            font-size: 11px;
        }
        .diane-rating-value {
            color: #333333;
            font-weight: 600;
        }
        .diane-rating-count,
        .diane-rating-empty {
            color: #888888;
        }

        /* SIZE AND ADD TO BAG */
        .diane-card-cart {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            margin-top: 15px;
        }
        .diane-size-select {
            width: 100%;
            min-height: 41px;
            padding: 0 12px;
            background: #ffffff;
            color: #111111;
            border: 1px solid #dddddd;
            border-radius: 0;
            font-size: 10px;
            letter-spacing: .5px;
        }
        .diane-add-button {
            width: 100%;
            min-height: 43px;
            padding: 10px;
            background: #111111;
            color: #ffffff;
            border: 1px solid #111111;
            border-radius: 0;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1px;
            cursor: pointer;
            transition: .2s ease;
        }
        .diane-add-button:hover {
            background: #ffffff;
            color: #111111;
        }
        .diane-add-button:disabled {
            opacity: .45;
            cursor: not-allowed;
        }
        .diane-card-links {
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .diane-card-links a,
        .diane-card-links button {
            padding: 0;
            background: transparent;
            border: 0;
            color: #666666;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 1px;
            text-decoration: none;
            cursor: pointer;
        }
        .diane-card-links a:hover,
        .diane-card-links button:hover {
            color: #111111;
            text-decoration: underline;
        }
        .diane-card-signin {
            display: block;
            margin-top: 15px;
            padding: 14px 8px;
            background: #111111;
            color: #ffffff;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 1px;
            text-align: center;
            text-decoration: none;
        }
        .diane-empty-message {
            grid-column: 1 / -1;
            padding: 65px 20px;
            color: #777777;
            font-size: 13px;
            text-align: center;
            line-height: 1.8;
        }
        /* =========================================
           07. EDITORIAL BANNER
        ========================================= */
        .diane-editorial {
            margin: 0 5% 95px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: #111111;
            color: #ffffff;
        }
        .diane-editorial-copy {
            padding: 70px 12%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
        }
        .diane-editorial-copy .diane-eyebrow {
            color: #aaaaaa;
        }
        .diane-editorial-copy h2 {
            margin: 0;
            font-family: var(--diane-serif);
            font-size: clamp(34px, 4vw, 52px);
            font-weight: normal;
            line-height: 1.15;
        }
        .diane-editorial-copy p {
            max-width: 360px;
            margin: 20px 0 27px;
            color: #cccccc;
            font-size: 12px;
            line-height: 1.9;
        }
        .diane-editorial-button {
            padding: 14px 22px;
            background: #ffffff;
            color: #111111;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1px;
            text-decoration: none;
            transition: .2s ease;
        }
        .diane-editorial-button:hover {
            background: #dddddd;
        }
        .diane-editorial-image {
            min-height: 370px;
            background: #e5e5e5;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .diane-editorial-image img {
            width: 100%;
            height: 100%;
            min-height: 370px;
            object-fit: contain;
            mix-blend-mode: multiply;
        }
        /* =========================================
           08. VIRTUAL TRY-ON
        ========================================= */
        .diane-tryon-section {
            padding: 85px 5% 100px;
            background: #f5f5f3;
        }
        .diane-tryon-heading {
            margin-bottom: 36px;
            text-align: center;
        }
        .diane-tryon-heading h2 {
            margin: 0;
            font-family: var(--diane-serif);
            font-size: clamp(32px, 4vw, 45px);
            font-weight: normal;
        }
        .diane-tryon-heading p:last-child {
            margin: 14px auto 0;
            max-width: 450px;
            color: #777777;
            font-size: 12px;
            line-height: 1.8;
        }
        .tryon-layout {
            max-width: 1100px;
            margin: auto;
            display: grid;
            grid-template-columns: 1.3fr .7fr;
            gap: 32px;
            align-items: start;
        }
        .camera-stage {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            min-height: 0;
            overflow: hidden;
            background: #e9e9e7;
        }
        .camera-stage video {
            position: absolute;
            inset: 0;
            z-index: 1;
            display: block;
            width: 100%;
            height: 100%;
            min-height: 0;
            object-fit: cover;
            object-position: center;
        }
        #clothing-overlay {
            position: absolute;
            z-index: 2;
            width: 50%;
            height: auto;
            left: 50%;
            top: 40%;
            transform: translate(-50%, -50%);
            pointer-events: none;
        }
        #camera-placeholder {
            position: absolute;
            z-index: 3;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 20px;
            color: #777777;
            text-align: center;
        }
        #camera-placeholder[hidden] {
            display: none !important;
        }
        .camera-icon {
            margin-bottom: 15px;
            font-size: 45px;
        }
        .camera-caption {
            color: #777777;
            font-size: 11px;
            line-height: 1.7;
        }
        .tryon-controls {
            padding: 28px;
            background: #ffffff;
            border: 1px solid #e5e5e5;
        }
        .tryon-controls h3 {
            margin: 0 0 25px;
            font-family: var(--diane-serif);
            font-size: 25px;
            font-weight: normal;
        }
        .tryon-controls > label,
        .tryon-controls .control-group label {
            color: #555555;
            font-size: 11px;
        }
        .tryon-controls select {
            width: 100%;
            min-height: 43px;
            margin: 10px 0 20px;
            padding: 8px;
            background: #ffffff;
            border: 1px solid #dddddd;
        }
        .tryon-controls > button {
            width: 100%;
            margin-bottom: 12px;
        }
        .control-group {
            margin: 20px 0;
        }
        .control-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .control-heading output {
            color: #777777;
            font-size: 11px;
        }
        .control-group input[type="range"] {
            width: 100%;
            margin-top: 12px;
        }
        #camera-status {
            color: #777777;
            font-size: 11px;
            line-height: 1.6;
        }
        #start-camera[hidden],
        #clothing-overlay[hidden],
        #camera-placeholder[hidden] {
            display: none !important;
        }
        #camera-placeholder {
            z-index: 3;
            padding: clamp(18px, 4vw, 42px);
            background: rgba(255, 255, 255, 0.86);
            color: #222222;
            text-align: center;
        }
        #camera-placeholder h3 {
            margin: 12px 0 8px;
            font-family: var(--diane-serif);
            font-size: clamp(18px, 2.2vw, 25px);
            font-weight: normal;
            letter-spacing: 0.3px;
        }
        #camera-placeholder p {
            max-width: 420px;
            margin: 6px auto;
            color: #555555;
            font-size: 12px;
            line-height: 1.7;
        }
        /* =========================================
           09. FOOTER
        ========================================= */
        .diane-footer {
            padding: 65px 5% 25px;
            background: #111111;
            color: #ffffff;
        }
        .diane-footer-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 35px;
            padding-bottom: 45px;
            border-bottom: 1px solid #3a3a3a;
        }
        .diane-footer-brand {
            text-decoration: none;
            color: #ffffff;
        }
        .diane-footer-brand strong {
            display: block;
            font-family: var(--diane-serif);
            font-size: 31px;
            font-weight: normal;
            letter-spacing: 2px;
        }
        .diane-footer-brand span {
            display: block;
            margin-top: 7px;
            font-size: 8px;
            letter-spacing: 4px;
        }
        .diane-footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: 22px;
        }
        .diane-footer-links a {
            color: #dddddd;
            font-size: 10px;
            letter-spacing: .8px;
            text-decoration: none;
        }
        .diane-footer-bottom {
            padding-top: 22px;
            display: flex;
            justify-content: space-between;
            gap: 15px;
            color: #aaaaaa;
            font-size: 10px;
        }
        /* =========================================
           10. RESPONSIVE DESIGN
        ========================================= */
        @media (max-width: 1100px) {
            .diane-product-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .diane-header {
                padding: 12px 3%;
            }
            .diane-header-right {
                gap: 18px;
            }
            .diane-navigation {
                gap: 14px;
            }
            .diane-account {
                gap: 10px;
            }
        }
        @media (max-width: 800px) {
            .diane-header {
                flex-wrap: wrap;
                justify-content: center;
            }
            .diane-header-right {
                width: 100%;
                margin: 0;
                justify-content: center;
                flex-wrap: wrap;
            }
            .diane-hero {
                grid-template-columns: 1fr;
            }
            .diane-hero-copy {
                padding: 65px 8%;
            }
            .diane-hero-image {
                min-height: 430px;
            }
            .diane-editorial {
                grid-template-columns: 1fr;
            }
            .diane-editorial-copy {
                padding: 55px 9%;
            }
            .tryon-layout {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 600px) {
            .diane-header {
                padding: 18px 4%;
                gap: 16px;
            }
            .diane-brand-name {
                font-size: 30px;
            }
            .diane-header-right {
                flex-direction: column;
                gap: 15px;
            }
            .diane-navigation {
                justify-content: center;
                flex-wrap: wrap;
                gap: 15px;
            }
            .diane-account {
                justify-content: center;
                flex-wrap: wrap;
                gap: 12px;
            }
            .diane-hero {
                min-height: auto;
            }
            .diane-hero-copy {
                padding: 62px 7%;
            }
            .diane-hero-image {
                min-height: 340px;
            }
            .diane-intro {
                padding: 60px 22px 45px;
            }
            .diane-collection {
                padding: 20px 4% 65px;
            }
            .diane-section-top {
                align-items: flex-start;
                flex-direction: column;
            }
            .diane-category-list {
                gap: 19px;
            }
            .diane-search-row {
                align-items: stretch;
                flex-direction: column;
                gap: 10px;
                margin-top: -5px;
            }
            .diane-search-form {
                width: 100%;
            }
            .diane-search-clear {
                align-self: flex-start;
            }
            .diane-product-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                column-gap: 12px;
                row-gap: 32px;
            }
            .diane-product-name {
                font-size: 12px;
            }
            .diane-product-info {
                padding-top: 10px;
            }
            .diane-product-badge {
                top: 8px;
                left: 8px;
                padding: 6px 8px;
            }
            .diane-editorial {
                margin: 0 4% 65px;
            }
            .diane-editorial-copy {
                padding: 45px 8%;
            }
            .diane-editorial-image {
                min-height: 290px;
            }
            .diane-editorial-image img {
                min-height: 290px;
            }
            .diane-tryon-section {
                padding: 60px 4%;
            }
            .camera-stage,
            .camera-stage video {
                min-height: 0;
            }
            .tryon-controls {
                padding: 22px;
            }
            .diane-footer-top {
                flex-direction: column;
            }
            .diane-footer-bottom {
                flex-direction: column;
            }
        }

    
/* =========================================
   DIANE VIRTUAL TRY-ON CAMERA SIZE UPDATE
========================================= */

/* Slightly larger camera on desktop */
@media (min-width: 801px) {
    .tryon-layout {
        max-width: 1180px;
        grid-template-columns: minmax(0, 1.45fr) minmax(300px, 0.75fr);
        gap: 28px;
    }

    .camera-stage {
        width: 100%;
        aspect-ratio: 16 / 9;
        min-height: 0;
    }

    .camera-stage video {
        width: 100%;
        height: 100%;
        min-height: 0;
        object-fit: cover;
    }
}

/* Keep the layout responsive on mobile */
@media (max-width: 800px) {
    .tryon-layout {
        grid-template-columns: 1fr;
    }

    .camera-stage {
        width: 100%;
        aspect-ratio: 16 / 9;
        min-height: 0;
    }

    .camera-stage video {
        width: 100%;
        height: 100%;
        min-height: 0;
        object-fit: cover;
    }
}

  </style>

</head>
<body>
<!-- =========================================
     HEADER
========================================= -->
<header class="diane-header">
    <a class="diane-brand" href="index.php">
        <span class="diane-brand-name">
            DIANE
        </span>
        <span class="diane-brand-subtitle">
            CLOTHING SHOP
        </span>
    </a>
    <div class="diane-header-right">
        <nav class="diane-navigation" aria-label="Main navigation">
            <a href="index.php">HOME</a>
            <a href="#collection">COLLECTION</a>
            <a href="#try-on">VIRTUAL TRY-ON</a>
        </nav>
        <div class="diane-account">
            <?php if ($isCustomerLoggedIn): ?>
                <a
                    class="diane-bag-link"
                    href="cart.php"
                >
                    MY BAG (<?= (int)$cartCount ?>)
                </a>
                <!-- NEW: CUSTOMER ORDER HISTORY LINK -->
                <a href="my_orders.php">
                    MY ORDERS
                </a>
                <a href="logout.php">
                    LOG OUT
                </a>
                <span class="diane-greeting">
                    Hi, <?= htmlspecialchars(
                        $customerName,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </span>
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
            <?php else: ?>
                <a href="login.php">
                    SIGN IN
                </a>
                <a href="signup.php">
                    CREATE ACCOUNT
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
<!-- =========================================
     HERO SECTION
========================================= -->
<section class="diane-hero">
    <div class="diane-hero-copy">
        <p class="diane-eyebrow">
            THE DIANE COLLECTION
        </p>
        <h1 class="diane-hero-title">
            A quieter kind of statement.
        </h1>
        <p class="diane-hero-description">
            Discover timeless pieces, modern essentials,
            and styles that make everyday dressing feel special.
        </p>
        <a
            href="#collection"
            class="diane-primary-button"
        >
            EXPLORE COLLECTION
        </a>
        <a
            href="#collection"
            class="diane-hero-secondary"
        >
            DISCOVER YOUR STYLE
        </a>
    </div>
    <div class="diane-hero-image">
        <?php if (
            is_array($heroProduct) &&
            !empty($heroProduct['image'])
        ): ?>
            <img
                src="<?= htmlspecialchars(
                    $heroProduct['image'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                alt="Diane Clothing Shop featured collection"
            >
        <?php else: ?>
            <span class="diane-hero-image-placeholder">
                DIANE / NEW COLLECTION
            </span>
        <?php endif; ?>
        <span class="diane-hero-image-label">
            THE NEW EDIT
        </span>
    </div>
</section>
<!-- =========================================
     EDITORIAL INTRO
========================================= -->
<section class="diane-intro">
    <p class="diane-eyebrow">
        SIMPLE. TIMELESS. YOU.
    </p>
    <h2>
        Your style, your statement.
    </h2>
    <p>
        Explore thoughtfully selected styles for every day.
        Find the pieces that make you feel confident and comfortable.
    </p>
    <div class="diane-intro-line"></div>
</section>
<main>
    <!-- =========================================
         COLLECTION
    ========================================= -->
    <section
        id="collection"
        class="diane-collection"
    >
        <div class="diane-section-top">
            <div>
                <p class="diane-section-label">
                    DISCOVER YOUR NEXT FAVORITE LOOK
                </p>
                <h2 class="diane-section-title">
                    The Collection
                </h2>
            </div>
            <span class="diane-product-count">
                <?= count($visibleProducts) ?>
                <?= $searchQuery !== '' ? 'RESULTS' : (
                    count($visibleProducts) === 1 ? 'PRODUCT' : 'PRODUCTS'
                ) ?>
            </span>
        </div>
        <!-- CATEGORY FILTERS -->
        <div class="diane-category-list">
            <?php foreach ($categories as $category): ?>
                <a
                    class="<?= $selectedCategory === $category ? 'active' : '' ?>"
                    href="?category=<?= urlencode($category) ?><?= $searchQuery !== '' ? '&amp;search=' . urlencode($searchQuery) : '' ?>#collection"
                    <?= $selectedCategory === $category
                        ? 'aria-current="page"'
                        : ''
                    ?>
                >
                    <?= htmlspecialchars(
                        $category,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <!-- CUSTOMER SEARCH -->
        <div class="diane-search-row">
            <form class="diane-search-form" method="GET" action="#collection" role="search">
                <input
                    type="hidden"
                    name="category"
                    value="<?= htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8') ?>"
                >
                <input
                    class="diane-search-input"
                    type="search"
                    name="search"
                    value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Search dresses, tops, trousers..."
                    aria-label="Search products"
                    maxlength="100"
                >
                <button class="diane-search-button" type="submit">SEARCH</button>
            </form>
            <?php if ($searchQuery !== ''): ?>
                <a class="diane-search-clear" href="?category=<?= urlencode($selectedCategory) ?>#collection">
                    Clear search
                </a>
            <?php endif; ?>
        </div>
        <!-- PRODUCT GRID -->
        <div class="diane-product-grid">
            <?php if ($productLoadError): ?>
                <div class="diane-empty-message">
                    We couldn't load the collection right now.
                    <br>
                    Please refresh the page and try again.
                </div>
            <?php elseif (empty($visibleProducts)): ?>
                <div class="diane-empty-message">
                    <?php if ($searchQuery !== ''): ?>
                        No products found for &ldquo;<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>&rdquo;.
                        <br>
                        Try another product name or category.
                    <?php else: ?>
                        There are no products in this category yet.
                        <br>
                        Check back soon for new styles.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($visibleProducts as $product): ?>
                    <article class="diane-product-card">
                        <!-- PRODUCT IMAGE -->
                        <div class="diane-product-visual">
                            <a
                                href="product.php?id=<?= rawurlencode(
                                    $product['id']
                                ) ?>"
                                aria-label="View <?= htmlspecialchars(
                                    $product['name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >
                                <?php if (!empty($product['image'])): ?>
                                    <img
                                        src="<?= htmlspecialchars(
                                            $product['image'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        alt="<?= htmlspecialchars(
                                            $product['name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        loading="lazy"
                                    >
                                <?php endif; ?>
                            </a>
                            <?php if (!empty($product['is_new'])): ?>
                                <span class="diane-product-badge">
                                    NEW
                                </span>
                            <?php elseif (!empty($product['is_featured'])): ?>
                                <span class="diane-product-badge">
                                    FEATURED
                                </span>
                            <?php endif; ?>
                        </div>
                        <!-- PRODUCT INFORMATION -->
                        <div class="diane-product-info">
                            <p class="diane-product-category">
                                <?= htmlspecialchars(
                                    $product['category'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>
                            <a
                                class="diane-product-name"
                                href="product.php?id=<?= rawurlencode(
                                    $product['id']
                                ) ?>"
                            >
                                <?= htmlspecialchars(
                                    $product['name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </a>
                            <!-- CUSTOMER RATING -->
                            <div
                                class="diane-product-rating"
                                aria-label="<?= $product['review_count'] > 0
                                    ? number_format($product['rating'], 1) . ' out of 5 stars, ' . $product['review_count'] . ' reviews'
                                    : 'No customer reviews yet'
                                ?>"
                            >
                                <?php if ($product['review_count'] > 0): ?>
                                    <?php $filledStars = (int)round($product['rating']); ?>
                                    <span class="diane-rating-stars" aria-hidden="true"><?= str_repeat('★', $filledStars) . str_repeat('☆', 5 - $filledStars) ?></span>
                                    <span class="diane-rating-value"><?= number_format($product['rating'], 1) ?></span>
                                    <span class="diane-rating-count">(<?= $product['review_count'] ?> <?= $product['review_count'] === 1 ? 'review' : 'reviews' ?>)</span>
                                <?php else: ?>
                                    <span class="diane-rating-empty">No reviews yet</span>
                                <?php endif; ?>
                            </div>
                            <!-- PRICE -->
                            <div class="diane-price-row">
                                <span class="diane-product-price">
                                    ₱<?= number_format(
                                        $product['price'],
                                        2
                                    ) ?>
                                </span>
                                <?php if (
                                    $product['original_price'] !== null &&
                                    $product['original_price'] > $product['price']
                                ): ?>
                                    <span class="diane-old-price">
                                        ₱<?= number_format(
                                            $product['original_price'],
                                            2
                                        ) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <!-- ADD TO BAG -->
                            <?php if (
                                $isCustomerLoggedIn &&
                                !empty($product['sizes']) &&
                                $product['stock'] > 0
                            ): ?>
                                <form
                                    class="diane-card-cart"
                                    method="POST"
                                    action="add_to_cart.php"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars(
                                            $customerCsrf,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="product_id"
                                        value="<?= htmlspecialchars(
                                            $product['id'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="quantity"
                                        value="1"
                                    >
                                    <select
                                        class="diane-size-select"
                                        name="size"
                                        required
                                        aria-label="Choose size for <?= htmlspecialchars(
                                            $product['name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >
                                        <option value="">
                                            SELECT SIZE
                                        </option>
                                        <?php foreach (
                                            $product['sizes'] as $size
                                        ): ?>
                                            <option value="<?= htmlspecialchars(
                                                (string)$size,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>">
                                                <?= htmlspecialchars(
                                                    (string)$size,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button
                                        type="submit"
                                        class="diane-add-button"
                                    >
                                        ADD TO BAG
                                    </button>
                                </form>
                            <?php elseif (!$isCustomerLoggedIn): ?>
                                <a
                                    class="diane-card-signin"
                                    href="login.php"
                                >
                                    SIGN IN TO ADD TO BAG
                                </a>
                            <?php else: ?>
                                <button
                                    type="button"
                                    class="diane-add-button"
                                    disabled
                                >
                                    OUT OF STOCK
                                </button>
                            <?php endif; ?>
                            <!-- DETAILS AND TRY-ON -->
                            <div class="diane-card-links">
                                <a
                                    href="product.php?id=<?= rawurlencode(
                                        $product['id']
                                    ) ?>"
                                >
                                    VIEW DETAILS
                                </a>
                                <button
                                    type="button"
                                    class="try-button"
                                    data-product-id="<?= htmlspecialchars(
                                        $product['id'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    onclick="openTryOn(this)"
                                >
                                    TRY ON
                                </button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <!-- =========================================
         EDITORIAL BANNER
    ========================================= -->
    <section class="diane-editorial">
        <div class="diane-editorial-copy">
            <p class="diane-eyebrow">
                THE DIANE STYLE
            </p>
            <h2>
                Less, but better.
            </h2>
            <p>
                Clothing should feel effortless.
                Discover pieces that bring simplicity,
                confidence, and personality to your everyday wardrobe.
            </p>
            <a
                class="diane-editorial-button"
                href="#collection"
            >
                SHOP THE COLLECTION
            </a>
        </div>
        <div class="diane-editorial-image">
            <?php if (
                is_array($heroProduct) &&
                !empty($heroProduct['image'])
            ): ?>
                <img
                    src="<?= htmlspecialchars(
                        $heroProduct['image'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    alt="Diane clothing editorial"
                    loading="lazy"
                >
            <?php else: ?>
                <span class="diane-hero-image-placeholder">
                    DIANE
                </span>
            <?php endif; ?>
        </div>
    </section>
    <!-- =========================================
         VIRTUAL TRY-ON
    ========================================= -->
    <section
        id="try-on"
        class="diane-tryon-section"
    >
        <div class="diane-tryon-heading">
            <p class="diane-eyebrow">
                YOUR VIRTUAL FITTING ROOM
            </p>
            <h2>
                Try it before you buy it.
            </h2>
            <p>
                Explore your favorite pieces using the
                Virtual Try-On camera.
            </p>
        </div>
        <div class="tryon-layout">
            <!-- CAMERA PREVIEW -->
            <div class="camera-column">
                <div class="camera-stage">
                    <video
                        id="camera"
                        autoplay
                        playsinline
                        muted
                    ></video>
                    <img
                        id="clothing-overlay"
                        alt="Selected clothing overlay"
                        hidden
                    >
                    <div id="camera-placeholder">
                        <span class="camera-icon">◎</span>
                        <h3>DIANE VIRTUAL FITTING ROOM</h3>
                        <p>
                            Select a piece from the clothing menu, then position
                            yourself in the center of the camera frame.
                        </p>
                        <p>
                            Click OPEN CAMERA to start your virtual fitting.
                        </p>
                    </div>
                </div>
                <p class="camera-caption">
                    Position yourself in the center
                    of the camera frame.
                </p>
            </div>
            <!-- CAMERA CONTROLS -->
            <aside class="tryon-controls">
                <h3>
                    Adjust Your Look
                </h3>
                <label for="product-select">
                    Select clothing
                </label>
                <select id="product-select">
                    <?php foreach ($products as $product): ?>
                        <option
                            value="<?= htmlspecialchars(
                                $product['id'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            data-image="<?= htmlspecialchars(
                                $product['image'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            data-overlay="<?= htmlspecialchars(
                                $product['overlay'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >
                            <?= htmlspecialchars(
                                $product['name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- ONE TOGGLE BUTTON ONLY -->
                <button
                    id="start-camera"
                    class="diane-primary-button"
                    type="button"
                    aria-label="Open camera"
                >
                    OPEN CAMERA
                </button>

                <!-- CLOTHING SIZE -->
                <div class="control-group">
                    <div class="control-heading">
                        <label for="size-control">
                            Clothing size
                        </label>
                        <output id="size-value">
                            50%
                        </output>
                    </div>
                    <input
                        id="size-control"
                        type="range"
                        min="20"
                        max="100"
                        value="50"
                    >
                </div>
                <!-- HORIZONTAL POSITION -->
                <div class="control-group">
                    <div class="control-heading">
                        <label for="horizontal-control">
                            Horizontal position
                        </label>
                        <output id="horizontal-value">
                            50%
                        </output>
                    </div>
                    <input
                        id="horizontal-control"
                        type="range"
                        min="0"
                        max="100"
                        value="50"
                    >
                </div>
                <!-- VERTICAL POSITION -->
                <div class="control-group">
                    <div class="control-heading">
                        <label for="vertical-control">
                            Vertical position
                        </label>
                        <output id="vertical-value">
                            40%
                        </output>
                    </div>
                    <input
                        id="vertical-control"
                        type="range"
                        min="0"
                        max="100"
                        value="40"
                    >
                </div>
                <!-- TRANSPARENCY -->
                <div class="control-group">
                    <div class="control-heading">
                        <label for="opacity-control">
                            Transparency
                        </label>
                        <output id="opacity-value">
                            90%
                        </output>
                    </div>
                    <input
                        id="opacity-control"
                        type="range"
                        min="10"
                        max="100"
                        value="90"
                    >
                </div>
                <p
                    id="camera-status"
                    role="status"
                    aria-live="polite"
                >
                    Camera is off.
                </p>
            </aside>
        </div>
    </section>
</main>
<!-- =========================================
     FOOTER
========================================= -->
<footer class="diane-footer">
    <div class="diane-footer-top">
        <a href="index.php" class="diane-footer-brand">
            <strong>DIANE</strong>
            <span>CLOTHING SHOP</span>
        </a>
        <div class="diane-footer-links">
            <a href="index.php">HOME</a>
            <a href="index.php#collection">COLLECTION</a>
            <a href="index.php#try-on">VIRTUAL TRY-ON</a>
            <?php if ($isCustomerLoggedIn): ?>
                <a href="cart.php">MY BAG</a>
                <a href="my_orders.php">MY ORDERS</a>
                <a href="logout.php">LOG OUT</a>
            <?php else: ?>
                <a href="login.php">SIGN IN</a>
                <a href="signup.php">CREATE ACCOUNT</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="diane-footer-bottom">
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
