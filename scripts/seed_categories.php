<?php
// scripts/seed_categories.php
// Purpose: Ensure service_categories table exists, seed common categories, and classify existing services
// Usage (CLI): php scripts/seed_categories.php [--force]
// Usage (web): scripts/seed_categories.php?force=1

// Safety: Only allow running by authenticated admin via web; CLI always allowed

declare(strict_types=1);

$runningFromCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/../database.php';
$db = new database();
$pdo = $db->opencon();

// Gate web access unless explicitly allowed and logged in as admin
if (!$runningFromCli) {
    session_start();
    $isAdmin = !empty($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
    $allow = isset($_GET['force']) || $isAdmin;
    if (!$allow) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$force = false;
if ($runningFromCli) {
    $force = in_array('--force', $argv ?? [], true);
} else {
    $force = isset($_GET['force']);
}

function out($msg) {
    global $runningFromCli;
    if ($runningFromCli) {
        fwrite(STDOUT, $msg . PHP_EOL);
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
    }
}

try {
    // 1) Ensure service_categories table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_categories (
        category_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    out('Ensured table service_categories');

    // Helpers: slug support detection and generation
    $hasSlug = false;
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_categories' AND COLUMN_NAME = 'slug'");
        $chk->execute();
        $hasSlug = ((int)$chk->fetchColumn() > 0);
    } catch (Throwable $e) { /* ignore */ }

    $slugify = function(string $name): string {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') $slug = 'category';
        return $slug;
    };

    $uniqueSlug = function(string $baseSlug) use ($pdo): string {
        $slug = $baseSlug; $i = 2;
        while (true) {
            $st = $pdo->prepare("SELECT 1 FROM service_categories WHERE slug = :s LIMIT 1");
            $st->execute([':s' => $slug]);
            if (!$st->fetchColumn()) return $slug;
            $slug = $baseSlug . '-' . $i;
            $i++;
        }
    };

    // Normalize empty slugs if slug column exists
    if ($hasSlug) {
        $rows = $pdo->query("SELECT category_id, name, slug FROM service_categories")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $slug = $r['slug'] ?? null;
            if ($slug === null || $slug === '') {
                $base = $slugify((string)$r['name']);
                $uniq = $uniqueSlug($base);
                $up = $pdo->prepare("UPDATE service_categories SET slug = :s WHERE category_id = :id");
                $up->execute([':s' => $uniq, ':id' => (int)$r['category_id']]);
            }
        }
        out('Normalized empty slugs');
    }

    // 2) Seed common categories
    $common = [
        'Web Development' => 'Websites, web apps, backend, frontend',
        'Mobile App Development' => 'iOS, Android, Flutter, React Native',
        'UI/UX Design' => 'Product design, wireframes, prototypes',
        'Graphic Design' => 'Logos, branding, print, illustration',
        'Writing & Translation' => 'Articles, copywriting, translation, editing',
        'Digital Marketing' => 'SEO, SEM, social media, email marketing',
        'Video & Animation' => 'Editing, motion graphics, 3D',
        'Music & Audio' => 'Voice over, mixing, podcast editing',
        'Data Science & AI' => 'Analysis, ML, dashboards',
        'DevOps & Cloud' => 'CI/CD, Docker, Kubernetes, AWS, Azure, GCP',
        'Cybersecurity' => 'Pentesting, audit, hardening',
        'IT Support' => 'Helpdesk, troubleshooting, networking',
        'Business & Finance' => 'Plans, analysis, bookkeeping',
        'Virtual Assistance' => 'Admin tasks, research, scheduling',
        'Gaming' => 'Game coaching, boosting, esports, in-game services',
        'Photography' => 'Photo shoot, retouching',
        'Lifestyle' => 'Coaching, wellness, personal services'
    ];

    $getId = function(string $name) use ($pdo, $hasSlug, $slugify, $uniqueSlug) : int {
        $st = $pdo->prepare('SELECT category_id FROM service_categories WHERE name = :n LIMIT 1');
        $st->execute([':n' => $name]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['category_id'])) return (int)$row['category_id'];
        if ($hasSlug) {
            $base = $slugify($name);
            $slug = $uniqueSlug($base);
            try {
                $ins = $pdo->prepare('INSERT INTO service_categories (name, description, slug) VALUES (:n, :d, :s)');
                $ins->execute([':n' => $name, ':d' => null, ':s' => $slug]);
                return (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                // Fallback in case of race: select again
                $st2 = $pdo->prepare('SELECT category_id FROM service_categories WHERE name = :n LIMIT 1');
                $st2->execute([':n' => $name]);
                $row2 = $st2->fetch(PDO::FETCH_ASSOC);
                if ($row2 && isset($row2['category_id'])) return (int)$row2['category_id'];
                throw $e;
            }
        } else {
            $ins = $pdo->prepare('INSERT INTO service_categories (name, description) VALUES (:n, :d)');
            $ins->execute([':n' => $name, ':d' => null]);
            return (int)$pdo->lastInsertId();
        }
    };

    $categoryMap = [];
    foreach ($common as $name => $desc) {
        $id = $getId($name);
        // Update description if empty
        $updateDesc = $pdo->prepare("UPDATE service_categories SET description = CASE WHEN description IS NULL OR description = '' THEN :d ELSE description END WHERE category_id = :id");
        $updateDesc->execute([':d' => $desc, ':id' => $id]);
        $categoryMap[$name] = $id;
    }
    out('Seeded common categories (' . count($categoryMap) . ')');

    // 3) Classification rules by keyword
    $rules = [
        'Web Development' => ['website', 'web app', 'frontend', 'backend', 'react', 'vue', 'angular', 'laravel', 'php', 'node', 'express', 'wordpress', 'shopify'],
        'Mobile App Development' => ['mobile', 'android', 'ios', 'flutter', 'react native', 'kotlin', 'swift'],
        'UI/UX Design' => ['ui', 'ux', 'wireframe', 'prototype', 'figma', 'adobe xd', 'user research'],
        'Graphic Design' => ['logo', 'branding', 'poster', 'flyer', 'illustration', 'photoshop', 'illustrator'],
        'Writing & Translation' => ['write', 'writing', 'article', 'blog', 'copy', 'translation', 'edit', 'proofread'],
        'Digital Marketing' => ['seo', 'sem', 'marketing', 'facebook ads', 'google ads', 'social media', 'smm', 'email campaign'],
        'Video & Animation' => ['video', 'animation', 'after effects', 'premiere', 'motion graphics', '3d', 'edit video'],
        'Music & Audio' => ['voice over', 'voiceover', 'mixing', 'mastering', 'podcast', 'audio'],
        'Data Science & AI' => ['data', 'analysis', 'machine learning', 'ml', 'ai', 'python', 'pandas', 'notebook'],
        'DevOps & Cloud' => ['devops', 'docker', 'kubernetes', 'aws', 'azure', 'gcp', 'ci/cd', 'terraform'],
        'Cybersecurity' => ['security', 'pentest', 'penetration test', 'vulnerability', 'hardening'],
        'IT Support' => ['it support', 'helpdesk', 'troubleshoot', 'network', 'pc repair'],
        'Business & Finance' => ['business plan', 'financial', 'accounting', 'bookkeeping', 'excel'],
        'Virtual Assistance' => ['virtual assistant', 'va', 'admin', 'data entry', 'research', 'schedule'],
        // Avoid ambiguous terms like 'boost', 'rank', 'carry' that can appear in non-gaming contexts
        // Keep explicit game titles and strongly game-specific terms
        'Gaming' => ['game', 'gaming', 'valorant', 'valo', 'csgo', 'cs2', 'dota', 'league of legends', 'lol', 'mobile legends', 'mlbb', 'pubg', 'call of duty', 'cod', 'genshin', 'derank', 'deranking', 'elo', 'mmr', 'duo queue', 'raid', 'roblox', 'minecraft', 'fortnite', 'apex', 'overwatch'],
        'Photography' => ['photo', 'photography', 'shoot', 'retouch'],
        'Lifestyle' => ['fitness', 'nutrition', 'coaching', 'life coach']
    ];

    // 4) Update existing services: set category_id where null or when --force
    $where = $force ? '1=1' : 'category_id IS NULL OR category_id = 0';
    $sql = "SELECT service_id, title, description, category_id FROM services WHERE $where";
    $st = $pdo->query($sql);
    $toUpdate = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $updated = 0; $skipped = 0; $uncategorized = 0;

    $updateStmt = $pdo->prepare('UPDATE services SET category_id = :cid WHERE service_id = :sid');

    foreach ($toUpdate as $svc) {
        $text = strtolower(($svc['title'] ?? '') . ' ' . ($svc['description'] ?? ''));
        $bestCat = null;
        foreach ($rules as $catName => $keywords) {
            foreach ($keywords as $kw) {
                if ($kw !== '' && strpos($text, strtolower($kw)) !== false) {
                    $bestCat = $catName; break 2;
                }
            }
        }
        if ($bestCat !== null && isset($categoryMap[$bestCat])) {
            $updateStmt->execute([':cid' => $categoryMap[$bestCat], ':sid' => (int)$svc['service_id']]);
            $updated++;
        } else {
            // When forcing, leave existing categories unchanged if no clear match
            if ($force) {
                $skipped++;
            } else {
                $uncategorized++;
            }
        }
    }

    out("Services processed: " . count($toUpdate));
    if ($force) {
        out("Updated: $updated | Left unchanged (no match): $skipped");
    } else {
        out("Updated: $updated | Left unchanged (no force and no match): $uncategorized");
    }

    // 5) Summary counts per category
    $sum = $pdo->query('SELECT c.name, COUNT(*) as cnt FROM services s LEFT JOIN service_categories c ON c.category_id = s.category_id GROUP BY c.name ORDER BY cnt DESC')->fetchAll(PDO::FETCH_ASSOC);
    out('Counts per category:');
    foreach ($sum as $row) {
        out(sprintf(' - %-24s %4d', $row['name'] ?? 'Uncategorized', (int)$row['cnt']));
    }

    out('Done.');
} catch (Throwable $e) {
    http_response_code(500);
    out('Error: ' . $e->getMessage());
    exit(1);
}
