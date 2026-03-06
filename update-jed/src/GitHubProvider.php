<?php
/**
 * GitHub Release Provider
 *
 * Implements ReleaseProviderInterface by fetching releases from the GitHub
 * REST API.
 *
 * Checksum strategy (in priority order):
 *   1. Local cache — avoids any network call if the value is still fresh.
 *   2. Companion .sha256 asset on GitHub — a tiny text file (64 bytes)
 *      published alongside the ZIP at release time.  This is the
 *      authoritative source: the checksum is computed once by the developer
 *      and stored permanently in the release, independent of the server.
 *   3. Fallback: download the full ZIP and compute SHA-256 locally.
 *      This only happens when no .sha256 asset was published with the release.
 *
 * Convention for the companion file:
 *   Release asset: plg_system_nsprism_v1.2.3.zip
 *   Checksum file: plg_system_nsprism_v1.2.3.zip.sha256   ← same name + .sha256
 *   Content:       e3b0c44298fc1c149afb...  (hex digest, optionally followed
 *                  by two spaces and the filename, shasum-style)
 */

namespace UpdateJed;

class GitHubProvider implements ReleaseProviderInterface
{
    /** @var array */
    private $config;

    /** @var array<string, array{checksum: string, cachedAt: int}> */
    private $cache = [];

    /** @var string Absolute path to the JSON cache file */
    private $cacheFile;

    /**
     * Constructor
     *
     * @param array $config {
     *   @type string   $github_repo    Repository in "owner/repo" format (required)
     *   @type string   $tag_pattern    Regex to extract semver from a tag, e.g. '/^nsprism-v([\d.]+)$/'
     *   @type string   $asset_pattern  Glob pattern for the release ZIP, e.g. 'plg_system_nsprism_v*.zip'
     *                                  The companion checksum file must be named {zip}.sha256
     *   @type string   $github_token   Optional personal-access token for higher rate limits
     *   @type int      $cache_ttl      Seconds before a cached checksum expires (default 86400)
     *   @type string   $cache_dir      Directory for the JSON cache file (default sys_get_temp_dir())
     * }
     */
    public function __construct(array $config)
    {
        $defaults = [
            'github_repo'    => '',
            'tag_pattern'    => '/^v([\d.]+)$/',
            'asset_pattern'  => '*.zip',
            'github_token'   => '',
            'cache_ttl'      => 86400,
            'cache_dir'      => sys_get_temp_dir(),
        ];

        $this->config = array_merge($defaults, $config);

        if (empty($this->config['github_repo'])) {
            throw new \InvalidArgumentException('GitHubProvider: github_repo is required');
        }

        $slug = preg_replace('/[^a-z0-9_-]/', '_', strtolower($this->config['github_repo']));
        $this->cacheFile = rtrim($this->config['cache_dir'], '/') . '/gh_' . $slug . '.json';

        $this->loadCache();
    }

    // -------------------------------------------------------------------------
    // Public API (Interface contract)
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function getReleases(): array
    {
        $raw = $this->fetchFromApi();

        $releases = [];

        foreach ($raw as $release) {
            if ($release['draft'] || $release['prerelease']) {
                continue;
            }

            $version = $this->extractVersion($release['tag_name']);
            if ($version === null) {
                continue;
            }

            $assets = $this->matchAssets($release['assets'] ?? []);
            if ($assets === null) {
                continue;  // no ZIP found for this release
            }

            $releases[] = [
                'version'     => $version,
                'downloadUrl' => $assets['zip'],
                'checksum'    => $this->resolveChecksum($version, $assets['zip'], $assets['sha256']),
                'description' => $this->plainText($release['body'] ?? ''),
                'releaseDate' => $release['published_at'] ?? '',
            ];
        }

        usort($releases, function (array $a, array $b): int {
            return $this->versionToInt($b['version']) - $this->versionToInt($a['version']);
        });

        return $releases;
    }

    // -------------------------------------------------------------------------
    // Private — GitHub API
    // -------------------------------------------------------------------------

