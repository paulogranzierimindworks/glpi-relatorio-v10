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

use Glpi\Application\View\TemplateRenderer;

/**
 * Renders the on-screen reports as GLPI-native markup (Tabler cards and
 * tables) meant to be echoed inside a regular GLPI page.
 *
 * The prepare*Data() methods are the single source of truth for formatting and
 * totals: the PDF export consumes them too, so both outputs always agree. The
 * *_cor entries carry raw hex colors for TCPDF, the *_css entries carry the
 * equivalent Bootstrap utility class for the browser.
 */
class ReportRenderer
{
    /** SLA is considered healthy from this percentage of non-violated tickets up. */
    private const SLA_TARGET = 90.0;

    /** Contract usage above this percentage is worth a warning, at 100% it is an overrun. */
    private const USAGE_WARN = 80.0;

    private const COLOR_OK    = '#0F6E56';
    private const COLOR_WARN  = '#b7791f';
    private const COLOR_ALERT = '#c0392b';

    private const CSS_OK    = 'text-success';
    private const CSS_WARN  = 'text-warning';
    private const CSS_ALERT = 'text-danger';

    public static function renderClientReport(
        array $entity,
        array $tickets,
        array $categories,
        string $date_start,
        string $date_end,
        ?string $pdf_url = null
    ): string {
        return TemplateRenderer::getInstance()->render(
            '@relatorioglpicomercial/report_client.html.twig',
            self::prepareClientData($entity, $tickets, $categories, $date_start, $date_end)
            + [
                'pdf_url'       => $pdf_url,
                'can_link_item' => \Session::haveRight('ticket', READ),
            ]
        );
    }

    public static function renderGeneralReport(
        array $rows,
        string $date_start,
        string $date_end,
        ?string $pdf_url = null
    ): string {
        return TemplateRenderer::getInstance()->render(
            '@relatorioglpicomercial/report_general.html.twig',
            self::prepareGeneralData($rows, $date_start, $date_end)
            + [
                'pdf_url'       => $pdf_url,
                'can_link_item' => \Session::haveRight('entity', READ),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function prepareClientData(
        array $entity,
        array $tickets,
        array $categories,
        string $date_start,
        string $date_end
    ): array {
        $total_segundos = array_sum(array_column($tickets, 'tempo_trabalhado_segundos'));

        $violacoes = count(array_filter(
            $tickets,
            static fn (array $ticket): bool => ($ticket['situacao'] ?? '') === 'Violação'
        ));
        $sla_percentual = count($tickets) > 0 ? (1 - ($violacoes / count($tickets))) * 100 : 0.0;

        $tickets = array_map(static function (array $ticket): array {
            $ticket['tempo_trabalhado_formatado'] = self::formatSeconds($ticket['tempo_trabalhado_segundos']);
            $ticket['data_abertura_fmt']          = self::formatDateTime($ticket['data_abertura']);
            $ticket['data_solucao_fmt']           = self::formatDateTime($ticket['data_solucao']);
            $ticket['data_fechamento_fmt']        = self::formatDateTime($ticket['data_fechamento']);
            return $ticket;
        }, $tickets);

        $categories = array_map(static function (array $category): array {
            $category['tempo_total_formatado'] = self::formatSeconds($category['tempo_total_segundos']);
            return $category;
        }, $categories);

        return [
            'entity'                     => $entity,
            'tickets'                    => $tickets,
            'categories'                 => $categories,
            'date_start_fmt'             => self::formatDate($date_start),
            'date_end_fmt'               => self::formatDate($date_end),
            'total_trabalhado_formatado' => self::formatSeconds($total_segundos),
            'violacoes'                  => $violacoes,
            'violacoes_cor'              => $violacoes > 0 ? self::COLOR_ALERT : self::COLOR_OK,
            'violacoes_css'              => $violacoes > 0 ? self::CSS_ALERT : self::CSS_OK,
            'sla_percentual'             => $sla_percentual,
            'sla_percentual_fmt'         => number_format($sla_percentual, 1),
            'sla_cor'                    => $sla_percentual >= self::SLA_TARGET ? self::COLOR_OK : self::COLOR_ALERT,
            'sla_css'                    => $sla_percentual >= self::SLA_TARGET ? self::CSS_OK : self::CSS_ALERT,
            'generated_at'               => date('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function prepareGeneralData(array $rows, string $date_start, string $date_end): array
    {
        $total_contratadas = array_sum(array_column($rows, 'horas_contratadas'));
        $total_trabalhadas = array_sum(array_column($rows, 'horas_trabalhadas'));

        $rows = array_map(static function (array $row): array {
            $row['horas_contratadas_fmt'] = self::formatHours($row['horas_contratadas']);
            $row['horas_trabalhadas_fmt'] = self::formatHours($row['horas_trabalhadas']);
            $row['percentual_fmt']        = number_format($row['percentual'], 1);
            $row['percentual_css']        = self::usageCss((float) $row['percentual']);
            return $row;
        }, $rows);

        $total_percentual = $total_contratadas > 0 ? ($total_trabalhadas / $total_contratadas) * 100 : 0;

        return [
            'rows'                     => $rows,
            'date_start_fmt'           => self::formatDate($date_start),
            'date_end_fmt'             => self::formatDate($date_end),
            'total_contratadas_fmt'    => self::formatHours($total_contratadas),
            'total_trabalhadas_fmt'    => self::formatHours($total_trabalhadas),
            'total_percentual'         => $total_percentual,
            'total_percentual_fmt'     => number_format($total_percentual, 1),
            'total_percentual_css'     => self::usageCss($total_percentual),
            'generated_at'             => date('d/m/Y H:i'),
        ];
    }

    /** Bootstrap text class for a contracted-hours usage percentage. */
    private static function usageCss(float $percentual): string
    {
        if ($percentual >= 100) {
            return self::CSS_ALERT;
        }

        return $percentual >= self::USAGE_WARN ? self::CSS_WARN : self::CSS_OK;
    }

    /**
     * Hex color for a contracted-hours usage percentage, for outputs that
     * cannot use Bootstrap classes (PDF, email).
     */
    public static function usageColor(float $percentual): string
    {
        if ($percentual >= 100) {
            return self::COLOR_ALERT;
        }

        return $percentual >= self::USAGE_WARN ? self::COLOR_WARN : self::COLOR_OK;
    }

    private static function formatSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /** Decimal hours (ex: 2.3333) formatted as HH:MM, like the original report. */
    public static function formatHours(float $decimal): string
    {
        $horas = (int) floor($decimal);
        $minutos = (int) round(($decimal - $horas) * 60);
        return sprintf('%02d:%02d', $horas, $minutos);
    }

    public static function formatDate(string $date): string
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false ? $d->format('d/m/Y') : $date;
    }

    private static function formatDateTime(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '-';
        }
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
        return $d !== false ? $d->format('d/m/Y H:i') : '-';
    }
}
