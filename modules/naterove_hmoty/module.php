<?php
return [
    'slug' => 'naterove_hmoty',
    'name' => 'NH – Nátěrové hmoty',
    'description' => 'Evidence nátěrových hmot a jejich výrobních kroků.',
    'api' => [
        'list' => __DIR__ . '/api/list.php',
        'get' => __DIR__ . '/api/get.php',
        'upsert' => __DIR__ . '/api/upsert.php',
        'delete' => __DIR__ . '/api/delete.php',
        'vyroba.list' => __DIR__ . '/api/vyroba/list.php',
        'vyroba.get' => __DIR__ . '/api/vyroba/get.php',
        'vyroba.export_csv' => __DIR__ . '/api/vyroba/export_csv.php',
        'vyroba.export_pdf' => __DIR__ . '/api/vyroba/export_pdf.php',
        'vyroba.detail_export_csv' => __DIR__ . '/api/vyroba/detail_export_csv.php',
        'vyroba.detail_export_pdf' => __DIR__ . '/api/vyroba/detail_export_pdf.php',
    ],
    'includes' => [
        'helpers' => __DIR__ . '/includes/helpers.php',
        'vyroba_helpers' => __DIR__ . '/includes/vyroba_helpers.php',
    ],
    'assets' => [
        'js' => [
            'public/js/nh.controller.js',
            'public/js/nh.vyr.controller.js',
        ],
    ],
];
