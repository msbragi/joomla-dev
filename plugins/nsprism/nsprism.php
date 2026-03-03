<?php

/**
 * System Plugin to integrate Prism.js with TinyMCE
 *
 * @package     Joomla.Plugin
 * @subpackage  System.nsprism
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Event\Event;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Language\Text;

/**
 * Nospace Prism System Plugin
 * - Injects a full-featured Prism.js for frontend and backend.
 * - Extends TinyMCE's code sample languages via server-side event.
 * - Provides options for themes, CSS class features, and custom layouts.
 */
class PlgSystemNsprism extends CMSPlugin implements SubscriberInterface
{
    /**
     * Returns an array of events this subscriber listens to.
     *
     * @return  array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onExtensionBeforeSave' => 'onExtensionBeforeSave',
            'onAfterDispatch'       => 'onAfterDispatch',
        ];
    }

    /**
     * Fires before an extension record is saved.
     * Blocks saving our own plugin when the languages JSON is invalid.
     *
     * @param   Event  $event  The before-save event.
     * @return  void
     */
    public function onExtensionBeforeSave(Event $event): void
    {
        // Joomla 4+ dispatches Model\BeforeSaveEvent with NAMED arguments:
        // 'context', 'subject' (the table), 'isNew', 'data'.
        $context = $event->getArgument('context', '');
        $table   = $event->getArgument('subject');

        // Only intercept our own plugin record.
        if ($context !== 'com_plugins.plugin') {
            return;
        }

        if (!is_object($table)
            || !isset($table->element, $table->folder)
            || $table->element !== 'nsprism'
            || $table->folder  !== 'system') {
            return;
        }

        // Read the params that are about to be saved.
        $params     = new \Joomla\Registry\Registry($table->params ?? '');
        $patchLangs = (bool) $params->get('patch_tinymce_langs', 1);

        if (!$patchLangs) {
            return;
        }

        $rawJson = trim((string) $params->get('languages_json', ''));

        // Empty field: restore the formatted default and let the save proceed.
        if ($rawJson === '' || $rawJson === '[]') {
            $languages = $this->getDefaultLanguages();
        } else {
            $validation = $this->parseLanguagesJson($rawJson);

            if ($validation['error'] !== null) {
                Factory::getApplication()->enqueueMessage(
                    Text::sprintf('PLG_SYSTEM_NSPRISM_ERROR_INVALID_LANGS_JSON', $validation['error']),
                    'error'
                );
                $event->addResult(false);

                return;
            }

            $languages = $validation['languages'];
        }

        // Always normalise to one-object-per-line JSON before storing.
        $formatted = $this->encodeLanguagesJson($languages);
        $params->set('languages_json', $formatted);
        $table->params = (string) $params;
    }

    /**
     * Encodes a languages array to a compact-per-object JSON string, e.g.:
     *   [
     *       {"text": "PHP", "value": "php"},
     *       {"text": "Go",  "value": "go"}
     *   ]
     *
     * @param   array  $languages
     * @return  string
     */
    private function encodeLanguagesJson(array $languages): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $lines = array_map(
            static fn(array $item) => '    ' . json_encode($item, $flags),
            $languages
        );

