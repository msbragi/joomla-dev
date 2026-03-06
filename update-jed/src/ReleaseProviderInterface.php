<?php
/**
 * Release Provider Interface
 *
 * Contract for all release data sources (GitHub, GitLab, HTTP, etc.).
 * Any class implementing this interface can be used as a provider in JedHelper.
 */

namespace UpdateJed;

interface ReleaseProviderInterface
{
    /**
     * Fetch and return a list of available releases, ordered by version descending.
     *
     * Each entry MUST contain the following keys:
     *
     *   - version     (string)       Semantic version string, e.g. "1.2.3"
     *   - downloadUrl (string)       Direct download URL of the installable archive
     *   - checksum    (string|null)  SHA-256 hex digest of the archive, or null if unavailable
     *   - description (string)       Short release description / release notes (plain text)
     *   - releaseDate (string)       ISO 8601 publication date, e.g. "2026-01-15T10:00:00Z"
     *
     * Implementations MUST:
     *   - Skip draft and pre-release entries
     *   - Return an empty array (not throw) when no matching releases are found
     *
     * @return array<int, array{version: string, downloadUrl: string, checksum: string|null, description: string, releaseDate: string}>
     *
     * @throws \RuntimeException When the upstream source is unreachable or returns an unrecoverable error
     */
    public function getReleases(): array;
}
