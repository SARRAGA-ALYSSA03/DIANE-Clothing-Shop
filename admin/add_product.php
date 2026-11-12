

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



// 3. Create CSRF token

if (empty($_SESSION['csrf_token'])) {

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

}



// 4. Retrieve categories from Supabase

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



// 5. Form defaults

$values = [

    'name' => '',

    'category' => '',

    'gender' => 'women',

    'price' => '',

    'original_price' => '',


    'sizes' => 'S, M, L, XL',

    'colors' => '',

    'material' => '',

    'stock' => '20',

    'description' => ''

];



$message = '';

$messageType = '';



// Show success after redirect

if (($_GET['success'] ?? '') === '1') {

    $message = 'Product added successfully!';

    $messageType = 'success';

}



// 6. Process product submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Preserve submitted text fields if validation or upload fails.
    foreach ($values as $key => $default) {
        $values[$key] = trim((string)($_POST[$key] ?? ''));
    }

    $csrfSubmitted = $_POST['csrf_token'] ?? '';
    $csrfSession = $_SESSION['csrf_token'] ?? '';

    if (
        $csrfSession === '' ||
        $csrfSubmitted === '' ||
        !hash_equals($csrfSession, $csrfSubmitted)
    ) {
        http_response_code(403);
        exit('Invalid request. Please refresh the page and try again.');
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

    // Convert comma-separated sizes and colors into arrays.
    $sizes = array_values(array_filter(
        array_map('trim', explode(',', $values['sizes'])),
        fn($value) => $value !== ''
    ));

    $colors = array_values(array_filter(
        array_map('trim', explode(',', $values['colors'])),
        fn($value) => $value !== ''
    ));

    $uploadedFile = $_FILES['image_file'] ?? null;
    $uploadError = $uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE;

    // 7. Validate product information before uploading anything.
    if (
        $values['name'] === '' ||
        $values['category'] === '' ||
        $values['gender'] === '' ||
        $values['price'] === '' ||
        $price === false ||
        $price <= 0 ||
        $stock === false ||
        $stock === null ||
        $stock < 0
    ) {
        $message = 'Please complete all required fields with valid information.';
        $messageType = 'error';

    } elseif (!in_array($values['category'], $categories, true)) {

        $message = 'Please select a valid product category.';
        $messageType = 'error';

    } elseif (!in_array($values['gender'], $genderOptions, true)) {

        $message = 'Please select a valid gender.';
        $messageType = 'error';

    } elseif (
        $values['original_price'] !== '' &&
        ($originalPrice === false || $originalPrice <= 0)
    ) {

        $message = 'Please enter a valid original price.';
        $messageType = 'error';

    } elseif (empty($sizes)) {

        $message = 'Please enter at least one product size.';
        $messageType = 'error';

    } elseif ($uploadError !== UPLOAD_ERR_OK) {

        $uploadMessages = [
            UPLOAD_ERR_INI_SIZE => 'The image exceeds the upload limit configured in PHP.',
            UPLOAD_ERR_FORM_SIZE => 'The image is too large.',
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please choose a product image.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server is missing its temporary upload folder.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded image.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the image upload.'
        ];

        $message = $uploadMessages[$uploadError]
            ?? 'The image could not be uploaded. Please choose it again.';
        $messageType = 'error';

    } else {

        // 8. Upload the selected image directly to Supabase Storage.
        $imageUpload = supabaseUploadProductImage(
            $uploadedFile,
            $_SESSION['access_token']
        );

        if (empty($imageUpload['success']) || empty($imageUpload['url'])) {

            $message = $imageUpload['error']
                ?? 'Unable to upload the product image. Please try again.';
            $messageType = 'error';

        } else {

            $imageUrl = $imageUpload['url'];

            // 9. Prepare product data using the public Storage URL.
            $product = [
                'id' => 'prod_' . bin2hex(random_bytes(8)),
                'name' => $values['name'],
                'price' => $price,
                'original_price' => $originalPrice,
                'category' => $values['category'],
                'gender' => $values['gender'],
                'image' => $imageUrl,
                'images' => [$imageUrl],
                'sizes' => $sizes,
                'colors' => $colors,
                'material' => $values['material'],
                'description' => $values['description'],
                'stock' => $stock,
                'rating' => 0,
                'review_count' => 0,
                'is_new' => isset($_POST['is_new']),
                'is_featured' => isset($_POST['is_featured'])
            ];

            // 10. Insert the product into Supabase.
            $insertResult = supabaseRequest(
                '/rest/v1/products',
                'POST',
                $product,
                $_SESSION['access_token']
            );

            if ($insertResult['success']) {
                header('Location: add_product.php?success=1');
                exit;
            }

            // The file upload succeeded, but the product insert failed.
            // Keep the returned error generic; do not expose tokens or secrets.
            $message = 'The image uploaded, but the product could not be saved. Please check the products table permissions and try again.';
            $messageType = 'error';
        }
    }
}

?>



<!DOCTYPE html>

<html lang="en">



