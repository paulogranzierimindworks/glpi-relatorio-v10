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
use Entity;
use GLPIMailer;
use Throwable;

/**
 * Builds and sends the scheduled report emails of every active
 * {@see TransmissionGroup} through the SMTP configured in GLPI, driven by an
 * hourly automatic action.
 *
 * Each group picks one report type (commercial "por cliente" or the ticket
 * dashboard), which decides the clients and the placeholders of the message;
 * the PDF attachment is the Mindworks report built by {@see AttachmentComposer}
 * from the group's {@see AttachmentConfig}. The SQL that feeds them is the
 * one already used on screen ({@see Report::getTicketsForEntity()},
 * {@see DashboardReport::getRows()}) so the email can never disagree with the
 * report.
 */
class ReportMailer
{
    public const CRON_TASK = 'SendCommercialReport';

    /** Placeholders available when the group's report type is "comercial". */
    public const VARIABLES_COMERCIAL = [
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

    /** Placeholders available when the group's report type is "dashboard". */
    public const VARIABLES_DASHBOARD = [
        'cliente',
        'periodo',
        'data_inicio',
        'data_fim',
        'total_chamados',
        'sla_percentual',
        'sla_percentual_cor',
        'gerado_em',
    ];

    /**
     * @param string $name
     * @return array{description: string}
     */
    public static function cronInfo($name): array
    {
        return [
            'description' => __('Envio automático dos grupos de transmissão por e-mail', 'relatorioglpicomercial'),
        ];
    }

    /**
     * Hourly tick: for every active group, sends to every included client
     * that has not been served yet in the current period.
     *
     * @return int >0 when something was sent, 0 when there was nothing to do
     */
    public static function cronSendCommercialReport(CronTask $task): int
    {
        $now  = new DateTimeImmutable();
        $sent = 0;

        foreach ((new TransmissionGroup())->find(['is_active' => 1]) as $group) {
            $period = ReportScheduler::periodKey($group, $now);
            if ($period === null) {
                continue;
            }

            $group_id = (int) $group['id'];
            $pending  = array_filter(
                TransmissionGroupEntity::getActiveForGroup($group_id),
                static fn (array $entity): bool => $entity['last_sent_period'] !== $period
            );
            if ($pending === []) {
                continue;
            }

            [$date_start, $date_end] = ReportScheduler::resolveWindow($group, $now);

            $sent += $group['report_type'] === TransmissionGroup::REPORT_DASHBOARD
                ? self::sendDashboardGroup($task, $group, $pending, $period, $date_start, $date_end)
                : self::sendCommercialGroup($task, $group, $pending, $period, $date_start, $date_end);
        }

        $task->addVolume($sent);

        return $sent > 0 ? 1 : 0;
    }

    /**
     * @param array<string, mixed>          $group
     * @param array<int, array<string, mixed>> $pending entities_id => entity config
     */
    private static function sendCommercialGroup(
        CronTask $task,
        array $group,
        array $pending,
        string $period,
        string $date_start,
        string $date_end
    ): int {
        $group_id = (int) $group['id'];

        $rows = [];
        foreach (Report::getGeneralReport($date_start, $date_end) as $row) {
            $rows[$row['entities_id']] = $row;
        }

        $sent = 0;

        foreach ($pending as $entities_id => $entity_config) {
            if (!isset($rows[$entities_id])) {
                // No contract with hours in this period: nothing to report.
                $task->log(sprintf('Grupo %d, entidade %d ignorada: sem contrato com horas.', $group_id, $entities_id));
                continue;
            }

            $recipients = TransmissionGroupEntity::resolveRecipients($entity_config);
            foreach ($recipients['skipped'] as $skipped) {
                $task->log(sprintf('Grupo %d, entidade %d, destinatário ignorado: %s', $group_id, $entities_id, $skipped));
            }

            if ($recipients['emails'] === []) {
                // Deliberately not stamped: the problem must resurface next tick.
                $task->log(sprintf('Grupo %d, entidade %d ignorada: nenhum destinatário válido.', $group_id, $entities_id));
                continue;
            }

            $row       = $rows[$entities_id];
            $entity    = Entity::getById($entities_id);
            $variables = self::buildVariablesComercial($row, $date_start, $date_end);

            $html_attachment = null;
            if ($entity !== false && (int) $group['send_html'] === 1) {
                $tickets         = Report::getTicketsForEntity($entities_id, $date_start, $date_end);
                $html_attachment = ReportRenderer::renderClientReport(
                    $entity->fields,
                    $tickets,
                    Report::getHoursByCategory($tickets),
                    $date_start,
                    $date_end
                );
            }
            $pdf_attachment = self::buildPdfAttachment($group, $entities_id, $entity_config, $date_start, $date_end);

            $error = self::send($entities_id, $group, $variables, $recipients['emails'], $html_attachment, $pdf_attachment);

            if ($error !== null) {
                $task->log(sprintf('Falha no envio do grupo %d para a entidade %d: %s', $group_id, $entities_id, $error));
                continue;
            }

            TransmissionGroupEntity::markSent($group_id, $entities_id, $period);
            $sent++;
        }

        return $sent;
    }

    /**
     * @param array<string, mixed>          $group
     * @param array<int, array<string, mixed>> $pending entities_id => entity config
     */
    private static function sendDashboardGroup(
        CronTask $task,
        array $group,
        array $pending,
        string $period,
        string $date_start,
        string $date_end
    ): int {
        $group_id = (int) $group['id'];
        $sent     = 0;

        foreach ($pending as $entities_id => $entity_config) {
            $rows = DashboardReport::getRows([
                'entities_id' => $entities_id,
                'date_start'  => $date_start,
                'date_end'    => $date_end,
            ]);

            if ($rows === []) {
                $task->log(sprintf('Grupo %d, entidade %d ignorada: nenhum chamado no período.', $group_id, $entities_id));
                continue;
            }

            $recipients = TransmissionGroupEntity::resolveRecipients($entity_config);
            foreach ($recipients['skipped'] as $skipped) {
                $task->log(sprintf('Grupo %d, entidade %d, destinatário ignorado: %s', $group_id, $entities_id, $skipped));
            }

            if ($recipients['emails'] === []) {
                $task->log(sprintf('Grupo %d, entidade %d ignorada: nenhum destinatário válido.', $group_id, $entities_id));
                continue;
            }

            $entity = Entity::getById($entities_id);
            $nome   = $entity !== false ? (string) $entity->fields['name'] : (string) $entities_id;

            $summary   = DashboardReport::summarize($rows);
            $variables = self::buildVariablesDashboard($nome, $summary, $date_start, $date_end);

            $html_attachment = (int) $group['send_html'] === 1
                ? DashboardRenderer::render($rows, $date_start, $date_end)
                : null;
            $pdf_attachment = self::buildPdfAttachment($group, $entities_id, $entity_config, $date_start, $date_end);

            $error = self::send($entities_id, $group, $variables, $recipients['emails'], $html_attachment, $pdf_attachment);

            if ($error !== null) {
                $task->log(sprintf('Falha no envio do grupo %d para a entidade %d: %s', $group_id, $entities_id, $error));
                continue;
            }

            TransmissionGroupEntity::markSent($group_id, $entities_id, $period);
            $sent++;
        }

        return $sent;
    }

    /**
     * Sends the report of one entity to the current user only, using the very
     * same code path as the cron so that SMTP, subject, body and attachments
     * are all exercised for real. Never touches last_sent_period.
     *
     * @return string|null null on success, the error message otherwise
     */
    public static function sendTest(int $group_id, int $entities_id, int $users_id): ?string
    {
        $group = new TransmissionGroup();
        if (!$group->getFromDB($group_id)) {
            return __('Grupo de transmissão não encontrado.', 'relatorioglpicomercial');
        }

        $email = \UserEmail::getDefaultForUser($users_id);
        if ($email === '') {
            return __('Seu usuário não possui e-mail cadastrado.', 'relatorioglpicomercial');
        }

        $now = new DateTimeImmutable();
        [$date_start, $date_end] = ReportScheduler::resolveWindow($group->fields, $now);

        $entity_config = TransmissionGroupEntity::getAllForGroup($group_id)[$entities_id] ?? [];

        if ($group->fields['report_type'] === TransmissionGroup::REPORT_DASHBOARD) {
            $rows = DashboardReport::getRows([
                'entities_id' => $entities_id,
                'date_start'  => $date_start,
                'date_end'    => $date_end,
            ]);
            if ($rows === []) {
                return __('A entidade selecionada não possui chamados no período.', 'relatorioglpicomercial');
            }

            $entity    = Entity::getById($entities_id);
            $nome      = $entity !== false ? (string) $entity->fields['name'] : (string) $entities_id;
            $summary   = DashboardReport::summarize($rows);
            $variables = self::buildVariablesDashboard($nome, $summary, $date_start, $date_end);

            $html_attachment = (int) $group->fields['send_html'] === 1
                ? DashboardRenderer::render($rows, $date_start, $date_end)
                : null;
            $pdf_attachment = self::buildPdfAttachment($group->fields, $entities_id, $entity_config, $date_start, $date_end);

            return self::send($entities_id, $group->fields, $variables, [$email], $html_attachment, $pdf_attachment);
        }

        foreach (Report::getGeneralReport($date_start, $date_end) as $row) {
            if ($row['entities_id'] !== $entities_id) {
                continue;
            }

            $entity    = Entity::getById($entities_id);
            $variables = self::buildVariablesComercial($row, $date_start, $date_end);

            $html_attachment = null;
            if ($entity !== false && (int) $group->fields['send_html'] === 1) {
                $tickets         = Report::getTicketsForEntity($entities_id, $date_start, $date_end);
                $html_attachment = ReportRenderer::renderClientReport(
                    $entity->fields,
                    $tickets,
                    Report::getHoursByCategory($tickets),
                    $date_start,
                    $date_end
                );
            }
            $pdf_attachment = self::buildPdfAttachment($group->fields, $entities_id, $entity_config, $date_start, $date_end);

            return self::send($entities_id, $group->fields, $variables, [$email], $html_attachment, $pdf_attachment);
        }

        return __('A entidade selecionada não possui contrato com horas no período.', 'relatorioglpicomercial');
    }

    /**
     * The group's PDF attachment for one client, or null when the group does
     * not attach one.
     *
     * @param array<string, mixed> $group         A TransmissionGroup's fields
     * @param array<string, mixed> $entity_config The client's row of the group
     * @return array{content: string, filename: string}|null
     */
    private static function buildPdfAttachment(
        array $group,
        int $entities_id,
        array $entity_config,
        string $date_start,
        string $date_end
    ): ?array {
        if ((int) $group['send_pdf'] !== 1) {
            return null;
        }

        return AttachmentComposer::render(
            AttachmentConfig::fromFields($group),
            $entities_id,
            $entity_config,
            $date_start,
            $date_end
        );
    }

    /**
     * @param array<string, mixed> $group     A TransmissionGroup's fields
     * @param array<string, string> $variables
     * @param array<int, string>   $emails    Already validated addresses
     * @param array{content: string, filename: string}|null $pdf_attachment
     * @return string|null null on success, the error message otherwise
     */
    private static function send(
        int $entities_id,
        array $group,
        array $variables,
        array $emails,
        ?string $html_attachment,
        ?array $pdf_attachment
    ): ?string {
        $sender = Config::getEmailSender($entities_id);

        if ($sender['email'] === null) {
            return __('Nenhum remetente de e-mail configurado no GLPI.', 'relatorioglpicomercial');
        }

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
            $mailer->Subject = self::renderTemplate(TransmissionGroup::effectiveSubjectFor($group), $variables, false);
            $mailer->isHTML(true);
            $mailer->Body = self::renderTemplate(TransmissionGroup::effectiveBodyFor($group), $variables, true);

            if ($html_attachment !== null) {
                $mailer->addStringAttachment($html_attachment, 'relatorio.html', 'base64', 'text/html');
            }
            if ($pdf_attachment !== null) {
                $mailer->addStringAttachment($pdf_attachment['content'], $pdf_attachment['filename'], 'base64', 'application/pdf');
            }

            if (!$mailer->send()) {
                return $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : __('Falha desconhecida no envio.', 'relatorioglpicomercial');
            }
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Values offered to the subject and body templates of a "comercial"
     * group, formatted by {@see ReportRenderer} so they match the on-screen
     * report exactly.
     *
     * @param array<string, mixed> $row One row of Report::getGeneralReport()
     * @return array<string, string>
     */
    public static function buildVariablesComercial(array $row, string $date_start, string $date_end): array
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
     * Values offered to the subject and body templates of a "dashboard"
     * group.
     *
     * @param array<string, mixed> $summary DashboardReport::summarize() output
     * @return array<string, string>
     */
    public static function buildVariablesDashboard(string $cliente, array $summary, string $date_start, string $date_end): array
    {
        $data_inicio = ReportRenderer::formatDate($date_start);
        $data_fim    = ReportRenderer::formatDate($date_end);

        $total  = (int) $summary['total'];
        $violado = (int) $summary['violado'];
        $no_prazo = (int) $summary['no_prazo'];
        $base    = $violado + $no_prazo;
        $sla_percentual = $base > 0 ? ($no_prazo / $base) * 100 : 0.0;

        return [
            'cliente'             => $cliente,
            'periodo'             => $data_inicio . ' - ' . $data_fim,
            'data_inicio'         => $data_inicio,
            'data_fim'            => $data_fim,
            'total_chamados'      => (string) $total,
            'sla_percentual'      => number_format($sla_percentual, 1),
            'sla_percentual_cor'  => ReportRenderer::usageColor(100 - $sla_percentual),
            'gerado_em'           => date('d/m/Y H:i'),
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
