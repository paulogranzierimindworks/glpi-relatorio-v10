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

use GlpiPlugin\Relatorioglpicomercial\DashboardColumns;
use GlpiPlugin\Relatorioglpicomercial\DashboardReport;
use GlpiPlugin\Relatorioglpicomercial\Report;
use GlpiPlugin\Relatorioglpicomercial\ReportPdf;

Session::checkRight(Report::DASHBOARD_RIGHTNAME, READ);

$filters = DashboardReport::filtersFromRequest($_GET);

if (
    !Report::isValidDate($filters['date_start'])
    || !Report::isValidDate($filters['date_end'])
    || $filters['date_start'] > $filters['date_end']
) {
    Html::displayErrorAndDie('Invalid date range');
}

if ($filters['entities_id'] < 0 || ($filters['entities_id'] > 0 && !Session::haveAccessToEntity($filters['entities_id']))) {
    Html::displayErrorAndDie('Invalid entity');
}

$rows    = DashboardReport::getRows($filters);
$visible = DashboardColumns::getVisibleKeys((int) Session::getLoginUserID());

ReportPdf::streamDashboardReport($rows, $filters['date_start'], $filters['date_end'], $visible);
