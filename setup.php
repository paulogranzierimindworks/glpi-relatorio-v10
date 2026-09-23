<?php

/**
 * -------------------------------------------------------------------------
 * Relatorio Comercial plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Relatorio Comercial plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/pluginsGLPI/relatorioglpicomercial
 * -------------------------------------------------------------------------
 */

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_RELATORIOGLPICOMERCIAL_VERSION', '1.0.0');

// Minimal GLPI version, inclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_RELATORIOGLPICOMERCIAL_MIN_GLPI_VERSION", "10.0.0");

// Maximum GLPI version, exclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_RELATORIOGLPICOMERCIAL_MAX_GLPI_VERSION", "10.0.99");

// GLPI 10.x has no global htmlescape() helper (added in 11.0); this plugin's
// front scripts rely on it to escape output, so provide the same
// implementation core uses from 11.0 onward when it is missing.
if (!function_exists('htmlescape')) {
    function htmlescape(mixed $str): string
    {
        return htmlspecialchars((string) $str);
    }
}

/**
 * Init hooks of the plugin.
 * REQUIRED
 */
function plugin_init_relatorioglpicomercial(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['relatorioglpicomercial'] = true;

    if (!Plugin::isPluginActive('relatorioglpicomercial')) {
        return;
    }

    $PLUGIN_HOOKS['menu_toadd']['relatorioglpicomercial']['tools'] = [
        \GlpiPlugin\Relatorioglpicomercial\Menu::class,
        \GlpiPlugin\Relatorioglpicomercial\DashboardMenu::class,
    ];

    // Wrench icon next to the plugin in Setup > Plugins.
    $PLUGIN_HOOKS['config_page']['relatorioglpicomercial'] = 'front/config.form.php';

    // Adds a tab on the core Profile form so admins can grant/revoke the
    // plugin's READ right per profile.
    Plugin::registerClass(\GlpiPlugin\Relatorioglpicomercial\Profile::class, [
        'addtabon' => \Profile::class,
    ]);
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          }
 *      }
 * }
 */
function plugin_version_relatorioglpicomercial(): array
{
    return [
        'name'           => 'Relatorio Comercial',
        'version'        => PLUGIN_RELATORIOGLPICOMERCIAL_VERSION,
        'author'         => '<a href="https://www.mindworks.com.br/">Mindworks</a>',
        'license'        => '',
        'homepage'       => 'https://www.mindworks.com.br/',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_RELATORIOGLPICOMERCIAL_MIN_GLPI_VERSION,
                'max' => PLUGIN_RELATORIOGLPICOMERCIAL_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install
 * OPTIONAL
 */
function plugin_relatorioglpicomercial_check_prerequisites(): bool
{
    return true;
}

/**
 * Check configuration process
 * OPTIONAL
 *
 * @param bool $verbose Whether to display message on failure. Defaults to false.
 */
function plugin_relatorioglpicomercial_check_config(bool $verbose = false): bool
{
    return true;
}
