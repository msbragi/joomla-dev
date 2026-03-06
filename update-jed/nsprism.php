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
const INFO_URL = 'https://www.nospace.net/en/toolbox/prism-js-joomla-plugin';

// Directory for checksum cache files (must be writable by the web server)
const CACHE_DIR = __DIR__ . '/cache';

$options = [

    // ------------------------------------------------------------------
    // JED / XML manifest metadata
    // ------------------------------------------------------------------
    'jed' => [
        'name'          => 'Nsprism - Prism Code Highlighter',
        'element'       => 'nsprism',
        'type'          => 'plugin',
        'client'        => '0',         // 0 = site
        'maintainer'    => 'nospace.net',
        'maintainerurl' => 'https://www.nospace.net',
        'infourl' => [
            'value' => INFO_URL,
            'attrs' => ['title' => 'Documentation and release notes'],
        ],
        'targetplatform' => [
            'attrs' => ['name' => 'joomla', 'version' => '4.*|5.*|6.*'],
        ],
        'downloads' => [
            'attrs' => ['type' => 'full', 'format' => 'zip'],
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

