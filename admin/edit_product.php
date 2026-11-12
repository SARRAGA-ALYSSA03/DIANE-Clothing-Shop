
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

// Admin access was verified during login. Keep the established admin session.

// 3. Get product ID
$productId = trim($_GET['id'] ?? $_POST['id'] ?? '');

if ($productId === '') {
    header('Location: products.php');
    exit;
}

// 4. Create CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 5. Retrieve product
$productResult = supabaseRequest(
    '/rest/v1/products?id=eq.' . rawurlencode($productId) .
    '&select=id,name,price,original_price,category,gender,image,sizes,colors,material,description,stock,is_new,is_featured',
    'GET',
    null,
    $_SESSION['access_token']
);

$product = $productResult['data'][0] ?? null;

$error = '';
$message = '';
$messageType = 'success';

// 6. Retrieve categories
$categoryResult = supabaseRequest(
    '/rest/v1/categories?select=name&order=name.asc',
    'GET',
    null,
    $_SESSION['access_token']
);

$categories = [];

if ($categoryResult['success'] && is_array($categoryResult['data'])) {
    foreach ($categoryResult['data'] as $category) {
        if (!empty($category['name'])) {
            $categories[] = $category['name'];
        }
    }
}

if (!$productResult['success']) {
    $error = 'Unable to retrieve product information. Please try again.';
} elseif (!$product) {
    $error = 'Product not found.';
}

// 7. Prepare form values
$values = [
    'name' => $product['name'] ?? '',
    'price' => $product['price'] ?? '',
    'original_price' => $product['original_price'] ?? '',
    'category' => $product['category'] ?? '',
    'gender' => $product['gender'] ?? 'women',
    'image' => $product['image'] ?? '',
    'sizes' => implode(', ', $product['sizes'] ?? []),
    'colors' => implode(', ', $product['colors'] ?? []),
    'material' => $product['material'] ?? '',
    'description' => $product['description'] ?? '',
    'stock' => $product['stock'] ?? 0,
    'change_reason' => ''
];

