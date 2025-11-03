<?php
return [
    'slug' => 'nh_vyroba',
    'name' => 'Výrobní příkaz – NH',
    'description' => 'Evidence výrobních příkazů pro nátěrové hmoty.',
    'api' => [
        'list' => __DIR__ . '/api/list.php',
        'get' => __DIR__ . '/api/get.php',
        'export_csv' => __DIR__ . '/api/export_csv.php',
        'export_pdf' => __DIR__ . '/api/export_pdf.php',
        'detail_export_csv' => __DIR__ . '/api/detail_export_csv.php',
        'detail_export_pdf' => __DIR__ . '/api/detail_export_pdf.php',
        'create' => __DIR__ . '/api/create.php',
        'nh' => __DIR__ . '/api/nh.php',
        'next_vp' => __DIR__ . '/api/next_vp.php',
        'shades' => __DIR__ . '/api/shades.php',
    ],
    'includes' => [
        'helpers' => __DIR__ . '/includes/helpers.php',
    ],
    'ui' => [
        'tabs' => [
            [
                'slug' => 'nh-vyroba',
                'label' => 'Výrobní příkazy NH',
                'order' => 40,
                'view' => 'modules/nh_vyroba/tab.html',
                'tab_id' => 'tab-nh-vyr',
                'pane_id' => 'pane-nh-vyr',
            ],
        ],
        'assets' => [
            'js' => [
                'js/nh.vyr.controller.js',
            ],
        ],
    ],
];
