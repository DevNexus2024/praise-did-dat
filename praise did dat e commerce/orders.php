<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/lib/customer-auth.php';
$context = pdd_require_customer();
$db = $context['db'];
$customer = $context['customer'];
$statement = $db->prepare('SELECT o.id, o.order_number, o.order_status, o.payment_status, o.total_amount, o.currency, o.created_at, o.updated_at, oi.item_name, oi.item_kind, oi.quantity FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id WHERE o.customer_id = :customer_id ORDER BY o.created_at DESC, o.id DESC LIMIT 100');
$statement->execute(['customer_id' => (int) $customer['id']]);
$orders = [];
foreach ($statement->fetchAll() as $row) {
    $id = (int) $row['id'];
    if (!isset($orders[$id])) {
        $orders[$id] = [
            'order_number' => $row['order_number'], 'order_status' => $row['order_status'],
            'payment_status' => $row['payment_status'], 'total_amount' => $row['total_amount'],
            'currency' => $row['currency'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'], 'items' => [],
        ];
    }
    if ($row['item_name'] !== null) $orders[$id]['items'][] = ['name' => $row['item_name'], 'kind' => $row['item_kind'], 'quantity' => (int) $row['quantity']];
}
$notice = (string) ($_SESSION['pdd_order_notice'] ?? '');
unset($_SESSION['pdd_order_notice']);
$steps = ['pending' => 'Received', 'confirmed' => 'Confirmed', 'processing' => 'In progress', 'completed' => 'Completed'];
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><meta name="theme-color" content="#030604" /><title>My orders — Praise Did Dat</title><link rel="stylesheet" href="styles.css" /></head>
<body class="customer-shop customer-subpage">
  <header class="customer-header"><a class="customer-brand" href="shop.php"><span class="customer-brand-mark">p.</span><span>praise did <b>dat</b><sup>™</sup></span></a><nav aria-label="Shop navigation"><a href="shop.php#products">Products</a><a href="shop.php#services">Services</a><a href="orders.php" aria-current="page">My orders</a></nav><div class="customer-header-actions"><a class="customer-cart-link" href="cart.php">Cart <b><?= array_sum(is_array($_SESSION['pdd_cart'] ?? null) ? $_SESSION['pdd_cart'] : []) ?></b></a><a href="signout.php">Sign out</a></div></header>
  <main class="customer-page-main"><p class="customer-kicker">YOUR ORDER JOURNEY</p><h1>My <em>orders.</em></h1><p class="customer-page-lede">Track the latest progress on your Praise Did Dat orders.</p>
    <?php if ($notice !== ''): ?><div class="customer-order-success" role="status"><span>✓</span><div><strong>Order request received</strong><p>Your order number is <b><?= pdd_customer_escape($notice) ?></b>. Keep it handy if you contact us.</p></div></div><?php endif; ?>
    <?php if (!$orders): ?><div class="customer-empty-panel"><p>You haven’t placed an order yet.</p><a class="customer-button" href="shop.php">Find something good <span>↗</span></a></div><?php else: ?><div class="customer-orders-list">
      <?php foreach ($orders as $order): $status = $order['order_status']; $currentStep = array_search($status, array_keys($steps), true); ?>
        <article class="customer-order-card"><div class="customer-order-heading"><div><p class="customer-kicker">ORDER <?= pdd_customer_escape($order['order_number']) ?></p><h2><?= date('j M Y', strtotime($order['created_at'])) ?></h2></div><span class="customer-order-status status-<?= pdd_customer_escape($status) ?>"><?= pdd_customer_escape(ucfirst($status)) ?></span></div>
          <?php if (in_array($status, ['cancelled', 'refunded'], true)): ?><p class="customer-order-terminal">This order was <?= pdd_customer_escape($status) ?>. Contact us if you need help.</p><?php else: ?><ol class="customer-order-steps" aria-label="Order progress"><?php $stepIndex = 0; foreach ($steps as $stepKey => $stepLabel): ?><li class="<?= $currentStep !== false && $stepIndex <= $currentStep ? 'is-done' : '' ?><?= $stepKey === $status ? ' is-current' : '' ?>"><span><?= $stepIndex + 1 ?></span><small><?= pdd_customer_escape($stepLabel) ?></small></li><?php $stepIndex++; endforeach; ?></ol><?php endif; ?>
          <ul class="customer-order-lines"><?php foreach ($order['items'] as $line): ?><li><span><?= pdd_customer_escape($line['name']) ?> <small>(<?= pdd_customer_escape($line['kind']) ?>)</small></span><b>×<?= (int) $line['quantity'] ?></b></li><?php endforeach; ?></ul>
          <div class="customer-order-footer"><span>Payment: <?= pdd_customer_escape(ucfirst($order['payment_status'])) ?></span><strong><?= pdd_customer_escape($order['currency']) ?> <?= number_format((float) $order['total_amount'], 2) ?></strong></div>
        </article>
      <?php endforeach; ?>
    </div><?php endif; ?>
  </main><footer class="customer-footer"><span>praise did dat™</span><span>Made with feeling. Ordered with ease.</span><a href="shop.php">Back to shop</a></footer>
</body></html>
