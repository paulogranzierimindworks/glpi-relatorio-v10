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

use Config;
use CronTask;
use DateTimeImmutable;
use GLPIMailer;
use Throwable;

/**
 * Builds and sends the per-client "contracted vs. worked hours" email through
 * the SMTP configured in GLPI, driven by an hourly automatic action.
 *
 * This is the port of the n8n "Schedule Trigger1" branch: the schedule, the
 * recipients and the message itself became configuration (see
 * {@see MailConfig}, {@see EntityMailConfig} and {@see ReportScheduler}), and
 * the SQL is the one already used on screen ({@see Report::getGeneralReport()})
 * so the email can never disagree with the report.
 */
class ReportMailer
{
    public const CRON_TASK = 'SendCommercialReport';

    /**
     * Placeholders admins may use in the subject and the HTML body. Anything
     * else is replaced by an empty string — see renderTemplate().
     */
    public const VARIABLES = [
        'cliente',
        'periodo',
        'data_inicio',
        'data_fim',
        'horas_contratadas',
        'horas_trabalhadas',
        'percentual',
        'percentual_cor',
        'gerado_em',
    ];

    /**
     * @param string $name
     * @return array{description: string}
     */
    public static function cronInfo($name): array
    {
        return [
            'description' => __('Envio automático do relatório comercial por e-mail', 'relatorioglpicomercial'),
        ];
    }

    /**
     * Hourly tick: sends to every included client that has not been served yet
     * in the current period.
     *
     * @return int >0 when something was sent, 0 when there was nothing to do
     */
    public static function cronSendCommercialReport(CronTask $task): int
    {
        $config = MailConfig::getAll();
        if ($config['is_active'] !== 1) {
            return 0;
        }

        $now    = new DateTimeImmutable();
        $period = ReportScheduler::periodKey($config, $now);
        if ($period === null) {
            return 0;
        }

        $pending = array_filter(
            EntityMailConfig::getActive(),
            static fn (array $entity): bool => $entity['last_sent_period'] !== $period
        );
        if ($pending === []) {
            return 0;
        }

        [$date_start, $date_end] = ReportScheduler::resolveWindow($config, $now);

        $rows = [];
        foreach (Report::getGeneralReport($date_start, $date_end) as $row) {
            $rows[$row['entities_id']] = $row;
        }

        $sent = 0;

        foreach ($pending as $entities_id => $entity_config) {
            if (!isset($rows[$entities_id])) {
                // No contract with hours in this period: nothing to report.
                $task->log(sprintf('Entidade %d ignorada: sem contrato com horas.', $entities_id));
                continue;
            }

            $recipients = EntityMailConfig::resolveRecipients($entity_config);
            foreach ($recipients['skipped'] as $skipped) {
                $task->log(sprintf('Entidade %d, destinatário ignorado: %s', $entities_id, $skipped));
            }

            if ($recipients['emails'] === []) {
                // Deliberately not stamped: the problem must resurface next tick
                // instead of being silently swallowed.
                $task->log(sprintf('Entidade %d ignorada: nenhum destinatário válido.', $entities_id));
                continue;
            }

            $error = self::send(
                $rows[$entities_id],
                $config,
                $date_start,
                $date_end,
                $recipients['emails']
            );

            if ($error !== null) {
                $task->log(sprintf('Falha no envio para a entidade %d: %s', $entities_id, $error));
                continue;
            }

            EntityMailConfig::markSent($entities_id, $period);
            $sent++;
        }

        $task->addVolume($sent);

        return $sent > 0 ? 1 : 0;
    }

