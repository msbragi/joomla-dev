<?php

/**
 * Custom form field: Textarea + Format JSON button
 *
 * @package     Joomla.Plugin
 * @subpackage  System.nsprism
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\TextareaField;

class JFormFieldLanguagesjson extends TextareaField
{
    protected $type = 'Languagesjson';

    public function getInput(): string
    {
        $textarea = parent::getInput();
        $id       = $this->id;

        $button = <<<HTML
<button
    type="button"
    id="{$id}_format_btn"
    class="btn btn-sm btn-outline-secondary mt-2"
    onclick="(function (btn) {
        var ta = document.getElementById('{$id}');
        try {
            var parsed = JSON.parse(ta.value);
            var lines  = parsed.map(function (item) { return '    ' + JSON.stringify(item); });
            ta.value   = '[\\n' + lines.join(',\\n') + '\\n]';
            btn.textContent = 'OK - Formatted';
            btn.className   = 'btn btn-sm btn-outline-success mt-2';
        } catch (e) {
            btn.textContent = 'Error - Invalid JSON';
            btn.className   = 'btn btn-sm btn-outline-danger mt-2';
        }
        setTimeout(function () {
            btn.textContent = 'Format JSON';
            btn.className   = 'btn btn-sm btn-outline-secondary mt-2';
        }, 2000);
    }(this))">Format JSON</button>
HTML;

        return $textarea . "\n" . $button;
    }
}
