<?php
require_once __DIR__ . '/../helpers.php';

if (!function_exists('balp_nh_table_exists')) {
    function balp_nh_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1'
            );
            $stmt->execute([':table' => $table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            try {
                $pdo->query("SELECT 1 FROM " . sql_quote_ident($table) . " LIMIT 0");
                return true;
            } catch (Throwable $ignored) {
                return false;
            }
        }
    }
}

if (!function_exists('balp_nh_table_name')) {
    function balp_nh_table_name(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $config = cfg();
        $candidates = [];

        $configured = $config['tables']['nh'] ?? null;
        if (is_string($configured)) {
            $configured = trim($configured);
            if ($configured !== '') {
                $candidates[] = $configured;
            }
        }

        // prefer known legacy table names
        foreach (['balp_nhods', 'balp_nh'] as $candidate) {
            if (!in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        $pdo = null;
        try {
            $pdo = db();
        } catch (Throwable $ignored) {
            $pdo = null;
        }

        if ($pdo instanceof PDO) {
            foreach ($candidates as $candidate) {
                if (balp_nh_table_exists($pdo, $candidate)) {
                    return $resolved = $candidate;
                }
            }
        }

        // fallback to the first candidate if we could not verify existence
        return $resolved = ($candidates[0] ?? 'balp_nh');
    }
}

if (!function_exists('balp_ensure_nh_table')) {
    function balp_ensure_nh_table(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        $table = balp_nh_table_name();
        $tableQuoted = sql_quote_ident($table);

        if (balp_nh_table_exists($pdo, $table)) {
            return;
        }

        $config = cfg();
        $charset = 'utf8mb4';
        $collation = 'utf8mb4_czech_ci';
        if (isset($config['db']) && is_array($config['db'])) {
            if (!empty($config['db']['charset']) && is_string($config['db']['charset'])) {
                $candidate = preg_replace('/[^A-Za-z0-9_]/', '', $config['db']['charset']);
                if ($candidate !== '') {
                    $charset = $candidate;
                }
            }
            if (!empty($config['db']['collation']) && is_string($config['db']['collation'])) {
                $candidate = preg_replace('/[^A-Za-z0-9_]/', '', $config['db']['collation']);
                if ($candidate !== '') {
                    $collation = $candidate;
                }
            }
        }

        $indexName = 'uniq_' . preg_replace('/[^A-Za-z0-9_]/', '_', $table) . '_cislo';
        $createSql = sprintf(
            'CREATE TABLE %s (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cislo` varchar(32) NOT NULL,
  `nazev` varchar(255) NOT NULL,
  `pozn` text NULL,
  `dtod` datetime NOT NULL DEFAULT \'' . "1970-01-01 00:00:00" . '\',
  `dtdo` datetime NOT NULL DEFAULT \'' . "9999-12-31 23:59:59" . '\',
  PRIMARY KEY (`id`),
  UNIQUE KEY `%s` (`cislo`)
) ENGINE=InnoDB DEFAULT CHARSET=%s COLLATE=%s',
            $tableQuoted,
            $indexName,
            $charset,
            $collation
        );

        $pdo->exec($createSql);
    }
}
