<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/lib/catalog-store.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite === 'cross-site') {
    http_response_code(403);
    echo json_encode(['error' => 'Cross-site event rejected.']);
    exit;
}
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$requestHost = (string) parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
if ($origin !== '' && strcasecmp((string) parse_url($origin, PHP_URL_HOST), $requestHost) !== 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Origin rejected.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$itemId = filter_var($payload['item_id'] ?? null, FILTER_VALIDATE_INT);
$eventType = (string) ($payload['event_type'] ?? '');
if (!$itemId || !in_array($eventType, ['view', 'click'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid catalog event.']);
    exit;
}

try {
    $db = pdd_catalog_db();
    $exists = $db->prepare('SELECT 1 FROM catalog_items WHERE id = :id AND active = 1');
    $exists->execute(['id' => $itemId]);
    if (!$exists->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Catalog item not found.']);
        exit;
    }

    if ($eventType === 'view') {
        $seenKey = 'pdd_seen_items_' . gmdate('Y-m-d');
        $_SESSION[$seenKey] ??= [];
        if (isset($_SESSION[$seenKey][$itemId])) {
            echo json_encode(['ok' => true, 'counted' => false]);
            exit;
        }
        $_SESSION[$seenKey][$itemId] = true;
    }

    $insert = $db->prepare('INSERT INTO catalog_events (catalog_item_id, event_type) VALUES (:item_id, :event_type)');
    $insert->execute(['item_id' => $itemId, 'event_type' => $eventType]);
    echo json_encode(['ok' => true, 'counted' => true]);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'The analytics event could not be saved.']);
}
