<?php
require_once __DIR__ . '/../database.php';
$db = new database();
$pdo = $db->opencon();

// Load categories map
$catMap = $db->listServiceCategoryNames();

// Load a few active services
$st = $pdo->prepare("SELECT service_id, title, category_id, status FROM services WHERE status='active' ORDER BY created_at DESC LIMIT 20");
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($rows as $r) {
    $cid = (int)($r['category_id'] ?? 0);
    $name = $catMap[$cid] ?? 'Uncategorized';
    echo sprintf("#%d | %s | cat_id=%s | resolved=%s\n", (int)$r['service_id'], $r['title'], var_export($r['category_id'], true), $name);
}

// Print category map size
echo 'catMap size=' . count($catMap) . "\n";
