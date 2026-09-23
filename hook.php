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

use GlpiPlugin\Relatorioglpicomercial\EntityMailConfig;
use GlpiPlugin\Relatorioglpicomercial\MailConfig;
use GlpiPlugin\Relatorioglpicomercial\Report;
use GlpiPlugin\Relatorioglpicomercial\ReportMailer;

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

    if (!$DB->tableExists(EntityMailConfig::TABLE_CONFIG)) {
        $table = EntityMailConfig::TABLE_CONFIG;
        $DB->doQueryOrDie(
            "CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT '0',
                `is_active` tinyint NOT NULL DEFAULT '0',
                `emails` text,
                `last_sent_period` varchar(20) DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
            $DB->error()
        );
    }

    // Recipients used to be picked among GLPI users, in a dedicated table and an
    // "extra_emails" column beside it. They are now plain addresses typed in the
    // configuration page, so fold the old shape into the current one.
    $migration->changeField(EntityMailConfig::TABLE_CONFIG, 'extra_emails', 'emails', 'text');
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
            'comment'       => 'Envio automático do relatório comercial por e-mail',
        ]
    );

    $migration->executeMigration();

    return true;
}

/**
 * Plugin uninstall process
 */
function plugin_relatorioglpicomercial_uninstall(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_RELATORIOGLPICOMERCIAL_VERSION);
    $migration->dropTable(EntityMailConfig::TABLE_CONFIG);
    $migration->dropTable('glpi_plugin_relatorioglpicomercial_recipients');
    $migration->executeMigration();

    MailConfig::deleteAll();

    // Core also unregisters plugin tasks on uninstall; doing it here keeps the
    // cleanup explicit and independent of that.
    CronTask::unregister('relatorioglpicomercial');

    $DB->delete('glpi_profilerights', ['name' => Report::RIGHTNAME]);

    return true;
}
