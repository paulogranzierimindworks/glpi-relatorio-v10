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
use GlpiPlugin\Relatorioglpicomercial\ReportMailer;
use GlpiPlugin\Relatorioglpicomercial\ReportScheduler;
use GlpiPlugin\Relatorioglpicomercial\TransmissionGroup;
use GlpiPlugin\Relatorioglpicomercial\TransmissionGroupEntity;

$item = new TransmissionGroup();

// CSRF is validated by the kernel's CheckCsrfListener before this file runs
// (the plugin declares "csrf_compliant"), and that check consumes the token,
// so it must not be repeated here.
if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);

    $newID = $item->add($_POST);
    if ($newID) {
        Session::addMessageAfterRedirect(__s('Grupo criado. Configure agora os destinatários.', 'relatorioglpicomercial'));
        Html::redirect($item->getFormURL() . '?id=' . $newID);
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);

    TransmissionGroupEntity::saveAll(
        (int) $_POST['id'],
        (array) ($_POST['entities'] ?? []),
        (array) ($_POST['active'] ?? []),
        (array) ($_POST['emails'] ?? []),
        ReportScheduler::currentPeriodKey($item->fields, new DateTimeImmutable()),
        [
            'contract_number'  => (array) ($_POST['contract_number'] ?? []),
            'client_rep_name'  => (array) ($_POST['client_rep_name'] ?? []),
            'client_rep_title' => (array) ($_POST['client_rep_title'] ?? []),
        ],
        (array) ($_POST['glpi_groups'] ?? [])
    );

    Session::addMessageAfterRedirect(__s('Configuração salva.', 'relatorioglpicomercial'));
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, 1);
    $item->redirectToList();
} elseif (isset($_POST['send_test'])) {
    $item->check($_POST['id'], UPDATE);

    $error = ReportMailer::sendTest(
        (int) $_POST['id'],
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
} else {
    Html::header(
        TransmissionGroup::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'tools',
        Menu::class
    );

    $item->showForm((int) ($_GET['id'] ?? -1));

    Html::footer();
}
