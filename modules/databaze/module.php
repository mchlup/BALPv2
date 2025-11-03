<?php
return [
    'slug' => 'databaze',
    'name' => 'Databáze',
    'description' => 'Výchozí modul pro správu připojení a údržbu databáze.',
    'order' => 20,
    'assets' => [
        'js' => [
            'js/databaze.controller.js',
        ],
    ],
    'ui' => [
        'icon' => 'icons/analytics.svg',
        'tabs' => [
            [
                'slug' => 'databaze',
                'label' => 'Databáze',
                'order' => 6,
                'view' => 'modules/databaze/index.html',
                'tab_id' => 'tab-databaze',
                'pane_id' => 'pane-databaze',
            ],
        ],
    ],
];
