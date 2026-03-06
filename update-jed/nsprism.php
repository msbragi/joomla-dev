<?php
/**
 * nsprism — Joomla Update Server Manifest
 *
 * Generates the dynamic update manifest XML consumed by Joomla's
 * built-in update manager.
 *
 * Usage: https://www.nospace.net/update-jed/nsprism.php
 */

if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}

require_once __DIR__ . '/src/ReleaseProviderInterface.php';
require_once __DIR__ . '/src/GitHubProvider.php';
require_once __DIR__ . '/src/JedHelper.php';

use UpdateJed\GitHubProvider;
use UpdateJed\JedHelper;

// ============================================================================
// CONFIGURATION
// ============================================================================

// GitHub repository in "owner/repo" format (no URL)
const GITHUB_REPO  = 'msbragi/joomla-dev';
const GITHUB_TOKEN = ''; // Optional: personal access token for higher rate limits

// Extension info page shown in Joomla's update manager
const INFO_URL = 'https://www.nospace.net/en/toolbox/prism-js-joomla-plugin#release-note';
const CHANGE_LOG_URL = 'https://github.com/msbragi/joomla-dev/releases';

// Directory for checksum cache files (must be writable by the web server)
const CACHE_DIR = __DIR__ . '/cache';

$options = [

    // ------------------------------------------------------------------
    // JED / XML manifest metadata
    //
    // Convention:
    //   'key' => 'string'             → <key>string</key>
    //   'key' => ['@attr'=>'v', ...]  → <key attr="v">...</key>
    //   'key' => ['@text'=>'t', ...]  → text content of the element
    //   'key' => [['@a'=>'1'],[...]]  → repeated <key a="1"/> elements
    //   {{placeholders}} are replaced per-release by JedHelper
    // ------------------------------------------------------------------
    'jed' => [
        'name'          => 'Nsprism - Prism Code Highlighter',
        'description'   => '{{description}}',
        'element'       => 'nsprism',
        'type'          => 'plugin',
        'folder'        => 'system',
        'client'        => 'site',
        'version'       => '{{version}}',
        'infourl'       => ['@title' => 'Documentation and Release notes', '@text' => INFO_URL],
        'changelogurl'  => CHANGE_LOG_URL,
        'downloads'     => [
            'downloadurl' => [
                '@type'   => 'full',
                '@format' => 'zip',
                '@sha256' => '{{checksum}}',
                '@text'   => '{{downloadUrl}}',
            ],
        ],
        'tags'          => ['tag' => 'stable'],
        'maintainer'    => 'nospace.net',
        'maintainerurl' => 'https://www.nospace.net',
        'targetplatform' => [
            ['@name' => 'joomla', '@version' => '4.*'],
            ['@name' => 'joomla', '@version' => '5.*'],
            ['@name' => 'joomla', '@version' => '6.*'],
        ],
    ],

    // ------------------------------------------------------------------
    // GitHub provider configuration
    // ------------------------------------------------------------------
    'config' => [
        'github_repo'    => GITHUB_REPO,
        'github_token'   => GITHUB_TOKEN,
        'tag_pattern'    => '/^nsprism-v([\d.]+)$/',
        'asset_pattern'  => 'plg_system_nsprism_v*.zip',
        'cache_ttl'      => 86400,      // seconds — 24 h
        'cache_dir'      => CACHE_DIR,
    ],

];

// ============================================================================
// GENERATE MANIFEST
// ============================================================================

try {
    $provider = new GitHubProvider($options['config']);
    $helper   = new JedHelper($options['jed'], $provider);

    header('Content-Type: application/xml; charset=utf-8');
    echo $helper->buildManifest();
} catch (Exception $e) {
    http_response_code(500);
    echo '<?xml version="1.0"?><error>' . htmlspecialchars($e->getMessage()) . '</error>';
}

