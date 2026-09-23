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
use GlpiPlugin\Relatorioglpicomercial\ReportRenderer;

Session::checkRight(Report::RIGHTNAME, READ);

Html::header(__('Relatório Comercial', 'relatorioglpicomercial'), $_SERVER['PHP_SELF'], 'tools', Menu::class);

$tipo        = $_GET['tipo'] ?? 'cliente';
$tipo        = in_array($tipo, ['cliente', 'geral'], true) ? $tipo : 'cliente';
$entities_id = (int) ($_GET['entities_id'] ?? 0);
$date_start  = (string) ($_GET['date_start'] ?? date('Y-m-01'));
$date_end    = (string) ($_GET['date_end'] ?? date('Y-m-d'));

$has_valid_dates = Report::isValidDate($date_start) && Report::isValidDate($date_end) && $date_start <= $date_end;
$can_generate     = $has_valid_dates && ($tipo === 'geral' || Report::entityExists($entities_id));

echo "<div class='card mb-3'><div class='card-body'>";
$self_path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
echo "<form method='get' action='" . htmlescape($self_path) . "' class='row row-cols-lg-auto g-2 align-items-end'>";

echo "<div class='col-12'>";
echo "<label class='form-label'>" . __s('Tipo de relatório', 'relatorioglpicomercial') . "</label>";
echo "<select name='tipo' class='form-select' id='relatorioglpicomercial-tipo' onchange='
        document.getElementById(\"relatorioglpicomercial-entidade\").style.display = this.value === \"cliente\" ? \"\" : \"none\";
      '>";
echo "<option value='cliente'" . ($tipo === 'cliente' ? " selected" : "") . ">" . __s('Por Cliente (chamados e SLA)', 'relatorioglpicomercial') . "</option>";
echo "<option value='geral'" . ($tipo === 'geral' ? " selected" : "") . ">" . __s('Geral (horas x contrato)', 'relatorioglpicomercial') . "</option>";
echo "</select>";
echo "</div>";

echo "<div class='col-12' id='relatorioglpicomercial-entidade'" . ($tipo !== 'cliente' ? " style='display:none;'" : "") . ">";
echo "<label class='form-label'>" . __s('Entidade', 'relatorioglpicomercial') . "</label>";
Dropdown::show('Entity', [
    'name'                => 'entities_id',
    'value'               => $entities_id,
    'display_emptychoice' => true,
]);
echo "</div>";

echo "<div class='col-12'>";
echo "<label class='form-label'>" . __s('Data inicial', 'relatorioglpicomercial') . "</label>";
Html::showDateField('date_start', ['value' => $date_start, 'maybeempty' => false]);
echo "</div>";

echo "<div class='col-12'>";
echo "<label class='form-label'>" . __s('Data final', 'relatorioglpicomercial') . "</label>";
Html::showDateField('date_end', ['value' => $date_end, 'maybeempty' => false]);
echo "</div>";

echo "<div class='col-12'>";
echo "<button type='submit' class='btn btn-primary'>" . __s('Gerar', 'relatorioglpicomercial') . "</button>";
echo "</div>";

echo "</form>";
echo "</div></div>";

if (!$has_valid_dates) {
    echo "<div class='alert alert-warning'>" . __s('Informe um período válido (data inicial até data final).', 'relatorioglpicomercial') . "</div>";
} elseif (!$can_generate) {
    echo "<div class='alert alert-warning'>" . __s('Selecione uma entidade válida.', 'relatorioglpicomercial') . "</div>";
} else {
    $query = [
        'tipo'       => $tipo,
        'date_start' => $date_start,
        'date_end'   => $date_end,
    ];
    if ($tipo === 'cliente') {
        $query['entities_id'] = $entities_id;
    }
    $pdf_url = 'report_pdf.php?' . http_build_query($query);

    if ($tipo === 'cliente') {
        $tickets    = Report::getTicketsForEntity($entities_id, $date_start, $date_end);
        $categories = Report::getHoursByCategory($tickets);
        $entity     = Entity::getById($entities_id)->fields;

        echo ReportRenderer::renderClientReport($entity, $tickets, $categories, $date_start, $date_end, $pdf_url);
    } else {
        $rows = Report::getGeneralReport($date_start, $date_end);

        echo ReportRenderer::renderGeneralReport($rows, $date_start, $date_end, $pdf_url);
    }
}

Html::footer();
