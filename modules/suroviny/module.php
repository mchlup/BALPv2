<?php
return [
    'slug' => 'suroviny',
    'name' => 'Suroviny',
    'description' => 'Správa seznamu surovin a jejich parametrů.',
    'api' => [
        'list' => __DIR__ . '/api/list.php',
        'get' => __DIR__ . '/api/get.php',
        'upsert' => __DIR__ . '/api/upsert.php',
        'delete' => __DIR__ . '/api/delete.php',
        'clone' => __DIR__ . '/api/clone.php',
        'export_csv' => __DIR__ . '/api/export_csv.php',
        'filters' => __DIR__ . '/api/filters.php',
        'search' => __DIR__ . '/api/search.php',
    ],
    'ui' => [
        'tabs' => [
            [
                'slug' => 'suroviny',
                'label' => 'Suroviny',
                'order' => 20,
                'view' => 'modules/suroviny/tab.html',
                'tab_id' => 'tab-suroviny',
                'pane_id' => 'pane-suroviny',
            ],
        ],
        'assets' => [
            'js' => [
                'js/sur.controller.js',
            ],
        ],
    ],
];
