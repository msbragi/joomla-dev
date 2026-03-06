# JED Update Server System

Dynamic Joomla update manifest system.  Each extension has its own entry-point
script that wires a **provider** (source of release data) to the **JedHelper**
(XML builder), keeping the two concerns fully decoupled.

## Architecture

### Core Components

| File | Role |
|---|---|
| `src/ReleaseProviderInterface.php` | Contract for all release data sources |
| `src/GitHubProvider.php` | Fetches releases from GitHub REST API; caches checksums |
| `src/JedHelper.php` | Builds Joomla update manifest XML — no network I/O |
| `nsprism.php` | Entry point for the nsprism extension |

### Class diagram

```
ReleaseProviderInterface
        ▲
        │ implements
 GitHubProvider          ← $options['config']
        │
        │ injected into
    JedHelper            ← $options['jed']
        │
        └─ buildManifest() → XML string
```

Future providers (`GitLabProvider`, `HttpProvider`, …) only need to implement
`ReleaseProviderInterface::getReleases()` and can be dropped in without
touching `JedHelper`.

### Features

✅ Dynamic generation from GitHub releases  
✅ SHA-256 checksums with file-based cache (no re-download on each request)  
✅ Multi-version targeting (Joomla 4 / 5 / 6) via a single pipe-delimited string  
✅ Dependency-inverted design — `JedHelper` depends on the interface, not GitHub  
✅ Drop-in extensibility: add `GitLabProvider`, `HttpProvider`, etc. without touching `JedHelper`  

---

## Configuration structure

Each entry-point script declares two config blocks:

```php
$options = [

    // Extension metadata written into the XML manifest
    'jed' => [
        'name'          => 'Nsprism - Prism Code Highlighter',
        'element'       => 'nsprism',
        'type'          => 'plugin',
        'client'        => '0',          // 0 = site, 1 = administrator

        'maintainer'    => 'nospace.net',
        'maintainerurl' => 'https://www.nospace.net',

        'infourl' => [
            'value' => 'https://www.nospace.net/en/toolbox/prism-js-joomla-plugin',
            'attrs' => ['title' => 'Documentation and release notes'],
        ],

        'targetplatform' => [
            // Pipe-separated — generates one <targetplatform> element per token
            'attrs' => ['name' => 'joomla', 'version' => '4.*|5.*|6.*'],
        ],

        'downloads' => [
            'attrs' => ['type' => 'full', 'format' => 'zip'],
        ],
    ],

    // Provider-specific settings (GitHub in this case)
    'config' => [
        'github_repo'   => 'owner/repo',          // "owner/repo" only, no URL
        'github_token'  => '',                     // optional PAT for higher rate limits
        'tag_pattern'   => '/^nsprism-v([\d.]+)$/',
        'asset_pattern' => 'plg_system_nsprism_v*.zip',
        'cache_ttl'     => 86400,                  // seconds before checksum cache expires
        'cache_dir'     => __DIR__ . '/cache',     // must be writable by the web server
    ],

];
```

---

## Adding a new extension

### Step 1: Create the entry-point script (`myext.php`)

```php
<?php
if (!defined('_JEXEC')) define('_JEXEC', 1);

require_once __DIR__ . '/src/ReleaseProviderInterface.php';
require_once __DIR__ . '/src/GitHubProvider.php';
require_once __DIR__ . '/src/JedHelper.php';

use UpdateJed\GitHubProvider;
use UpdateJed\JedHelper;

$options = [
    'jed' => [
        'name'           => 'My Extension',
        'element'        => 'myext',
        'type'           => 'plugin',      // plugin | component | module | library
        'client'         => '0',
        'maintainer'     => 'nospace.net',
        'maintainerurl'  => 'https://www.nospace.net',
        'infourl'        => ['value' => 'https://...', 'attrs' => ['title' => 'Docs']],
        'targetplatform' => ['attrs' => ['name' => 'joomla', 'version' => '5.*|6.*']],
        'downloads'      => ['attrs' => ['type' => 'full', 'format' => 'zip']],
    ],
    'config' => [
        'github_repo'   => 'owner/myext-repo',
        'tag_pattern'   => '/^myext-v([\d.]+)$/',
        'asset_pattern' => 'plg_system_myext_v*.zip',
        'cache_ttl'     => 86400,
        'cache_dir'     => __DIR__ . '/cache',
    ],
];

try {
    $provider = new GitHubProvider($options['config']);
    $helper   = new JedHelper($options['jed'], $provider);
    header('Content-Type: application/xml; charset=utf-8');
    echo $helper->buildManifest();
} catch (Exception $e) {
    http_response_code(500);
    echo '<?xml version="1.0"?><error>' . htmlspecialchars($e->getMessage()) . '</error>';
}
```

