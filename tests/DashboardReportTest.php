<?php

/**
 * -------------------------------------------------------------------------
 * Relatorio Comercial plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Relatorio Comercial plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Relatorioglpicomercial\Tests;

use GlpiPlugin\Relatorioglpicomercial\DashboardRenderer;
use GlpiPlugin\Relatorioglpicomercial\DashboardReport;
use PHPUnit\Framework\TestCase;

class DashboardReportTest extends TestCase
{
    private const NOW = '2026-09-23 12:00:00';

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'id'               => 1,
            'tipo'             => 'Requisição',
            'categoria'        => 'DBA',
            'status_code'      => 4,
            'sla'              => DashboardReport::SLA_OK,
            'nota'             => null,
            'periodo_segundos' => 0,
        ];
    }

    public function testEnumLabels(): void
    {
        $this->assertSame('Incidente', DashboardReport::typeLabel(1));
        $this->assertSame('Requisição', DashboardReport::typeLabel(2));
        $this->assertSame('Outro', DashboardReport::typeLabel(9));

        $this->assertSame('Muito Baixa', DashboardReport::urgencyLabel(1));
        $this->assertSame('Média', DashboardReport::urgencyLabel(3));
        $this->assertSame('Muito Alta', DashboardReport::urgencyLabel(5));

        $this->assertSame('Novo', DashboardReport::statusLabel(1));
        $this->assertSame('Em atendimento', DashboardReport::statusLabel(2));
        $this->assertSame('Em atendimento', DashboardReport::statusLabel(3));
        $this->assertSame('Pendente', DashboardReport::statusLabel(4));
        $this->assertSame('Solucionado', DashboardReport::statusLabel(5));
        $this->assertSame('Fechado', DashboardReport::statusLabel(6));
    }

    public function testSlaWithoutDeadline(): void
    {
        $this->assertSame(
            DashboardReport::SLA_NO_DEADLINE,
            DashboardReport::slaLabel(5, null, '2026-09-10 10:00:00', self::NOW)
        );
        $this->assertSame(
            DashboardReport::SLA_NO_DEADLINE,
            DashboardReport::slaLabel(4, '', null, self::NOW)
        );
    }

    public function testSlaOfClosedTickets(): void
    {
        $deadline = '2026-09-20 09:00:00';

        $this->assertSame(
            DashboardReport::SLA_TO_VALIDATE,
            DashboardReport::slaLabel(5, $deadline, null, self::NOW)
        );
        $this->assertSame(
            DashboardReport::SLA_OK,
            DashboardReport::slaLabel(6, $deadline, '2026-09-20 09:00:00', self::NOW)
        );
        $this->assertSame(
            DashboardReport::SLA_VIOLATED,
            DashboardReport::slaLabel(6, $deadline, '2026-09-20 09:00:01', self::NOW)
        );
    }

    public function testSlaOfOpenTicketsDependsOnTheDeadline(): void
    {
        $this->assertSame(
            DashboardReport::SLA_OK,
            DashboardReport::slaLabel(4, '2026-09-30 09:00:00', null, self::NOW)
        );
        $this->assertSame(
            DashboardReport::SLA_VIOLATED,
            DashboardReport::slaLabel(2, '2026-09-01 09:00:00', null, self::NOW)
        );
    }

    public function testSummarizeCountsEachTicketOnce(): void
    {
        $rows = [
            $this->row(['id' => 10, 'status_code' => 4, 'periodo_segundos' => 900, 'nota' => 5]),
            $this->row(['id' => 10, 'status_code' => 4, 'periodo_segundos' => 1800, 'nota' => 5]),
            $this->row(['id' => 11, 'status_code' => 2, 'tipo' => 'Incidente', 'sla' => DashboardReport::SLA_VIOLATED, 'categoria' => 'Servidores', 'periodo_segundos' => 600, 'nota' => 4]),
            $this->row(['id' => 12, 'status_code' => 6, 'periodo_segundos' => 0]),
            $this->row(['id' => 13, 'status_code' => 5, 'periodo_segundos' => 0]),
        ];

        $summary = DashboardReport::summarize($rows);

        $this->assertSame(4, $summary['total']);
        $this->assertSame(1, $summary['em_atendimento']);
        $this->assertSame(1, $summary['pendente']);
        $this->assertSame(1, $summary['fechado']);
        $this->assertSame(1, $summary['violado']);
        $this->assertSame(3, $summary['no_prazo']);
        $this->assertSame(3300, $summary['total_periodo_segundos']);
        $this->assertEqualsWithDelta(4.5, $summary['media_satisfacao'], 0.0001);
        $this->assertSame([DashboardReport::SLA_OK => 3, DashboardReport::SLA_VIOLATED => 1], $summary['sla']);
        $this->assertSame(['Requisição' => 3, 'Incidente' => 1], $summary['tipos']);
    }

    public function testSummarizeGroupsHoursByCategorySortedAscending(): void
    {
        $rows = [
            $this->row(['id' => 1, 'categoria' => 'DBA', 'periodo_segundos' => 3600]),
            $this->row(['id' => 2, 'categoria' => 'Servidores', 'periodo_segundos' => 1800]),
            $this->row(['id' => 3, 'categoria' => 'DBA', 'periodo_segundos' => 5400]),
            $this->row(['id' => 4, 'categoria' => 'Sem horas', 'periodo_segundos' => 0]),
        ];

        $this->assertSame(
            [
                ['categoria' => 'Servidores', 'segundos' => 1800],
                ['categoria' => 'DBA', 'segundos' => 9000],
            ],
            DashboardReport::summarize($rows)['categorias']
        );
    }

    public function testSummarizeOfNoRows(): void
    {
        $summary = DashboardReport::summarize([]);

        $this->assertSame(0, $summary['total']);
        $this->assertNull($summary['media_satisfacao']);
        $this->assertSame([], $summary['sla']);
        $this->assertSame([], $summary['categorias']);
    }

    public function testPercentFormatting(): void
    {
        $this->assertSame('100%', DashboardRenderer::formatPercent(100.0));
        $this->assertSame('87,5%', DashboardRenderer::formatPercent(87.5));
        $this->assertSame('12,5%', DashboardRenderer::formatPercent(12.5));
        $this->assertSame('0%', DashboardRenderer::formatPercent(0.0));
    }

    public function testSeriesComputesPercentagesAndColors(): void
    {
        $series = DashboardRenderer::series(
            ['Requisição' => 7, 'Incidente' => 1],
            ['Requisição' => '#111111']
        );

        $this->assertSame('87,5%', $series[0]['pct']);
        $this->assertSame('#111111', $series[0]['color']);
        $this->assertSame('12,5%', $series[1]['pct']);
        $this->assertNotSame('', $series[1]['color'], 'unknown labels fall back to a default color');
    }
}
