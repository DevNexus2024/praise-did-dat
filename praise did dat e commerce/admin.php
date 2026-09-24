<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/lib/catalog-store.php';

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$dbError = '';
try {
    $db = pdd_catalog_db();
    $adminCount = (int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn();
} catch (Throwable $exception) {
    $dbError = 'The MySQL database could not be opened. Make sure XAMPP MySQL is running, the praise_did_dat schema is imported, and the connection settings are correct.';
    $db = null;
    $adminCount = 0;
}

$currentAdmin = null;
if (isset($_SESSION['pdd_admin_id']) && $db instanceof PDO) {
    $adminLookup = $db->prepare('SELECT id, email, role, is_active FROM admins WHERE id = :id LIMIT 1');
    $adminLookup->execute(['id' => (int) $_SESSION['pdd_admin_id']]);
    $currentAdmin = $adminLookup->fetch() ?: null;
}
$authenticated = $currentAdmin !== null && (int) $currentAdmin['is_active'] === 1;
$isOwner = $authenticated && $currentAdmin['role'] === 'owner';
if (!$authenticated && $db instanceof PDO) {
    unset($_SESSION['pdd_admin_id'], $_SESSION['pdd_admin_email'], $_SESSION['pdd_admin_role']);
    header('Location: index.php?mode=admin', true, 303);
    exit;
}
$isLocalRequest = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
$canSetupAdmin = $adminCount === 0 && $isLocalRequest;
$notice = $_SESSION['pdd_admin_notice'] ?? '';
$noticeError = !empty($_SESSION['pdd_admin_notice_error']);
unset($_SESSION['pdd_admin_notice'], $_SESSION['pdd_admin_notice_error']);

$redirectWithNotice = static function (string $message, bool $isError = false): void {
    $_SESSION['pdd_admin_notice'] = $message;
    $_SESSION['pdd_admin_notice_error'] = $isError;
    header('Location: admin.php', true, 303);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db instanceof PDO) {
    if (!pdd_verify_csrf($_POST['csrf_token'] ?? null)) {
        $redirectWithNotice('Your session expired. Refresh the page and try again.', true);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'setup' && $adminCount === 0 && !$isLocalRequest) {
        $redirectWithNotice('Initial admin setup must be completed from the local XAMPP machine.', true);
    }

    if ($action === 'setup' && $canSetupAdmin) {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $redirectWithNotice('Enter a valid admin email address.', true);
        }
        if (strlen($password) < 12) {
            $redirectWithNotice('Use at least 12 characters for the admin password.', true);
        }
        if (!hash_equals($password, $confirmation)) {
            $redirectWithNotice('The password confirmation does not match.', true);
        }
        try {
            $statement = $db->prepare('INSERT INTO admins (email, password_hash) VALUES (:email, :hash)');
            $statement->execute(['email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['pdd_admin_id'] = (int) $db->lastInsertId();
            $_SESSION['pdd_admin_email'] = $email;
            $redirectWithNotice('Admin account created. Welcome to your dashboard.');
        } catch (PDOException $exception) {
            $redirectWithNotice('That admin email could not be saved. It may already be in use.', true);
        }
    }

    if ($action === 'login' && $adminCount > 0) {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $statement = $db->prepare('SELECT id, email, password_hash FROM admins WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $admin = $statement->fetch();
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            $redirectWithNotice('Email or password is incorrect.', true);
        }
        session_regenerate_id(true);
        $_SESSION['pdd_admin_id'] = (int) $admin['id'];
        $_SESSION['pdd_admin_email'] = $admin['email'];
        $db->prepare('UPDATE admins SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $admin['id']]);
        $redirectWithNotice('Welcome back.');
    }

    if ($action === 'logout' && $authenticated) {
        unset($_SESSION['pdd_admin_id'], $_SESSION['pdd_admin_email'], $_SESSION['pdd_admin_role']);
        session_regenerate_id(true);
        $redirectWithNotice('You have been signed out.');
    }

    if ($action === 'add_item' && $authenticated) {
        $kind = (string) ($_POST['kind'] ?? '');
        if ($kind === 'admin') {
            if (!$isOwner) {
                $redirectWithNotice('Only the original admin can create admin accounts.', true);
            }
            $email = trim((string) ($_POST['admin_email'] ?? ''));
            $password = (string) ($_POST['admin_password'] ?? '');
            $confirmation = (string) ($_POST['admin_password_confirmation'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                $redirectWithNotice('Enter a valid admin email address.', true);
            }
            if (strlen($password) < 12) {
                $redirectWithNotice('Use at least 12 characters for the admin password.', true);
            }
            if (!hash_equals($password, $confirmation)) {
                $redirectWithNotice('The admin passwords do not match.', true);
            }
            try {
                $addAdmin = $db->prepare("INSERT INTO admins (email, password_hash, role) VALUES (:email, :password_hash, 'admin')");
                $addAdmin->execute(['email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                $redirectWithNotice('Admin account created. They can now sign in to the dashboard.');
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $redirectWithNotice('An admin account with that email already exists.', true);
                }
                $redirectWithNotice('The admin account could not be created. Check the database and try again.', true);
            }
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $priceInput = trim((string) ($_POST['price'] ?? ''));
        $imagePath = null;
        if (!in_array($kind, ['product', 'service'], true) || mb_strlen($name) < 2 || mb_strlen($name) > 80 || mb_strlen($description) > 1200) {
            $redirectWithNotice('Check the item type, name, and description, then try again.', true);
        }
        $price = null;
        if ($priceInput !== '') {
            if (!is_numeric($priceInput) || (float) $priceInput < 0 || (float) $priceInput > 100000000) {
                $redirectWithNotice('Enter a valid price, or leave it blank for a service quote.', true);
            }
            $price = round((float) $priceInput, 2);
        } elseif ($kind === 'product') {
            $redirectWithNotice('Products need a price. Services can be left blank for a quote.', true);
        }
        $uploadedImage = $_FILES['image_file'] ?? null;
        if (is_array($uploadedImage) && (int) ($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadError = (int) ($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_OK) {
                $redirectWithNotice('The image upload did not complete. Please choose the image and try again.', true);
            }
            if ((int) ($uploadedImage['size'] ?? 0) > 5 * 1024 * 1024) {
                $redirectWithNotice('Images must be 5 MB or smaller.', true);
            }
            if (!class_exists('finfo')) {
                $redirectWithNotice('Image upload needs PHP fileinfo enabled in XAMPP.', true);
            }

            $temporaryImage = (string) ($uploadedImage['tmp_name'] ?? '');
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryImage);
            $imageTypes = [
                'image/jpeg' => ['extension' => 'jpg', 'type' => IMAGETYPE_JPEG],
                'image/png' => ['extension' => 'png', 'type' => IMAGETYPE_PNG],
                'image/webp' => ['extension' => 'webp', 'type' => IMAGETYPE_WEBP],
            ];
            $imageInfo = @getimagesize($temporaryImage);
            if (!isset($imageTypes[$mimeType]) || !$imageInfo || $imageInfo[2] !== $imageTypes[$mimeType]['type']) {
                $redirectWithNotice('Choose a valid JPG, PNG, or WebP image.', true);
            }
            if ($imageInfo[0] > 8000 || $imageInfo[1] > 8000) {
                $redirectWithNotice('Image dimensions must be 8000 by 8000 pixels or smaller.', true);
            }

            $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'catalog';
            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                $redirectWithNotice('The image upload folder could not be created. Check XAMPP folder permissions.', true);
            }
            $imageFileName = bin2hex(random_bytes(16)) . '.' . $imageTypes[$mimeType]['extension'];
            if (!move_uploaded_file($temporaryImage, $uploadDirectory . DIRECTORY_SEPARATOR . $imageFileName)) {
                $redirectWithNotice('The image could not be saved. Check XAMPP folder permissions and try again.', true);
            }
            $imagePath = 'assets/uploads/catalog/' . $imageFileName;
        }

        $brand = $kind === 'product' && ($_POST['brand'] ?? '') === 'vans' ? 'vans' : 'praise';
        $category = $kind === 'product' ? trim((string) ($_POST['category'] ?? '')) : '';
        if (mb_strlen($category) > 80) {
            $redirectWithNotice('Keep the product category to 80 characters or fewer.', true);
        }
        if ($brand === 'vans' && $category === '') $category = 'Other';
        $currency = $brand === 'vans' ? 'ZAR' : 'SZL';
        $statement = $db->prepare('INSERT INTO catalog_items (kind, brand, name, category, description, price, currency, image_url) VALUES (:kind, :brand, :name, :category, :description, :price, :currency, :image_url)');
        $statement->execute(['kind' => $kind, 'brand' => $brand, 'name' => $name, 'category' => $category !== '' ? $category : null, 'description' => $description, 'price' => $price, 'currency' => $currency, 'image_url' => $imagePath]);
        $redirectWithNotice(ucfirst($kind) . ' added to the live catalog.');
    }

    if ($action === 'activate_item' && $authenticated) {
        $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$itemId) {
            $redirectWithNotice('That catalog item could not be found.', true);
        }
        $statement = $db->prepare('UPDATE catalog_items SET active = 1 WHERE id = :id');
        $statement->execute(['id' => $itemId]);
        $redirectWithNotice($statement->rowCount() ? 'Catalog item is now active.' : 'That catalog item could not be found.', !$statement->rowCount());
    }

    if ($action === 'delete_item' && $authenticated) {
        $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$itemId) {
            $redirectWithNotice('That catalog item could not be found.', true);
        }
        $statement = $db->prepare('DELETE FROM catalog_items WHERE id = :id');
        $statement->execute(['id' => $itemId]);
        $redirectWithNotice($statement->rowCount() ? 'Catalog item deleted.' : 'That catalog item could not be found.', !$statement->rowCount());
    }

    if ($action === 'update_order_status' && $authenticated) {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($_POST['order_status'] ?? '');
        $allowedStatuses = ['pending', 'confirmed', 'processing', 'completed', 'cancelled', 'refunded'];
        if (!$orderId || !in_array($status, $allowedStatuses, true)) {
            $redirectWithNotice('Choose a valid order and status.', true);
        }
        $statement = $db->prepare('UPDATE orders SET order_status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $orderId]);
        $exists = $db->prepare('SELECT 1 FROM orders WHERE id = :id');
        $exists->execute(['id' => $orderId]);
        $orderExists = (bool) $exists->fetchColumn();
        $redirectWithNotice($orderExists ? 'Order tracking status updated.' : 'That order could not be found.', !$orderExists);
    }
}

$items = [];
$orders = [];
$summary = ['products' => 0, 'services' => 0, 'views' => 0, 'clicks' => 0];
$range = '7 days';
if ($authenticated && $db instanceof PDO) {
    $items = $db->query("SELECT i.id, i.kind, i.brand, i.category, i.currency, i.name, i.description, i.price, i.image_url, i.active, i.created_at,
        SUM(CASE WHEN e.event_type = 'view' AND e.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS views_7d,
        SUM(CASE WHEN e.event_type = 'click' AND e.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS clicks_7d
        FROM catalog_items i LEFT JOIN catalog_events e ON e.catalog_item_id = i.id
        GROUP BY i.id ORDER BY i.created_at DESC, i.id DESC")->fetchAll();
    foreach ($items as $item) {
        if ((int) $item['active'] === 1) {
            $summary[$item['kind'] === 'product' ? 'products' : 'services']++;
        }
        $summary['views'] += (int) $item['views_7d'];
        $summary['clicks'] += (int) $item['clicks_7d'];
    }
    $orders = $db->query('SELECT o.id, o.order_number, o.customer_first_name, o.customer_last_name, o.customer_email, o.order_status, o.payment_status, o.total_amount, o.currency, o.created_at, COUNT(oi.id) AS item_count FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id GROUP BY o.id ORDER BY o.created_at DESC, o.id DESC LIMIT 50')->fetchAll();
}
$ctr = $summary['views'] > 0 ? round(($summary['clicks'] / $summary['views']) * 100, 1) : 0;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="theme-color" content="#030604" />
  <title><?= $authenticated ? 'Admin dashboard' : 'Admin sign in' ?> — Praise Did Dat</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="styles.css" />
</head>
<body class="admin-page">
  <header class="admin-header"><a class="wordmark" href="index.html"><span class="wordmark-icon">p.</span><span>praise did <strong>dat</strong><sup>™</sup></span></a><span class="admin-header-title">STUDIO CONTROL PANEL</span><?php if ($authenticated): ?><form method="post" action="admin.php"><input type="hidden" name="csrf_token" value="<?= $escape(pdd_csrf_token()) ?>" /><input type="hidden" name="action" value="logout" /><button class="admin-logout" type="submit">Sign out <span>↗</span></button></form><?php else: ?><a class="admin-logout" href="index.html">Back to shop</a><?php endif; ?></header>

  <main class="admin-main">
    <?php if ($dbError !== ''): ?>
      <section class="admin-gate"><p class="admin-kicker">ADMIN ACCESS</p><h1>Dashboard unavailable</h1><p><?= $escape($dbError) ?></p></section>
    <?php elseif (!$authenticated): ?>
      <section class="admin-gate"><p class="admin-kicker">PRAISE DID DAT · ADMIN</p><?php if ($canSetupAdmin): ?><h1>Set up your<br /><em>admin account.</em></h1><p class="admin-gate-copy">Create the first admin login. Your password is stored as a secure hash on this server.</p><?php elseif ($adminCount === 0): ?><h1>Setup starts<br /><em>on this server.</em></h1><p class="admin-gate-copy">For security, create the first admin account from the local XAMPP machine.</p><?php else: ?><h1>Welcome<br /><em>back.</em></h1><p class="admin-gate-copy">Sign in to manage your products, services, and performance.</p><?php endif; ?>
        <?php if ($notice !== ''): ?><p class="admin-notice<?= $noticeError ? ' is-error' : '' ?>"><?= $escape($notice) ?></p><?php endif; ?>
        <?php if ($canSetupAdmin || $adminCount > 0): ?><form class="admin-auth-form" method="post" action="admin.php" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= $escape(pdd_csrf_token()) ?>" /><input type="hidden" name="action" value="<?= $canSetupAdmin ? 'setup' : 'login' ?>" />
          <label>Email address<input type="email" name="email" autocomplete="username" placeholder="you@yourstudio.com" required /></label>
          <label>Password<input type="password" name="password" autocomplete="<?= $adminCount === 0 ? 'new-password' : 'current-password' ?>" placeholder="<?= $adminCount === 0 ? 'At least 12 characters' : 'Your admin password' ?>" minlength="<?= $adminCount === 0 ? '12' : '1' ?>" required /></label>
          <?php if ($adminCount === 0): ?><label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" placeholder="Type it again" minlength="12" required /></label><?php endif; ?>
          <button class="admin-primary" type="submit"><?= $adminCount === 0 ? 'Create admin account' : 'Sign in to dashboard' ?><span>↗</span></button>
        </form><p class="admin-secure-note"><span>●</span> PRIVATE ADMIN AREA · YOUR CATALOG DATA STAYS ON THIS SERVER</p><?php endif; ?>
      </section>
    <?php else: ?>
      <section class="admin-welcome"><div><p class="admin-kicker">PRAISE DID DAT · STUDIO CONTROL</p><h1>Good morning,<br /><em>let’s make good things.</em></h1><p>Manage what’s on the shop floor and see how it’s performing.</p></div><a class="admin-view-shop" href="index.html" target="_blank" rel="noopener">View storefront <span>↗</span></a></section>
      <?php if ($notice !== ''): ?><p class="admin-notice<?= $noticeError ? ' is-error' : '' ?>"><?= $escape($notice) ?></p><?php endif; ?>
      <section class="admin-stats" aria-label="Catalog overview"><article><span>LIVE PRODUCTS</span><strong><?= number_format($summary['products']) ?></strong><small>Active in your shop</small></article><article><span>LIVE SERVICES</span><strong><?= number_format($summary['services']) ?></strong><small>Ready for new enquiries</small></article><article><span>PRODUCT &amp; SERVICE VIEWS</span><strong><?= number_format($summary['views']) ?></strong><small>Last 7 days</small></article><article><span>CATALOG CLICK RATE</span><strong><?= number_format($ctr, 1) ?>%</strong><small><?= number_format($summary['clicks']) ?> actions · last 7 days</small></article></section>

      <section class="admin-content-grid"><div class="admin-panel admin-add-panel"><div class="admin-panel-heading"><div><p class="admin-kicker">GROW THE GOOD STUFF</p><h2>Add to your catalog</h2></div><span class="admin-heading-spark">✳</span></div><p class="admin-panel-intro" id="catalog-form-intro">Add a product or a service. New live items show up on the storefront automatically.</p>
        <form class="admin-item-form" method="post" action="admin.php" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= $escape(pdd_csrf_token()) ?>" /><input type="hidden" name="action" value="add_item" />
          <label>What are we adding?<select name="kind" id="catalog-kind"><option value="product">A product</option><option value="service">A service</option><?php if ($isOwner): ?><option value="admin">An admin</option><?php endif; ?></select></label>
          <label class="catalog-only" id="catalog-brand-field" hidden>Store<select name="brand" id="catalog-brand"><option value="praise">Praise Did Dat</option><option value="vans">Vans</option></select></label>
          <label class="catalog-only" id="catalog-category-field" hidden>Product category<input type="text" name="category" maxlength="80" placeholder="Cases, stickers, custom designs" /></label>
          <label class="catalog-only">Name<input type="text" name="name" maxlength="80" placeholder="e.g. Custom iPhone Waffle Case" required /></label>
          <label class="catalog-only">Tell us a little about it<textarea name="description" maxlength="1200" rows="4" placeholder="What makes it special?" required></textarea></label>
          <div class="admin-form-row catalog-only"><label>Price <span class="admin-field-hint" id="catalog-price-currency">(E)</span><input type="number" name="price" min="0" max="100000000" step="0.01" placeholder="320" /></label><label>Import image <span class="admin-field-hint">(optional · JPG, PNG, WebP · up to 5 MB)</span><input type="file" name="image_file" accept="image/jpeg,image/png,image/webp" /></label></div>
          <p class="admin-form-help catalog-only">Choose an image stored on this device. Images from other sources can be downloaded first, then imported here.</p>
          <p class="admin-form-help catalog-only" id="catalog-kind-help">Products need a price. Leave a service price blank if you quote per project.</p>
          <?php if ($isOwner): ?><div id="admin-account-fields" hidden><label>Admin email<input type="email" name="admin_email" maxlength="254" autocomplete="off" placeholder="newadmin@example.com" disabled required /></label><div class="admin-form-row"><label>Password<input type="password" name="admin_password" autocomplete="new-password" minlength="12" placeholder="At least 12 characters" disabled required /></label><label>Confirm password<input type="password" name="admin_password_confirmation" autocomplete="new-password" minlength="12" placeholder="Type it again" disabled required /></label></div><p class="admin-form-help">Only the original admin can create admin accounts. Passwords are stored as secure hashes.</p></div><?php endif; ?>
          <button class="admin-primary" id="catalog-submit" type="submit">Add to storefront <span>↗</span></button>
        </form>
      </div>

      <div class="admin-panel admin-performance"><div class="admin-panel-heading"><div><p class="admin-kicker">WHAT’S GETTING LOVE</p><h2>Catalog performance</h2></div><span class="admin-period">LAST 7 DAYS</span></div><p class="admin-panel-intro">Views are counted when an item enters the visitor’s screen. Actions are product clicks or service enquiries.</p>
        <?php if (!$items): ?><div class="admin-empty">Your catalog will show up here.</div><?php else: ?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>ITEM</th><th>TYPE / STORE</th><th>VIEWS</th><th>CLICKS</th><th>CTR</th><th>STATUS</th><th>ACTIONS</th></tr></thead><tbody><?php foreach ($items as $item): ?><?php $itemCtr = (int) $item['views_7d'] > 0 ? round(((int) $item['clicks_7d'] / (int) $item['views_7d']) * 100, 1) : 0; ?><tr><td><strong><?= $escape($item['name']) ?></strong><?php if ($item['price'] !== null): ?><small><?= $escape($item['currency']) ?> <?= number_format((float) $item['price'], 2) ?></small><?php endif; ?></td><td><span class="admin-type-tag"><?= $escape($item['kind']) ?></span><small><?= $item['brand'] === 'vans' ? 'Vans' : 'Praise Did Dat' ?><?= !empty($item['category']) ? ' · ' . $escape($item['category']) : '' ?></small></td><td><?= number_format((int) $item['views_7d']) ?></td><td><?= number_format((int) $item['clicks_7d']) ?></td><td><?= number_format($itemCtr, 1) ?>%</td><td><span class="admin-status<?= (int) $item['active'] === 1 ? ' is-live' : '' ?>"><?= (int) $item['active'] === 1 ? 'Active' : 'Inactive' ?></span></td><td><div class="admin-row-actions"><form method="post" action="admin.php"><input type="hidden" name="csrf_token" value="<?= $escape(pdd_csrf_token()) ?>" /><input type="hidden" name="action" value="activate_item" /><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" /><button class="admin-toggle" type="submit"<?= (int) $item['active'] === 1 ? ' disabled aria-disabled="true"' : '' ?>>Active</button></form><form method="post" action="admin.php" onsubmit="return confirm('Delete this catalog item permanently?');"><input type="hidden" name="csrf_token" value="<?= $escape(pdd_csrf_token()) ?>" /><input type="hidden" name="action" value="delete_item" /><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" /><button class="admin-delete" type="submit">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <div class="admin-analytics-note"><span>↗</span><p>These are engagement metrics, not sales. Order and revenue analytics can be added when checkout is connected.</p></div>
      </div></section>
      <section class="admin-panel admin-orders-panel"><div class="admin-panel-heading"><div><p class="admin-kicker">KEEP CUSTOMERS IN THE LOOP</p><h2>Recent orders</h2></div><span class="admin-period">LATEST 50</span></div><p class="admin-panel-intro">Update an order’s status here; the customer can follow progress under My Orders.</p>
        <?php if (!$orders): ?><div class="admin-empty">No customer orders have been placed yet.</div><?php else: ?><div class="admin-table-wrap"><table class="admin-table admin-orders-table"><thead><tr><th>ORDER</th><th>CUSTOMER</th><th>ITEMS</th><th>TOTAL</th><th>PAYMENT</th><th>TRACKING</th><th>UPDATE</th></tr></thead><tbody><?php foreach ($orders as $order): ?><tr><td><strong><?= $escape($order['order_number']) ?></strong><small><?= date('j M Y', strtotime($order['created_at'])) ?></small></td><td><?= $escape(trim($order['customer_first_name'] . ' ' . ($order['customer_last_name'] ?? ''))) ?><small><?= $escape($order['customer_email']) ?></small></td><td><?= (int) $order['item_count'] ?></td><td><?= $escape($order['currency']) ?> <?= number_format((float) $order['total_amount'], 2) ?></td><td><?= $escape(ucfirst($order['payment_status'])) ?></td><td><span class="admin-status is-live"><?= $escape(ucfirst($order['order_status'])) ?></span></td><td><form class="admin-order-update" method="post" action="admin.php"><input type="hidden" name="csrf_token" value="<?= $escape(pdd_csrf_token()) ?>" /><input type="hidden" name="action" value="update_order_status" /><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>" /><select name="order_status" aria-label="New status for <?= $escape($order['order_number']) ?>"><option value="pending"<?= $order['order_status'] === 'pending' ? ' selected' : '' ?>>Pending</option><option value="confirmed"<?= $order['order_status'] === 'confirmed' ? ' selected' : '' ?>>Confirmed</option><option value="processing"<?= $order['order_status'] === 'processing' ? ' selected' : '' ?>>Processing</option><option value="completed"<?= $order['order_status'] === 'completed' ? ' selected' : '' ?>>Completed</option><option value="cancelled"<?= $order['order_status'] === 'cancelled' ? ' selected' : '' ?>>Cancelled</option><option value="refunded"<?= $order['order_status'] === 'refunded' ? ' selected' : '' ?>>Refunded</option></select><button class="admin-toggle" type="submit">Save</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
      </section>
      <footer class="admin-footer"><span>Signed in as <?= $escape($_SESSION['pdd_admin_email'] ?? '') ?></span><span>Catalog analytics are stored in the Praise Did Dat MySQL database.</span></footer>
    <?php endif; ?>
  </main>
  <script>
    const catalogKind = document.querySelector('#catalog-kind');
    if (catalogKind) {
      const catalogFields = [...document.querySelectorAll('.catalog-only')];
      const catalogInputs = [...document.querySelectorAll('.catalog-only input, .catalog-only textarea')];
      const adminFields = document.querySelector('#admin-account-fields');
      const adminInputs = adminFields ? [...adminFields.querySelectorAll('input')] : [];
      const priceInput = document.querySelector('[name="price"]');
      const submitButton = document.querySelector('#catalog-submit');
      const intro = document.querySelector('#catalog-form-intro');
      const kindHelp = document.querySelector('#catalog-kind-help');
      const brandField = document.querySelector('#catalog-brand-field');
      const categoryField = document.querySelector('#catalog-category-field');
      const brandSelect = document.querySelector('#catalog-brand');
      const priceCurrency = document.querySelector('#catalog-price-currency');
      const updateCatalogForm = () => {
        const isAdmin = catalogKind.value === 'admin';
        catalogFields.forEach((field) => { field.hidden = isAdmin; });
        catalogInputs.forEach((input) => { input.disabled = isAdmin; });
        adminInputs.forEach((input) => { input.disabled = !isAdmin; });
        if (adminFields) adminFields.hidden = !isAdmin;
        if (priceInput) priceInput.required = catalogKind.value === 'product';
        const isProduct = catalogKind.value === 'product';
        if (brandField) brandField.hidden = !isProduct;
        if (categoryField) categoryField.hidden = !isProduct;
        if (brandSelect) brandSelect.disabled = !isProduct;
        if (priceCurrency) priceCurrency.textContent = isProduct && brandSelect && brandSelect.value === 'vans' ? '(R)' : '(E)';
        const categoryInput = document.querySelector('[name="category"]');
        if (categoryInput) categoryInput.disabled = !isProduct;
        if (kindHelp && catalogKind.value === 'service') kindHelp.textContent = 'Leave the price blank if you quote per project.';
        else if (kindHelp && catalogKind.value === 'product') kindHelp.textContent = 'Products need a price.';
        if (intro) intro.textContent = isAdmin ? 'Create an admin login for someone you trust to manage the shop.' : 'Add a product or service. New live items show up on the storefront automatically.';
        if (submitButton && submitButton.firstChild) submitButton.firstChild.nodeValue = isAdmin ? 'Create admin account ' : 'Add to storefront ';
      };
      catalogKind.addEventListener('change', updateCatalogForm);
      if (brandSelect) brandSelect.addEventListener('change', updateCatalogForm);
      updateCatalogForm();
    }
  </script>
</body>
</html>
