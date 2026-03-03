<?php
/**
 * @package     NsPrism
 * @subpackage  plg_system_nsprism
 * @copyright   (C) 2026 Nospace. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;

/**
 * Installation script for plg_system_nsprism.
 *
 * Ensures the media directory exists before the installer processes the plugin
 * params (which include a filelist field that scans that directory), preventing
 * a "Path is not a folder" warning during a fresh install.
 */
class PlgSystemNsprismInstallerScript
{
    /**
     * Called before any files are copied.
     *
     * @param   string            $type    install | update | uninstall
     * @param   InstallerAdapter  $parent  Installer adapter instance
     *
     * @return  bool  Return false to abort installation.
     */
    public function preflight(string $type, InstallerAdapter $parent): bool
    {
        if ($type === 'install' || $type === 'update') {
            $cssDir = JPATH_ROOT . '/media/plg_system_nsprism/css';

            if (!is_dir($cssDir)) {
                mkdir($cssDir, 0755, true);
            }
        }

        return true;
    }
}
