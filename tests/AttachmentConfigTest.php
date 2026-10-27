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

use DateTimeImmutable;
use GlpiPlugin\Relatorioglpicomercial\AttachmentConfig;
use GlpiPlugin\Relatorioglpicomercial\TransmissionGroup;
use PHPUnit\Framework\TestCase;

class AttachmentConfigTest extends TestCase
{
    public function testDefaultsEnableOnlyTheReportOfTheGroupType(): void
    {
        $dashboard = AttachmentConfig::defaults(TransmissionGroup::REPORT_DASHBOARD);
        $comercial = AttachmentConfig::defaults(TransmissionGroup::REPORT_COMERCIAL);

        $this->assertTrue($dashboard['sections']['chamados']['enabled']);
        $this->assertFalse($dashboard['sections']['comercial']['enabled']);
        $this->assertFalse($comercial['sections']['chamados']['enabled']);
        $this->assertTrue($comercial['sections']['comercial']['enabled']);
        $this->assertTrue($comercial['sections']['capa']['enabled']);
        $this->assertSame(array_keys(AttachmentConfig::getSections()), $dashboard['order']);
    }

    public function testNormalizeDropsUnknownKeysAndKeepsTheSubmittedOrder(): void
    {
        $config = AttachmentConfig::normalize([
            'order'    => ['aceite', 'nope', 'capa', 'aceite'],
            'sections' => ['nope' => ['enabled' => 1]],
            'evil'     => 'x',
        ], TransmissionGroup::REPORT_DASHBOARD);

        $this->assertArrayNotHasKey('evil', $config);
        $this->assertArrayNotHasKey('nope', $config['sections']);
        $this->assertSame(['aceite', 'capa'], array_slice($config['order'], 0, 2));
        // Sections the input forgot are appended, none is lost.
        $this->assertEqualsCanonicalizing(array_keys(AttachmentConfig::getSections()), $config['order']);
    }

    public function testNormalizeReadsUncheckedSwitchesAsDisabledAndClampsNumbers(): void
    {
        $config = AttachmentConfig::normalize([
            'sections' => [
                'capa'        => ['enabled' => '0'],
                'indicadores' => [
                    'enabled'          => '1',
                    'show_kpis'        => '0',
                    'meta_atendimento' => '150',
                    'meta_solucao'     => '87,5',
                ],
            ],
        ], TransmissionGroup::REPORT_DASHBOARD);

        $this->assertFalse($config['sections']['capa']['enabled']);
        $this->assertTrue($config['sections']['indicadores']['enabled']);
        $this->assertFalse($config['sections']['indicadores']['show_kpis']);
        $this->assertSame(100.0, $config['sections']['indicadores']['meta_atendimento']);
        $this->assertSame(87.5, $config['sections']['indicadores']['meta_solucao']);
    }

    public function testNormalizeKeepsDefaultTitlesWhenLeftBlankAndSanitizesColumns(): void
    {
        $config = AttachmentConfig::normalize([
            'sections' => [
                'chamados' => ['enabled' => '1', 'title' => '  ', 'columns' => ['titulo', 'unknown', 'id']],
                'aceite'   => ['enabled' => '1', 'text' => "Linha 1\r\n\r\nLinha 2"],
            ],
        ], TransmissionGroup::REPORT_DASHBOARD);

        $this->assertSame('2.2 Relatório de Chamados', $config['sections']['chamados']['title']);
        // Catalog order, unknown keys dropped.
        $this->assertSame(['id', 'titulo'], $config['sections']['chamados']['columns']);
        $this->assertSame("Linha 1\n\nLinha 2", $config['sections']['aceite']['text']);
    }

    public function testFromJsonFallsBackToTheDefaultsWhenUnreadable(): void
    {
        $this->assertSame(
            AttachmentConfig::defaults(TransmissionGroup::REPORT_COMERCIAL),
            AttachmentConfig::fromJson('{not json', TransmissionGroup::REPORT_COMERCIAL)
        );
        $this->assertSame(
            AttachmentConfig::defaults(TransmissionGroup::REPORT_DASHBOARD),
            AttachmentConfig::fromFields(['report_type' => TransmissionGroup::REPORT_DASHBOARD, 'attachment_config' => null])
        );
    }

    public function testJsonRoundTripIsLossless(): void
    {
        $config = AttachmentConfig::normalize([
            'filename' => 'Relatório "{{ cliente }}".pdf',
            'sections' => ['sumario' => ['enabled' => '1', 'text' => 'Texto com <tags> & aspas \'simples\'']],
        ], TransmissionGroup::REPORT_DASHBOARD);

        $this->assertSame($config, AttachmentConfig::fromJson(AttachmentConfig::toJson($config), TransmissionGroup::REPORT_DASHBOARD));
    }

    public function testBuildVariablesPrefersTheClientValuesOverTheGroupDefaults(): void
    {
        $config = AttachmentConfig::defaults(TransmissionGroup::REPORT_DASHBOARD);
        $config['contract_number']                              = 'CT-{{ cliente }}';
        $config['sections']['aceite']['responsavel_cliente']    = 'Padrão';
        $config['sections']['aceite']['cargo_cliente']          = 'Cargo padrão';
        $config['sections']['identificacao']['revisao']        = 'Rev. {{ gerado_em }}';

        $now       = new DateTimeImmutable('2026-10-01 08:30:00');
        $variables = AttachmentConfig::buildVariables(
            $config,
            'ACME',
            '2026-09-01',
            '2026-09-30',
            ['client_rep_name' => 'Maria', 'client_rep_title' => '  ', 'contract_number' => ''],
            $now
        );

        $this->assertSame(AttachmentConfig::VARIABLES, array_keys($variables));
        $this->assertSame('CT-ACME', $variables['contrato']);
        $this->assertSame('Maria', $variables['responsavel_cliente']);
        $this->assertSame('Cargo padrão', $variables['cargo_cliente']);
        $this->assertSame('Rev. 01/10/2026 08:30', $variables['revisao']);
        $this->assertSame('2026', $variables['ano']);
        $this->assertSame('SET/2026', $variables['periodo_curto']);
    }

    public function testShortPeriod(): void
    {
        $this->assertSame('FEV/2028', AttachmentConfig::shortPeriod('2028-02-01', '2028-02-29'));
        $this->assertSame('01/09 – 15/09/2026', AttachmentConfig::shortPeriod('2026-09-01', '2026-09-15'));
        $this->assertSame('15/12/2026 – 14/01/2027', AttachmentConfig::shortPeriod('2026-12-15', '2027-01-14'));
    }

    public function testFilenameIsSafeForMailClientsAndFileSystems(): void
    {
        $config = AttachmentConfig::defaults(TransmissionGroup::REPORT_DASHBOARD);

        $this->assertSame(
            'Relatorio_ACME_Ltda_SET-2026.pdf',
            AttachmentConfig::filename($config, ['cliente' => 'ACME Ltda', 'periodo_curto' => 'SET/2026'])
        );

        $config['filename'] = '{{ cliente }}: <relatório>?';
        $this->assertSame('ACME_relatório.pdf', AttachmentConfig::filename($config, ['cliente' => 'ACME']));

        $config['filename'] = '{{ desconhecido }}';
        $this->assertSame('relatorio.pdf', AttachmentConfig::filename($config, []));
    }
}
