<?php
return [
    'BE' => [
        'debug' => true,
        'installToolPassword' => '$argon2i$v=19$m=65536,t=16,p=1$Mm5QLkxWcG82VmxUb3k5Wg$Zeu6f2krjKVkgTMJ2FzFYRLcNBNI/1Va3tTZmSeBR4M',
        'passwordHashing' => [
            'className' => 'TYPO3\\CMS\\Core\\Crypto\\PasswordHashing\\Argon2iPasswordHash',
            'options' => [],
        ],
    ],
    'DB' => [
        'Connections' => [
            'Default' => [
                'charset' => 'utf8mb4',
                'dbname' => 'typo3_01',
                'defaultTableOptions' => [
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ],
                'driver' => 'pdo_mysql',
                'host' => '127.0.0.1',
                'password' => '',
                'port' => 3306,
                'user' => 'root',
            ],
        ],
    ],
    'EXTENSIONS' => [
        'backend' => [
            'backendFavicon' => '',
            'backendLogo' => '',
            'loginBackgroundImage' => '',
            'loginFootnote' => '',
            'loginHighlightColor' => '',
            'loginLogo' => '',
            'loginLogoAlt' => '',
        ],
        'cyberimpact_sync' => [
            'apiBaseUrl' => 'https://api.cyberimpact.com',
            'apiRetryInitialDelayMs' => '250',
            'apiRetryMaxAttempts' => '3',
            'apiTimeout' => '15',
            'apiToken' => '',
            'batchPollInitialDelayMs' => '1000',
            'batchPollIntervalMs' => '1000',
            'batchPollMaxAttempts' => '30',
            'chunkSize' => '500',
            'dryRunDefault' => '1',
            'exactSyncMaxUnsubscribeCount' => '1000',
            'exactSyncMissingContactsAction' => 'unsubscribe',
            'exactSyncRequireConfirmation' => '1',
            'falStorageUid' => '1',
            'incomingFolder' => 'incoming/',
            'reportsFolder' => 'reports/',
            'staleChunkTimeoutSeconds' => '900',
        ],
        'scheduler' => [
            'maxLifetime' => '1440',
        ],
    ],
    'FE' => [
        'cacheHash' => [
            'enforceValidation' => true,
        ],
        'debug' => true,
        'disableNoCacheParameter' => true,
        'passwordHashing' => [
            'className' => 'TYPO3\\CMS\\Core\\Crypto\\PasswordHashing\\Argon2iPasswordHash',
            'options' => [],
        ],
    ],
    'LOG' => [
        'TYPO3' => [
            'CMS' => [
                'deprecations' => [
                    'writerConfiguration' => [
                        'notice' => [
                            'TYPO3\CMS\Core\Log\Writer\FileWriter' => [
                                'disabled' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'MAIL' => [
        'transport' => 'sendmail',
        'transport_sendmail_command' => 'D:/laragon/bin/sendmail/sendmail.exe -t',
        'transport_smtp_encrypt' => '',
        'transport_smtp_password' => '',
        'transport_smtp_server' => '',
        'transport_smtp_username' => '',
    ],
    'SYS' => [
        'UTF8filesystem' => true,
        'caching' => [
            'cacheConfigurations' => [
                'hash' => [
                    'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\Typo3DatabaseBackend',
                ],
                'pages' => [
                    'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\Typo3DatabaseBackend',
                    'options' => [
                        'compression' => true,
                    ],
                ],
                'rootline' => [
                    'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\Typo3DatabaseBackend',
                    'options' => [
                        'compression' => true,
                    ],
                ],
            ],
        ],
        'devIPmask' => '',
        'displayErrors' => 1,
        'encryptionKey' => 'def7d03d309ca52d1ffaeff90060bd4dde0fcff7d8bfbdc98e29879c59d0402de69a7ba6e1822bb4859c2ef99068f40c',
        'exceptionalErrors' => 4096,
        'features' => [
            'frontend.cache.autoTagging' => true,
            'security.system.enforceAllowedFileExtensions' => true,
        ],
        'sitename' => 'New TYPO3 site',
        'systemMaintainers' => [
            1,
        ],
    ],
];
