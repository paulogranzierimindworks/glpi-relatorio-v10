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

use GlpiPlugin\Relatorioglpicomercial\ReportMailer;
use PHPUnit\Framework\TestCase;

class ReportMailerTest extends TestCase
{
    public function testBuildVariablesComercialMatchesTheDeclaredPlaceholders(): void
    {
        $row = [
            'nome'              => 'ACME',
            'horas_contratadas' => 40.0,
            'horas_trabalhadas' => 38.5,
            'percentual'        => 96.25,
        ];

        $variables = ReportMailer::buildVariablesComercial($row, '2026-09-01', '2026-09-30');

        $this->assertSame(ReportMailer::VARIABLES_COMERCIAL, array_keys($variables));
        $this->assertSame('ACME', $variables['cliente']);
        $this->assertSame('01/09/2026 - 30/09/2026', $variables['periodo']);
        $this->assertSame('96.3', $variables['percentual']);
    }

    public function testBuildVariablesDashboardMatchesTheDeclaredPlaceholders(): void
    {
        $summary = ['total' => 10, 'violado' => 1, 'no_prazo' => 9];

        $variables = ReportMailer::buildVariablesDashboard('ACME', $summary, '2026-09-01', '2026-09-30');

        $this->assertSame(ReportMailer::VARIABLES_DASHBOARD, array_keys($variables));
        $this->assertSame('ACME', $variables['cliente']);
        $this->assertSame('10', $variables['total_chamados']);
        $this->assertSame('90.0', $variables['sla_percentual']);
    }

    public function testBuildVariablesDashboardHandlesNoTicketsWithoutDivisionByZero(): void
    {
        $summary = ['total' => 0, 'violado' => 0, 'no_prazo' => 0];

        $variables = ReportMailer::buildVariablesDashboard('ACME', $summary, '2026-09-01', '2026-09-30');

        $this->assertSame('0', $variables['total_chamados']);
        $this->assertSame('0.0', $variables['sla_percentual']);
    }
}
