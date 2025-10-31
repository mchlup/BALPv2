<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');
balp_include_module_include('naterove_hmoty', 'vyroba_helpers');
require_once __DIR__ . '/pdf_helpers.php';

header('Content-Type: application/pdf');
$filename = 'balp_vyrobni_prikaz_' . date('Ymd_His') . '.pdf';
header('Content-Disposition: attachment; filename="' . $filename . '"');

try {
    $config = cfg();
    $authConf = $config['auth'] ?? [];
    if (!($authConf['enabled'] ?? false)) {
        http_response_code(403);
        echo balp_simple_pdf(['Přístup byl odepřen.'], 'Chyba');
        exit;
    }

    $token = balp_get_bearer_token();
    if (!$token) {
        http_response_code(401);
        echo balp_simple_pdf(['Chybí token.'], 'Chyba');
        exit;
    }

    jwt_decode($token, $authConf['jwt_secret'] ?? 'change', true);

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo balp_simple_pdf(['Chybí parametr ID.'], 'Chyba');
        exit;
    }

    $pdo = db();

    $detail = nh_vyr_fetch_detail($pdo, $id);
    $row = $detail['item'] ?? null;
    if (!$row) {
        http_response_code(404);
        echo balp_simple_pdf(['Záznam nebyl nalezen.'], 'Chyba');
        exit;
    }

    $cisloVp = nh_vyr_format_vp($row['cislo_vp'] ?? null) ?? ($row['cislo_vp'] ?? '');
    $datum = isset($row['datum_vyroby']) && $row['datum_vyroby'] ? substr((string)$row['datum_vyroby'], 0, 10) : '';

    $lines = [];
    $lines[] = 'Výrobní příkaz NH';
    $lines[] = 'ID: ' . ($row['id'] ?? '');
    $lines[] = 'Číslo VP: ' . $cisloVp;
    $lines[] = 'Datum výroby: ' . $datum;
    $lines[] = 'Číslo NH: ' . ($row['cislo_nh'] ?? '');
    $lines[] = 'Název NH: ' . ($row['nazev_nh'] ?? '');
    $lines[] = 'Vyrobit (g): ' . ($row['vyrobit_g'] ?? '');
    $lines[] = 'Poznámka: ' . ($row['poznamka'] ?? '');

    $recipe = $detail['rows'] ?? [];
    $lines[] = '';
    $lines[] = 'Receptura:';
    if ($recipe) {
        foreach ($recipe as $item) {
            $qty = $item['mnozstvi'] ?? '';
            if (is_numeric($qty)) {
                $qty = (string)+$qty;
            }
            $type = $item['typ'] ?? '';
            $code = trim((string)($item['cislo'] ?? ''));
            $name = trim((string)($item['nazev'] ?? ''));
            $parts = [];
            if ($type !== '') {
                $parts[] = $type;
            }
            if ($code !== '') {
                $parts[] = $code;
            }
            $label = $parts ? implode(' ', $parts) : 'Položka';
            if ($name !== '') {
                $label .= ' – ' . $name;
            }
            $lines[] = '  ' . $label . ($qty !== '' ? ': ' . $qty . ' g/kg' : '');
        }
    } else {
        $lines[] = '  — žádné položky —';
    }

    $tests = $detail['zkousky'] ?? [];
    $lines[] = '';
    $lines[] = 'Laboratorní zkoušky:';
    if ($tests) {
        foreach ($tests as $test) {
            $name = $test['nazev'] ?? '';
            $value = $test['hodnota'] ?? '';
            $unit = $test['jednotka'] ?? '';
            $note = $test['poznamka'] ?? '';
            $line = '  ' . ($name !== '' ? $name : 'Parametr');
            if ($value !== '') {
                $line .= ': ' . $value;
                if ($unit !== '') {
                    $line .= ' ' . $unit;
                }
            }
            if ($note !== '') {
                $line .= ' (' . $note . ')';
            }
            $lines[] = $line;
        }
    } else {
        $lines[] = '  — žádné záznamy —';
    }

    echo balp_simple_pdf($lines, 'Výrobní příkaz ' . $cisloVp);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo balp_simple_pdf(['Došlo k chybě:', $e->getMessage()], 'Chyba');
    exit;
}
