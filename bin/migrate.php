<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config/default.php';
if (is_file($root . '/config/local.php')) {
    $config = array_replace_recursive($config, require $root . '/config/local.php');
}

$dbPath = $config['db']['path'];
$dir = dirname($dbPath);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('PRAGMA foreign_keys = ON;');

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
    $stmt->execute([':name' => $table]);
    return (bool) $stmt->fetchColumn();
};

if ($tableExists($pdo, 'categories')) {
    $dupCats = $pdo->query('SELECT user_id, name, COUNT(*) AS c FROM categories GROUP BY user_id, name COLLATE NOCASE HAVING COUNT(*) > 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($dupCats) {
        throw new RuntimeException('Migration blocked: duplicate category names exist for a user (case-insensitive). Resolve duplicates manually before applying NOCASE unique indexes.');
    }
}

if ($tableExists($pdo, 'tasks')) {
    $dupTasks = $pdo->query('SELECT user_id, category_id, name, COUNT(*) AS c FROM tasks GROUP BY user_id, category_id, name COLLATE NOCASE HAVING COUNT(*) > 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($dupTasks) {
        throw new RuntimeException('Migration blocked: duplicate task names exist in a category (case-insensitive). Resolve duplicates manually before applying NOCASE unique indexes.');
    }
}

$schema = file_get_contents($root . '/database/schema.sql');
if ($schema === false) {
    throw new RuntimeException('Cannot read schema.sql');
}

$pdo->exec($schema);

echo "Migration complete: {$dbPath}" . PHP_EOL;
