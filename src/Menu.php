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

class Menu extends CommonGLPI
{
    public static function getMenuName(): string
    {
        return __('Relatório Comercial', 'relatorioglpicomercial');
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

        $page = '/plugins/relatorioglpicomercial/front/report.php';

        $links = ['search' => $page];

        if (Session::haveRight(Report::RIGHTNAME, UPDATE)) {
            $links['config'] = '/plugins/relatorioglpicomercial/front/config.form.php';
        }

        return [
            'title' => static::getMenuName(),
            'page'  => $page,
            'icon'  => 'ti ti-report',
            'links' => $links,
        ];
    }
}
