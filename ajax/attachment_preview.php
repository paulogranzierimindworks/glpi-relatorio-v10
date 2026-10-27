<?php

/**
 * -------------------------------------------------------------------------
 * Relatorio Comercial plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Relatorio Comercial plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * -------------------------------------------------------------------------
 */

/**
 * PDF attachment of a transmission group, rendered from the values currently
 * in its form (saved or not) for one client: the preview of AttachmentForm.
 *
 * Lives under ajax/ on purpose: GLPI keeps the CSRF token of AJAX requests, so
 * previewing does not invalidate the form's token and "Salvar" still works.
 */

include('../../../inc/includes.php');

use Glpi\Toolbox\Sanitizer;
use GlpiPlugin\Relatorioglpicomercial\AttachmentComposer;
use GlpiPlugin\Relatorioglpicomercial\AttachmentConfig;
use GlpiPlugin\Relatorioglpicomercial\Report;
use GlpiPlugin\Relatorioglpicomercial\ReportScheduler;
use GlpiPlugin\Relatorioglpicomercial\TransmissionGroup;
use GlpiPlugin\Relatorioglpicomercial\TransmissionGroupEntity;

Html::header_nocache();

Session::checkRight(Report::RIGHTNAME, UPDATE);

$fail = static function (int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $fail(405, 'Method not allowed');
}

$group = new TransmissionGroup();
if (!$group->getFromDB((int) ($_POST['id'] ?? 0))) {
    $fail(404, __('Grupo de transmissão não encontrado.', 'relatorioglpicomercial'));
}

// The client list of the form is the one of the saved report type.
$entities_id = (int) ($_POST['preview_entities_id'] ?? 0);
$selectable  = array_column(Report::getSelectableEntities((string) $group->fields['report_type']), 'entities_id');
if (!in_array($entities_id, $selectable, true)) {
    $fail(400, __('Cliente inválido para este grupo.', 'relatorioglpicomercial'));
}

$report_type = (string) ($_POST['report_type'] ?? '');
if (!array_key_exists($report_type, TransmissionGroup::getReportTypes())) {
    $report_type = (string) $group->fields['report_type'];
}

$attachment = Sanitizer::unsanitize((array) ($_POST['attachment'] ?? []));
$config     = AttachmentConfig::normalize(is_array($attachment) ? $attachment : [], $report_type);

$window_type = (string) ($_POST['window_type'] ?? $group->fields['window_type']);
if (!array_key_exists($window_type, ReportScheduler::getWindowTypes())) {
    $window_type = (string) $group->fields['window_type'];
}
[$date_start, $date_end] = ReportScheduler::resolveWindow(
    [
        'window_type' => $window_type,
        'window_days' => max(1, min(365, (int) ($_POST['window_days'] ?? $group->fields['window_days']))),
    ],
    new DateTimeImmutable()
);

// The client's own values: from the form when present, the saved ones otherwise.
$entity_config = TransmissionGroupEntity::getAllForGroup((int) $group->fields['id'])[$entities_id] ?? [];
foreach (AttachmentConfig::ENTITY_FIELDS as $field) {
    if (isset($_POST[$field][$entities_id]) && is_scalar($_POST[$field][$entities_id])) {
        $entity_config[$field] = (string) Sanitizer::unsanitize((string) $_POST[$field][$entities_id]);
    }
}

try {
    $result = AttachmentComposer::render($config, $entities_id, $entity_config, $date_start, $date_end);
} catch (Throwable $e) {
    $fail(500, sprintf(__('Falha ao gerar o anexo: %s', 'relatorioglpicomercial'), $e->getMessage()));
}

header('Content-Type: application/pdf');
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($result['filename']));
header('Content-Length: ' . strlen($result['content']));
echo $result['content'];
