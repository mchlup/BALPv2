<?php
return [
    'slug' => 'polotovary',
    'name' => 'Polotovary',
    'description' => 'Správa polotovarů a výrobních příkazů.',
    'order' => 40,
    'api' => [
        'list' => __DIR__ . '/api/list.php',
        'get' => __DIR__ . '/api/get.php',
        'save' => __DIR__ . '/api/save.php',
        'upsert' => __DIR__ . '/api/upsert.php',
        'recurse' => __DIR__ . '/api/recurse.php',
        'clone' => __DIR__ . '/api/clone.php',
        'clone2' => __DIR__ . '/api/clone2.php',
        'delete' => __DIR__ . '/api/delete.php',
        'delete2' => __DIR__ . '/api/delete2.php',
        'vyrobni_prikaz' => __DIR__ . '/api/vyrobni_prikaz.php',
        'search' => __DIR__ . '/api/search.php',
    ],
    'ui' => [
        'icon' => 'icons/factory.svg',
        'tabs' => [
            [
                'slug' => 'polotovary',
                'label' => 'Polotovary',
                'order' => 10,
                'view' => 'modules/polotovary/tab.html',
                'tab_id' => 'tab-pol',
                'pane_id' => 'pane-pol',
            ],
        ],
        'assets' => [
            'js' => [
                'js/pol.controller.patch.js',
                'js/pol.vyrobni-prikaz.js',
                'js/pol.row-open.js',
                'js/pol.vp.core.js',
                'js/pol.editor.js',
                'js/pol.row-modal.js',
            ],
        ],
    ],
];
