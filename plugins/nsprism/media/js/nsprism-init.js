/**
 * NsPrism Initializer Library
 *
 * Exposes a single function `plgNsprismApply(env, classMap)` that is called
 * from the Prism `before-highlight` hook registered inline by nsprism.php.
 *
 * Keeping the logic here (static, cacheable) and the hook registration in the
 * inline PHP-generated script (where classMap is embedded directly in the
 * closure) avoids any timing dependency between an external file and a global
 * variable set by a separate inline script.
 *
 * classMap shape: { "line-numbers": true, "match-braces": false, ... }
 *   true  → add the class to the target element
 *   false → remove it (even if hardcoded in source HTML)
 *
 * @package     Joomla.Plugin
 * @subpackage  System.nsprism
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
window.plgNsprismApply = function (env, classMap) {
    'use strict';

    var code = env.element; // always the <code> element
    if (!code) { return; }

    var pre    = code.parentElement;
    var hasPre = pre && pre.tagName === 'PRE';

    // ----------------------------------------------------------------
    // 1. Markup normalisation (always active, not configurable)
    //
    // Canonical form expected by Prism and our CSS selectors:
    //   <pre class="language-*"><code>…</code></pre>
    //
    // TinyMCE may also produce:
    //   <pre><code class="language-*">…</code></pre>
    //
    // If <code> carries the language class but the parent <pre> does
    // not, move it — so all downstream logic (Prism + CSS) works
    // uniformly regardless of which variant the editor emitted.
    // ----------------------------------------------------------------
    if (hasPre) {
        var langClass = null;

        code.classList.forEach(function (cls) {
            if (!langClass && cls.indexOf('language-') === 0) {
                langClass = cls;
            }
        });

        if (langClass && !pre.classList.contains(langClass)) {
            pre.classList.add(langClass);
            code.classList.remove(langClass);
        }
    }

    // ----------------------------------------------------------------
    // 2. Target resolution
    //
    // Managed classes are applied to <pre> when a parent <pre> exists
    // (block code), or to <code> itself for standalone inline code.
    // ----------------------------------------------------------------
    var target = hasPre ? pre : code;

    // ----------------------------------------------------------------
    // 3. Sync managed classes
    //
    // Remove every class this plugin owns from the target first — this
    // ensures that a class hardcoded in the source HTML is cleaned up
    // when the corresponding option is disabled in the plugin backend.
    // Then re-add those that are currently enabled.
    // ----------------------------------------------------------------
    classMap = classMap || {};
    Object.keys(classMap).forEach(function (cls) {
        target.classList.remove(cls);
        if (classMap[cls]) {
            target.classList.add(cls);
        }
    });
};
