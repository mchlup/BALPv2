<?php
function balp_simple_pdf(array $lines, string $title = 'Report'): string
{
    $textLines = [];
    foreach ($lines as $line) {
        if ($line === null) {
            $textLines[] = '';
        } else {
            $textLines[] = (string)$line;
        }
    }

    $escape = static function (string $text): string {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $text = preg_replace('/[\r\n]+/', ' ', $text) ?? $text;
        return $text;
    };

    $content = "BT\n/F1 12 Tf\n14 TL\n72 800 Td\n";
    foreach ($textLines as $line) {
        $content .= '(' . $escape($line) . ") Tj\nT*\n";
    }
    $content .= "ET";
    $length = strlen($content);

    $objects = [];
    $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj";
    $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj";
    $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj";
    $objects[] = "4 0 obj << /Length $length >> stream\n$content\nendstream endobj";
    $objects[] = "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj";

    $titleText = $escape($title);
    $date = gmdate('YmdHis');
    $dateString = 'D:' . $date . 'Z';
    $objects[] = "6 0 obj << /Title ($titleText) /Creator (BALP v2) /Producer (BALP v2) /CreationDate ($dateString) >> endobj";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object . "\n";
    }

    $xrefPos = strlen($pdf);
    $count = count($offsets);
    $pdf .= "xref\n0 $count\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer << /Size $count /Root 1 0 R /Info 6 0 R >>\n";
    $pdf .= "startxref\n$xrefPos\n%%EOF\n";

    return $pdf;
}
