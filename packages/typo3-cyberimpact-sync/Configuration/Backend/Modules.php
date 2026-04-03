<?php

declare(strict_types=1);

use Cyberimpact\CyberimpactSync\Controller\Backend\SyncModuleController;

return [
    'tools_cyberimpactsync' => [
        'parent' => 'admin',
        'position' => ['after' => 'integrations'],
        'access' => 'admin',
        'path' => '/module/admin/cyberimpact-sync',
        'iconIdentifier' => 'module-tools',
        'labels' => 'LLL:EXT:cyberimpact_sync/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => SyncModuleController::class . '::handleRequest',
            ],
            'test-token' => [
                'target' => SyncModuleController::class . '::testToken',
            ],
            'cyberimpact-fields' => [
                'target' => SyncModuleController::class . '::fetchCyberimpactFields',
            ],
            'cyberimpact-groups' => [
                'target' => SyncModuleController::class . '::fetchCyberimpactGroups',
            ],
            'column-mapping' => [
                'target' => SyncModuleController::class . '::saveColumnMapping',
            ],
            'selected-group' => [
                'target' => SyncModuleController::class . '::saveSelectedGroup',
            ],
        ],
    ],
];
