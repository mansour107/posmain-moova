<?php

/**
 * Commercial web artifact policy.
 *
 * The artifact is assembled from a committed Git tree. This policy deliberately
 * describes runtime material rather than copying the repository wholesale.
 */
return [
    'version' => 1,
    'endpoint_directories' => ['ajax', 'api', 'do', 'get', 'print'],
    'endpoint_internal_files' => [
    ],
    'root_internal_files' => [
        'phpstan-bootstrap.php',
    ],
    'root_runtime_files' => [
        '.htaccess',
        'Caddyfile',
        'LICENSE',
        'LICENSE.md',
        'entrypoint.sh',
        'php.ini',
        'pos_config.json',
        'pos_offline.html',
        'pos_sw.js',
        'qrCode.png',
        'sw.js',
        'version.json',
        'version.txt',
    ],
    'runtime_prefixes' => [
        'PhpSpreadsheet/',
        'assets/',
        'classes/',
        'components/',
        'config/',
        'css/',
        'dist/',
        'elements/',
        'includes/',
        'js/',
        'language/',
        'plugins/',
        'src/',
        'uploads/',
    ],
    'runtime_exact_files' => [
        'barcodegr/LICENSE.md',
    ],
    'runtime_library_prefixes' => [
        'barcodegr/src/',
    ],
    'dependency_manifests' => [
        'composer.json' => 'composer.lock',
        'package.json' => 'package-lock.json',
    ],
    'prohibited_prefixes' => [
        '.cursor/',
        '.git/',
        '.github/',
        '.vscode/',
        'audit-output/',
        'backup/',
        'cli/',
        'db/',
        'dbase/',
        'deploy/',
        'docs/',
        'logs/',
        'output/',
        'scripts/',
        'test-results/',
        'tests/',
        'tools/',
        'update/',
        'var/',
    ],
    'prohibited_basename_patterns' => [
        '/(^|[._-])(debug|fix|repair|setup|test|tests|example|examples|backup)([._-]|$)/i',
        '/\.(bak|dump|log|patch|sql|sqlite|sqlite3|xls|xlsx)$/i',
        '/(^|\/)\.env($|\.)/i',
        '/(^|\/)(id_rsa|id_ed25519|authorized_keys)(\.|$)/i',
    ],
];
