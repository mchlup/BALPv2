<?php
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/nh_helpers.php';
require_once __DIR__ . '/nh_vyr_helpers.php';

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

    $cisloVp = nh_vyr_format_vp($row['cislo_vp'] ?? null) ?? ($row['cislo_vp'] ?? '');
    $datum = isset($row['datum_vyroby']) && $row['datum_vyroby'] ? substr((string)$row['datum_vyroby'], 0, 10) : '';

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Číslo VP', 'Datum výroby', 'Číslo NH', 'Název NH', 'Vyrobit (g)', 'Poznámka'], ';');
    fputcsv($out, [
        $row['id'] ?? '',
        $cisloVp,
        $datum,
        $row['cislo_nh'] ?? '',
        $row['nazev_nh'] ?? '',
        $row['vyrobit_g'] ?? '',
        $row['poznamka'] ?? '',
    ], ';');

    $lines = $detail['rows'] ?? [];
    fputcsv($out, [], ';');
    fputcsv($out, ['Receptura'], ';');
    if ($lines) {
        fputcsv($out, ['ID položky', 'Typ', 'Kód', 'Název', 'Množství (g/kg)'], ';');
        foreach ($lines as $line) {
            $qty = $line['mnozstvi'] ?? '';
            if (is_numeric($qty)) {
                $qty = (string)+$qty;
            }
            fputcsv($out, [
                $line['id'] ?? '',
                $line['typ'] ?? '',
                $line['cislo'] ?? '',
                $line['nazev'] ?? '',
                $qty,
            ], ';');
        }
    } else {
        fputcsv($out, ['— žádné položky —'], ';');
    }

    $tests = $detail['zkousky'] ?? [];
    fputcsv($out, [], ';');
    fputcsv($out, ['Laboratorní zkoušky'], ';');
    if ($tests) {
        fputcsv($out, ['Parametr', 'Hodnota', 'Jednotka', 'Poznámka'], ';');
        foreach ($tests as $test) {
            fputcsv($out, [
                $test['nazev'] ?? '',
                $test['hodnota'] ?? '',
                $test['jednotka'] ?? '',
                $test['poznamka'] ?? '',
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
