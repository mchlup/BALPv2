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

if (!function_exists('balp_table_get_columns')) {
    function balp_table_get_columns(PDO $pdo, string $table, bool $forceReload = false): array
    {
        static $cache = [];
        $key = strtolower($table);
        if ($forceReload) {
            unset($cache[$key]);
        }
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $columns = [];
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM ' . sql_quote_ident($table));
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($row['Field'])) {
                        $columns[strtolower((string)$row['Field'])] = $row;
                    }
                }
            }
        } catch (Throwable $ignored) {
            $columns = [];
        }

        $cache[$key] = $columns;
        return $columns;
    }
}

if (!function_exists('balp_nh_get_columns')) {
    function balp_nh_get_columns(PDO $pdo, bool $forceReload = false): array
    {
        return balp_table_get_columns($pdo, balp_nh_table_name(), $forceReload);
    }
}

if (!function_exists('balp_nh_has_column')) {
    function balp_nh_has_column(PDO $pdo, string $column): bool
    {
        $columns = balp_nh_get_columns($pdo);
        return isset($columns[strtolower($column)]);
    }
}

if (!function_exists('balp_ensure_nh_column')) {
    function balp_ensure_nh_column(PDO $pdo, string $column, string $definition): void
    {
        if (balp_nh_has_column($pdo, $column)) {
            return;
        }

        $tableQuoted = sql_quote_ident(balp_nh_table_name());
        try {
            $sql = 'ALTER TABLE ' . $tableQuoted . ' ADD COLUMN ' . $definition;
            $pdo->exec($sql);
            balp_nh_get_columns($pdo, true);
        } catch (Throwable $ignored) {
            // Pokud se sloupec nepodaří přidat, pokračujeme bez chyby.
        }
    }
}

if (!function_exists('balp_nh_vp_expression')) {
    function balp_nh_vp_expression(PDO $pdo, string $alias = 'nh'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            $alias = 'nh';
        }

        if (balp_nh_has_column($pdo, 'cislo_vp')) {
            return $alias . '.' . sql_quote_ident('cislo_vp');
        }

        static $template = null;
        if ($template === null) {
            $columns = balp_table_get_columns($pdo, 'balp_nhods_vyr');
            $idColumn = null;
            foreach (['idnhods', 'id_nhods', 'idnh'] as $candidate) {
                if (isset($columns[strtolower($candidate)])) {
                    $idColumn = $candidate;
                    break;
                }
            }
            $vpColumn = null;
            foreach (['cislo_vp', 'cislo_vyr', 'cislo'] as $candidate) {
                if (isset($columns[strtolower($candidate)])) {
                    $vpColumn = $candidate;
                    break;
                }
            }

            if ($idColumn && $vpColumn) {
                $orderParts = [];
                if (isset($columns['dtdo'])) {
                    $dateTo = 'vyr.' . sql_quote_ident('dtdo');
                    $orderParts[] = "CASE WHEN ($dateTo IS NULL OR $dateTo > NOW()) THEN 0 ELSE 1 END";
                    $orderParts[] = "COALESCE($dateTo, '9999-12-31')";
                }
                if (isset($columns['dtod'])) {
                    $dateFrom = 'vyr.' . sql_quote_ident('dtod');
                    $orderParts[] = "COALESCE($dateFrom, '0000-01-01')";
                }
                if (isset($columns['id'])) {
                    $orderParts[] = 'vyr.' . sql_quote_ident('id') . ' DESC';
                }
                $orderSql = $orderParts ? (' ORDER BY ' . implode(', ', $orderParts)) : '';

                $template = sprintf(
                    '(SELECT %1$s FROM %2$s AS vyr WHERE %3$s = {alias}.%4$s%5$s LIMIT 1)',
                    'vyr.' . sql_quote_ident($vpColumn),
                    sql_quote_ident('balp_nhods_vyr'),
                    'vyr.' . sql_quote_ident($idColumn),
                    sql_quote_ident('id'),
                    $orderSql
                );
            } else {
                $template = 'NULL';
            }
        }

        if ($template === null) {
            return 'NULL';
        }

        return str_replace('{alias}', $alias, $template);
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
            balp_ensure_nh_column($pdo, 'cislo_vp', '`cislo_vp` varchar(64) DEFAULT NULL AFTER `cislo`');
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
  `cislo_vp` varchar(64) DEFAULT NULL,
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
        balp_nh_get_columns($pdo, true);
    }
}
