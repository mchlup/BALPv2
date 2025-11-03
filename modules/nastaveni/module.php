<?php
return [
    'slug' => 'nastaveni',
    'name' => 'Nastavení',
    'description' => 'Výchozí modul pro správu konfigurace aplikace.',
    'order' => 10,
    'assets' => [
        'js' => [
            'js/nastaveni.controller.js',
        ],
    ],
    'ui' => [
        'icon' => 'icons/settings.svg',
        'tabs' => [
            [
                'slug' => 'nastaveni',
                'label' => 'Nastavení',
                'order' => 10,
                'view' => 'modules/nastaveni/index.html',
                'tab_id' => 'tab-nastaveni',
                'pane_id' => 'pane-nastaveni',
            ],
            [
                'slug' => 'uzivatele',
                'label' => 'Uživatelé',
                'order' => 20,
                'view' => 'modules/nastaveni/users.html',
                'tab_id' => 'tab-uzivatele',
                'pane_id' => 'pane-uzivatele',
            ],
        ],
    ],
];
