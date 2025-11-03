<?php
return [
    'slug' => 'databaze',
    'name' => 'Databáze',
    'description' => 'Výchozí modul pro správu připojení a údržbu databáze.',
    'assets' => [
        'js' => [
            'js/databaze.controller.js',
        ],
    ],
    'ui' => [
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
