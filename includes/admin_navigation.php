
<?php

function renderAdminNavigation(): void
{
    $currentPage = basename($_SERVER['PHP_SELF']);

    $links = [
        'dashboard.php' => ['Dashboard', '01'],
        'products.php' => ['Products', '02'],
        'orders.php' => ['Orders', '03'],
        'customers.php' => ['Customers', '04'],
        'users.php' => ['Users', '05'],
        'reports.php' => ['Reports', '06'],
        'logout.php' => ['Logout', '→']
    ];
?>

<style>
    .admin-topbar {
        width: 100%;
        min-height: 76px;
        padding: 12px 35px;
        background: #111;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        z-index: 1001;
    }

    .admin-topbar-left {
        display: flex;
        align-items: center;
        gap: 22px;
    }

    .menu-toggle {
        width: 42px;
        height: 42px;
        border: 1px solid #777;
        background: transparent;
        color: white;
        font-size: 22px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background .2s, border-color .2s;
    }

    .menu-toggle:hover {
        background: #333;
        border-color: white;
    }

    .admin-brand {
        display: flex;
        flex-direction: column;
        gap: 3px;
        color: white;
        text-decoration: none;
    }

    .admin-brand strong {
        font-family: Arial, sans-serif;
        font-size: 23px;
        letter-spacing: 4px;
        line-height: 1;
    }

    .admin-brand small {
        font-size: 9px;
        letter-spacing: 2px;
        color: #aaa;
    }

    .admin-topbar-right {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 5px;
    }

    .admin-topbar-right strong {
        font-size: 11px;
        letter-spacing: 2px;
        font-weight: 500;
    }

    .admin-topbar-right small {
        color: #aaa;
        font-size: 10px;
        letter-spacing: 1px;
    }

    .admin-menu-overlay {
        position: fixed;
        inset: 0;
        z-index: 1100;
        background: rgba(0, 0, 0, .48);
        opacity: 0;
        visibility: hidden;
        transition: opacity .25s, visibility .25s;
    }

    .admin-menu-overlay.open {
        opacity: 1;
        visibility: visible;
    }

    .admin-drawer {
        position: fixed;
        z-index: 1101;
        top: 0;
        left: 0;
        width: 290px;
        max-width: 86vw;
        height: 100vh;
        background: #fff;
        transform: translateX(-105%);
        transition: transform .28s ease;
        box-shadow: 8px 0 35px rgba(0, 0, 0, .16);
        display: flex;
        flex-direction: column;
    }

    .admin-drawer.open {
        transform: translateX(0);
    }

    .admin-drawer-header {
        min-height: 105px;
        padding: 25px;
        background: #111;
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .admin-drawer-header strong {
        font-size: 25px;
        letter-spacing: 4px;
    }

    .admin-drawer-header small {
        display: block;
        margin-top: 5px;
        font-size: 9px;
        letter-spacing: 2px;
        color: #aaa;
    }

    .admin-menu-close {
        width: 36px;
        height: 36px;
        border: 1px solid #777;
        background: transparent;
        color: white;
        font-size: 22px;
        cursor: pointer;
    }

    .admin-drawer-label {
        padding: 27px 24px 12px;
        color: #888;
        font-size: 10px;
        letter-spacing: 2px;
    }

    .admin-drawer-links {
        padding: 0 13px;
        flex: 1;
    }

    .admin-drawer-links a {
        min-height: 49px;
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 0 13px;
        margin-bottom: 5px;
        text-decoration: none;
        color: #333;
        font-size: 13px;
        transition: background .2s, color .2s;
    }

    .admin-drawer-links a:hover {
        background: #f0f0f0;
    }

    .admin-drawer-links a.active {
        background: #111;
        color: white;
    }

    .admin-link-number {
        width: 24px;
        font-size: 10px;
        color: #999;
    }

    .admin-drawer-links a.active .admin-link-number {
        color: #ccc;
    }

    .admin-drawer-links a.logout-link {
        border-top: 1px solid #e8e8e8;
        margin-top: 20px;
        padding-top: 10px;
        color: #9b2525;
    }

    body.admin-menu-locked {
        overflow: hidden;
    }

    @media (max-width: 600px) {
        .admin-topbar {
            min-height: 68px;
            padding: 10px 16px;
        }

        .admin-topbar-left {
            gap: 14px;
        }

        .admin-brand strong {
            font-size: 20px;
        }

        .admin-topbar-right strong {
            font-size: 9px;
        }

        .admin-topbar-right small {
            font-size: 8px;
        }
    }
</style>

<header class="admin-topbar">

    <div class="admin-topbar-left">

        <button
            type="button"
            class="menu-toggle"
            id="adminMenuToggle"
            aria-label="Open navigation menu"
            aria-controls="adminDrawer"
            aria-expanded="false"
        >
            ☰
        </button>

        <a href="dashboard.php" class="admin-brand">
            <strong>DIANE</strong>
            <small>CLOTHING SHOP</small>
        </a>

    </div>

    <div class="admin-topbar-right">
        <strong>ADMIN PANEL</strong>
        <small>MANAGEMENT SYSTEM</small>
    </div>

</header>

<div class="admin-menu-overlay" id="adminMenuOverlay"></div>

<aside class="admin-drawer" id="adminDrawer">

    <div class="admin-drawer-header">

        <div>
            <strong>DIANE</strong>
            <small>CLOTHING SHOP</small>
        </div>

        <button
            type="button"
            class="admin-menu-close"
            id="adminMenuClose"
            aria-label="Close navigation menu"
        >
            ×
        </button>

    </div>

    <div class="admin-drawer-label">
        MAIN MENU
    </div>

    <nav class="admin-drawer-links">

        <?php foreach ($links as $file => $item): ?>

            <a
                href="<?= htmlspecialchars($file) ?>"
                class="<?= $currentPage === $file ? 'active' : '' ?> <?= $file === 'logout.php' ? 'logout-link' : '' ?>"
                <?= $currentPage === $file ? 'aria-current="page"' : '' ?>
            >

                <span class="admin-link-number">
                    <?= htmlspecialchars($item[1]) ?>
                </span>

                <span>
                    <?= htmlspecialchars($item[0]) ?>
                </span>

            </a>

        <?php endforeach; ?>

    </nav>

</aside>

<script>
(function () {
    const toggle = document.getElementById('adminMenuToggle');
    const drawer = document.getElementById('adminDrawer');
    const overlay = document.getElementById('adminMenuOverlay');
    const close = document.getElementById('adminMenuClose');

    if (!toggle || !drawer || !overlay || !close) {
        return;
    }

    function openMenu() {
        drawer.classList.add('open');
        overlay.classList.add('open');
        document.body.classList.add('admin-menu-locked');
        toggle.setAttribute('aria-expanded', 'true');
    }

    function closeMenu() {
        drawer.classList.remove('open');
        overlay.classList.remove('open');
        document.body.classList.remove('admin-menu-locked');
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', openMenu);
    close.addEventListener('click', closeMenu);
    overlay.addEventListener('click', closeMenu);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });
})();
</script>

<?php
}
