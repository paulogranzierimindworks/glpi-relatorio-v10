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

use GlpiPlugin\Relatorioglpicomercial\DashboardMenu;
use GlpiPlugin\Relatorioglpicomercial\DashboardRenderer;
use GlpiPlugin\Relatorioglpicomercial\DashboardReport;
use GlpiPlugin\Relatorioglpicomercial\Report;

Session::checkRight(Report::RIGHTNAME, READ);

Html::header(DashboardMenu::getMenuName(), $_SERVER['PHP_SELF'], 'tools', DashboardMenu::class);
Html::requireJs('charts');

$filters           = DashboardReport::filtersFromRequest($_GET);
$entities_id       = $filters['entities_id'];
$itilcategories_id = $filters['itilcategories_id'];
$users_id_tech     = $filters['users_id_tech'];
$status            = $filters['status'];
$date_start        = $filters['date_start'];
$date_end          = $filters['date_end'];

$has_valid_dates = Report::isValidDate($date_start) && Report::isValidDate($date_end) && $date_start <= $date_end;
$has_valid_entity = $entities_id === 0 || ($entities_id > 0 && Session::haveAccessToEntity($entities_id));

$status_labels = [
    'novo'        => __('Novo', 'relatorioglpicomercial'),
    'atendimento' => __('Em atendimento', 'relatorioglpicomercial'),
    'pendente'    => __('Pendente', 'relatorioglpicomercial'),
    'solucionado' => __('Solucionado', 'relatorioglpicomercial'),
    'fechado'     => __('Fechado', 'relatorioglpicomercial'),
];

echo "<div class='card mb-3'><div class='card-body'>";
$self_path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
echo "<form method='get' action='" . htmlescape($self_path) . "' class='row row-cols-lg-auto g-2 align-items-end'>";

echo "<div class='col-12'>";
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
echo "<label class='form-label'>" . __s('Categoria', 'relatorioglpicomercial') . "</label>";
Dropdown::show('ITILCategory', [
    'name'                => 'itilcategories_id',
    'value'               => $itilcategories_id,
    'display_emptychoice' => true,
]);
echo "</div>";

echo "<div class='col-12'>";
echo "<label class='form-label'>" . __s('Status', 'relatorioglpicomercial') . "</label>";
echo "<select name='status' class='form-select'>";
echo "<option value=''>" . __s('Todos', 'relatorioglpicomercial') . "</option>";
foreach ($status_labels as $key => $label) {
    echo "<option value='" . htmlescape($key) . "'" . ($status === $key ? " selected" : "") . ">" . htmlescape($label) . "</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='col-12'>";
echo "<label class='form-label'>" . __s('Analista', 'relatorioglpicomercial') . "</label>";
User::dropdown([
    'name'                => 'users_id_tech',
    'value'               => $users_id_tech,
    'right'               => 'own_ticket',
    'display_emptychoice' => true,
]);
echo "</div>";

echo "<div class='col-12'>";
echo "<button type='submit' class='btn btn-primary'>" . __s('Gerar', 'relatorioglpicomercial') . "</button>";
echo "</div>";

echo "</form>";
echo "</div></div>";

if (!$has_valid_dates) {
    echo "<div class='alert alert-warning'>" . __s('Informe um período válido (data inicial até data final).', 'relatorioglpicomercial') . "</div>";
} elseif (!$has_valid_entity) {
    echo "<div class='alert alert-warning'>" . __s('Selecione uma entidade válida.', 'relatorioglpicomercial') . "</div>";
} else {
    $rows = DashboardReport::getRows($filters);

    $pdf_url = 'dashboard_pdf.php?' . http_build_query($filters);

    echo DashboardRenderer::render($rows, $date_start, $date_end, $pdf_url);
}

Html::footer();
