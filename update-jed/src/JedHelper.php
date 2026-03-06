<?php
/**
 * Joomla Extension Distribution Helper
 *
 * Single responsibility: build Joomla update manifest XML.
 * Release data is supplied by any ReleaseProviderInterface implementation
 * (e.g. GitHubProvider), keeping this class completely decoupled from
 * any specific source or transport.
 *
 * Config array convention (the 'jed' key in nsprism.php):
 *
 *   'key' => 'string'          → <key>string</key>
 *   'key' => ['@attr' => 'v',  → <key attr="v">text</key>
 *             '@text' => 'text']
 *   'key' => ['child' => ...]  → <key><child>...</child></key>
 *   'key' => [['@a'=>'1'],     → <key a="1"/>  (repeated, one per entry)
 *             ['@a'=>'2']]       <key a="2"/>
 *
 * Dynamic values from the GitHub release are injected via placeholders:
 *   {{version}}, {{description}}, {{downloadUrl}}, {{checksum}}
 */

namespace UpdateJed;

class JedHelper
{
    /** @var array */
    private $jed;

    /** @var ReleaseProviderInterface */
    private $provider;

    /**
     * @param array                    $jedConfig  See file-level docblock for the convention.
     * @param ReleaseProviderInterface $provider   Injected release data source.
     */
    public function __construct(array $jedConfig, ReleaseProviderInterface $provider)
    {
        $this->jed      = $jedConfig;
        $this->provider = $provider;
    }

    /**
     * Build and return the complete Joomla update manifest XML.
     *
     * @return string
     * @throws \RuntimeException When the provider returns no usable releases
     */
    public function buildManifest(): string
    {
        $releases = $this->provider->getReleases();

        $xml  = '<?xml version="1.0" encoding="utf-8"?>' . PHP_EOL;
        $xml .= '<updates>' . PHP_EOL;

        foreach ($releases as $release) {
            $xml .= $this->buildUpdateRow($release);
        }

        $xml .= '</updates>' . PHP_EOL;

        return $xml;
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Build a single <update> XML block by substituting release placeholders
     * into the config template and then rendering it recursively.
     *
     * @param  array $release  Entry from ReleaseProviderInterface::getReleases()
     * @return string
     */
    private function buildUpdateRow(array $release): string
    {
        $data = $this->substitute($this->jed, $release);

        $xml = '    <update>' . PHP_EOL;
        foreach ($data as $tag => $value) {
            $xml .= $this->renderNode($tag, $value, 2);
        }
        $xml .= '    </update>' . PHP_EOL;

        return $xml;
    }

    /**
     * Recursively render a single XML node.
     *
     * Rules (see file-level docblock for the full convention):
     *   scalar              → <tag>escaped_value</tag>
     *   array with @keys    → attributes; @text = text content
     *   array with children → nested elements
     *   numeric array       → repeated <tag .../> (one per entry)
     *
     * @param  string          $tag
     * @param  string|int|array $value
     * @param  int             $depth  Indentation level (1 level = 4 spaces)
     * @return string
     */
    private function renderNode(string $tag, $value, int $depth = 1): string
    {
        $pad = str_repeat('    ', $depth);

        // Scalar → <tag>value</tag>
        if (!is_array($value)) {
            return $pad . '<' . $tag . '>' . $this->esc((string) $value) . '</' . $tag . '>' . PHP_EOL;
        }

        // Numeric array → repeated elements (e.g. targetplatform)
        if (isset($value[0])) {
            $xml = '';
            foreach ($value as $item) {
                $xml .= $this->renderNode($tag, $item, $depth);
            }
            return $xml;
        }

        // Associative array: collect @attrs, @text, and child elements
        $attrs    = '';
        $text     = null;
        $children = '';

        foreach ($value as $k => $v) {
            if ($k === '@text') {
                $text = (string) $v;
            } elseif ($k[0] === '@') {
                // attribute — skip if empty (e.g. optional sha256)
                if ($v !== '' && $v !== null) {
                    $attrs .= ' ' . substr($k, 1) . '="' . $this->esc((string) $v) . '"';
                }
            } else {
                $children .= $this->renderNode($k, $v, $depth + 1);
            }
        }

        if ($children !== '') {
            return $pad . '<' . $tag . $attrs . '>' . PHP_EOL
                 . $children
                 . $pad . '</' . $tag . '>' . PHP_EOL;
        }

        if ($text !== null) {
            return $pad . '<' . $tag . $attrs . '>' . $this->esc($text) . '</' . $tag . '>' . PHP_EOL;
        }

        // No text, no children → self-closing
        return $pad . '<' . $tag . $attrs . '/>' . PHP_EOL;
    }

    /**
     * Recursively replace {{placeholders}} in the config template with
     * actual values from the current release.
     *
     * Supported placeholders:
     *   {{version}}, {{description}}, {{downloadUrl}}, {{checksum}}
     *
     * @param  mixed $value
     * @param  array $release
     * @return mixed
     */
    private function substitute($value, array $release)
    {
        if (is_string($value)) {
            return str_replace(
                ['{{version}}', '{{description}}',                      '{{downloadUrl}}',        '{{checksum}}'],
                [$release['version'], substr($release['description'], 0, 200), $release['downloadUrl'], $release['checksum'] ?? ''],
                $value
            );
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->substitute($v, $release);
            }
            return $result;
        }

        return $value;
    }

    /**
     * Shorthand for htmlspecialchars with UTF-8.
     *
     * @param  string $value
     * @return string
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
