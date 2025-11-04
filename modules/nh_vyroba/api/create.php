<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');
balp_include_module_include('nh_vyroba', 'helpers');
balp_include_module_include('vzornik_ral', 'helpers');

header('Content-Type: application/json; charset=utf-8');

try {
    $config = cfg();
    $authConf = $config['auth'] ?? [];
    if (!($authConf['enabled'] ?? false)) {
        respond_json(['error' => 'Auth disabled'], 403);
    }

    $token = balp_get_bearer_token();
    if (!$token) {
        respond_json(['error' => 'missing token'], 401);
    }

    jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);

    $pdo = db();

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);
    if (!is_array($payload)) {
        respond_json(['error' => 'Invalid JSON body'], 400);
    }

    $shadeId = (int)($payload['shade_id'] ?? $payload['idnhods'] ?? 0);
    $nhId = (int)($payload['nh_id'] ?? ($payload['idnh'] ?? 0));
    $shade = null;
    if ($shadeId > 0) {
        $shade = nh_vyr_fetch_shade($pdo, $shadeId);
    }
    if (!$shade && $nhId > 0) {
        $shade = nh_vyr_fetch_shade_by_nh_id($pdo, $nhId);
        if ($shade) {
            $shadeId = (int)($shade['id'] ?? 0);
        }
    }
    if (!$shade) {
        $shade = nh_vyr_lookup_shade(
            $pdo,
            $payload['cislo_nh'] ?? null,
            $payload['cislo_ods'] ?? null,
            $payload['cislo'] ?? ($payload['code'] ?? null)
        );
        if ($shade) {
            $shadeId = (int)$shade['id'];
        }
    }

    if ($shadeId <= 0 || !$shade) {
        respond_json(['error' => 'Odstín NH nebyl nalezen.'], 400);
    }

    $vpDigits = nh_vyr_normalize_vp_digits($payload['cislo_vp_digits'] ?? ($payload['cislo_vp'] ?? null));
    if ($vpDigits === null) {
        respond_json(['error' => 'Neplatné číslo výrobního příkazu.'], 400);
    }
    $vpFormatted = nh_vyr_format_vp($vpDigits);
    if ($vpFormatted === null) {
        respond_json(['error' => 'Neplatné číslo výrobního příkazu.'], 400);
    }

    $dateRaw = $payload['datum_vyroby'] ?? ($payload['datum'] ?? null);
    if (is_string($dateRaw)) {
        $dateRaw = trim($dateRaw);
        if ($dateRaw === '') {
            $dateRaw = null;
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
            respond_json(['error' => 'Datum výroby musí být ve formátu YYYY-MM-DD.'], 400);
        }
    } else {
        $dateRaw = null;
    }

    $qtyRaw = $payload['vyrobit_g'] ?? ($payload['vyrobit'] ?? ($payload['mnozstvi'] ?? null));
    $qtyValue = nh_vyr_normalize_numeric($qtyRaw, 3);
    if ($qtyValue !== null && !is_numeric($qtyValue)) {
        respond_json(['error' => 'Množství musí být číslo.'], 400);
    }
    $qtyValue = $qtyValue !== null ? (float)$qtyValue : null;

    $note = $payload['poznamka'] ?? ($payload['pozn'] ?? null);
    if (is_string($note)) {
        $note = trim($note);
        if ($note === '') {
            $note = null;
        }
    } else {
        $note = null;
    }

    $ralId = null;
    $ralInput = $payload['ral_id'] ?? ($payload['idral'] ?? null);
    if ($ralInput !== null && $ralInput !== '') {
        if (is_numeric($ralInput)) {
            $ralId = (int)$ralInput;
        } elseif (is_string($ralInput)) {
            $filtered = trim($ralInput);
            if ($filtered !== '' && is_numeric($filtered)) {
                $ralId = (int)$filtered;
            }
        }
    }
    $ralCodeInput = $payload['ral_cislo'] ?? ($payload['ral_code'] ?? null);
    $ralCodeInput = is_string($ralCodeInput) ? trim($ralCodeInput) : '';
    if ($ralId !== null && $ralId > 0) {
        $ralRow = balp_ral_fetch($pdo, $ralId);
        if (!$ralRow) {
            respond_json(['error' => 'Zvolený odstín RAL nebyl nalezen.'], 400);
        }
    } elseif ($ralCodeInput !== '') {
        $ralRow = balp_ral_lookup($pdo, $ralCodeInput);
        if ($ralRow) {
            $ralId = isset($ralRow['id']) ? (int)$ralRow['id'] : null;
        } else {
            respond_json(['error' => 'Zadaný odstín RAL nebyl nalezen.'], 400);
        }
    }

    $table = sql_quote_ident(nh_vyr_table_name());
    $columns = [];
    $placeholders = [];
    $params = [];

    $vpColumn = nh_vyr_vp_column($pdo);
    $columns[] = sql_quote_ident($vpColumn);
    $placeholders[] = ':vp';
    $params[':vp'] = $vpFormatted;

    $shadeColumn = nh_vyr_vyr_nh_fk($pdo);
    $columns[] = sql_quote_ident($shadeColumn);
    $placeholders[] = ':shade';
    $params[':shade'] = $shadeId;

    $dateColumn = nh_vyr_date_column($pdo);
    if ($dateColumn && $dateRaw !== null) {
        $columns[] = sql_quote_ident($dateColumn);
        $placeholders[] = ':datum';
        $params[':datum'] = $dateRaw;
    }

    $qtyColumn = nh_vyr_qty_column($pdo);
    if ($qtyColumn && $qtyValue !== null) {
        $columns[] = sql_quote_ident($qtyColumn);
        $placeholders[] = ':qty';
        $params[':qty'] = $qtyValue;
    }

    $noteColumn = nh_vyr_note_column($pdo);
    if ($noteColumn && $note !== null) {
        $columns[] = sql_quote_ident($noteColumn);
        $placeholders[] = ':note';
        $params[':note'] = $note;
    }

    $ralColumn = nh_vyr_ral_fk($pdo);
    if ($ralColumn && $ralId !== null && $ralId > 0) {
        $columns[] = sql_quote_ident($ralColumn);
        $placeholders[] = ':ral';
        $params[':ral'] = $ralId;
    }

    if (!$columns) {
        respond_json(['error' => 'Nelze vytvořit záznam.'], 500);
    }

    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    $newId = (int)$pdo->lastInsertId();

    $detail = nh_vyr_fetch_detail($pdo, $newId);
    $response = ['id' => $newId];
    if (isset($detail['item'])) {
        $response['item'] = $detail['item'];
    }

    http_response_code(201);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
