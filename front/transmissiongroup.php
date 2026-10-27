<?php

/**
 * -------------------------------------------------------------------------
 * Relatorio Comercial plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Relatorio Comercial plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

use GlpiPlugin\Relatorioglpicomercial\Menu;
use GlpiPlugin\Relatorioglpicomercial\Report;
use GlpiPlugin\Relatorioglpicomercial\TransmissionGroup;

Session::checkRight(Report::RIGHTNAME, READ);

Html::header(
    TransmissionGroup::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'tools',
    Menu::class
);

if (TransmissionGroup::canCreate()) {
    echo "<div class='mb-3'>";
    echo "<a class='btn btn-primary' href='" . htmlescape(TransmissionGroup::getFormURL()) . "'>";
    echo "<i class='ti ti-plus me-1'></i>" . __s('Novo grupo de transmissão', 'relatorioglpicomercial');
    echo "</a>";
    echo "</div>";
}

Search::show(TransmissionGroup::class);

Html::footer();
