<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/lib/customer-auth.php';
$context = pdd_require_customer();
$db = $context['db'];
$customer = $context['customer'];
$cart = is_array($_SESSION['pdd_vans_cart'] ?? null) ? $_SESSION['pdd_vans_cart'] : [];
$items = $db->query("SELECT id, name, category, description, price, currency, image_url FROM catalog_items WHERE active = 1 AND brand = 'vans' AND kind = 'product' ORDER BY category ASC, created_at DESC, id DESC")->fetchAll();
$categories = array_values(array_unique(array_filter(array_map(static fn(array $item): string => trim((string) ($item['category'] ?? '')), $items))));
$cartCount = array_sum(array_map('intval', $cart));
$csrf = pdd_csrf_token();
$escape = 'pdd_customer_escape';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="theme-color" content="#e2231a" />
  <title>Vans collection — Praise Did Dat</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="styles.css" />
  <link rel="stylesheet" href="vans-shop.css" />
</head>
<body class="vans-store">
  <div class="vans-promo-bar">OFF THE WALL · BUILT FOR EVERY DAY</div>
  <header class="vans-header">
    <a class="vans-wordmark" href="vans-shop.php" aria-label="Vans collection home">VANS<span>®</span></a>
    <nav aria-label="Vans shop navigation"><a href="#new">New arrivals</a><a href="#categories">Shop</a><a href="orders.php">Track order</a></nav>
    <div class="vans-header-actions"><span>Hi, <?= $escape($customer['first_name']) ?></span><a href="cart.php?brand=vans">Bag <b><?= (int) $cartCount ?></b></a><a href="shop.php">PDD shop</a><a class="vans-signout" href="signout.php">Sign out <span aria-hidden="true">↗</span></a></div>
  </header>
  <main>
    <section class="vans-hero" id="new">
      <div class="vans-hero-copy"><p class="vans-eyebrow">THE VANS COLLECTION</p><h1>MAKE YOUR<br />OWN LANE.</h1><p>Everyday classics, skate staples and gear made to move with you.</p><a class="vans-red-button" href="#collection">SHOP THE COLLECTION <span>↗</span></a></div>
      <div class="vans-hero-art" aria-hidden="true"><span class="vans-checker"></span><span class="vans-hero-type">OFF<br />THE<br />WALL</span><div class="vans-hero-shoe"><i></i><b></b></div><small>VANS · EST. 1966</small></div>
      <a class="vans-hero-scroll" href="#categories">SCROLL TO EXPLORE <span>↓</span></a>
    </section>
    <section class="vans-categories" id="categories"><div class="vans-section-heading"><p class="vans-eyebrow">PICK YOUR LINE</p><h2>SHOP BY MOOD</h2></div><div class="vans-category-links">
      <?php foreach (['Shoes', 'Clothing', 'Accessories'] as $category): ?><a href="#collection" data-category-link="<?= $escape(strtolower($category)) ?>"><span class="vans-category-art vans-art-<?= strtolower($category) ?>"><b><?= $category === 'Shoes' ? 'V' : ($category === 'Clothing' ? 'OFF' : '66') ?></b></span><strong><?= $escape($category) ?></strong><span>Explore <i>↗</i></span></a><?php endforeach; ?>
    </div></section>
    <section class="vans-collection" id="collection">
      <div class="vans-collection-heading"><div><p class="vans-eyebrow">THE GOOD STUFF</p><h2>FRESH ON THE FLOOR</h2><p>Find a classic for wherever the day takes you.</p></div><span><?= count($items) ?> <?= count($items) === 1 ? 'piece' : 'pieces' ?> to explore</span></div>
      <div class="vans-tools"><label class="vans-search">Search the collection<input id="vans-search" type="search" placeholder="Try shoes, hoodie, classic…" autocomplete="off" /></label><label>Category<select id="vans-category"><option value="all">All categories</option><?php foreach ($categories as $category): ?><option value="<?= $escape(strtolower($category)) ?>"><?= $escape($category) ?></option><?php endforeach; ?></select></label><label>Sort by<select id="vans-sort"><option value="featured">Featured</option><option value="price-low">Price: low to high</option><option value="price-high">Price: high to low</option><option value="name">Name: A to Z</option></select></label>
      </div>
      <div class="vans-category-pills"><button class="is-selected" type="button" data-filter="all">All</button><?php foreach ($categories as $category): ?><button type="button" data-filter="<?= $escape(strtolower($category)) ?>"><?= $escape($category) ?></button><?php endforeach; ?></div>
      <?php if (!$items): ?><div class="vans-empty"><span class="vans-empty-logo">VANS</span><p>We’re getting this collection ready.</p><small>New Vans styles will appear here soon. In the meantime, browse the Praise Did Dat collection.</small><a class="vans-red-button" href="shop.php">BACK TO THE SHOP <span>↗</span></a></div><?php else: ?>
        <div class="vans-product-grid" id="vans-grid">
          <?php foreach ($items as $item): $category = trim((string) ($item['category'] ?? 'Other')) ?: 'Other'; ?>
            <article class="vans-product-card" data-category="<?= $escape(strtolower($category)) ?>" data-name="<?= $escape(strtolower($item['name'] . ' ' . $item['description'] . ' ' . $category)) ?>" data-price="<?= (float) $item['price'] ?>" data-id="<?= (int) $item['id'] ?>">
              <div class="vans-product-image"><?php if (!empty($item['image_url'])): ?><img src="<?= $escape($item['image_url']) ?>" alt="<?= $escape($item['name']) ?>" loading="lazy" /><?php else: ?><div class="vans-product-placeholder"><span>OFF THE WALL</span><b><?= $escape($category === 'Shoes' ? 'VANS' : $category) ?></b><i class="<?= strtolower($category) === 'shoes' ? 'placeholder-shoe' : 'placeholder-garment' ?>"></i></div><?php endif; ?><span class="vans-product-tag">VANS</span><button class="vans-favourite" type="button" aria-label="Save <?= $escape($item['name']) ?> to favourites">♡</button></div>
              <div class="vans-product-copy"><p><?= $escape(strtoupper($category)) ?></p><h3><?= $escape($item['name']) ?></h3><div class="vans-product-rating" aria-label="Vans classic">★★★★★ <span>CLASSIC</span></div><strong><?= $escape($item['currency']) ?> <?= number_format((float) $item['price'], 2) ?></strong><div class="vans-buy-actions"><form method="post" action="cart.php"><input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>" /><input type="hidden" name="brand" value="vans" /><input type="hidden" name="action" value="add_to_cart" /><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" /><input type="hidden" name="quantity" value="1" /><button class="vans-add-button" type="submit">ADD TO BAG <span>+</span></button></form><form method="post" action="cart.php"><input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>" /><input type="hidden" name="brand" value="vans" /><input type="hidden" name="action" value="buy_now" /><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" /><input type="hidden" name="quantity" value="1" /><button class="vans-buy-now" type="submit">BUY NOW ↗</button></form></div></div>
            </article>
          <?php endforeach; ?>
        </div><p class="vans-no-results" id="vans-no-results" hidden>No items match those filters. Try a different search.</p>
      <?php endif; ?>
    </section>
    <section class="vans-brand-band"><p class="vans-eyebrow">VANS · OFF THE WALL SINCE 1966</p><h2>THIS IS YOUR<br />SIDE OF THE LINE.</h2><a href="shop.php">Discover Praise Did Dat <span>↗</span></a></section>
  </main>
  <footer class="vans-footer"><span>VANS</span><a href="orders.php">Track my order</a><a href="shop.php">Praise Did Dat shop</a><a class="vans-footer-signout" href="signout.php">Sign out ↗</a></footer>
  <script>
    (() => {
      const grid = document.querySelector('#vans-grid');
      if (!grid) return;
      const cards = [...grid.querySelectorAll('.vans-product-card')];
      const search = document.querySelector('#vans-search');
      const category = document.querySelector('#vans-category');
      const sort = document.querySelector('#vans-sort');
      const empty = document.querySelector('#vans-no-results');
      const filter = (value) => {
        category.value = value;
        document.querySelectorAll('.vans-category-pills button').forEach((button) => button.classList.toggle('is-selected', button.dataset.filter === value));
        update();
      };
      const update = () => {
        const term = search.value.trim().toLowerCase();
        const selected = category.value;
        const visible = cards.filter((card) => (selected === 'all' || card.dataset.category === selected) && card.dataset.name.includes(term));
        cards.forEach((card) => { card.hidden = !visible.includes(card); });
        const byName = (a, b) => a.querySelector('h3').textContent.localeCompare(b.querySelector('h3').textContent);
        visible.sort(sort.value === 'price-low' ? (a, b) => Number(a.dataset.price) - Number(b.dataset.price) : sort.value === 'price-high' ? (a, b) => Number(b.dataset.price) - Number(a.dataset.price) : sort.value === 'name' ? byName : (a, b) => cards.indexOf(a) - cards.indexOf(b)).forEach((card) => grid.append(card));
        empty.hidden = visible.length > 0;
      };
      search.addEventListener('input', update);
      category.addEventListener('change', () => filter(category.value));
      sort.addEventListener('change', update);
      document.querySelectorAll('[data-filter]').forEach((button) => button.addEventListener('click', () => filter(button.dataset.filter)));
      document.querySelectorAll('[data-category-link]').forEach((link) => link.addEventListener('click', () => filter(link.dataset.categoryLink)));
      document.querySelectorAll('.vans-favourite').forEach((button) => button.addEventListener('click', () => { button.classList.toggle('is-saved'); button.textContent = button.classList.contains('is-saved') ? '♥' : '♡'; }));
      const sent = new Set();
      const observer = new IntersectionObserver((entries) => entries.forEach((entry) => {
        if (entry.isIntersecting && !sent.has(entry.target.dataset.id)) {
          sent.add(entry.target.dataset.id);
          fetch('analytics-event.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ item_id: entry.target.dataset.id, event_type: 'view' }) }).catch(() => {});
        }
      }), { threshold: 0.5 });
      cards.forEach((card) => observer.observe(card));
      grid.addEventListener('click', (event) => {
        if (event.target.closest('.vans-add-button')) {
          const card = event.target.closest('.vans-product-card');
          fetch('analytics-event.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ item_id: card.dataset.id, event_type: 'click' }) }).catch(() => {});
        }
      });
    })();
  </script>
</body>
</html>