        return "[\n" . implode(",\n", $lines) . "\n]";
    }

    /**
     * Returns the canonical default language list.
     *
     * @return  array
     */
    private function getDefaultLanguages(): array
    {
        return [
            ['text' => 'HTML/XML',    'value' => 'markup'],
            ['text' => 'CSS',         'value' => 'css'],
            ['text' => 'JavaScript',  'value' => 'javascript'],
            ['text' => 'TypeScript',  'value' => 'typescript'],
            ['text' => 'PHP',         'value' => 'php'],
            ['text' => 'Python',      'value' => 'python'],
            ['text' => 'Bash',        'value' => 'bash'],
            ['text' => 'SQL',         'value' => 'sql'],
            ['text' => 'JSON',        'value' => 'json'],
            ['text' => 'Docker',      'value' => 'docker'],
            ['text' => 'Java',        'value' => 'java'],
            ['text' => 'Go',          'value' => 'go'],
            ['text' => 'Rust',        'value' => 'rust'],
        ];
    }

    /**
     * Parses and validates the languages_json parameter.
     *
     * Validation rules:
     *  - Must be valid JSON.
     *  - Root value must be a non-empty array.
     *  - Each entry must be an object with a non-empty string "text"
     *    and a non-empty "value" matching a valid Prism language identifier
     *    (letters, digits, hyphens, underscores, plus signs).
     *
     * @param   string  $rawJson  Raw JSON string from plugin params.
     *
     * @return  array{languages: array, error: string|null}
     */
    private function parseLanguagesJson(string $rawJson): array
    {
        $decoded = json_decode($rawJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'languages' => $this->getDefaultLanguages(),
                'error'     => Text::sprintf('PLG_SYSTEM_NSPRISM_ERROR_JSON_SYNTAX', json_last_error_msg()),
            ];
        }

        if (!is_array($decoded)) {
            return [
                'languages' => $this->getDefaultLanguages(),
                'error'     => Text::_('PLG_SYSTEM_NSPRISM_ERROR_NOT_ARRAY'),
            ];
        }

        if (empty($decoded)) {
            return [
                'languages' => $this->getDefaultLanguages(),
                'error'     => Text::_('PLG_SYSTEM_NSPRISM_ERROR_EMPTY_ARRAY'),
            ];
        }

        foreach ($decoded as $index => $item) {
            $n = $index + 1;

            if (!is_array($item)) {
                return [
                    'languages' => $this->getDefaultLanguages(),
                    'error'     => Text::sprintf('PLG_SYSTEM_NSPRISM_ERROR_ITEM_NOT_OBJECT', $n),
                ];
            }

            if (empty($item['text']) || !is_string($item['text'])) {
                return [
                    'languages' => $this->getDefaultLanguages(),
                    'error'     => Text::sprintf('PLG_SYSTEM_NSPRISM_ERROR_ITEM_TEXT', $n),
                ];
            }

            if (empty($item['value']) || !is_string($item['value'])
                || !preg_match('/^[a-z0-9_+\-]+$/i', $item['value'])) {
                return [
                    'languages' => $this->getDefaultLanguages(),
                    'error'     => Text::sprintf('PLG_SYSTEM_NSPRISM_ERROR_ITEM_VALUE', $n),
                ];
            }
        }

        return ['languages' => array_values($decoded), 'error' => null];
    }

    /**
     * Event triggered after the framework has dispatched the application.
     * Used to load assets (JS/CSS) into the main document (head).
     *
     * @return  void
     */
    public function onAfterDispatch()
    {
        $app = Factory::getApplication();

        // Only run in the frontend or backend, not in CLI or API contexts
        if (!$app->isClient('administrator') && !$app->isClient('site')) {
            return;
        }

        $document = $app->getDocument();
        $wa       = $document->getWebAssetManager();

        // Get plugin parameters from the XML
        $loadCss = $this->params->get('load_css', 1);
        $loadLayout = $this->params->get('load_layout', 1);
        $themeCss = $this->params->get('theme_css', 'dark.css');
        $useMinified = $this->params->get('use_minified', 1);

        if (empty($themeCss)) {
            $themeCss = 'dark.css';
        }

        // --- Asset Registration ---

        // 1. Register the main Prism library.
        //    Loaded synchronously so that any inline initializer script that
        //    follows can reference the global Prism object without a race condition.
        $jsFile = $useMinified ? 'prism.min.js' : 'prism.js';
        $prismJsUrl = Uri::root() . 'media/plg_system_nsprism/js/' . $jsFile;
        $wa->registerScript('nsprism.library', $prismJsUrl, [], []);
        $wa->useScript('nsprism.library');

        // 2. Register CSS assets
        if ($loadCss) {
            $cssUrl = Uri::root() . 'media/plg_system_nsprism/css/' . $themeCss;
            $wa->registerStyle('nsprism.theme.css', $cssUrl);
            $wa->useStyle('nsprism.theme.css');
        }

        if ($loadLayout) {
            $layoutCssUrl = Uri::root() . 'media/plg_system_nsprism/css/code-blocks.css';
            // Only declare the theme as a dependency when it was actually registered.
            $layoutDeps = $loadCss ? ['nsprism.theme.css'] : [];
            $wa->registerStyle('nsprism.layout.css', $layoutCssUrl, [], [], $layoutDeps);
            $wa->useStyle('nsprism.layout.css');
        }

        // 3. Build a class-map from the enable_classes checkbox field, register the
        //    static init library (nsprism-init.js), then inject an inline hook that
        //    calls plgNsprismApply() with the map embedded in the closure.
        //
        //    The enable_classes field is a single checkboxes field where each option
        //    is a Prism CSS class name. The result is an array of selected class names.
        //
        //    The class-map is passed directly into the hook closure (not via a global
        //    variable) to avoid any ordering dependency between external script files
        //    and inline scripts emitted by the Web Asset Manager.
        $enabledClasses = (array) $this->params->get('enable_classes', []);
        $classMap       = array_fill_keys($enabledClasses, true);

        // Register the static init library (defines plgNsprismApply; cacheable).
        $initJsUrl = Uri::root() . 'media/plg_system_nsprism/js/nsprism-init.js';
        $wa->registerScript('nsprism.init', $initJsUrl, [], [], ['nsprism.library']);
        $wa->useScript('nsprism.init');

        // Inject the hook inline. classMap is embedded directly in the closure so
        // there is no reliance on a global variable being set before this runs.
        $encodedClassMap = json_encode($classMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hookScript      = <<<JS
Prism.hooks.add('before-highlight', function (env) {
    if (typeof plgNsprismApply === 'function') {
        plgNsprismApply(env, {$encodedClassMap});
    }
});
JS;
        // Depends on nsprism.init so plgNsprismApply is defined when the hook fires.
        $wa->addInlineScript($hookScript, [], [], ['nsprism.init']);

        // 4. Backend only: patch TinyMCE script options to inject codesample config.
        //
        //    In Joomla 5/6, onEditorSetup is used only to register editor providers,
        //    not to configure TinyMCE options. The TinyMCE init config lives in
        //    Joomla.optionsStorage['plg_editor_tinymce'], written server-side by the
        //    TinyMCE plugin and read by its deferred JS wrapper on DOMContentLoaded.
        //
        //    Strategy: inject an inline script (runs synchronously during HTML parsing,
        //    after core.js has populated optionsStorage but before the deferred TinyMCE
        //    wrapper fires its own DOMContentLoaded listener) that mutates the stored
        //    object in-place. Because Joomla.getOptions() returns a direct reference,
        //    the mutation is visible to all subsequent readers.
        if ($app->isClient('administrator') && $document->getType() === 'html') {
            $patchLangs = (bool) $this->params->get('patch_tinymce_langs', 1);

            // Build the language list only if the override flag is enabled.
            $encodedLangs = 'null';
            if ($patchLangs) {
                $rawJson = $this->params->get('languages_json', '[]');
                $result  = $this->parseLanguagesJson($rawJson);

                if ($result['error'] !== null) {
                    $app->enqueueMessage(
                        Text::sprintf('PLG_SYSTEM_NSPRISM_ERROR_INVALID_LANGS_JSON', $result['error']),
                        'warning'
                    );
                }

                $encodedLangs = json_encode($result['languages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $themeCssUrl  = Uri::root() . 'media/plg_system_nsprism/css/' . $themeCss;
            $layoutCssUrl = Uri::root() . 'media/plg_system_nsprism/css/code-blocks.css';

            $encodedTheme  = json_encode($themeCssUrl);
            $encodedLayout = json_encode($layoutCssUrl);

            $editorScript = <<<JS
(function () {
    var opts = Joomla.getOptions('plg_editor_tinymce');
    if (!opts || !opts.tinyMCE) { return; }
    var languages = {$encodedLangs};
    var cssTheme  = {$encodedTheme};
    var cssLayout = {$encodedLayout};
    Object.keys(opts.tinyMCE).forEach(function (setName) {
        var set = opts.tinyMCE[setName];
        if (!set || typeof set !== 'object') { return; }
        // Always tell TinyMCE to use the globally loaded Prism instance.
        set.codesample_global_prismjs = true;
        // Override the language list only when the flag is enabled.
        if (languages !== null) {
            set.codesample_languages = languages;
        }
        if (!Array.isArray(set.content_css)) {
            set.content_css = (typeof set.content_css === 'string') ? [set.content_css] : [];
        }
        // Inject only what this plugin owns: Prism theme then layout overrides.
        // The active template's editor.css is already injected by Joomla's TinyMCE plugin.
        if (set.content_css.indexOf(cssTheme)  === -1) { set.content_css.push(cssTheme); }
        if (set.content_css.indexOf(cssLayout) === -1) { set.content_css.push(cssLayout); }
    });
}());
JS;
            // Must run after core.js (which populates Joomla.optionsStorage) and
            // before the deferred TinyMCE wrapper reads the options on DOMContentLoaded.
            $wa->addInlineScript($editorScript, [], [], ['core']);
        }
    }
}
