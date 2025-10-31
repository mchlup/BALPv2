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
    'ui' => [
        'tabs' => [
            [
                'slug' => 'nh',
                'label' => 'NH – Nátěrové hmoty',
                'order' => 30,
                'view' => 'modules/naterove_hmoty/list.html',
                'tab_id' => 'tab-nh',
                'pane_id' => 'pane-nh',
            ],
            [
                'slug' => 'nh-vyroba',
                'label' => 'Výrobní příkazy NH',
                'order' => 40,
                'view' => 'modules/naterove_hmoty/vyroba.html',
                'tab_id' => 'tab-nh-vyr',
                'pane_id' => 'pane-nh-vyr',
            ],
        ],
        'assets' => [
            'js' => [
                'js/nh.controller.js',
                'js/nh.vyr.controller.js',
            ],
        ],
    ],
];
