<?php

/**
 * -------------------------------------------------------------------------
 * Relatorio Comercial plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Relatorio Comercial plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Relatorioglpicomercial;

use CommonGLPI;
use Session;

/**
 * Second entry of the plugin under the Tools menu: the ticket dashboard.
 */
class DashboardMenu extends CommonGLPI
{
    public static function getMenuName(): string
    {
        return __('Dashboard de Chamados', 'relatorioglpicomercial');
    }

    public static function canView(): bool
    {
        return Session::haveRight(Report::RIGHTNAME, READ);
    }

    public static function getMenuContent(): array|false
    {
        if (!static::canView()) {
            return false;
        }

        $page = '/plugins/relatorioglpicomercial/front/dashboard.php';

        return [
            'title' => static::getMenuName(),
            'page'  => $page,
            'icon'  => 'ti ti-chart-donut',
            'links' => ['search' => $page],
        ];
    }
}
