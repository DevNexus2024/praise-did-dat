<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/lib/customer-auth.php';
$context = pdd_require_customer();
$db = $context['db'];
$cartBrand = (string) ($_POST['brand'] ?? $_GET['brand'] ?? '') === 'vans' ? 'vans' : 'praise';
$cartSessionKey = $cartBrand === 'vans' ? 'pdd_vans_cart' : 'pdd_cart';
$cart = is_array($_SESSION[$cartSessionKey] ?? null) ? $_SESSION[$cartSessionKey] : [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!pdd_verify_csrf($_POST['csrf_token'] ?? null)) {
        $notice = 'Your session expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
        if (in_array($action, ['add_to_cart', 'buy_now'], true)) {
            $quantity = max(1, min(99, (int) ($_POST['quantity'] ?? 1)));
            $statement = $db->prepare('SELECT id, kind, brand FROM catalog_items WHERE id = :id AND active = 1 LIMIT 1');
            $statement->execute(['id' => $itemId]);
            $item = $statement->fetch();
            if (!$item || $item['brand'] !== $cartBrand) {
                $notice = 'That item is no longer available.';
            } else {
                if ($item['kind'] === 'service') $quantity = 1;
                if ($action === 'buy_now') {
                    $_SESSION['pdd_direct_order'] = [(int) $item['id'] => $quantity];
                    header('Location: checkout.php?mode=single' . ($cartBrand === 'vans' ? '&brand=vans' : ''), true, 303);
                    exit;
                }
                $cart[(int) $item['id']] = min(99, (int) ($cart[(int) $item['id']] ?? 0) + $quantity);
                    $_SESSION[$cartSessionKey] = $cart;
                $_SESSION['pdd_customer_notice'] = 'Added to your cart.';
                header('Location: cart.php' . ($cartBrand === 'vans' ? '?brand=vans' : ''), true, 303);
                exit;
            }
        } elseif ($action === 'update' && $itemId) {
            $quantity = (int) ($_POST['quantity'] ?? 0);
            if ($quantity <= 0) unset($cart[$itemId]);
            else {
                $quantity = min(99, $quantity);
                $statement = $db->prepare('SELECT kind, brand FROM catalog_items WHERE id = :id AND active = 1 LIMIT 1');
                $statement->execute(['id' => $itemId]);
                $item = $statement->fetch();
                if (!$item || $item['brand'] !== $cartBrand) {
                    unset($cart[$itemId]);
                    $notice = 'An unavailable item was removed from your cart.';
                } else {
                    $cart[$itemId] = $item['kind'] === 'service' ? 1 : $quantity;
                }
            }
            $_SESSION[$cartSessionKey] = $cart;
        } elseif ($action === 'remove' && $itemId) {
            unset($cart[$itemId]);
            $_SESSION[$cartSessionKey] = $cart;
        } elseif ($action === 'clear') {
            $cart = [];
            $_SESSION[$cartSessionKey] = [];
        }
    }
}

