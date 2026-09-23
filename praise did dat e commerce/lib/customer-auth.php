<?php
declare(strict_types=1);

require_once __DIR__ . '/catalog-store.php';

/** @return array{db: PDO, customer: array<string, mixed>} */
function pdd_require_customer(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['pdd_customer_id'])) {
        header('Location: login.php', true, 303);
        exit;
    }

    $db = pdd_db();
    $statement = $db->prepare('SELECT id, first_name, last_name, email FROM customers WHERE id = :id AND is_active = 1 LIMIT 1');
    $statement->execute(['id' => (int) $_SESSION['pdd_customer_id']]);
    $customer = $statement->fetch();
    if (!$customer) {
        unset($_SESSION['pdd_customer_id'], $_SESSION['pdd_customer_name'], $_SESSION['pdd_customer_email']);
        session_regenerate_id(true);
        header('Location: login.php', true, 303);
        exit;
    }

    $_SESSION['pdd_customer_name'] = $customer['first_name'];
    $_SESSION['pdd_customer_email'] = $customer['email'];
    return ['db' => $db, 'customer' => $customer];
}

function pdd_customer_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
