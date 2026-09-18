<?php

/**
 * -------------------------------------------------------------------------
 * Relatorio Comercial plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Relatorio Comercial plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Relatorioglpicomercial;

use CommonGLPI;
use Html;
use Profile as GlpiProfile;
use ProfileRight as GlpiProfileRight;

/**
 * Adds a "Relatório Comercial" tab to the core Profile form, letting admins
 * grant or revoke the plugin's READ right per profile.
 */
class Profile extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Relatório Comercial', 'relatorioglpicomercial');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!($item instanceof GlpiProfile) || $item->isNewItem()) {
            return '';
        }

        return self::getTypeName();
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!($item instanceof GlpiProfile) || $item->isNewItem()) {
            return false;
        }

        self::showForProfile((int) $item->getID());

        return true;
    }

    public static function showForProfile(int $profiles_id): void
    {
        $current  = GlpiProfileRight::getProfileRights($profiles_id, [Report::RIGHTNAME]);
        $rights   = (int) ($current[Report::RIGHTNAME] ?? 0);
        $can_edit = GlpiProfile::canUpdate();

        echo "<form method='post' action='/plugins/relatorioglpicomercial/front/profile.form.php'>";
        echo "<table class='table'><tbody>";

        echo "<tr><th>" . __s('Visualizar o relatório comercial', 'relatorioglpicomercial') . "</th><td>";
        Html::showCheckbox([
            'name'     => 'right_read',
            'checked'  => (bool) ($rights & READ),
            'disabled' => !$can_edit,
        ]);
        echo "</td></tr>";

        echo "<tr><th>" . __s('Configurar o envio automático por e-mail', 'relatorioglpicomercial') . "</th><td>";
        Html::showCheckbox([
            'name'     => 'right_update',
            'checked'  => (bool) ($rights & UPDATE),
            'disabled' => !$can_edit,
        ]);
        echo "</td></tr>";

        echo "</tbody></table>";

        if ($can_edit) {
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo "<div class='mb-2'>";
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
        }
        Html::closeForm();
    }
}