### Step 2: Register the update server in the extension manifest (`myext.xml`)

```xml
<updateservers>
    <server type="extension" name="My Extension Update Site" priority="1">
        https://www.nospace.net/update-jed/myext.php
    </server>
</updateservers>
```

---

## GitHub setup

### Tag naming

Tags must match the `tag_pattern` regex.  For nsprism:

```
nsprism-v1.0.0
nsprism-v1.0.1
nsprism-v2.0.0
```

### Release assets

Each GitHub release must include the ZIP archive **and** a companion
`.sha256` checksum file:

```
plg_system_nsprism_v1.0.0.zip
plg_system_nsprism_v1.0.0.zip.sha256
```

The `.sha256` file must contain the lowercase hex SHA-256 digest of the ZIP.
Both plain and `shasum`-style formats are accepted:

```
# plain
e3b0c44298fc1c149afb4c8996fb924...

# shasum -a 256 style (filename after two spaces is ignored)
e3b0c44298fc1c149afb4c8996fb924...  plg_system_nsprism_v1.0.0.zip
```

Generate it at release time:

```bash
sha256sum plg_system_nsprism_v1.0.0.zip > plg_system_nsprism_v1.0.0.zip.sha256
# or on macOS
shasum -a 256 plg_system_nsprism_v1.0.0.zip > plg_system_nsprism_v1.0.0.zip.sha256
```

Drafts and pre-releases are automatically skipped.

---

## Checksum resolution

`GitHubProvider` resolves the SHA-256 checksum using this priority chain:

| Priority | Source | Cost |
|---|---|---|
| 1 | Local JSON cache (still within `cache_ttl`) | Zero network I/O |
| 2 | `.sha256` companion asset on GitHub | ~64 bytes — instant |
| 3 | Fallback: download full ZIP and compute locally | Slow — avoid by publishing the `.sha256` file |

The `.sha256` file is the **authoritative source**: it is computed once by the
developer at release time and stored permanently in the GitHub release,
independent of any server state or deploy cycle.  The local cache just avoids
refetching it on every request.

Make the `cache/` directory writable by the web server:

```bash
mkdir -p update-jed/cache
chown www-data:www-data update-jed/cache
chmod 750 update-jed/cache
```

---

## GitHub token (optional)

Set `github_token` in the `config` block to use a personal access token.
This raises the API rate limit from 60 to 5,000 requests/hour and allows
access to private repositories.

[Create a token](https://github.com/settings/tokens) — scopes needed: `public_repo`

---

## API endpoint

| Environment | URL |
|---|---|
| Local | `http://www.nospace.lan/update-jed/nsprism.php` |
| Production | `https://www.nospace.net/update-jed/nsprism.php` |

---

## Error responses

On failure the endpoint returns HTTP 500 and an XML error body:

```xml
<?xml version="1.0"?>
<error>GitHubProvider: failed to reach GitHub API for owner/repo</error>
```

---

## Testing

```bash
# Syntax check all PHP files
php -l src/ReleaseProviderInterface.php
php -l src/GitHubProvider.php
php -l src/JedHelper.php
php -l nsprism.php

# Generate manifest (requires network access to GitHub)
php nsprism.php

# Via HTTP (local stack)
curl http://www.nospace.lan/update-jed/nsprism.php
```

In Joomla admin: **Extensions → Update → Find Updates** — the extension should
appear with the version from the latest GitHub release.

---

## JED submission checklist

✅ Update server URL uses `https` on the production domain  
✅ GitHub releases are tagged and include the correct ZIP asset  
✅ Each release includes a companion `.zip.sha256` file  
✅ Extension installable manifest declares `<updateservers>`  
✅ At least one stable (non-draft, non-pre-release) release is published  
✅ `cache/` directory is writable by the web server  

---

## License

GNU General Public License version 2 or later
