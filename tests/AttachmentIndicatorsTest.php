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

use GlpiPlugin\Relatorioglpicomercial\AttachmentComposer;
use GlpiPlugin\Relatorioglpicomercial\AttachmentIndicators;
use GlpiPlugin\Relatorioglpicomercial\DashboardReport;
use PHPUnit\Framework\TestCase;

class AttachmentIndicatorsTest extends TestCase
{
    public function testOnlyMeasuredTicketsCountTowardsThePercentage(): void
    {
        $rows = AttachmentIndicators::compute([
            'sla_atendimento' => [
                DashboardReport::SLA_OK          => 17,
                DashboardReport::SLA_VIOLATED    => 3,
                DashboardReport::SLA_IN_PROGRESS => 5,
            ],
            'sla' => [
                DashboardReport::SLA_OK => 4,
            ],
        ], 90.0, 99.5);

        $this->assertSame(['SLA01', 'SLA02'], array_column($rows, 'id'));

        $this->assertSame(17, $rows[0]['atendido']);
        $this->assertSame(3, $rows[0]['nao_atendido']);
        $this->assertSame('85,00%', $rows[0]['percentual_fmt']);
        $this->assertSame('90%', $rows[0]['meta']);
        $this->assertSame(AttachmentIndicators::COLOR_MISSED, $rows[0]['cor']);

        $this->assertSame('100,00%', $rows[1]['percentual_fmt']);
        $this->assertSame('99,5%', $rows[1]['meta']);
        $this->assertSame(AttachmentIndicators::COLOR_MET, $rows[1]['cor']);
    }

    public function testNoMeasuredTicketsIsNotAPercentage(): void
    {
        $rows = AttachmentIndicators::compute(['sla_atendimento' => [], 'sla' => []], 90.0, 90.0);

        $this->assertNull($rows[0]['percentual']);
        $this->assertSame('-', $rows[0]['percentual_fmt']);
        $this->assertSame(AttachmentIndicators::COLOR_NEUTRAL, $rows[0]['cor']);
    }

    public function testHoursPanelKeepsTheBiggestCategoriesAndGroupsTheRest(): void
    {
        $panel = AttachmentComposer::hoursPanel([
            'categorias' => [
                ['categoria' => 'A', 'segundos' => 600],
                ['categoria' => 'Raiz > B', 'segundos' => 7200],
                ['categoria' => 'C', 'segundos' => 3600],
                ['categoria' => 'D', 'segundos' => 1800],
                ['categoria' => 'E', 'segundos' => 1200],
                ['categoria' => 'F', 'segundos' => 300],
            ],
        ]);

        $this->assertSame(['B', 'C', 'D', 'E', 'Outras'], array_column($panel['series'], 'label'));
        $this->assertSame('02:00', $panel['series'][0]['display']);
        $this->assertSame(900, $panel['series'][4]['value']);
        $this->assertSame('04:05', $panel['total_display']);
    }
}
