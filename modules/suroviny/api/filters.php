<?php
// Společné pomocné funkce pro filtry nad tabulkou balp_sur

declare(strict_types=1);

/**
 * Normalize column name for ORDER BY – fallback to 'nazev'.
 */
function sur_normalize_sort_column(?string $column): string {
    $allowed = ['id', 'cislo', 'nazev', 'sh', 'sus_sh', 'sus_hmot', 'okp', 'olej', 'dtod', 'dtdo'];
    $column = $column ? strtolower($column) : '';
    foreach ($allowed as $allow) {
        if ($column === strtolower($allow)) {
            return $allow;
        }
    }
    return 'nazev';
}

/**
 * Build WHERE clause for common filters (search, olej flag, validity date).
 * Returns SQL string and fills $params (by reference) with bound values.
 */
function sur_build_where(array $input, array &$params): string {
    $where = ['1'];

    $search = trim((string)($input['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(nazev LIKE :search OR cislo LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $olej = trim((string)($input['olej'] ?? ''));
    if ($olej === '1') {
        $where[] = '(olej IS NOT NULL AND olej <> 0)';
    } elseif ($olej === '0') {
        $where[] = '(olej IS NULL OR olej = 0)';
    }

    $platnost = trim((string)($input['platnost'] ?? ''));
    if ($platnost !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $platnost)) {
        $params[':platnost'] = $platnost;
        $where[] = '((dtod IS NULL OR dtod <= :platnost) AND (dtdo IS NULL OR dtdo >= :platnost))';
    }

    return implode(' AND ', $where);
}

/**
 * Bind prepared parameters (search, platnost...).
 */
function sur_bind_params($stmt, array $params): void {
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
}
