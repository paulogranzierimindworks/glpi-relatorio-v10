<?php

/**
 * -------------------------------------------------------------------------
 * Relatorio Comercial plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Relatorio Comercial plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Relatorioglpicomercial\EntityMailConfig;
use GlpiPlugin\Relatorioglpicomercial\MailConfig;
use GlpiPlugin\Relatorioglpicomercial\Menu;
use GlpiPlugin\Relatorioglpicomercial\Report;
use GlpiPlugin\Relatorioglpicomercial\ReportMailer;
use GlpiPlugin\Relatorioglpicomercial\ReportScheduler;

Session::checkRight(Report::RIGHTNAME, UPDATE);

// CSRF is validated by the kernel's CheckCsrfListener before this file runs
// (the plugin declares "csrf_compliant"), and that check consumes the token,
// so it must not be repeated here.
if (isset($_POST['update'])) {
    $config = MailConfig::validate($_POST);
    MailConfig::save($config);

    EntityMailConfig::saveAll(
        (array) ($_POST['entities'] ?? []),
        (array) ($_POST['active'] ?? []),
        (array) ($_POST['emails'] ?? []),
        ReportScheduler::currentPeriodKey($config, new DateTimeImmutable())
    );

    Session::addMessageAfterRedirect(__s('Configuração salva.', 'relatorioglpicomercial'));
    Html::back();
}

if (isset($_POST['send_test'])) {
    $error = ReportMailer::sendTest(
        (int) ($_POST['test_entities_id'] ?? 0),
        (int) Session::getLoginUserID()
    );

    if ($error === null) {
        Session::addMessageAfterRedirect(__s('E-mail de teste enviado.', 'relatorioglpicomercial'));
    } else {
        Session::addMessageAfterRedirect(
            sprintf(__s('Falha no envio do e-mail de teste: %s', 'relatorioglpicomercial'), htmlescape($error)),
            false,
            ERROR
        );
    }

    Html::back();
}

Html::header(
    __('Relatório Comercial', 'relatorioglpicomercial'),
    $_SERVER['PHP_SELF'],
    'tools',
    Menu::class
);

$config          = MailConfig::getAll();
$entity_configs  = EntityMailConfig::getAll();
$entities        = Report::getEntitiesWithContract();
$self_path       = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$hours = [];
for ($h = 0; $h <= 23; $h++) {
    $hours[$h] = sprintf('%02d:00', $h);
}

$days = [];
for ($d = 1; $d <= 31; $d++) {
    $days[$d] = (string) $d;
}

echo "<form method='post' action='" . htmlescape($self_path) . "'>";

// ---------------------------------------------------------------- schedule --
echo "<div class='card mb-3'>";
echo "<div class='card-header'><h3 class='card-title'>"
    . __s('Envio automático do relatório por e-mail', 'relatorioglpicomercial')
    . "</h3></div>";
echo "<div class='card-body'>";

echo "<div class='row mb-3'>";
echo "<div class='col-md-3'>";
echo "<label class='form-label'>" . __s('Ativo', 'relatorioglpicomercial') . "</label><div>";
Html::showCheckbox(['name' => 'is_active', 'checked' => $config['is_active'] === 1]);
echo "</div></div>";

echo "<div class='col-md-3'>";
echo "<label class='form-label'>" . __s('Periodicidade', 'relatorioglpicomercial') . "</label>";
Dropdown::showFromArray('frequency', ReportScheduler::getFrequencies(), [
    'value'     => $config['frequency'],
    'on_change' => 'plugin_relatorioglpicomercial_toggleFrequency(this.value);',
]);
echo "</div>";

echo "<div class='col-md-3' id='relatorioglpicomercial-weekday'>";
echo "<label class='form-label'>" . __s('Dia da semana', 'relatorioglpicomercial') . "</label>";
Dropdown::showFromArray('send_weekday', ReportScheduler::getWeekdays(), ['value' => $config['send_weekday']]);
echo "</div>";

echo "<div class='col-md-3' id='relatorioglpicomercial-monthday'>";
echo "<label class='form-label'>" . __s('Dia do mês', 'relatorioglpicomercial') . "</label>";
Dropdown::showFromArray('send_day', $days, ['value' => $config['send_day']]);
echo "<div class='form-hint'>"
    . __s('Dias 29 a 31 são ajustados para o último dia do mês.', 'relatorioglpicomercial')
    . "</div>";
echo "</div>";
echo "</div>";

echo "<div class='row mb-3'>";
echo "<div class='col-md-3'>";
echo "<label class='form-label'>" . __s('Horário de envio', 'relatorioglpicomercial') . "</label>";
Dropdown::showFromArray('send_hour', $hours, ['value' => $config['send_hour']]);
echo "</div>";

echo "<div class='col-md-3'>";
echo "<label class='form-label'>" . __s('Janela de dados', 'relatorioglpicomercial') . "</label>";
Dropdown::showFromArray('window_type', ReportScheduler::getWindowTypes(), [
    'value'     => $config['window_type'],
    'on_change' => 'plugin_relatorioglpicomercial_toggleWindow(this.value);',
]);
echo "</div>";

echo "<div class='col-md-3' id='relatorioglpicomercial-windowdays'>";
echo "<label class='form-label'>" . __s('Quantidade de dias', 'relatorioglpicomercial') . "</label>";
echo "<input type='number' class='form-control' name='window_days' min='1' max='365' value='"
    . htmlescape((string) $config['window_days']) . "'>";
echo "</div>";
echo "</div>";

echo "</div></div>";

// ----------------------------------------------------------------- message --
echo "<div class='card mb-3'>";
echo "<div class='card-header'><h3 class='card-title'>"
    . __s('Conteúdo do e-mail', 'relatorioglpicomercial')
    . "</h3></div>";
echo "<div class='card-body'>";

echo "<div class='mb-3'>";
echo "<label class='form-label'>" . __s('Assunto', 'relatorioglpicomercial') . "</label>";
echo "<input type='text' class='form-control' name='subject' value='"
    . htmlescape($config['subject']) . "'>";
echo "</div>";

echo "<div class='mb-2'>";
echo "<label class='form-label'>" . __s('Corpo do e-mail (HTML)', 'relatorioglpicomercial') . "</label>";
echo "<textarea class='form-control font-monospace' name='body_html' id='relatorioglpicomercial-body' rows='14'>"
    . htmlescape(MailConfig::getEffectiveBody($config)) . "</textarea>";
echo "</div>";

echo "<div class='mb-3'>";
echo "<button type='button' class='btn btn-outline-secondary btn-sm'"
    . " onclick='plugin_relatorioglpicomercial_resetBody();'>"
    . __s('Restaurar padrão', 'relatorioglpicomercial') . "</button>";
echo "</div>";

echo "<div class='alert alert-info mb-0'>";
echo "<strong>" . __s('Marcadores disponíveis', 'relatorioglpicomercial') . "</strong><br>";
foreach (ReportMailer::VARIABLES as $variable) {
    echo "<code class='me-2'>{{ " . htmlescape($variable) . " }}</code>";
}
echo "</div>";

echo "</div></div>";

// -------------------------------------------------------------- recipients --
echo "<div class='card mb-3'>";
echo "<div class='card-header'>";
echo "<h3 class='card-title'>" . __s('Destinatários por cliente', 'relatorioglpicomercial') . "</h3>";
echo "<div class='card-actions'>";
echo "<input type='text' class='form-control form-control-sm'"
    . " placeholder='" . __s('Filtrar clientes...', 'relatorioglpicomercial') . "'"
    . " onkeyup='plugin_relatorioglpicomercial_filter(this.value);'>";
echo "</div>";
echo "</div>";

if ($entities === []) {
    echo "<div class='card-body'><div class='alert alert-warning mb-0'>"
        . __s('Nenhum cliente com contrato de horas foi encontrado.', 'relatorioglpicomercial')
        . "</div></div>";
} else {
    echo "<div class='table-responsive'>";
    echo "<table class='table card-table'>";
    echo "<thead><tr>";
    echo "<th style='width:5%'>" . __s('Ativo', 'relatorioglpicomercial') . "</th>";
    echo "<th style='width:25%'>" . __s('Cliente', 'relatorioglpicomercial') . "</th>";
    echo "<th style='width:55%'>" . __s('E-mails dos destinatários', 'relatorioglpicomercial') . "</th>";
    echo "<th style='width:15%'>" . __s('Último envio', 'relatorioglpicomercial') . "</th>";
    echo "</tr></thead><tbody id='relatorioglpicomercial-clients'>";

    foreach ($entities as $entity) {
        $entities_id   = $entity['entities_id'];
        $entity_config = $entity_configs[$entities_id] ?? null;

        echo "<tr data-name='" . htmlescape(mb_strtolower($entity['nome'])) . "'>";

        echo "<td>";
        echo Html::hidden('entities[]', ['value' => $entities_id]);
        Html::showCheckbox([
            'name'    => "active[$entities_id]",
            'checked' => $entity_config !== null && $entity_config['is_active'] === 1,
        ]);
        echo "</td>";

        echo "<td>" . htmlescape($entity['nome']) . "</td>";

        echo "<td>";
        echo "<input type='text' class='form-control' name='emails[$entities_id]'"
            . " value='" . htmlescape($entity_config['emails'] ?? '') . "'"
            . " placeholder='" . __s('fulano@cliente.com, gestor@cliente.com', 'relatorioglpicomercial') . "'>";
        echo "</td>";

        echo "<td>" . htmlescape($entity_config['last_sent_period'] ?? '-') . "</td>";

        echo "</tr>";
    }

    echo "</tbody></table></div>";
}

echo "</div>";

echo "<div class='mb-3'>";
echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
echo "</div>";

Html::closeForm();

// ----------------------------------------------------------------- test mail --
if ($entities !== []) {
    echo "<form method='post' action='" . htmlescape($self_path) . "'>";
    echo "<div class='card'>";
    echo "<div class='card-header'><h3 class='card-title'>"
        . __s('Enviar e-mail de teste', 'relatorioglpicomercial')
        . "</h3></div>";
    echo "<div class='card-body'>";
    echo "<p class='text-muted'>"
        . __s('O e-mail de teste é enviado somente para o seu próprio endereço e não altera o controle de envios.', 'relatorioglpicomercial')
        . "</p>";
    echo "<div class='row row-cols-lg-auto g-2 align-items-end'>";

    echo "<div class='col-12'>";
    echo "<label class='form-label'>" . __s('Cliente', 'relatorioglpicomercial') . "</label>";
    Dropdown::showFromArray(
        'test_entities_id',
        array_column($entities, 'nome', 'entities_id'),
        ['value' => $entities[0]['entities_id']]
    );
    echo "</div>";

    echo "<div class='col-12'>";
    echo Html::submit(__s('Enviar teste', 'relatorioglpicomercial'), [
        'name'  => 'send_test',
        'class' => 'btn btn-outline-primary',
    ]);
    echo "</div>";

    echo "</div></div></div>";
    Html::closeForm();
}

// Pristine copy of the shipped template, for the "restore default" button.
// JSON_HEX_TAG keeps a "</script>" inside the template from closing the block.
echo Html::scriptBlock(
    'window.plugin_relatorioglpicomercial_default_body = '
    . json_encode(MailConfig::getDefaultBody(), JSON_HEX_TAG | JSON_HEX_AMP) . ';'
);

echo Html::scriptBlock(<<<'JS'
function plugin_relatorioglpicomercial_toggleFrequency(value) {
    document.getElementById('relatorioglpicomercial-weekday').style.display  = (value === 'weekly') ? '' : 'none';
    document.getElementById('relatorioglpicomercial-monthday').style.display = (value === 'monthly') ? '' : 'none';
}

function plugin_relatorioglpicomercial_toggleWindow(value) {
    document.getElementById('relatorioglpicomercial-windowdays').style.display = (value === 'last_n_days') ? '' : 'none';
}

function plugin_relatorioglpicomercial_resetBody() {
    document.getElementById('relatorioglpicomercial-body').value
        = window.plugin_relatorioglpicomercial_default_body;
}

function plugin_relatorioglpicomercial_filter(term) {
    term = term.toLowerCase();
    document.querySelectorAll('#relatorioglpicomercial-clients tr').forEach(function (row) {
        row.style.display = row.dataset.name.indexOf(term) !== -1 ? '' : 'none';
    });
}

$(function() {
    plugin_relatorioglpicomercial_toggleFrequency(
        document.querySelector('[name="frequency"]').value
    );
    plugin_relatorioglpicomercial_toggleWindow(
        document.querySelector('[name="window_type"]').value
    );
});
JS);

Html::footer();
