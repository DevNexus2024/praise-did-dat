<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function pdd_catalog_db(): PDO
{
    static $seeded = false;
    $pdo = pdd_db();

    if (!$seeded) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM catalog_items')->fetchColumn();
        if ($count === 0) {
            $seed = $pdo->prepare('INSERT INTO catalog_items (kind, name, description, price, image_url) VALUES (?, ?, ?, ?, NULL)');
            $starterItems = [
                ['product', 'The Waffle Case', 'A sweet, textured iPhone case, made to brighten up your everyday.', 280],
                ['product', 'Make It Yours', 'A personalised phone case built around your colours, words, and ideas.', 320],
                ['product', 'Happy Little Stickers', 'A small pack of happy details for your phone, notebook, and favourite things.', 95],
                ['service', 'Design & branding', 'Visual identities and graphics with a little more you in them.', null],
                ['service', 'Custom creations', 'Personalised pieces for gifts, celebrations, and just-because days.', null],
                ['service', 'Digital & social', 'Fresh content and digital design to help your ideas find their people.', null],
            ];
            foreach ($starterItems as $item) {
                $seed->execute($item);
            }
        }
        $seeded = true;
    }

    return $pdo;
}

function pdd_csrf_token(): string
{
    if (empty($_SESSION['pdd_csrf'])) {
        $_SESSION['pdd_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['pdd_csrf'];
}

function pdd_verify_csrf(mixed $token): bool
{
    return is_string($token) && isset($_SESSION['pdd_csrf']) && hash_equals($_SESSION['pdd_csrf'], $token);
}
