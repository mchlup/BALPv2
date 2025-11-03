<?php
return [
    'slug' => 'nastaveni',
    'name' => 'Nastavení',
    'description' => 'Výchozí modul pro správu konfigurace aplikace.',
    'assets' => [
        'js' => [
            'js/nastaveni.controller.js',
        ],
    ],
    'ui' => [
        'tabs' => [
            [
                'slug' => 'nastaveni',
                'label' => 'Nastavení',
                'order' => 5,
                'view' => 'modules/nastaveni/index.html',
                'tab_id' => 'tab-nastaveni',
                'pane_id' => 'pane-nastaveni',
            ],
        ],
    ],
];
