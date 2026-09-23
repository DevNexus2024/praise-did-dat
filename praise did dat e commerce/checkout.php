<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/lib/customer-auth.php';
$context = pdd_require_customer();
$db = $context['db'];
$customer = $context['customer'];
$mode = (string) ($_GET['mode'] ?? $_POST['mode'] ?? 'cart');
$mode = $mode === 'single' ? 'single' : 'cart';
$cartKey = $mode === 'single' ? 'pdd_direct_order' : 'pdd_cart';
$selected = is_array($_SESSION[$cartKey] ?? null) ? $_SESSION[$cartKey] : [];
$error = '';
$placedOrder = '';

$loadLines = static function (PDO $db, array $selected): array {
    $normalized = [];
    foreach ($selected as $id => $quantity) {
        $validId = filter_var($id, FILTER_VALIDATE_INT);
        $validQuantity = filter_var($quantity, FILTER_VALIDATE_INT);
        if (!$validId || !$validQuantity || $validQuantity < 1) continue;
        $normalized[(int) $validId] = min(99, (int) $validQuantity);
    }
    if (!$normalized) return [];
    $placeholders = implode(',', array_fill(0, count($normalized), '?'));
    $statement = $db->prepare("SELECT id, kind, name, description, price, currency, active FROM catalog_items WHERE id IN ($placeholders)");
    $statement->execute(array_keys($normalized));
    $available = [];
    foreach ($statement->fetchAll() as $item) $available[(int) $item['id']] = $item;
    $lines = [];
    foreach ($normalized as $id => $quantity) {
        if (!isset($available[$id]) || (int) $available[$id]['active'] !== 1) {
            throw new RuntimeException('An item in your order is no longer available. Return to your cart and update it.');
        }
        $item = $available[$id];
        if ($item['kind'] === 'service') $quantity = 1;
        $item['quantity'] = $quantity;
        $item['unit_price'] = $item['price'] === null ? 0.0 : (float) $item['price'];
        $item['line_total'] = round($item['unit_price'] * $quantity, 2);
        $lines[] = $item;
    }
    return $lines;
};