<head>



    <meta charset="UTF-8">



    <meta name="viewport" content="width=device-width, initial-scale=1.0">



    <title>Add Product | Diane Clothing Shop</title>



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



        .image-preview {

            width: 115px;

            height: 140px;

            margin-top: 13px;

            object-fit: cover;

            border: 1px solid #eee;

            display: none;

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



        .success {

            background: #e5f1e8;

            color: #245c35;

            border: 1px solid #cce2d1;

        }



        .error {

            background: #f9e8e8;

            color: #982b2b;

            border: 1px solid #edcece;

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

            transition: background .2s;

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



    <h1>Add New Product</h1>



    <p class="subtitle">

        Add a clothing item to your Diane Clothing Shop collection.

    </p>



    <?php if ($message !== ''): ?>



        <div class="notification <?= htmlspecialchars($messageType) ?>">

            <?= htmlspecialchars($message) ?>

        </div>



    <?php endif; ?>



    <?php if (empty($categories)): ?>



        <div class="notification error">

            Unable to load categories from Supabase. Please check your categories table and permissions.

        </div>



    <?php else: ?>



        <section class="form-panel">



            <div class="form-heading">

                <h2>Product Information</h2>

                <p>Fields marked with * are required.</p>

            </div>



            <div class="form-content">



                <form method="POST" enctype="multipart/form-data">



                    <input

                        type="hidden"

                        name="csrf_token"

                        value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"

                    >



                    <!-- Basic information -->

                    <section class="form-section">



                        <h3>01 / Basic Information</h3>



                        <div class="form-grid">



                            <div class="form-group full">



                                <label>

                                    Product Name <span class="required">*</span>

                                </label>



                                <input

                                    type="text"

                                    name="name"

                                    value="<?= htmlspecialchars($values['name']) ?>"

                                    placeholder="Enter product name"

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

                                    value="<?= htmlspecialchars($values['price']) ?>"

                                    placeholder="0.00"

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

                                    value="<?= htmlspecialchars($values['original_price']) ?>"

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
                                Upload Product Image <span class="required">*</span>
                            </label>

                            <input
                                type="file"
                                name="image_file"
                                id="imageFile"
                                accept="image/png,image/jpeg,image/webp"
                                required
                            >

                            <small class="hint">
                                Choose a JPG, PNG, or WEBP image. Maximum size: 5 MB.
                            </small>

                            <img
                                id="imagePreview"
                                class="image-preview"
                                alt="Selected product image preview"
                            >

                        </div>

                    </section>

                    <!-- Variants -->

                    <section class="form-section">



                        <h3>03 / Product Variants</h3>



                        <div class="form-grid">



                            <div class="form-group">



                                <label>Available Sizes *</label>



                                <input

                                    type="text"

                                    name="sizes"

                                    value="<?= htmlspecialchars($values['sizes']) ?>"

                                    placeholder="XS, S, M, L, XL"

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

                                    placeholder="Cotton, Polyester, Ribbed fabric"

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

                                    value="<?= htmlspecialchars($values['stock']) ?>"

                                    required

                                >



                            </div>



                        </div>



                    </section>



                    <!-- Description and visibility -->

                    <section class="form-section">



                        <h3>04 / Description & Display</h3>



                        <div class="form-group">



                            <label>Product Description</label>



                            <textarea

                                name="description"

                                placeholder="Describe the clothing item..."

                            ><?= htmlspecialchars($values['description']) ?></textarea>



                        </div>



                        <div class="checkbox-row">



                            <label class="checkbox-label">



                                <input

                                    type="checkbox"

                                    name="is_new"

                                    <?= isset($_POST['is_new']) ? 'checked' : '' ?>

                                >



                                Mark as New Arrival



                            </label>



                            <label class="checkbox-label">



                                <input

                                    type="checkbox"

                                    name="is_featured"

                                    <?= isset($_POST['is_featured']) ? 'checked' : '' ?>

                                >



                                Feature on Homepage



                            </label>



                        </div>



                    </section>



                    <!-- Buttons -->

                    <div class="form-actions">



                        <button type="submit" class="submit-button">

                            ADD PRODUCT

                        </button>



                        <a href="products.php" class="cancel-button">

                            CANCEL

                        </a>



                    </div>



                </form>



            </div>



        </section>



    <?php endif; ?>



    <a href="products.php" class="back-link">

        ← BACK TO PRODUCTS

    </a>



</main>



<script>
const imageInput = document.getElementById('imageFile');
const imagePreview = document.getElementById('imagePreview');

let previewUrl = null;

imageInput.addEventListener('change', function () {
    const file = imageInput.files[0];

    if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
        previewUrl = null;
    }

    if (!file) {
        imagePreview.style.display = 'none';
        imagePreview.removeAttribute('src');
        return;
    }

    const allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
    const maxSize = 5 * 1024 * 1024;

    if (!allowedTypes.includes(file.type)) {
        imageInput.value = '';
        imagePreview.style.display = 'none';
        alert('Please select a PNG, JPG, or WEBP image.');
        return;
    }

    if (file.size > maxSize) {
        imageInput.value = '';
        imagePreview.style.display = 'none';
        alert('The image must not exceed 5 MB.');
        return;
    }

    previewUrl = URL.createObjectURL(file);
    imagePreview.src = previewUrl;
    imagePreview.style.display = 'block';

    imagePreview.onerror = function () {
        imagePreview.style.display = 'none';
    };
});

window.addEventListener('beforeunload', function () {
    if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
    }
});
</script>



</body>

</html>
