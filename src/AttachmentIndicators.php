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

/**
 * Rows of the "Painel de Indicadores" of the attachment, computed from the
 * dashboard's own SLA rule ({@see DashboardReport::slaLabel()}) so the panel
 * and the ticket table can never disagree.
 *
 * Only tickets whose SLA was actually measured count: "no prazo" is met,
 * "violado" is not met; tickets still in progress or without a deadline are
 * left out of the percentage.
 */
class AttachmentIndicators
{
    public const COLOR_MET     = '#15803d';
    public const COLOR_MISSED  = '#c0392b';
    public const COLOR_NEUTRAL = '#000e27';

    /**
     * @param array<string, mixed> $summary DashboardReport::summarize() output
     * @return list<array{
     *     id: string,
     *     indicador: string,
     *     meta: string,
     *     atendido: int,
     *     nao_atendido: int,
     *     percentual: ?float,
     *     percentual_fmt: string,
     *     cor: string,
     * }>
     */
    public static function compute(array $summary, float $meta_atendimento, float $meta_solucao): array
    {
        return [
            self::row('SLA01', 'Prazo de atendimento dos chamados', $summary['sla_atendimento'] ?? [], $meta_atendimento),
            self::row('SLA02', 'Prazo de solução dos chamados', $summary['sla'] ?? [], $meta_solucao),
        ];
    }

    /**
     * @param array<string, int> $counts SLA label => tickets
     * @return array{id: string, indicador: string, meta: string, atendido: int, nao_atendido: int, percentual: ?float, percentual_fmt: string, cor: string}
     */
    private static function row(string $id, string $indicador, array $counts, float $meta): array
    {
        $atendido     = (int) ($counts[DashboardReport::SLA_OK] ?? 0);
        $nao_atendido = (int) ($counts[DashboardReport::SLA_VIOLATED] ?? 0);
        $base         = $atendido + $nao_atendido;
        $percentual   = $base > 0 ? ($atendido / $base) * 100 : null;

        return [
            'id'             => $id,
            'indicador'      => $indicador,
            'meta'           => DashboardRenderer::formatPercent($meta),
            'atendido'       => $atendido,
            'nao_atendido'   => $nao_atendido,
            'percentual'     => $percentual,
            'percentual_fmt' => $percentual !== null ? number_format($percentual, 2, ',', '.') . '%' : '-',
            'cor'            => match (true) {
                $percentual === null  => self::COLOR_NEUTRAL,
                $percentual >= $meta  => self::COLOR_MET,
                default               => self::COLOR_MISSED,
            },
        ];
    }
}