    /**
     * Fetch all releases from the GitHub REST API.
     *
     * @return array
     * @throws \RuntimeException
     */
    private function fetchFromApi(): array
    {
        $url     = 'https://api.github.com/repos/' . $this->config['github_repo'] . '/releases';
        $headers = [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: Joomla-Extension-Update-Server/2.0',
        ];

        if (!empty($this->config['github_token'])) {
            $headers[] = 'Authorization: token ' . $this->config['github_token'];
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", $headers),
                'timeout' => 10,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new \RuntimeException('GitHubProvider: failed to reach GitHub API for ' . $this->config['github_repo']);
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new \RuntimeException('GitHubProvider: unexpected response from GitHub API');
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Private — Parsing helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the semver string from a git tag using the configured regex.
     *
     * @param  string $tag
     * @return string|null
     */
    private function extractVersion(string $tag): ?string
    {
        if (preg_match($this->config['tag_pattern'], $tag, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Find the ZIP asset and its optional companion .sha256 file.
     *
     * Returns an array with keys:
     *   - zip    (string)       Download URL of the ZIP archive
     *   - sha256 (string|null)  Download URL of the .sha256 companion file,
     *                           or null if not published with this release
     *
     * The companion file must be named exactly "{zip_filename}.sha256".
     *
     * @param  array $assets Assets list from GitHub release payload
     * @return array{zip: string, sha256: string|null}|null  Null when no ZIP matches
     */
    private function matchAssets(array $assets): ?array
    {
        // Convert glob to regex: escape everything, then restore the wildcard
        $escaped  = preg_quote($this->config['asset_pattern'], '/');
        $zipRegex = '/^' . str_replace('\\*', '[\\w.\\-]+', $escaped) . '$/i';

        // Index all asset names → download URLs for fast companion lookup
        $index = [];
        foreach ($assets as $asset) {
            $index[$asset['name']] = $asset['browser_download_url'];
        }

        foreach ($assets as $asset) {
            if (!preg_match($zipRegex, $asset['name'])) {
                continue;
            }

            return [
                'zip'    => $asset['browser_download_url'],
                'sha256' => $index[$asset['name'] . '.sha256'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Convert a semver string to a comparable integer.
     *
     * @param  string $version e.g. "1.2.3"
     * @return int
     */
    private function versionToInt(string $version): int
    {
        $parts  = explode('.', $version);
        $result = 0;
        foreach ($parts as $i => $part) {
            if ($i < 3) {
                $result += (int) $part * (int) pow(1000, 2 - $i);
            }
        }
        return $result;
    }

    /**
     * Strip basic Markdown syntax down to plain text.
     *
     * @param  string $markdown
     * @return string
     */
    private function plainText(string $markdown): string
    {
        $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $markdown); // links
        $text = preg_replace('/^#+\s+/m', '', $text);                      // headings
        $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text);            // bold
        $text = preg_replace('/\*([^*]+)\*/', '$1', $text);                // italic
        return trim($text);
    }

    // -------------------------------------------------------------------------
    // Private — Checksum resolution (cache → .sha256 asset → ZIP fallback)
    // -------------------------------------------------------------------------

    /**
     * Resolve the SHA-256 checksum for a release using the following priority:
     *
     *   1. Local cache (avoids all network I/O if still fresh)
     *   2. Companion .sha256 asset on GitHub (authoritative, ~64 bytes)
     *   3. Fallback: download the full ZIP and compute locally (slow)
     *
     * @param  string      $version
     * @param  string      $zipUrl     Download URL of the ZIP archive
     * @param  string|null $sha256Url  Download URL of the .sha256 companion file, or null
     * @return string|null
     */
    private function resolveChecksum(string $version, string $zipUrl, ?string $sha256Url): ?string
    {
        // 1. Cache hit
        if (isset($this->cache[$version])) {
            $entry = $this->cache[$version];
            if ((time() - $entry['cachedAt']) < $this->config['cache_ttl']) {
                return $entry['checksum'];
            }
        }

        // 2. Companion .sha256 file published on GitHub (preferred)
        if ($sha256Url !== null) {
            $checksum = $this->fetchSha256Asset($sha256Url);
            if ($checksum !== null) {
                $this->persistCache($version, $checksum);
                return $checksum;
            }
        }

        // 3. Fallback: download full ZIP and compute hash locally
        $checksum = $this->computeFromZip($zipUrl);
        if ($checksum !== null) {
            $this->persistCache($version, $checksum);
        }
        return $checksum;
    }

    /**
     * Fetch and parse a .sha256 companion file.
     *
     * Accepts both bare hex format and shasum-style output:
     *   e3b0c44298fc1c149afb4c8996fb92427ae41e4649b934ca495991b7852b855
     *   e3b0c44298fc1c149afb4c8996fb92427ae41e4649b934ca495991b7852b855  file.zip
     *
     * @param  string $url
     * @return string|null  64-char lowercase hex digest, or null on failure
     */
    private function fetchSha256Asset(string $url): ?string
    {
        $context = stream_context_create([
            'http'  => ['method' => 'GET', 'timeout' => 10],
            'https' => ['method' => 'GET', 'timeout' => 10],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        // Extract first 64-char hex sequence (ignore trailing filename if present)
        if (preg_match('/\b([0-9a-f]{64})\b/i', trim($body), $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Download the full ZIP archive and compute its SHA-256 hash.
     * Used only as a last resort when no .sha256 asset is available.
     *
     * @param  string $url
     * @return string|null
     */
    private function computeFromZip(string $url): ?string
    {
        $context = stream_context_create([
            'http'  => ['method' => 'GET', 'timeout' => 30],
            'https' => ['method' => 'GET', 'timeout' => 30],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            return null;
        }

        return hash('sha256', $data);
    }

    /**
     * Persist a checksum to the in-memory cache and write to disk.
     *
     * @param string $version
     * @param string $checksum
     */
    private function persistCache(string $version, string $checksum): void
    {
        $this->cache[$version] = [
            'checksum' => $checksum,
            'cachedAt' => time(),
        ];
        $this->saveCache();
    }

    /**
     * Load the JSON cache file into memory.
     */
    private function loadCache(): void
    {
        if (!file_exists($this->cacheFile)) {
            return;
        }

        $raw = @file_get_contents($this->cacheFile);
        if ($raw === false) {
            return;
        }

        $data = json_decode($raw, true);
        if (is_array($data)) {
            $this->cache = $data;
        }
    }

    /**
     * Write the in-memory cache back to the JSON file.
     */
    private function saveCache(): void
    {
        @file_put_contents(
            $this->cacheFile,
            json_encode($this->cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
