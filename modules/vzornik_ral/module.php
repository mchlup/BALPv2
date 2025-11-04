<?php
return [
    'slug' => 'vzornik_ral',
    'name' => 'Vzorník RAL',
    'description' => 'Přehled odstínů RAL pro technologii výroby.',
    'order' => 70,
    'api' => [
        'list' => __DIR__ . '/api/list.php',
    ],
    'includes' => [
        'helpers' => __DIR__ . '/includes/helpers.php',
    ],
    'assets' => [
        'css' => [
            'css/vzornik-ral.css',
        ],
        'js' => [
            'js/vzornik-ral.controller.js',
        ],
    ],
    'ui' => [
        'icon' => 'icons/palette.svg',
        'tabs' => [
            [
                'slug' => 'paleta',
                'label' => 'Paleta RAL',
                'order' => 10,
                'view' => 'modules/vzornik_ral/index.html',
                'tab_id' => 'tab-vzornik-ral',
                'pane_id' => 'pane-vzornik-ral',
            ],
        ],
    ],
];
