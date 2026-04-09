<?php

declare(strict_types=1);

defined('TYPO3') || die();

$EM_CONF[$_EXTKEY] = [
    'title' => 'Cyberimpact Sync',
    'description' => 'TYPO3 backend tools for importing Excel contacts and syncing with Cyberimpact.',
    'category' => 'module',
    'author' => 'Cyberimpact',
    'author_email' => 'devnull@example.com',
    'state' => 'stable',
    'clearCacheOnLoad' => 0,
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.9.99',
            'scheduler' => '12.4.0-14.9.99',
        ],
    ],
];