try {
    $lines = $loadLines($db, $selected);
} catch (RuntimeException $exception) {
    $lines = [];
    $error = $exception->getMessage();
}
if (!$lines && $error === '') {
    header('Location: cart.php', true, 303);
    exit;
}
$hasProducts = (bool) array_filter($lines, static fn(array $line): bool => $line['kind'] === 'product');
$currency = $lines[0]['currency'] ?? 'SZL';
foreach ($lines as $line) {
    if ($line['currency'] !== $currency) $error = 'Your items use different currencies. Please order them separately.';
}
$subtotal = round(array_sum(array_column($lines, 'line_total')), 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'place_order') {
    if (!pdd_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif ($error === '') {
        $address1 = trim((string) ($_POST['shipping_address_line1'] ?? ''));
        $city = trim((string) ($_POST['shipping_city'] ?? ''));
        $region = trim((string) ($_POST['shipping_region'] ?? ''));
        $postalCode = trim((string) ($_POST['shipping_postal_code'] ?? ''));
        $country = strtoupper(trim((string) ($_POST['shipping_country'] ?? 'SZ')));
        $note = trim((string) ($_POST['customer_note'] ?? ''));
        if (mb_strlen($note) > 2000) {
            $error = 'Please keep your order note under 2,000 characters.';
        } elseif ($hasProducts && ($address1 === '' || $city === '' || !preg_match('/^[A-Z]{2}$/', $country))) {
            $error = 'Add a delivery address, city, and two-letter country code for product orders.';
        } else {
            try {
                // Re-read active items and their current prices at the time the order is created.
                $lines = $loadLines($db, $selected);
                $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
                $db->beginTransaction();
                $orderNumber = 'PDD-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
                $createOrder = $db->prepare("INSERT INTO orders
                    (order_number, customer_id, customer_email, customer_first_name, customer_last_name, order_status, payment_status, subtotal, shipping_amount, tax_amount, total_amount, currency, shipping_address_line1, shipping_city, shipping_region, shipping_postal_code, shipping_country, customer_note)
                    VALUES (:order_number, :customer_id, :customer_email, :first_name, :last_name, 'pending', 'unpaid', :subtotal, 0, 0, :total, :currency, :address1, :city, :region, :postal_code, :country, :note)");
                $createOrder->execute([
                    'order_number' => $orderNumber,
                    'customer_id' => (int) $customer['id'],
                    'customer_email' => $customer['email'],
                    'first_name' => $customer['first_name'],
                    'last_name' => $customer['last_name'],
                    'subtotal' => $subtotal,
                    'total' => $subtotal,
                    'currency' => $currency,
                    'address1' => $hasProducts ? $address1 : null,
                    'city' => $hasProducts ? $city : null,
                    'region' => $hasProducts && $region !== '' ? $region : null,
                    'postal_code' => $hasProducts && $postalCode !== '' ? $postalCode : null,
                    'country' => $hasProducts ? $country : null,
                    'note' => $note !== '' ? $note : null,
                ]);
                $orderId = (int) $db->lastInsertId();
                $createLine = $db->prepare('INSERT INTO order_items (order_id, catalog_item_id, item_kind, item_name, item_description, quantity, unit_price, line_total) VALUES (:order_id, :catalog_item_id, :kind, :name, :description, :quantity, :unit_price, :line_total)');
                foreach ($lines as $line) {
                    $createLine->execute([
                        'order_id' => $orderId,
                        'catalog_item_id' => (int) $line['id'],
                        'kind' => $line['kind'],
                        'name' => $line['name'],
                        'description' => $line['description'],
                        'quantity' => (int) $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'line_total' => $line['line_total'],
                    ]);
                }
                $db->commit();
                if ($mode === 'single') unset($_SESSION['pdd_direct_order']);
                else $_SESSION['pdd_cart'] = [];
                $_SESSION['pdd_order_notice'] = $orderNumber;
                header('Location: orders.php', true, 303);
                exit;
            } catch (Throwable $exception) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'We could not place the order just now. Please try again.';
            }
        }
    }
}
$csrf = pdd_csrf_token();
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><meta name="theme-color" content="#030604" /><title>Checkout — Praise Did Dat</title><link rel="stylesheet" href="styles.css" /></head>
<body class="customer-shop customer-subpage">
  <header class="customer-header"><a class="customer-brand" href="shop.php"><span class="customer-brand-mark">p.</span><span>praise did <b>dat</b><sup>™</sup></span></a><nav aria-label="Shop navigation"><a href="shop.php#products">Products</a><a href="shop.php#services">Services</a><a href="orders.php">My orders</a></nav><div class="customer-header-actions"><a class="customer-cart-link" href="cart.php">Cart <b><?= array_sum(is_array($_SESSION['pdd_cart'] ?? null) ? $_SESSION['pdd_cart'] : []) ?></b></a><a href="signout.php">Sign out</a></div></header>
  <main class="customer-page-main"><p class="customer-kicker">ALMOST YOURS</p><h1>Review your <em>order.</em></h1><p class="customer-page-lede">We’ll double-check availability and get in touch about payment and delivery.</p>
    <?php if ($error !== ''): ?><p class="customer-flash is-error" role="alert"><?= pdd_customer_escape($error) ?></p><?php endif; ?>
    <?php if ($lines): ?><div class="customer-checkout-layout"><section class="customer-checkout-items"><h2>Your order</h2><?php foreach ($lines as $line): ?><article class="customer-checkout-line"><span><?= (int) $line['quantity'] ?> ×</span><div><strong><?= pdd_customer_escape($line['name']) ?></strong><small><?= $line['price'] === null ? 'Quote to be confirmed' : pdd_customer_escape($currency) . ' ' . number_format((float) $line['unit_price'], 2) ?></small></div><b><?= $line['price'] === null ? 'Quote' : pdd_customer_escape($currency) . ' ' . number_format((float) $line['line_total'], 2) ?></b></article><?php endforeach; ?>
      <form class="customer-checkout-form" method="post" action="checkout.php?mode=<?= pdd_customer_escape($mode) ?>"><input type="hidden" name="csrf_token" value="<?= pdd_customer_escape($csrf) ?>" /><input type="hidden" name="action" value="place_order" /><input type="hidden" name="mode" value="<?= pdd_customer_escape($mode) ?>" />
        <?php if ($hasProducts): ?><fieldset class="customer-address-fields"><legend>Delivery address</legend><label>Address line<input type="text" name="shipping_address_line1" maxlength="190" autocomplete="address-line1" required value="<?= pdd_customer_escape($_POST['shipping_address_line1'] ?? '') ?>" /></label><div class="customer-address-row"><label>City / town<input type="text" name="shipping_city" maxlength="100" autocomplete="address-level2" required value="<?= pdd_customer_escape($_POST['shipping_city'] ?? '') ?>" /></label><label>Region<input type="text" name="shipping_region" maxlength="100" autocomplete="address-level1" value="<?= pdd_customer_escape($_POST['shipping_region'] ?? '') ?>" /></label></div><div class="customer-address-row"><label>Postal code <small>OPTIONAL</small><input type="text" name="shipping_postal_code" maxlength="30" autocomplete="postal-code" value="<?= pdd_customer_escape($_POST['shipping_postal_code'] ?? '') ?>" /></label><label>Country code<input type="text" name="shipping_country" maxlength="2" pattern="[A-Za-z]{2}" autocomplete="country" required value="<?= pdd_customer_escape($_POST['shipping_country'] ?? 'SZ') ?>" /></label></div></fieldset><?php endif; ?>
        <label class="customer-note-field">Order note <small>OPTIONAL</small><textarea name="customer_note" maxlength="2000" rows="3" placeholder="Anything we should know?"><?= pdd_customer_escape($_POST['customer_note'] ?? '') ?></textarea></label>
        <div class="customer-checkout-total"><span>Total before delivery and payment</span><strong><?= pdd_customer_escape($currency) ?> <?= number_format($subtotal, 2) ?></strong></div><p class="customer-checkout-note">This places an order request; payment and delivery charges are confirmed afterward. If your order includes a service quote, we’ll confirm the price with you.</p><button class="customer-button" type="submit">Place order request <span>↗</span></button>
      </form></section><aside class="customer-checkout-side"><p class="customer-kicker">ORDERING WITH PRAISE DID DAT</p><h2>Made with care.</h2><p>Your order will appear under My Orders as soon as you place it. We’ll update its progress there.</p><a class="customer-text-link" href="<?= $mode === 'single' ? 'shop.php' : 'cart.php' ?>">Back to <?= $mode === 'single' ? 'shop' : 'cart' ?></a></aside></div><?php else: ?><div class="customer-empty-panel"><p>Your selected items are unavailable.</p><a class="customer-button" href="cart.php">Return to cart <span>↗</span></a></div><?php endif; ?>
  </main><footer class="customer-footer"><span>praise did dat™</span><span>Made with feeling. Ordered with ease.</span><a href="mailto:hello@praisediddat.com">Need a hand?</a></footer>
</body></html>
