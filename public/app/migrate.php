<?php
// Applies database updates in app/migrations/NNN_*.sql that haven't been applied yet, in order,
// each once. Runs on every admin page load (cheap when there's nothing to do), so a deploy
// followed by opening the admin updates the live database. schema.sql records the versions it
// already contains, so a freshly built database skips them.

function apply_migrations(PDO $db): array
{
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $done = array_flip($db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    $files = glob(__DIR__ . '/migrations/[0-9][0-9][0-9]_*.sql') ?: [];
    sort($files);
    $applied = [];
    foreach ($files as $file) {
        $version = substr(basename($file), 0, 3);
        if (isset($done[$version])) {
            continue;
        }
        $db->beginTransaction();
        try {
            $db->exec(file_get_contents($file));
            $db->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $db->commit();
            $applied[] = basename($file);
        } catch (Throwable $e) {
            $db->rollBack();
            throw new RuntimeException('Database update ' . basename($file) . ' failed: ' . $e->getMessage());
        }
    }
    return $applied;
}
