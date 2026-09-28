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
 * Builds the downloadable A4 portrait reports.
 *
 * Data and formatting come from ReportRenderer so the PDF and the on-screen
 * preview never drift apart; only the markup differs, because the preview
 * targets a browser and the PDF targets TCPDF's much narrower HTML support.
 */
class ReportPdf
{
    private const EYEBROW = 'Relatório Comercial';

    private const COLOR_INFO  = '#185fa5';
    private const COLOR_WARN  = '#b7791f';
    private const COLOR_OK    = '#0F6E56';
    private const COLOR_ALERT = '#c0392b';

    /** Relative width of each dashboard ticket column in the PDF. */
    private const DEFAULT_WEIGHT = 10;
    private const COLUMN_WEIGHTS = [
        'id'                => 5,
        'tipo'              => 8,
        'entidade'          => 14,
        'solicitante'       => 13,
        'grupo_solicitante' => 13,
        'categoria'         => 14,
        'analista'          => 13,
        'grupo_atribuido'   => 13,
        'titulo'            => 26,
        'prioridade'        => 8,
        'prioridade_glpi'   => 8,
        'impacto'           => 8,
        'origem'            => 11,
        'localizacao'       => 13,
        'status'            => 9,
        'abertura'          => 14,
        'data'              => 14,
        'atualizacao'       => 14,
        'tempo_atendimento' => 14,
        'solucao'           => 14,
        'tempo_solucao'     => 14,
        'fechamento'        => 14,
        'horas_previstas'   => 9,
        'sla_atendimento'   => 9,
        'sla'               => 9,
        'nota'              => 5,
    ];

    public static function streamClientReport(
        array $entity,
        array $tickets,
        array $categories,
        string $date_start,
        string $date_end
    ): void {
        $data = ReportRenderer::prepareClientData($entity, $tickets, $categories, $date_start, $date_end);

        $heading = (string) ($entity['name'] ?? '');
        $pdf     = new ReportPdfDocument(
            self::EYEBROW,
            $heading !== '' ? $heading : 'Cliente',
            self::formatPeriod($data),
            $data['generated_at']
        );

        $pdf->drawSummaryCards([
            ['label' => 'Chamados', 'value' => (string) count($tickets)],
            ['label' => 'Horas', 'value' => $data['total_trabalhado_formatado']],
            ['label' => 'SLA', 'value' => $data['sla_percentual_fmt'] . '%', 'color' => $data['sla_cor']],
            ['label' => 'Violações', 'value' => (string) $data['violacoes'], 'color' => $data['violacoes_cor']],
        ]);

        $pdf->writeBody(
            TemplateRenderer::getInstance()->render('@relatorioglpicomercial/pdf/report_client.html.twig', $data)
        );

        $pdf->Output('relatorio-cliente-' . $entity['id'] . '.pdf', 'D');
    }

    public static function streamGeneralReport(array $rows, string $date_start, string $date_end): void
    {
        $data = ReportRenderer::prepareGeneralData($rows, $date_start, $date_end);

        $pdf = new ReportPdfDocument(
            self::EYEBROW,
            'Horas por Cliente',
            self::formatPeriod($data),
            $data['generated_at']
        );

        $pdf->drawSummaryCards([
            ['label' => 'Clientes', 'value' => (string) count($rows)],
            ['label' => 'Contratadas', 'value' => $data['total_contratadas_fmt']],
            ['label' => 'Trabalhadas', 'value' => $data['total_trabalhadas_fmt']],
        ]);

        $pdf->writeBody(
            TemplateRenderer::getInstance()->render('@relatorioglpicomercial/pdf/report_general.html.twig', $data)
        );

        $pdf->Output('relatorio-geral.pdf', 'D');
    }

    /**
     * "Dashboard de Chamados" in landscape: the ticket table can hold dozens of
     * columns, so it gets the wider page. The ticket columns are the ones the
     * user picked on screen.
     *
     * @param list<array<string, mixed>> $rows    rows from DashboardReport::getRows()
     * @param list<string>               $visible column keys, see DashboardColumns
     */
    public static function streamDashboardReport(array $rows, string $date_start, string $date_end, array $visible): void
    {
        $data = DashboardRenderer::prepareData($rows, $date_start, $date_end);

        $pdf = new ReportPdfDocument(
            self::EYEBROW,
            'Dashboard de Chamados',
            self::formatPeriod($data),
            $data['generated_at'],
            'L'
        );

        $kpis = $data['kpis'];
        $pdf->drawSummaryCards([
            ['label' => 'Total', 'value' => (string) $kpis['total']],
            ['label' => 'Em atendimento', 'value' => (string) $kpis['em_atendimento'], 'color' => self::COLOR_INFO],
            ['label' => 'Pendente', 'value' => (string) $kpis['pendente'], 'color' => self::COLOR_WARN],
            ['label' => 'Fechado', 'value' => (string) $kpis['fechado'], 'color' => self::COLOR_OK],
            ['label' => 'SLA violado', 'value' => (string) $kpis['violado'], 'color' => self::COLOR_ALERT],
            ['label' => 'SLA no prazo', 'value' => (string) $kpis['no_prazo'], 'color' => self::COLOR_OK],
            ['label' => 'Satisfação', 'value' => $data['media_satisfacao']],
        ]);

        $pdf->drawDonutPanels([
            ['title' => 'SLA Atendimento', 'caption' => 'chamados', 'series' => $data['sla_atendimento_series']],
            ['title' => 'SLA Solução', 'caption' => 'chamados', 'series' => $data['sla_series']],
            ['title' => 'Tipo do Chamado', 'caption' => 'chamados', 'series' => $data['tipo_series']],
        ]);

        $columns = self::pdfColumns($visible);

        $pdf->writeBody(
            TemplateRenderer::getInstance()->render(
                '@relatorioglpicomercial/pdf/report_dashboard.html.twig',
                $data + [
                    'columns'   => $columns,
                    'font_size' => self::dashboardFontSize(count($columns)),
                ]
            )
        );

        $pdf->Output('dashboard-chamados.pdf', 'D');
    }

    /**
     * The visible columns of the ticket table with the width (percentage of the
     * table) and alignment the PDF needs. Widths follow the typical content
     * length of each column so titles get room and IDs do not.
     *
     * @param list<string> $visible
     * @return list<array{key: string, label: string, field: string, width: string, align: string}>
     */
    private static function pdfColumns(array $visible): array
    {
        $columns = array_values(array_filter(
            DashboardColumns::describe($visible),
            static fn (array $column): bool => $column['visible']
        ));

        $weights = array_map(
            static fn (array $column): int => self::COLUMN_WEIGHTS[$column['key']] ?? self::DEFAULT_WEIGHT,
            $columns
        );
        $total = array_sum($weights);

        $result = [];
        foreach ($columns as $index => $column) {
            $result[] = [
                'key'   => $column['key'],
                'label' => $column['label'],
                'field' => $column['field'],
                'width' => number_format(($weights[$index] / $total) * 100, 2, '.', '') . '%',
                'align' => str_contains($column['class'], 'rgc-left') ? 'left' : 'center',
            ];
        }

        return $result;
    }

    private static function dashboardFontSize(int $columns): float
    {
        return match (true) {
            $columns <= 8  => 8.0,
            $columns <= 14 => 7.0,
            $columns <= 22 => 6.0,
            default        => 5.5,
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function formatPeriod(array $data): string
    {
        return $data['date_start_fmt'] . ' – ' . $data['date_end_fmt'];
    }
}