$validCart = [];
$cartRows = [];
$total = 0.0;
if ($cart) {
    foreach ($cart as $id => $quantity) {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
        if ($id && $quantity && $quantity > 0) $validCart[(int) $id] = min(99, (int) $quantity);
    }
    if ($validCart) {
        $marks = implode(',', array_fill(0, count($validCart), '?'));
        $statement = $db->prepare("SELECT id, kind, name, description, price, currency, image_url, active FROM catalog_items WHERE id IN ($marks)");
        $statement->execute(array_keys($validCart));
        $byId = [];
        foreach ($statement->fetchAll() as $item) $byId[(int) $item['id']] = $item;
        foreach ($validCart as $id => $quantity) {
            if (!isset($byId[$id])) continue;
            $item = $byId[$id];
            $item['quantity'] = $item['kind'] === 'service' ? 1 : $quantity;
            $item['line_total'] = $item['price'] === null ? null : (float) $item['price'] * $item['quantity'];
            if ((int) $item['active'] === 1 && $item['line_total'] !== null) $total += $item['line_total'];
            $cartRows[] = $item;
        }
    }
}
$csrf = pdd_csrf_token();
$cartCurrency = $cartRows[0]['currency'] ?? ($cartBrand === 'vans' ? 'ZAR' : 'SZL');
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><meta name="theme-color" content="<?= $cartBrand === 'vans' ? '#e2231a' : '#030604' ?>" /><title>Your cart — <?= $cartBrand === 'vans' ? 'Vans' : 'Praise Did Dat' ?></title><link rel="stylesheet" href="styles.css" /></head>
<body class="customer-shop customer-subpage<?= $cartBrand === 'vans' ? ' vans-cart-page' : '' ?>">
  <header class="customer-header"><a class="customer-brand" href="<?= $cartBrand === 'vans' ? 'vans-shop.php' : 'shop.php' ?>"><?= $cartBrand === 'vans' ? '<span class="customer-brand-mark">V</span><span>VANS</span>' : '<span class="customer-brand-mark">p.</span><span>praise did <b>dat</b><sup>™</sup></span>' ?></a><nav aria-label="Shop navigation"><a href="shop.php#products">Products</a><a href="shop.php#services">Services</a><a href="orders.php">My orders</a></nav><div class="customer-header-actions"><a class="customer-cart-link" href="cart.php<?= $cartBrand === 'vans' ? '?brand=vans' : '' ?>">Cart <b><?= array_sum($validCart) ?></b></a><a href="signout.php">Sign out</a></div></header>
  <main class="customer-page-main"><p class="customer-kicker">YOUR PICKS</p><h1>Your <em>cart.</em></h1><p class="customer-page-lede">A little look at the good things you picked.</p>
    <?php if ($notice !== ''): ?><p class="customer-flash" role="status"><?= pdd_customer_escape($notice) ?></p><?php endif; ?>
    <?php if (!$cartRows): ?><div class="customer-empty-panel"><p>Your cart is waiting for a little joy.</p><a class="customer-button" href="<?= $cartBrand === 'vans' ? 'vans-shop.php' : 'shop.php' ?>">Explore the shop <span>↗</span></a></div><?php else: ?>
      <div class="customer-cart-layout"><section class="customer-cart-items" aria-label="Cart items">
        <?php foreach ($cartRows as $item): ?><article class="customer-cart-row<?= (int) $item['active'] !== 1 ? ' is-unavailable' : '' ?>">
          <div class="customer-cart-thumb"><?php if (!empty($item['image_url'])): ?><img src="<?= pdd_customer_escape($item['image_url']) ?>" alt="" /><?php else: ?><span>p.</span><?php endif; ?></div>
          <div class="customer-cart-detail"><p><?= strtoupper(pdd_customer_escape($item['kind'])) ?><?= (int) $item['active'] === 1 ? '' : ' · UNAVAILABLE' ?></p><h2><?= pdd_customer_escape($item['name']) ?></h2><span><?= $item['price'] === null ? 'Quote confirmed after request' : pdd_customer_escape($item['currency']) . ' ' . number_format((float) $item['price'], 2) ?></span></div>
          <form class="customer-cart-update" method="post" action="cart.php"><input type="hidden" name="csrf_token" value="<?= pdd_customer_escape($csrf) ?>" /><input type="hidden" name="brand" value="<?= $cartBrand ?>" /><input type="hidden" name="action" value="update" /><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" /><?php if ($item['kind'] === 'service'): ?><input type="hidden" name="quantity" value="1" /><?php endif; ?><label>Qty<input type="number" name="quantity" min="0" max="99" value="<?= (int) $item['quantity'] ?>"<?= $item['kind'] === 'service' ? ' disabled' : '' ?> /></label><button type="submit" class="customer-mini-button">Update</button></form>
          <strong class="customer-cart-total"><?= $item['line_total'] === null ? 'Quote' : pdd_customer_escape($item['currency']) . ' ' . number_format((float) $item['line_total'], 2) ?></strong>
          <form method="post" action="cart.php"><input type="hidden" name="csrf_token" value="<?= pdd_customer_escape($csrf) ?>" /><input type="hidden" name="brand" value="<?= $cartBrand ?>" /><input type="hidden" name="action" value="remove" /><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" /><button class="customer-remove" type="submit" aria-label="Remove <?= pdd_customer_escape($item['name']) ?>">Remove</button></form>
        </article><?php endforeach; ?>
        <form method="post" action="cart.php" class="customer-clear-cart"><input type="hidden" name="csrf_token" value="<?= pdd_customer_escape($csrf) ?>" /><input type="hidden" name="brand" value="<?= $cartBrand ?>" /><input type="hidden" name="action" value="clear" /><button type="submit">Clear cart</button></form>
      </section><aside class="customer-cart-summary"><p class="customer-kicker">ORDER SUMMARY</p><h2>Looking good.</h2><div><span>Known item total</span><strong><?= pdd_customer_escape($cartCurrency) ?> <?= number_format($total, 2) ?></strong></div><small>Payment is arranged after the order is reviewed.</small><a class="customer-button" href="checkout.php?mode=cart<?= $cartBrand === 'vans' ? '&brand=vans' : '' ?>">Continue to checkout <span>↗</span></a><a class="customer-text-link" href="<?= $cartBrand === 'vans' ? 'vans-shop.php' : 'shop.php' ?>">Keep browsing</a></aside></div>
    <?php endif; ?>
  </main><footer class="customer-footer"><span>praise did dat™</span><span>Made with feeling. Ordered with ease.</span><a href="mailto:hello@praisediddat.com">Need a hand?</a></footer>
</body></html>
