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

use GlpiPlugin\Relatorioglpicomercial\Report;

Session::checkRight('profile', UPDATE);

$profiles_id = (int) ($_POST['id'] ?? 0);

if (isset($_POST['update'])) {
    $rights = (isset($_POST['right_read']) ? READ : 0)
        | (isset($_POST['right_update']) ? UPDATE : 0);
    ProfileRight::updateProfileRights($profiles_id, [Report::RIGHTNAME => $rights]);
}

Html::back();