// 8. Process update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $product) {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        $submittedToken === '' ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid request. Please refresh the page and try again.');
    }

    foreach ($values as $key => $default) {
        $values[$key] = trim((string)($_POST[$key] ?? ''));
    }

    $genderOptions = ['women', 'men', 'unisex'];

    $price = filter_var($values['price'], FILTER_VALIDATE_FLOAT);
    $stock = filter_var($values['stock'], FILTER_VALIDATE_INT);

    $originalPrice = null;

    if ($values['original_price'] !== '') {
        $originalPrice = filter_var(
            $values['original_price'],
            FILTER_VALIDATE_FLOAT
        );
    }

    $sizes = array_values(array_filter(
        array_map('trim', explode(',', $values['sizes'])),
        fn($value) => $value !== ''
    ));

    $colors = array_values(array_filter(
        array_map('trim', explode(',', $values['colors'])),
        fn($value) => $value !== ''
    ));

    // Validate required information
    if (
        $values['name'] === '' ||
        $values['category'] === '' ||
        $values['image'] === '' ||
        !filter_var($values['image'], FILTER_VALIDATE_URL) ||
        $values['price'] === '' ||
        $price === false ||
        $price <= 0 ||
        $stock === false ||
        $stock < 0 ||
        strlen($values['change_reason']) > 250 ||
        empty($sizes)
    ) {
        $error = 'Please complete all required fields with valid information.';

    } elseif (!in_array($values['category'], $categories, true)) {

        $error = 'Please select a valid product category.';

    } elseif (!in_array($values['gender'], $genderOptions, true)) {

        $error = 'Please select a valid gender.';

    } elseif (
        $values['original_price'] !== '' &&
        ($originalPrice === false || $originalPrice <= 0)
    ) {

        $error = 'Please enter a valid original price.';

    } else {

        // Prepare updated product information
        $updatedProduct = [
            'name' => $values['name'],
            'price' => $price,
            'original_price' => $originalPrice,
            'category' => $values['category'],
            'gender' => $values['gender'],
            'image' => $values['image'],
            'images' => [$values['image']],
            'sizes' => $sizes,
            'colors' => $colors,
            'material' => $values['material'],
            'description' => $values['description'],
            'stock' => $stock,
            'is_new' => isset($_POST['is_new']),
            'is_featured' => isset($_POST['is_featured'])
        ];

        // Update existing product, keeping its ID
        $updateResult = supabaseRequest(
            '/rest/v1/products?id=eq.' . rawurlencode($productId),
            'PATCH',
            $updatedProduct,
            $_SESSION['access_token']
        );

        if (!empty($updateResult['success'])) {

            // Record a stock adjustment only when the quantity actually changed.
            $previousStock = (int)($product['stock'] ?? 0);
            $stockChanged = ($previousStock !== $stock);

            // Keep the current page data aligned with the successful product update.
            $product = array_merge($product, $updatedProduct);

            if ($stockChanged) {

                $adminId = trim((string)($_SESSION['admin_id'] ?? ''));

                if ($adminId === '') {

                    $messageType = 'warning';
                    $message = 'Product details were saved, but the inventory history could not be recorded because the administrator session ID is missing.';

                } else {

                    $inventoryLog = [
                        'product_id' => $productId,
                        'admin_id' => $adminId,
                        'previous_stock' => $previousStock,
                        'new_stock' => $stock,
                        'change_reason' => $values['change_reason'] !== ''
                            ? $values['change_reason']
                            : 'Stock updated from Edit Product'
                    ];

                    $logResult = supabaseRequest(
                        '/rest/v1/inventory_logs',
                        'POST',
                        $inventoryLog,
                        $_SESSION['access_token']
                    );

                    if (empty($logResult['success'])) {

                        // Product update already succeeded; report partial completion honestly.
                        $messageType = 'warning';
                        $message = 'Product details were saved, but the inventory history could not be recorded. Please check the Admin inventory_logs permission before making another stock adjustment.';

                    } else {

                        header('Location: products.php?updated=1&stock_logged=1');
                        exit;
                    }
                }

            } else {

                header('Location: products.php?updated=1');
                exit;
            }

        } else {

            $error = 'Unable to update product. Please check your database permissions and information.';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Product | Diane Clothing Shop</title>

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
            max-width: 1100px;
            margin: auto;
            padding: 38px 42px 55px;
        }

        .eyebrow {
            color: #777;
            font-size: 10px;
            letter-spacing: 2.5px;
            margin-bottom: 12px;
            font-weight: bold;
        }

        h1 {
            margin: 0 0 9px;
            font-size: 34px;
            font-weight: 500;
            letter-spacing: -1px;
        }

        .subtitle {
            color: #777;
            font-size: 13px;
            margin: 0 0 30px;
        }

        .form-panel {
            background: white;
            border: 1px solid #e5e5e5;
        }

        .form-heading {
            padding: 24px 28px;
            border-bottom: 1px solid #eee;
        }

        .form-heading h2 {
            margin: 0 0 7px;
            font-size: 17px;
            font-weight: 500;
        }

        .form-heading p {
            margin: 0;
            font-size: 12px;
            color: #777;
        }

        .form-content {
            padding: 28px;
        }

        .form-section {
            margin-bottom: 30px;
        }

        .form-section h3 {
            margin: 0 0 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eee;
            font-size: 12px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            font-weight: bold;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 17px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: bold;
            color: #333;
        }

        .required {
            color: #a12d2d;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #dcdcdc;
            background: white;
            color: #222;
            border-radius: 0;
            font-family: inherit;
            font-size: 13px;
            outline: none;
            transition: border-color .2s;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #111;
        }

        textarea {
            min-height: 115px;
            resize: vertical;
            line-height: 1.6;
        }

        .hint {
            display: block;
            margin-top: 7px;
            color: #888;
            font-size: 11px;
        }

        .product-id {
            padding: 12px 14px;
            background: #f5f5f5;
            border: 1px solid #eee;
            font-family: monospace;
            font-size: 12px;
        }

        .image-preview {
            width: 115px;
            height: 140px;
            margin-top: 13px;
            object-fit: cover;
            border: 1px solid #eee;
            display: block;
        }

        .checkbox-row {
            display: flex;
            flex-wrap: wrap;
            gap: 25px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 12px;
            font-weight: normal;
            cursor: pointer;
        }

        .checkbox-label input {
            width: 16px;
            height: 16px;
            accent-color: #111;
        }

        .notification {
            padding: 15px 18px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .error {
            background: #f9e8e8;
            color: #982b2b;
            border: 1px solid #edcece;
        }

        .success {
            background: #eaf5ec;
            color: #245b31;
            border: 1px solid #cfe6d3;
        }

        .warning {
            background: #fff6df;
            color: #76530b;
            border: 1px solid #f0dfa9;
        }

        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }

        .submit-button {
            padding: 14px 24px;
            background: #111;
            color: white;
            border: 1px solid #111;
            cursor: pointer;
            font-size: 11px;
            letter-spacing: 1.3px;
        }

        .submit-button:hover {
            background: #444;
        }

        .cancel-button {
            padding: 14px 20px;
            color: #333;
            background: white;
            border: 1px solid #ccc;
            text-decoration: none;
            font-size: 11px;
            letter-spacing: 1px;
        }

        .back-link {
            display: inline-block;
            margin-top: 25px;
            color: #555;
            text-decoration: none;
            font-size: 11px;
            letter-spacing: 1px;
        }

        @media (max-width: 650px) {
            .page-container {
                padding: 27px 16px 40px;
            }

            h1 {
                font-size: 28px;
            }

            .form-content {
                padding: 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .form-group.full {
                grid-column: auto;
            }

            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .submit-button,
            .cancel-button {
                text-align: center;
            }
        }
    </style>

</head>

<body>

<?php renderAdminNavigation(); ?>

<main class="page-container">

    <div class="eyebrow">
        DIANE CLOTHING SHOP / INVENTORY
    </div>

    <h1>Edit Product</h1>

    <p class="subtitle">
        Update the information of an existing clothing product.
    </p>

    <?php if ($error !== ''): ?>

        <div class="notification error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>

    <?php if ($message !== ''): ?>

        <div class="notification <?= $messageType === 'warning' ? 'warning' : 'success' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php endif; ?>

    <?php if ($product): ?>

        <section class="form-panel">

            <div class="form-heading">

                <h2>Product Information</h2>

                <p>
                    Update the fields below and save your changes.
                </p>

            </div>

            <div class="form-content">

                <form method="POST">

                    <input
                        type="hidden"
                        name="id"
                        value="<?= htmlspecialchars($productId) ?>"
                    >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                    >

                    <!-- Basic information -->
                    <section class="form-section">

                        <h3>01 / Basic Information</h3>

                        <div class="form-group">

                            <label>Product ID</label>

                            <div class="product-id">
                                <?= htmlspecialchars($productId) ?>
                            </div>

                            <small class="hint">
                                Product ID cannot be changed.
                            </small>

                        </div>

                        <div class="form-grid">

                            <div class="form-group full">

                                <label>
                                    Product Name <span class="required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="name"
                                    value="<?= htmlspecialchars($values['name']) ?>"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>
                                    Category <span class="required">*</span>
                                </label>

                                <select name="category" required>

                                    <option value="">Select category</option>

                                    <?php foreach ($categories as $category): ?>

                                        <option
                                            value="<?= htmlspecialchars($category) ?>"
                                            <?= $values['category'] === $category ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($category) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="form-group">

                                <label>
                                    Gender <span class="required">*</span>
                                </label>

                                <select name="gender" required>

                                    <option value="women" <?= $values['gender'] === 'women' ? 'selected' : '' ?>>
                                        Women
                                    </option>

                                    <option value="men" <?= $values['gender'] === 'men' ? 'selected' : '' ?>>
                                        Men
                                    </option>

                                    <option value="unisex" <?= $values['gender'] === 'unisex' ? 'selected' : '' ?>>
                                        Unisex
                                    </option>

                                </select>

                            </div>

                            <div class="form-group">

                                <label>
                                    Price (₱) <span class="required">*</span>
                                </label>

                                <input
                                    type="number"
                                    name="price"
                                    min="0.01"
                                    step="0.01"
                                    value="<?= htmlspecialchars((string)$values['price']) ?>"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>Original Price (₱)</label>

                                <input
                                    type="number"
                                    name="original_price"
                                    min="0.01"
                                    step="0.01"
                                    value="<?= htmlspecialchars((string)$values['original_price']) ?>"
                                    placeholder="Optional"
                                >

                            </div>

                        </div>

                    </section>

                    <!-- Product image -->
                    <section class="form-section">

                        <h3>02 / Product Image</h3>

                        <div class="form-group">

                            <label>
                                Product Image URL <span class="required">*</span>
                            </label>

                            <input
                                type="url"
                                name="image"
                                id="imageUrl"
                                value="<?= htmlspecialchars($values['image']) ?>"
                                required
                            >

                            <small class="hint">
                                Enter the public image URL from Supabase Storage.
                            </small>

                            <img
                                id="imagePreview"
                                class="image-preview"
                                src="<?= htmlspecialchars($values['image']) ?>"
                                alt="Current product image"
                            >

                        </div>

                    </section>

                    <!-- Product variants -->
                    <section class="form-section">

                        <h3>03 / Product Variants</h3>

                        <div class="form-grid">

                            <div class="form-group">

                                <label>
                                    Available Sizes <span class="required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="sizes"
                                    value="<?= htmlspecialchars($values['sizes']) ?>"
                                    required
                                >

                                <small class="hint">
                                    Separate sizes with commas.
                                </small>

                            </div>

                            <div class="form-group">

                                <label>Available Colors</label>

                                <input
                                    type="text"
                                    name="colors"
                                    value="<?= htmlspecialchars($values['colors']) ?>"
                                    placeholder="Black, White, Beige"
                                >

                                <small class="hint">
                                    Separate colors with commas.
                                </small>

                            </div>

                            <div class="form-group">

                                <label>Material</label>

                                <input
                                    type="text"
                                    name="material"
                                    value="<?= htmlspecialchars($values['material']) ?>"
                                >

                            </div>

                            <div class="form-group">

                                <label>
                                    Stock Quantity <span class="required">*</span>
                                </label>

                                <input
                                    type="number"
                                    name="stock"
                                    min="0"
                                    step="1"
                                    value="<?= htmlspecialchars((string)$values['stock']) ?>"
                                    required
                                >

                            </div>

                            <div class="form-group full">

                                <label for="change_reason">Stock Change Reason (Optional)</label>

                                <input
                                    type="text"
                                    id="change_reason"
                                    name="change_reason"
                                    maxlength="250"
                                    value="<?= htmlspecialchars($values['change_reason'], ENT_QUOTES, 'UTF-8') ?>"
                                    placeholder="Example: Restocked new batch or corrected stock count"
                                >

                                <small class="hint">
                                    Saved to the inventory history only when the stock quantity changes.
                                </small>

                            </div>

                        </div>

                    </section>

                    <!-- Description and display -->
                    <section class="form-section">

                        <h3>04 / Description & Display</h3>

                        <div class="form-group">

                            <label>Product Description</label>

                            <textarea name="description"><?= htmlspecialchars($values['description']) ?></textarea>

                        </div>

                        <div class="checkbox-row">

                            <label class="checkbox-label">

                                <input
                                    type="checkbox"
                                    name="is_new"
                                    <?= !empty($product['is_new']) ? 'checked' : '' ?>
                                    <?= isset($_POST['is_new']) && $_SERVER['REQUEST_METHOD'] === 'POST' ? 'checked' : '' ?>
                                >

                                Mark as New Arrival

                            </label>

                            <label class="checkbox-label">

                                <input
                                    type="checkbox"
                                    name="is_featured"
                                    <?= !empty($product['is_featured']) ? 'checked' : '' ?>
                                    <?= isset($_POST['is_featured']) && $_SERVER['REQUEST_METHOD'] === 'POST' ? 'checked' : '' ?>
                                >

                                Feature on Homepage

                            </label>

                        </div>

                        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== ''): ?>
                            <small class="hint">
                                Check the selected options before saving again.
                            </small>
                        <?php endif; ?>

                    </section>

                    <!-- Actions -->
                    <div class="form-actions">

                        <button type="submit" class="submit-button">
                            SAVE CHANGES
                        </button>

                        <a href="products.php" class="cancel-button">
                            CANCEL
                        </a>

                    </div>

                </form>

            </div>

        </section>

    <?php else: ?>

        <div class="notification error">
            Product information could not be displayed.
            Return to the Products page and select an existing product.
        </div>

        <a href="products.php" class="cancel-button">
            BACK TO PRODUCTS
        </a>

    <?php endif; ?>

    <a href="products.php" class="back-link">
        ← BACK TO PRODUCTS
    </a>

</main>

<script>
const imageInput = document.getElementById('imageUrl');
const imagePreview = document.getElementById('imagePreview');

if (imageInput && imagePreview) {

    imageInput.addEventListener('input', function () {
        const url = imageInput.value.trim();

        if (url) {
            imagePreview.src = url;
            imagePreview.style.display = 'block';
        } else {
            imagePreview.style.display = 'none';
            imagePreview.removeAttribute('src');
        }
    });

    imagePreview.onerror = function () {
        imagePreview.style.display = 'none';
    };
}
</script>

</body>
</html>
