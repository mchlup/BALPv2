<?php
return [
    'slug' => 'polotovary',
    'name' => 'Polotovary',
    'description' => 'Správa polotovarů a výrobních příkazů.',
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
    'assets' => [
        'js' => [
            'public/js/pol.controller.patch.js',
            'public/js/pol.editor.js',
            'public/js/pol.row-modal.js',
            'public/js/pol.row-open.js',
            'public/js/pol.vp.core.js',
            'public/js/pol.vyrobni-prikaz.js',
        ],
    ],
];
