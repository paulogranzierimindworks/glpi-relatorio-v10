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
     * @param array<string, mixed> $data
     */
    private static function formatPeriod(array $data): string
    {
        return $data['date_start_fmt'] . ' – ' . $data['date_end_fmt'];
    }
}
