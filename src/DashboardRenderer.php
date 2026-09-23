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
 * Renders the "Dashboard de Chamados" inline in a GLPI page. Formatting lives
 * in prepareData(); the Twig template only lays the values out.
 */
class DashboardRenderer
{
    private const SLA_COLORS = [
        DashboardReport::SLA_OK          => '#6fa8dc',
        DashboardReport::SLA_VIOLATED    => '#e15759',
        DashboardReport::SLA_NO_DEADLINE => '#8c9db5',
        DashboardReport::SLA_TO_VALIDATE => '#fdb913',
    ];

    private const TYPE_COLORS = [
        'Requisição' => '#6fa8dc',
        'Incidente'  => '#fdb913',
        'Outro'      => '#8c9db5',
    ];

    private const FALLBACK_COLOR = '#8c9db5';

    /**
     * @param list<array<string, mixed>> $rows rows from DashboardReport::getRows()
     */
    public static function render(array $rows, string $date_start, string $date_end): string
    {
        return TemplateRenderer::getInstance()->render(
            '@relatorioglpicomercial/report_dashboard.html.twig',
            self::prepareData($rows, $date_start, $date_end)
            + ['can_link_item' => \Session::haveRight('ticket', READ)]
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function prepareData(array $rows, string $date_start, string $date_end): array
    {
        $summary = DashboardReport::summarize($rows);

        $rows = array_map(static function (array $row): array {
            $row['abertura_fmt']          = self::formatDateTime($row['abertura']);
            $row['tempo_atendimento_fmt'] = self::formatDateTime($row['tempo_atendimento']);
            $row['solucao_fmt']           = self::formatDateTime($row['solucao']);
            $row['tempo_solucao_fmt']     = self::formatDateTime($row['tempo_solucao']);
            $row['total_fmt']             = self::formatSecondsOrBlank((int) $row['total_segundos']);
            $row['periodo_fmt']           = self::formatSecondsOrBlank((int) $row['periodo_segundos']);
            $row['nota_fmt']              = $row['nota'] !== null ? (string) $row['nota'] : '';
            return $row;
        }, $rows);

        $categorias = array_map(static function (array $categoria): array {
            $categoria['tempo_fmt'] = ReportRenderer::formatSeconds($categoria['segundos']);
            return $categoria;
        }, $summary['categorias']);

        return [
            'rows'                => $rows,
            'kpis'                => [
                'em_atendimento' => $summary['em_atendimento'],
                'pendente'       => $summary['pendente'],
                'fechado'        => $summary['fechado'],
                'total'          => $summary['total'],
                'violado'        => $summary['violado'],
                'no_prazo'       => $summary['no_prazo'],
            ],
            'media_satisfacao'    => $summary['media_satisfacao'] !== null
                ? number_format($summary['media_satisfacao'], 2, ',', '')
                : '-',
            'sla_series'          => self::series($summary['sla'], self::SLA_COLORS),
            'tipo_series'         => self::series($summary['tipos'], self::TYPE_COLORS),
            'categorias'          => $categorias,
            'total_periodo_fmt'   => ReportRenderer::formatSeconds($summary['total_periodo_segundos']),
            'date_start_fmt'      => ReportRenderer::formatDate($date_start),
            'date_end_fmt'        => ReportRenderer::formatDate($date_end),
            'generated_at'        => date('d/m/Y H:i'),
        ];
    }

    /**
     * Donut series (label, count, percentage text, color) from label => count.
     *
     * @param array<string, int>    $counts
     * @param array<string, string> $colors
     * @return list<array{label: string, value: int, pct: string, color: string}>
     */
    public static function series(array $counts, array $colors): array
    {
        $total = array_sum($counts);
        $series = [];
        foreach ($counts as $label => $value) {
            $series[] = [
                'label' => (string) $label,
                'value' => $value,
                'pct'   => self::formatPercent($total > 0 ? ($value / $total) * 100 : 0.0),
                'color' => $colors[$label] ?? self::FALLBACK_COLOR,
            ];
        }

        return $series;
    }

    /** "100%", "87,5%": one decimal only when needed, comma as separator. */
    public static function formatPercent(float $percent): string
    {
        $text = number_format($percent, 1, ',', '');
        if (str_ends_with($text, ',0')) {
            $text = substr($text, 0, -2);
        }

        return $text . '%';
    }

    private static function formatSecondsOrBlank(int $seconds): string
    {
        return $seconds > 0 ? ReportRenderer::formatSeconds($seconds) : '';
    }

    private static function formatDateTime(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $datetime);

        return $d !== false ? $d->format('d/m/Y H:i:s') : '';
    }
}
