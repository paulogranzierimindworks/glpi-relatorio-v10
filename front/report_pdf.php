<?php

/**
 * -------------------------------------------------------------------------
 * Relatorio Comercial plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Relatorio Comercial plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * -------------------------------------------------------------------------
 */

use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Relatorioglpicomercial\Report;
use GlpiPlugin\Relatorioglpicomercial\ReportPdf;

Session::checkRight(Report::RIGHTNAME, READ);

$tipo       = (string) ($_GET['tipo'] ?? '');
$date_start = (string) ($_GET['date_start'] ?? '');
$date_end   = (string) ($_GET['date_end'] ?? '');

if (!Report::isValidDate($date_start) || !Report::isValidDate($date_end) || $date_start > $date_end) {
    throw new BadRequestHttpException('Invalid date range');
}

if ($tipo === 'cliente') {
    $entities_id = (int) ($_GET['entities_id'] ?? 0);
    if (!Report::entityExists($entities_id)) {
        throw new BadRequestHttpException('Invalid entity');
    }

    $tickets    = Report::getTicketsForEntity($entities_id, $date_start, $date_end);
    $categories = Report::getHoursByCategory($tickets);
    $entity     = Entity::getById($entities_id)->fields;

    ReportPdf::streamClientReport($entity, $tickets, $categories, $date_start, $date_end);
} elseif ($tipo === 'geral') {
    $rows = Report::getGeneralReport($date_start, $date_end);

    ReportPdf::streamGeneralReport($rows, $date_start, $date_end);
} else {
    throw new BadRequestHttpException('Invalid "tipo" parameter');
}
