<?php
require_once __DIR__ . '/../helpers.php';

if (!function_exists('balp_nh_table_name')) {
    function balp_nh_table_name(): string
    {
        $config = cfg();
        $table = $config['tables']['nh'] ?? 'balp_nh';
        if (!is_string($table) || $table === '') {
            $table = 'balp_nh';
        }
        return $table;
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

        $exists = false;
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1'
            );
            $stmt->execute([':table' => $table]);
            $exists = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            try {
                $pdo->query("SELECT 1 FROM {$tableQuoted} LIMIT 0");
                $exists = true;
            } catch (Throwable $ignored) {
                $exists = false;
            }
        }

        if ($exists) {
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
