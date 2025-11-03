<?php
require_once balp_api_path('auth_helpers.php');
require_once balp_api_path('jwt_helper.php');
require_once balp_project_root() . '/helpers.php';
balp_include_module_include('naterove_hmoty', 'helpers');
balp_include_module_include('nh_vyroba', 'helpers');
require_once balp_api_path('pdf_helpers.php');

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

    $row = nh_vyr_normalize_header_row($pdo, $row);
    $cisloVp = $row['cislo_vp'] ?? '';
    $datum = isset($row['datum_vyroby']) && $row['datum_vyroby'] ? substr((string)$row['datum_vyroby'], 0, 10) : '';
    $vyrobit = $row['vyrobit_g'] ?? '';
    if (is_float($vyrobit) || is_int($vyrobit)) {
        $vyrobitFormatted = number_format((float)$vyrobit, 3, ',', ' ');
        $vyrobit = rtrim(rtrim($vyrobitFormatted, '0'), ',');
    }

    $lines = [];
    $lines[] = 'Výrobní příkaz NH';
    $lines[] = 'ID: ' . ($row['id'] ?? '');
    $lines[] = 'Číslo VP: ' . $cisloVp;
    $lines[] = 'Datum výroby: ' . $datum;
    $lines[] = 'Číslo NH: ' . ($row['cislo_nh'] ?? '');
    $lines[] = 'Název NH: ' . ($row['nazev_nh'] ?? '');
    $lines[] = 'Vyrobit (g): ' . ($vyrobit !== '' ? $vyrobit : '');
    $lines[] = 'Poznámka: ' . ($row['poznamka'] ?? '');

    $recipe = $detail['rows'] ?? [];
    $lines[] = '';
    $lines[] = 'Receptura:';
    if ($recipe) {
        foreach ($recipe as $item) {
            $qty = $item['mnozstvi'] ?? '';
            if (is_float($qty) || is_int($qty)) {
                $qtyFormatted = number_format((float)$qty, 3, ',', ' ');
                $qty = rtrim(rtrim($qtyFormatted, '0'), ',');
            }
            $nav = $item['navazit'] ?? '';
            if (is_float($nav) || is_int($nav)) {
                $navFormatted = number_format((float)$nav, 3, ',', ' ');
                $nav = rtrim(rtrim($navFormatted, '0'), ',');
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
            $amountParts = [];
            if ($qty !== '') {
                $amountParts[] = $qty . ' g/kg';
            }
            if ($nav !== '') {
                $amountParts[] = $nav . ' g';
            }
            if ($amountParts) {
                $label .= ': ' . implode(', ', $amountParts);
            }
            $lines[] = '  ' . $label;
        }
    } else {
        $lines[] = '  — žádné položky —';
    }

    $tests = $detail['zkousky'] ?? [];
    $lines[] = '';
    $lines[] = 'Laboratorní zkoušky:';
    if ($tests) {
        foreach ($tests as $test) {
            $date = $test['datum'] ?? '';
            $type = $test['typ'] ?? '';
            $resultText = $test['vysledek'] ?? '';
            $labelParts = [];
            if ($date !== '') {
                $labelParts[] = $date;
            }
            if ($type !== '') {
                $labelParts[] = $type;
            }
            $line = '  ' . ($labelParts ? implode(' – ', $labelParts) : 'Parametr');
            if ($resultText !== '') {
                $line .= ': ' . $resultText;
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
