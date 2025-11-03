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
    ],
    'includes' => [
        'helpers' => __DIR__ . '/includes/helpers.php',
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
        ],
        'assets' => [
            'js' => [
                'js/nh.controller.js',
            ],
        ],
    ],
];
