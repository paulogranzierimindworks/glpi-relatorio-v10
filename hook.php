<?php

/**
 * -------------------------------------------------------------------------
 * Relatorio Comercial plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Relatorio Comercial plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/pluginsGLPI/relatorioglpicomercial
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Relatorioglpicomercial\AttachmentConfig;
use GlpiPlugin\Relatorioglpicomercial\DashboardColumns;
use GlpiPlugin\Relatorioglpicomercial\Report;
use GlpiPlugin\Relatorioglpicomercial\ReportMailer;
use GlpiPlugin\Relatorioglpicomercial\ReportScheduler;
use GlpiPlugin\Relatorioglpicomercial\TransmissionGroup;
use GlpiPlugin\Relatorioglpicomercial\TransmissionGroupEntity;

/**
 * Plugin install process
 */
function plugin_relatorioglpicomercial_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_RELATORIOGLPICOMERCIAL_VERSION);

    // Creates the custom right; profiles that already manage the "config" right
    // (typically Super-Admin/Admin) are granted READ + UPDATE on it automatically.
    $migration->addRight(Report::RIGHTNAME, READ | UPDATE);

    // The dashboard used to be covered by the report READ right. When the
    // dashboard right is created on an existing installation, carry that access
    // over so nobody loses the dashboard; afterwards the two are independent.
    $dashboard_right_exists = countElementsInTable('glpi_profilerights', ['name' => Report::DASHBOARD_RIGHTNAME]) > 0;
    $migration->addRight(Report::DASHBOARD_RIGHTNAME, READ);

    if (!$dashboard_right_exists) {
        $report_readers = [];
        foreach (
            $DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => [
                    'name' => Report::RIGHTNAME,
                    new QueryExpression($DB::quoteName('rights') . ' & ' . READ . ' = ' . READ),
                ],
            ]) as $row
        ) {
            $report_readers[] = (int) $row['profiles_id'];
        }

        if ($report_readers !== []) {
            $DB->update(
                'glpi_profilerights',
                ['rights' => READ],
                ['name' => Report::DASHBOARD_RIGHTNAME, 'profiles_id' => $report_readers]
            );
            $DB->update(
                'glpi_profiles',
                ['last_rights_update' => Session::getCurrentTime()],
                ['id' => $report_readers]
            );
        }
    }

    // addRight() only inserts rows that do not exist yet, so an installation
    // made before the scheduled email existed would keep a READ-only right.
    // Grant UPDATE there too, but only to the profiles allowed to configure GLPI.
    $config_profiles = [];
    foreach (
        $DB->request([
            'SELECT' => 'profiles_id',
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => [
                'name' => 'config',
                new QueryExpression($DB::quoteName('rights') . ' & ' . UPDATE . ' = ' . UPDATE),
            ],
        ]) as $row
    ) {
        $config_profiles[] = (int) $row['profiles_id'];
    }

    if ($config_profiles !== []) {
        $DB->update(
            'glpi_profilerights',
            ['rights' => new QueryExpression($DB::quoteName('rights') . ' | ' . UPDATE)],
            [
                'name'        => Report::RIGHTNAME,
                'profiles_id' => $config_profiles,
            ]
        );

        // Rights are cached in the session and only reloaded when the profile
        // advertises a change, which is what Migration::addRight() does for the
        // rows it inserts. Do the same here, otherwise users already logged in
        // keep being denied until they sign in again.
        $DB->update(
            'glpi_profiles',
            ['last_rights_update' => Session::getCurrentTime()],
            ['id' => $config_profiles]
        );
    }

    $legacy_entityconfigs_table = 'glpi_plugin_relatorioglpicomercial_entityconfigs';
    $had_legacy_config          = $DB->tableExists($legacy_entityconfigs_table);

    if (!$DB->tableExists('glpi_plugin_relatorioglpicomercial_transmissiongroups')) {
        $DB->doQueryOrDie(
            "CREATE TABLE `glpi_plugin_relatorioglpicomercial_transmissiongroups` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `comment` text,
                `is_active` tinyint NOT NULL DEFAULT '0',
                `report_type` varchar(20) NOT NULL DEFAULT 'comercial',
                `send_pdf` tinyint NOT NULL DEFAULT '0',
                `send_html` tinyint NOT NULL DEFAULT '0',
                `frequency` varchar(20) NOT NULL DEFAULT 'monthly',
                `send_hour` tinyint unsigned NOT NULL DEFAULT '8',
                `send_weekday` tinyint unsigned NOT NULL DEFAULT '1',
                `send_day` tinyint unsigned NOT NULL DEFAULT '1',
                `window_type` varchar(20) NOT NULL DEFAULT 'previous_month',
                `window_days` smallint unsigned NOT NULL DEFAULT '30',
                `subject` varchar(255) NOT NULL DEFAULT '',
                `body_html` text,
                `attachment_config` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `is_active` (`is_active`),
                KEY `report_type` (`report_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
            $DB->error()
        );
    }

    if (!$DB->tableExists(TransmissionGroupEntity::TABLE)) {
        $table = TransmissionGroupEntity::TABLE;
        $DB->doQueryOrDie(
            "CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `transmissiongroups_id` int unsigned NOT NULL,
                `entities_id` int unsigned NOT NULL,
                `is_active` tinyint NOT NULL DEFAULT '0',
                `emails` text,
                `groups_id` int unsigned NOT NULL DEFAULT '0',
                `last_sent_period` varchar(20) DEFAULT NULL,
                `contract_number` varchar(255) NOT NULL DEFAULT '',
                `client_rep_name` varchar(255) NOT NULL DEFAULT '',
                `client_rep_title` varchar(255) NOT NULL DEFAULT '',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `group_entity` (`transmissiongroups_id`, `entities_id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
            $DB->error()
        );
    }

    // One-time migration from the old single global template + one-entity-list
    // (MailConfig/EntityMailConfig) into a Transmission Group, so an upgrade
    // keeps sending exactly what it already sent, with no manual reconfiguration.
    if ($had_legacy_config) {
        // Very old installs still name the column "extra_emails"; flush this
        // rename now (instead of at the bottom with the rest of the queued
        // changes) so the SELECT below already sees "emails". No-op when the
        // column was already renamed.
        $migration->changeField($legacy_entityconfigs_table, 'extra_emails', 'emails', 'text');
        $migration->executeMigration();

        $legacy_context = 'plugin:relatorioglpicomercial';
        $legacy         = [];
        foreach (
            $DB->request([
                'FROM'  => 'glpi_configs',
                'WHERE' => ['context' => $legacy_context],
            ]) as $row
        ) {
            $legacy[$row['name']] = $row['value'];
        }

        // Frozen on purpose: this migration's behavior must not drift if a
        // future release changes what "default" means for a new group.
        $legacy += [
            'is_active'    => 0,
            'frequency'    => ReportScheduler::FREQ_MONTHLY,
            'send_hour'    => 8,
            'send_weekday' => 1,
            'send_day'     => 1,
            'window_type'  => ReportScheduler::WINDOW_PREVIOUS_MONTH,
            'window_days'  => 30,
            'subject'      => 'Relatório de Horas - {{ cliente }} - {{ periodo }}',
            'body_html'    => '',
        ];

        $now = date('Y-m-d H:i:s');

        $DB->insert('glpi_plugin_relatorioglpicomercial_transmissiongroups', [
            'name'          => 'Envio Automático (migrado)',
            'is_active'     => (int) $legacy['is_active'],
            'report_type'   => TransmissionGroup::REPORT_COMERCIAL,
            // The legacy sender never attached anything: keep output identical
            // right after the upgrade, admins can turn attachments on later.
            'send_pdf'      => 0,
            'send_html'     => 0,
            'frequency'     => (string) $legacy['frequency'],
            'send_hour'     => (int) $legacy['send_hour'],
            'send_weekday'  => (int) $legacy['send_weekday'],
            'send_day'      => (int) $legacy['send_day'],
            'window_type'   => (string) $legacy['window_type'],
            'window_days'   => (int) $legacy['window_days'],
            'subject'       => (string) $legacy['subject'],
            'body_html'     => (string) $legacy['body_html'],
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);
        $new_group_id = $DB->insertId();

        foreach ($DB->request(['FROM' => $legacy_entityconfigs_table]) as $row) {
            $DB->insert(TransmissionGroupEntity::TABLE, [
                'transmissiongroups_id' => $new_group_id,
                'entities_id'           => $row['entities_id'],
                'is_active'             => $row['is_active'],
                'emails'                => $row['emails'],
                'last_sent_period'      => $row['last_sent_period'],
                'date_creation'         => $row['date_creation'],
                'date_mod'              => $row['date_mod'],
            ]);
        }

        $migration->dropTable($legacy_entityconfigs_table);
        $DB->delete('glpi_configs', ['context' => $legacy_context]);
    }

    // 1.3.0: pages of the PDF attachment per group, and per-client values for it.
    // No-ops when the tables were just created with these columns.
    $migration->addField('glpi_plugin_relatorioglpicomercial_transmissiongroups', 'attachment_config', 'text', [
        'after' => 'body_html',
    ]);
    // 1.4.0: GLPI group whose members' e-mails are added to a client's recipients.
    $migration->addField(TransmissionGroupEntity::TABLE, 'groups_id', "int unsigned NOT NULL DEFAULT '0'", [
        'after' => 'emails',
    ]);
    $previous = 'last_sent_period';
    foreach (AttachmentConfig::ENTITY_FIELDS as $field) {
        $migration->addField(TransmissionGroupEntity::TABLE, $field, "varchar(255) NOT NULL DEFAULT ''", [
            'after' => $previous,
        ]);
        $previous = $field;
    }

    // Older still: recipients used to be picked among GLPI users in a
    // dedicated table, before the free-text "emails" column existed at all.
    $migration->dropTable('glpi_plugin_relatorioglpicomercial_recipients');

    // Wakes up hourly; the plugin configuration decides whether it is time to
    // send (see ReportScheduler), because a frequency in seconds cannot express
    // "every 1st of the month".
    CronTask::register(
        ReportMailer::class,
        ReportMailer::CRON_TASK,
        HOUR_TIMESTAMP,
        [
            'state'         => CronTask::STATE_WAITING,
            'mode'          => CronTask::MODE_EXTERNAL,
            'allowmode'     => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
            'hourmin'       => 0,
            'hourmax'       => 24,
            'logs_lifetime' => 30,
            'comment'       => 'Envio automático dos grupos de transmissão por e-mail',
        ]
    );

    $migration->executeMigration();

    // Groups saved before 1.3.0 switch to the Mindworks attachment with every
    // page enabled (the report of their own type included).
    foreach (
        $DB->request([
            'SELECT' => ['id', 'report_type'],
            'FROM'   => 'glpi_plugin_relatorioglpicomercial_transmissiongroups',
            'WHERE'  => ['attachment_config' => null],
        ]) as $row
    ) {
        $DB->update(
            'glpi_plugin_relatorioglpicomercial_transmissiongroups',
            ['attachment_config' => $DB->escape(AttachmentConfig::toJson(AttachmentConfig::defaults((string) $row['report_type'])))],
            ['id' => $row['id']]
        );
    }

    return true;
}

/**
 * Plugin uninstall process
 */
function plugin_relatorioglpicomercial_uninstall(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_RELATORIOGLPICOMERCIAL_VERSION);
    $migration->dropTable(TransmissionGroupEntity::TABLE);
    $migration->dropTable('glpi_plugin_relatorioglpicomercial_transmissiongroups');
    $migration->dropTable('glpi_plugin_relatorioglpicomercial_entityconfigs');
    $migration->dropTable('glpi_plugin_relatorioglpicomercial_recipients');
    $migration->executeMigration();

    $DB->delete('glpi_configs', ['context' => 'plugin:relatorioglpicomercial']);
    $DB->delete('glpi_configs', ['context' => DashboardColumns::CONTEXT]);

    // Core also unregisters plugin tasks on uninstall; doing it here keeps the
    // cleanup explicit and independent of that.
    CronTask::unregister('relatorioglpicomercial');

    $DB->delete('glpi_profilerights', ['name' => [Report::RIGHTNAME, Report::DASHBOARD_RIGHTNAME]]);

    return true;
}