    /**
     * Sends the report of one entity to the current user only, using the very
     * same code path as the cron so that SMTP, subject and body are all
     * exercised for real. Never touches last_sent_period.
     *
     * @return string|null null on success, the error message otherwise
     */
    public static function sendTest(int $entities_id, int $users_id): ?string
    {
        $email = \UserEmail::getDefaultForUser($users_id);
        if ($email === '') {
            return __('Seu usuário não possui e-mail cadastrado.', 'relatorioglpicomercial');
        }

        $config = MailConfig::getAll();
        $now    = new DateTimeImmutable();
        [$date_start, $date_end] = ReportScheduler::resolveWindow($config, $now);

        foreach (Report::getGeneralReport($date_start, $date_end) as $row) {
            if ($row['entities_id'] === $entities_id) {
                return self::send($row, $config, $date_start, $date_end, [$email]);
            }
        }

        return __('A entidade selecionada não possui contrato com horas no período.', 'relatorioglpicomercial');
    }

    /**
     * @param array<string, mixed> $row    One row of Report::getGeneralReport()
     * @param array<string, mixed> $config MailConfig::getAll()
     * @param array<int, string>   $emails Already validated addresses
     * @return string|null null on success, the error message otherwise
     */
    private static function send(
        array $row,
        array $config,
        string $date_start,
        string $date_end,
        array $emails
    ): ?string {
        $entities_id = (int) $row['entities_id'];
        $sender      = Config::getEmailSender($entities_id);

        if ($sender['email'] === null) {
            return __('Nenhum remetente de e-mail configurado no GLPI.', 'relatorioglpicomercial');
        }

        $variables = self::buildVariables($row, $date_start, $date_end);

        try {
            // GLPIMailer extends PHPMailer in GLPI 10.x and self-configures its
            // SMTP transport from $CFG_GLPI on construction: one instance per
            // message, as core's own NotificationMailing does.
            $mailer = new GLPIMailer();
            $mailer->addCustomHeader('Auto-Submitted', 'auto-generated');
            $mailer->addCustomHeader('X-Auto-Response-Suppress', 'OOF, DR, NDR, RN, NRN');
            $mailer->setFrom($sender['email'], $sender['name'] ?? '');
            foreach ($emails as $recipient) {
                $mailer->addAddress($recipient);
            }
            $mailer->Subject = self::renderTemplate(MailConfig::getEffectiveSubject($config), $variables, false);
            $mailer->isHTML(true);
            $mailer->Body = self::renderTemplate(MailConfig::getEffectiveBody($config), $variables, true);

            if (!$mailer->send()) {
                return $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : __('Falha desconhecida no envio.', 'relatorioglpicomercial');
            }
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Values offered to the subject and body templates, formatted by
     * {@see ReportRenderer} so they match the on-screen report exactly.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function buildVariables(array $row, string $date_start, string $date_end): array
    {
        $data_inicio = ReportRenderer::formatDate($date_start);
        $data_fim    = ReportRenderer::formatDate($date_end);

        return [
            'cliente'           => (string) $row['nome'],
            'periodo'           => $data_inicio . ' - ' . $data_fim,
            'data_inicio'       => $data_inicio,
            'data_fim'          => $data_fim,
            'horas_contratadas' => ReportRenderer::formatHours((float) $row['horas_contratadas']),
            'horas_trabalhadas' => ReportRenderer::formatHours((float) $row['horas_trabalhadas']),
            'percentual'        => number_format((float) $row['percentual'], 1),
            'percentual_cor'    => ReportRenderer::usageColor((float) $row['percentual']),
            'gerado_em'         => date('d/m/Y H:i'),
        ];
    }

    /**
     * Replaces `{{ placeholder }}` by its value.
     *
     * Deliberately *not* Twig: the template is authored by an administrator but
     * running it through TemplateRenderer would hand the full Twig environment
     * (functions, filters, includes) to whoever holds the configuration right.
     * Only the whitelisted names above resolve; anything else becomes empty.
     *
     * @param array<string, string> $variables
     * @param bool $escape Escape values for an HTML context (false for the subject)
     */
    public static function renderTemplate(string $template, array $variables, bool $escape): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            static function (array $matches) use ($variables, $escape): string {
                $value = $variables[$matches[1]] ?? '';
                return $escape ? htmlescape($value) : $value;
            },
            $template
        );
    }
}
