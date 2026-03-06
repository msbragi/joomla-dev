<?php
/**
 * Joomla Extension Distribution Helper
 *
 * Single responsibility: build Joomla update manifest XML.
 * Release data is supplied by any ReleaseProviderInterface implementation
 * (e.g. GitHubProvider), keeping this class completely decoupled from
 * any specific source or transport.
 */

namespace UpdateJed;

class JedHelper
{
    /** @var array */
    private $jed;

    /** @var ReleaseProviderInterface */
    private $provider;

    /**
     * Constructor
     *
     * @param array                    $jedConfig  Extension metadata for the XML manifest.
     *   Required keys:
     *     - name          (string)  Display name, e.g. "Nsprism - Prism Code Highlighter"
     *     - element       (string)  Joomla element, e.g. "nsprism"
     *     - type          (string)  Extension type: plugin|component|module|library
     *     - type          (string)  Extension type: plugin|component|module|library
     *     - client        (string)  Numeric client id: '0' = site, '1' = administrator
     *     - maintainer    (string)  Publisher name
     *     - maintainerurl (string)  Publisher URL
     *     - infourl       (array)   ['value' => URL, 'attrs' => ['title' => '...']]
     *     - targetplatform(array)   ['attrs' => ['name' => 'joomla', 'version' => '4.*|5.*|6.*']]
     *     - downloads     (array)   ['attrs' => ['type' => 'full', 'format' => 'zip']]
     *
     * @param ReleaseProviderInterface $provider   Injected release data source
     */
    public function __construct(array $jedConfig, ReleaseProviderInterface $provider)
    {
        $this->jed      = array_merge($this->defaults(), $jedConfig);
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
     * Default values for optional jed config keys.
     *
     * @return array
     */
    private function defaults(): array
    {
        return [
            'name'            => '',
            'element'         => '',
            'type'            => 'plugin',
            'client'          => '0',
            'maintainer'      => '',
            'maintainerurl'   => '',
            'infourl'         => ['value' => '', 'attrs' => []],
            'targetplatform'  => ['attrs' => ['name' => 'joomla', 'version' => '4.*|5.*|6.*']],
            'downloads'       => ['attrs' => ['type' => 'full', 'format' => 'zip']],
        ];
    }

    /**
     * Build a single <update> XML block.
     *
     * @param  array $release  Entry from ReleaseProviderInterface::getReleases()
     * @return string
     */
    private function buildUpdateRow(array $release): string
    {
        $j = $this->jed;

        $xml  = '    <update>' . PHP_EOL;

        // Identity
        $xml .= '        <name>'    . $this->esc($j['name'])           . '</name>'    . PHP_EOL;
        $xml .= '        <description>' . $this->esc(substr($release['description'], 0, 200)) . '</description>' . PHP_EOL;
        $xml .= '        <element>' . $this->esc($j['element'])        . '</element>' . PHP_EOL;
        $xml .= '        <type>'    . $this->esc($j['type'])           . '</type>'    . PHP_EOL;
        $xml .= '        <client>'  . $this->esc($j['client'])         . '</client>'  . PHP_EOL;
        $xml .= '        <version>' . $this->esc($release['version'])  . '</version>' . PHP_EOL;

        // Info URL  (with optional attributes)
        $infoAttrs = $this->buildAttrs($j['infourl']['attrs'] ?? []);
        $xml .= '        <infourl' . $infoAttrs . '>' . $this->esc($j['infourl']['value'] ?? '') . '</infourl>' . PHP_EOL;

        // Downloads
        $dlAttrs = $this->buildAttrs($j['downloads']['attrs'] ?? []);
        $xml .= '        <downloads>' . PHP_EOL;
        if (!empty($release['checksum'])) {
            $xml .= '            <downloadurl' . $dlAttrs . ' sha256="' . $this->esc($release['checksum']) . '">'
                 . $this->esc($release['downloadUrl']) . '</downloadurl>' . PHP_EOL;
        } else {
            $xml .= '            <downloadurl' . $dlAttrs . '>'
                 . $this->esc($release['downloadUrl']) . '</downloadurl>' . PHP_EOL;
        }
        $xml .= '        </downloads>' . PHP_EOL;

        // Tags
        $xml .= '        <tags>' . PHP_EOL;
        $xml .= '            <tag>stable</tag>' . PHP_EOL;
        $xml .= '        </tags>' . PHP_EOL;

        // Maintainer
        $xml .= '        <maintainer>'    . $this->esc($j['maintainer'])    . '</maintainer>'    . PHP_EOL;
        $xml .= '        <maintainerurl>' . $this->esc($j['maintainerurl']) . '</maintainerurl>' . PHP_EOL;

        // Target platforms — split pipe-delimited version string
        $platformAttrs = $j['targetplatform']['attrs'] ?? [];
        $platformName  = $platformAttrs['name'] ?? 'joomla';
        $versions      = array_filter(array_map('trim', explode('|', $platformAttrs['version'] ?? '5.*')));
        foreach ($versions as $ver) {
            $xml .= '        <targetplatform name="' . $this->esc($platformName)
                 . '" version="' . $this->esc($ver) . '"/>' . PHP_EOL;
        }

        $xml .= '    </update>' . PHP_EOL;

        return $xml;
    }

    /**
     * Build an XML attribute string from a key-value array.
     * Returns a string starting with a space, e.g. ' type="full" format="zip"'.
     *
     * @param  array $attrs
     * @return string
     */
    private function buildAttrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $k => $v) {
            $out .= ' ' . $k . '="' . $this->esc($v) . '"';
        }
        return $out;
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
