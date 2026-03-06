<?php
/**
 * Manual manifest test — no real GitHub needed.
 *
 * Injects a stub ReleaseProviderInterface to verify that JedHelper
 * produces correct XML without any network calls.
 *
 * Run from the container or CLI:
 *   php test-manifest.php
 *   curl http://www.nospace.lan/update-jed/test-manifest.php
 */

if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}

require_once __DIR__ . '/src/ReleaseProviderInterface.php';
require_once __DIR__ . '/src/JedHelper.php';

use UpdateJed\ReleaseProviderInterface;
use UpdateJed\JedHelper;

// ============================================================================
// STUB PROVIDER — simulates what GitHubProvider returns after resolving
// a real release (with .sha256 file published on GitHub)
// ============================================================================

class StubProvider implements ReleaseProviderInterface
{
    public function getReleases(): array
    {
        return [
            [
                'version'     => '1.2.0',
                'downloadUrl' => 'https://github.com/msbragi/joomla-dev/releases/download/nsprism-v1.2.0/plg_system_nsprism_v1.2.0.zip',
                'checksum'    => 'a3f1c2e4b5d6789012345678901234567890abcdef1234567890abcdef123456',
                'description' => 'Added dark-mode support for Prism themes. Minor performance improvements.',
                'releaseDate' => '2026-03-06T10:00:00Z',
            ],
            [
                'version'     => '1.1.0',
                'downloadUrl' => 'https://github.com/msbragi/joomla-dev/releases/download/nsprism-v1.1.0/plg_system_nsprism_v1.1.0.zip',
                'checksum'    => 'b4f2d3e5c6a7890123456789012345678901bcdef2345678901bcdef2345678',
                'description' => 'Initial public release with Prism.js integration.',
                'releaseDate' => '2026-01-15T09:00:00Z',
            ],
        ];
    }
}

// ============================================================================
// JED CONFIG (mirrors nsprism.php)
// ============================================================================

$jedConfig = [
    'name'          => 'Nsprism - Prism Code Highlighter',
    'element'       => 'nsprism',
    'type'          => 'plugin',
    'client'        => '0',
    'maintainer'    => 'nospace.net',
    'maintainerurl' => 'https://www.nospace.net',

    'infourl' => [
        'value' => 'https://www.nospace.net/en/toolbox/prism-js-joomla-plugin',
        'attrs' => ['title' => 'Documentation and release notes'],
    ],

    'targetplatform' => [
        'attrs' => ['name' => 'joomla', 'version' => '4.*|5.*|6.*'],
    ],

    'downloads' => [
        'attrs' => ['type' => 'full', 'format' => 'zip'],
    ],
];

// ============================================================================
// RUN
// ============================================================================

$provider = new StubProvider();
$helper   = new JedHelper($jedConfig, $provider);
$xml      = $helper->buildManifest();

// Output as XML when called via HTTP, plain text when called from CLI
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/xml; charset=utf-8');
    echo $xml;
} else {
    echo $xml;

    // CLI: basic assertions
    $failures = 0;

    $checks = [
        'XML declaration'           => str_contains($xml, '<?xml version="1.0"'),
        '<updates> root element'    => str_contains($xml, '<updates>'),
        'Two <update> blocks'       => substr_count($xml, '<update>') === 2,
        'Version 1.2.0 present'     => str_contains($xml, '<version>1.2.0</version>'),
        'Version 1.1.0 present'     => str_contains($xml, '<version>1.1.0</version>'),
        'sha256 attribute present'  => str_contains($xml, 'sha256="a3f1c2'),
        '<infourl> with title attr' => str_contains($xml, 'title="Documentation'),
        'Three targetplatform rows' => substr_count($xml, '<targetplatform') === 6, // 3 per update × 2 updates
        'client = 0'                => str_contains($xml, '<client>0</client>'),
    ];

    echo PHP_EOL . str_repeat('-', 50) . PHP_EOL;
    foreach ($checks as $label => $pass) {
        printf("  [%s] %s\n", $pass ? 'OK' : 'FAIL', $label);
        if (!$pass) {
            $failures++;
        }
    }
    echo str_repeat('-', 50) . PHP_EOL;
    echo ($failures === 0)
        ? "All checks passed.\n"
        : "$failures check(s) FAILED.\n";

    exit($failures > 0 ? 1 : 0);
}
