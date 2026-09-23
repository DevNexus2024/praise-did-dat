<?php
declare(strict_types=1);

/**
 * Shared PDO connection for the XAMPP MySQL database.
 * XAMPP defaults are localhost / root / no password. Set the PDD_DB_* environment
 * variables if your local MySQL account uses different connection details.
 */
function pdd_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('PDD_DB_HOST') ?: '127.0.0.1';
    $database = getenv('PDD_DB_NAME') ?: 'praise_did_dat';
    $username = getenv('PDD_DB_USER') ?: 'root';
    $password = getenv('PDD_DB_PASSWORD');
    if ($password === false) {
        $password = '';
    }

    $pdo = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // Add the admin roles column to databases created from the earlier schema.
    $roleColumn = $pdo->query("SHOW COLUMNS FROM admins LIKE 'role'")->fetch();
    if (!$roleColumn) {
        $pdo->exec("ALTER TABLE admins ADD COLUMN role ENUM('owner', 'admin') NOT NULL DEFAULT 'admin' AFTER password_hash");
    }

    // The earliest existing admin is the original owner on upgraded databases.
    $ownerId = $pdo->query("SELECT id FROM admins WHERE role = 'owner' LIMIT 1")->fetchColumn();
    if (!$ownerId) {
        $firstAdminId = $pdo->query('SELECT id FROM admins ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($firstAdminId) {
            $statement = $pdo->prepare("UPDATE admins SET role = 'owner' WHERE id = :id");
            $statement->execute(['id' => $firstAdminId]);
        }
    }

    return $pdo;
}
