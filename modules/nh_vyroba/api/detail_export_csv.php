<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');
balp_include_module_include('nh_vyroba', 'helpers');

header('Content-Type: text/csv; charset=utf-8');
$filename = 'balp_vyrobni_prikaz_' . date('Ymd_His') . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

try {
    $config = cfg();
    $authConf = $config['auth'] ?? [];
    if (!($authConf['enabled'] ?? false)) {
        http_response_code(403);
        echo 'error: auth disabled';
        exit;
    }

    $token = balp_get_bearer_token();
    if (!$token) {
        http_response_code(401);
        echo 'error: missing token';
        exit;
    }

    jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo 'error: missing id';
        exit;
    }

    $pdo = db();

    $detail = nh_vyr_fetch_detail($pdo, $id);
    $row = $detail['item'] ?? null;
    if (!$row) {
        http_response_code(404);
        echo 'error: not found';
        exit;
    }

    $row = nh_vyr_normalize_header_row($pdo, $row);
    $cisloVp = $row['cislo_vp'] ?? '';
    $datum = isset($row['datum_vyroby']) && $row['datum_vyroby'] ? substr((string)$row['datum_vyroby'], 0, 10) : '';
    $vyrobit = $row['vyrobit_g'] ?? '';
    if (is_float($vyrobit) || is_int($vyrobit)) {
        $vyrobit = number_format((float)$vyrobit, 3, ',', ' ');
        $vyrobit = rtrim(rtrim($vyrobit, '0'), ',');
    }

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Číslo VP', 'Datum výroby', 'Číslo NH', 'Název NH', 'Vyrobit (g)', 'Poznámka'], ';');
    fputcsv($out, [
        $row['id'] ?? '',
        $cisloVp,
        $datum,
        $row['cislo_nh'] ?? '',
        $row['nazev_nh'] ?? '',
        $vyrobit,
        $row['poznamka'] ?? '',
    ], ';');

    $lines = $detail['rows'] ?? [];
    fputcsv($out, [], ';');
    fputcsv($out, ['Receptura'], ';');
    if ($lines) {
        fputcsv($out, ['ID položky', 'Typ', 'Kód', 'Název', 'Množství (g/kg)', 'Navážit (g)'], ';');
        $formatNumber = static function ($value) {
            if (is_float($value) || is_int($value)) {
                $formatted = number_format((float)$value, 3, ',', ' ');
                return rtrim(rtrim($formatted, '0'), ',');
            }
            return $value;
        };
        foreach ($lines as $line) {
            $qty = $formatNumber($line['mnozstvi'] ?? '');
            $nav = $formatNumber($line['navazit'] ?? '');
            fputcsv($out, [
                $line['id'] ?? '',
                $line['typ'] ?? '',
                $line['cislo'] ?? '',
                $line['nazev'] ?? '',
                $qty,
                $nav,
            ], ';');
        }
    } else {
        fputcsv($out, ['— žádné položky —'], ';');
    }

    $tests = $detail['zkousky'] ?? [];
    fputcsv($out, [], ';');
    fputcsv($out, ['Laboratorní zkoušky'], ';');
    if ($tests) {
        fputcsv($out, ['Datum', 'Typ', 'Výsledek'], ';');
        foreach ($tests as $test) {
            fputcsv($out, [
                $test['datum'] ?? '',
                $test['typ'] ?? '',
                $test['vysledek'] ?? '',
            ], ';');
        }
    } else {
        fputcsv($out, ['— žádné zkoušky —'], ';');
    }
    fclose($out);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'error: ' . $e->getMessage();
    exit;
}
