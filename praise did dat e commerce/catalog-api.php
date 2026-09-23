<?php
declare(strict_types=1);

require __DIR__ . '/lib/catalog-store.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

try {
    $db = pdd_catalog_db();
    $items = $db->query('SELECT id, kind, name, description, price, image_url FROM catalog_items WHERE active = 1 ORDER BY id ASC')->fetchAll();
    echo json_encode([
        'products' => array_values(array_filter($items, static fn(array $item): bool => $item['kind'] === 'product')),
        'services' => array_values(array_filter($items, static fn(array $item): bool => $item['kind'] === 'service')),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'The catalog is temporarily unavailable.']);
}
