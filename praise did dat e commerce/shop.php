<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/lib/customer-auth.php';
$context = pdd_require_customer();
$db = pdd_catalog_db();
$customer = $context['customer'];
$items = $db->query("SELECT id, kind, name, description, price, currency, image_url, category FROM catalog_items WHERE active = 1 AND brand = 'praise' ORDER BY kind ASC, created_at DESC, id DESC")->fetchAll();
$products = array_values(array_filter($items, static fn(array $item): bool => $item['kind'] === 'product'));
$services = array_values(array_filter($items, static fn(array $item): bool => $item['kind'] === 'service'));
$productCategories = array_values(array_unique(array_map(static fn(array $item): string => trim((string) ($item['category'] ?? '')) ?: 'Other', $products)));
$cart = is_array($_SESSION['pdd_cart'] ?? null) ? $_SESSION['pdd_cart'] : [];
$cartCount = array_sum(array_map('intval', $cart));
$flash = (string) ($_SESSION['pdd_customer_notice'] ?? '');
unset($_SESSION['pdd_customer_notice']);
$csrf = pdd_csrf_token();
$escape = 'pdd_customer_escape';

$renderItem = static function (array $item) use ($escape, $csrf): void {
    $price = $item['price'] === null ? 'Request a quote' : $item['currency'] . ' ' . number_format((float) $item['price'], 2);
    ?>
    <article class="customer-item-card"<?= $item['kind'] === 'product' ? ' data-product-category="' . $escape(strtolower(trim((string) ($item['category'] ?? '')) ?: 'other')) . '"' : '' ?>>
      <div class="customer-item-art">
        <?php if (!empty($item['image_url'])): ?><img src="<?= $escape($item['image_url']) ?>" alt="<?= $escape($item['name']) ?>" loading="lazy" /><?php else: ?><span aria-hidden="true"><?= $item['kind'] === 'product' ? 'p.' : '✳' ?></span><?php endif; ?>
        <small><?= $item['kind'] === 'product' ? 'PRODUCT' : 'SERVICE' ?></small>
      </div>
      <div class="customer-item-copy"><p><?= $item['kind'] === 'product' ? 'MADE FOR EVERYDAY' : 'MADE AROUND YOUR IDEA' ?></p><h3><?= $escape($item['name']) ?></h3><div><?= nl2br($escape($item['description'])) ?></div><strong><?= $escape($price) ?></strong></div>
      <div class="customer-item-actions">
        <form method="post" action="cart.php"><input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>" /><input type="hidden" name="action" value="add_to_cart" /><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" /><label class="sr-only">Quantity for <?= $escape($item['name']) ?><input type="number" name="quantity" min="1" max="99" value="1" /></label><button class="customer-button customer-button-quiet" type="submit">Add to cart <span>+</span></button></form>
        <form method="post" action="cart.php"><input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>" /><input type="hidden" name="action" value="buy_now" /><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" /><input type="hidden" name="quantity" value="1" /><button class="customer-button" type="submit"><?= $item['kind'] === 'service' ? 'Request service' : 'Order once off' ?> <span>↗</span></button></form>
      </div>
    </article>
    <?php
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="theme-color" content="#030604" />
  <title>Your shop — Praise Did Dat</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="styles.css" />
</head>
<body class="customer-shop">
  <header class="customer-header">
    <a class="customer-brand" href="shop.php"><span class="customer-brand-mark">p.</span><span>praise did <b>dat</b><sup>™</sup></span></a>
    <nav aria-label="Shop navigation"><a href="#products">Products</a><a href="#services">Services</a><a href="orders.php">My orders</a></nav>
    <div class="customer-header-actions"><a class="customer-vans-link" href="vans-shop.php">Shop Vans <span aria-hidden="true">↗</span></a><a class="customer-cart-link" href="cart.php">Cart <b><?= (int) $cartCount ?></b></a><a href="signout.php">Sign out</a></div>
  </header>
  <main>
    <section class="customer-welcome"><div><p class="customer-kicker">YOUR PRAISE DID DAT SHOP</p><h1>Welcome in,<br /><em><?= pdd_customer_escape($customer['first_name']) ?>.</em></h1><p>Find a little joy for yourself, or let’s make one of your ideas real.</p><div class="customer-welcome-links"><a class="customer-button" href="#products">Explore products <span>↓</span></a><a class="customer-text-link" href="orders.php">Track my orders <span>↗</span></a><a class="customer-vans-button" href="vans-shop.php">Explore the Vans shop <span>↗</span></a></div></div><div class="customer-welcome-orbit" aria-hidden="true"><span>p.</span><i></i><b></b></div></section>
    <?php if ($flash !== ''): ?><p class="customer-flash" role="status"><?= pdd_customer_escape($flash) ?></p><?php endif; ?>
    <section class="customer-category" id="products"><div class="customer-section-title"><div><p class="customer-kicker">THE LITTLE GOOD THINGS</p><h2>Shop <em>products.</em></h2></div><p>One-off pieces and custom favourites, made to bring a little more joy to your everyday.</p></div><?php if ($products): ?><div class="pdd-product-filters" role="group" aria-label="Filter products by category"><button class="is-selected" type="button" data-product-filter="all">All products</button><?php foreach ($productCategories as $category): ?><button type="button" data-product-filter="<?= $escape(strtolower($category)) ?>"><?= $escape($category) ?></button><?php endforeach; ?></div><?php endif; ?><div class="customer-item-grid" id="pdd-product-grid"><?php if (!$products): ?><p class="customer-empty">New products are on their way.</p><?php else: foreach ($products as $item) $renderItem($item); endif; ?></div><p class="pdd-products-empty" id="pdd-products-empty" hidden>No products in this category yet.</p></section>
    <section class="customer-category customer-category-services" id="services"><div class="customer-section-title"><div><p class="customer-kicker">MADE WITH YOU IN MIND</p><h2>Creative <em>services.</em></h2></div><p>Have a good idea? Choose a service and send us a request. We’ll get back to you with the next steps.</p></div><div class="customer-item-grid customer-service-grid"><?php if (!$services): ?><p class="customer-empty">Fresh ideas are welcome. Services will show up here soon.</p><?php else: foreach ($services as $item) $renderItem($item); endif; ?></div></section>
    <section class="customer-tracking-preview"><div><p class="customer-kicker">KEEP AN EYE ON THE GOOD STUFF</p><h2>Your orders,<br /><em>all in one place.</em></h2><p>Check each order’s latest status and see what you’ve ordered.</p></div><a class="customer-button" href="orders.php">Track my orders <span>↗</span></a></section>
  </main>
  <footer class="customer-footer"><span>praise did dat™</span><span>Made with feeling. Ordered with ease.</span><a href="mailto:hello@praisediddat.com">Need a hand?</a></footer>
  <script>
    (() => {
      const grid = document.querySelector('#pdd-product-grid');
      if (!grid) return;
      const cards = [...grid.querySelectorAll('[data-product-category]')];
      const empty = document.querySelector('#pdd-products-empty');
      document.querySelectorAll('[data-product-filter]').forEach((button) => button.addEventListener('click', () => {
        const selected = button.dataset.productFilter;
        document.querySelectorAll('[data-product-filter]').forEach((option) => option.classList.toggle('is-selected', option === button));
        let visible = 0;
        cards.forEach((card) => {
          const show = selected === 'all' || card.dataset.productCategory === selected;
          card.hidden = !show;
          if (show) visible++;
        });
        empty.hidden = visible > 0;
      }));
    })();
  </script>
</body>
</html>
