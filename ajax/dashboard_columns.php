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
use GlpiPlugin\Relatorioglpicomercial\Report;

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

Session::checkRight(Report::DASHBOARD_RIGHTNAME, READ);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$users_id = (int) Session::getLoginUserID();

if (isset($_POST['reset'])) {
    DashboardColumns::reset($users_id);
    $keys = DashboardColumns::getDefaultKeys();
} else {
    $keys = DashboardColumns::save($users_id, (array) ($_POST['columns'] ?? []));
}

echo json_encode(['columns' => $keys]);
