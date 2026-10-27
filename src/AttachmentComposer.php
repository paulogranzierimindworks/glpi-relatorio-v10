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

use Entity;
use Glpi\Application\View\TemplateRenderer;

/**
 * Builds the PDF a {@see TransmissionGroup} attaches to its emails, page by
 * page as configured in {@see AttachmentConfig}, for one client and period.
 *
 * The data comes from the same queries as the on-screen reports
 * ({@see DashboardReport::getRows()}, {@see Report::getTicketsForEntity()}),
 * so the attachment can never disagree with what the client sees in GLPI.
 *
 * The document is rendered twice: the first pass only records the page each
 * section starts on, so the second one can print those numbers in the table
 * of contents.
 */
class AttachmentComposer
{
    /** Sections that are not listed in the table of contents. */
    private const NOT_IN_TOC = ['capa', 'confidencialidade', 'identificacao'];

    /** Sections that need the ticket dashboard's rows. */
    private const NEED_DASHBOARD = ['indicadores', 'chamados', 'justificativas'];

    private const HOURS_SLICES = 4;
    private const HOURS_COLORS = ['#005295', '#6be3f7', '#205493', '#fdb913', '#59a14f', '#8c9db5'];

    /**
     * @param array<string, mixed> $config        A normalized AttachmentConfig
     * @param array<string, mixed> $entity_config The client's row of the group (see TransmissionGroupEntity)
     * @return array{content: string, filename: string}
     */
    public static function render(
        array $config,
        int $entities_id,
        array $entity_config,
        string $date_start,
        string $date_end
    ): array {
        $sections  = AttachmentConfig::enabledSections($config) ?: ['capa'];
        $data      = self::loadData($sections, $entities_id, $date_start, $date_end);
        $variables = AttachmentConfig::buildVariables($config, $data['cliente'], $date_start, $date_end, $entity_config);

        [, $pages] = self::compose($sections, $config, $data, $variables, []);
        [$pdf]     = self::compose($sections, $config, $data, $variables, $pages);

        $filename = AttachmentConfig::filename($config, $variables);

        return [
            'content'  => (string) $pdf->Output($filename, 'S'),
            'filename' => $filename,
        ];
    }

    /**
     * @param list<string>          $sections
     * @param array<string, mixed>  $config
     * @param array<string, mixed>  $data
     * @param array<string, string> $variables
     * @param array<string, int>    $pages     Section => first page, from a previous pass
     * @return array{0: AttachmentPdfDocument, 1: array<string, int>}
     */
    private static function compose(array $sections, array $config, array $data, array $variables, array $pages): array
    {
        $pdf = new AttachmentPdfDocument(
            $variables['cliente'],
            $variables['ano'],
            $sections[0] === 'capa',
            'Relatório ' . $variables['cliente'] . ' - ' . $variables['periodo_curto']
        );

        $started = [];
        foreach ($sections as $key) {
            $section = $config['sections'][$key];

            if ($key === 'capa') {
                $pdf->startCover();
            } else {
                $pdf->startSection(self::plain($section['footer'], $variables));
            }
            $started[$key] = $pdf->getPage();

            switch ($key) {
                case 'capa':
                    $pdf->drawCover(
                        'Relatório ' . $variables['ano'],
                        self::plain($section['subtitle'], $variables),
                        $variables['cliente'] . ' – ' . $variables['periodo_curto']
                    );
                    break;

                case 'confidencialidade':
                    self::renderConfidencialidade($pdf, $section, $variables);
                    break;

                case 'identificacao':
                    self::renderIdentificacao($pdf, $variables);
                    break;

                case 'sumario':
                    self::renderSumario($pdf, $section, $config, $sections, $variables, $pages);
                    break;

                case 'indicadores':
                    self::renderIndicadores($pdf, $section, $data, $variables);
                    break;

                case 'chamados':
                    self::renderChamados($pdf, $section, $data, $variables);
                    break;

                case 'comercial':
                    self::renderComercial($pdf, $section, $data, $variables);
                    break;

                case 'justificativas':
                    self::renderJustificativas($pdf, $section, $config, $data, $variables);
                    break;

                case 'aceite':
                    self::renderAceite($pdf, $section, $variables);
                    break;
            }
        }

        return [$pdf, $started];
    }

    /**
     * Runs only the queries the enabled sections need.
     *
     * @param list<string> $sections
     * @return array<string, mixed>
     */
    private static function loadData(array $sections, int $entities_id, string $date_start, string $date_end): array
    {
        $entity = Entity::getById($entities_id);
        $data   = [
            'cliente'   => $entity !== false ? (string) $entity->fields['name'] : (string) $entities_id,
            'dashboard' => null,
            'summary'   => null,
            'comercial' => null,
        ];

        if (array_intersect($sections, self::NEED_DASHBOARD) !== []) {
            $rows = DashboardReport::getRows([
                'entities_id' => $entities_id,
                'date_start'  => $date_start,
                'date_end'    => $date_end,
            ]);
            $data['summary']   = DashboardReport::summarize($rows);
            $data['dashboard'] = DashboardRenderer::prepareData($rows, $date_start, $date_end);
        }

        if (in_array('comercial', $sections, true) && $entity !== false) {
            foreach (Report::getGeneralReport($date_start, $date_end) as $row) {
                if ($row['entities_id'] !== $entities_id) {
                    continue;
                }

                $tickets    = Report::getTicketsForEntity($entities_id, $date_start, $date_end);
                $categories = Report::getHoursByCategory($tickets);

                $data['comercial'] = ReportRenderer::prepareClientData($entity->fields, $tickets, $categories, $date_start, $date_end)
                    + ['contrato' => $row];
                break;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed>  $section
     * @param array<string, string> $variables
     */
    private static function renderConfidencialidade(AttachmentPdfDocument $pdf, array $section, array $variables): void
    {
        // The template highlights the parties' names in red.
        $highlight = array_values(array_filter(array_unique([$variables['cliente'], 'Mindworks Informática Ltda'])));

        $paragraphs = array_map(
            static function (string $paragraph) use ($highlight): string {
                $html = nl2br(htmlescape($paragraph));
                foreach ($highlight as $name) {
                    $html = str_replace(
                        htmlescape($name),
                        '<b style="color:#e11d48;font-style:normal;">' . htmlescape($name) . '</b>',
                        $html
                    );
                }
                return $html;
            },
            self::paragraphs(self::plain($section['text'], $variables))
        );

        $pdf->writeBodyAtBottom(self::template('confidencialidade', [
            'paragraphs' => $paragraphs,
            'ano'        => $variables['ano'],
        ]));
    }

    /**
     * @param array<string, string> $variables
     */
    private static function renderIdentificacao(AttachmentPdfDocument $pdf, array $variables): void
    {
        $pdf->Ln(8);
        $pdf->writeBodyInCard(self::template('identificacao', [
            'rows' => [
                ['label' => 'VERSÃO', 'value' => $variables['versao']],
                ['label' => 'PERÍODO DE REFERÊNCIA', 'value' => $variables['periodo']],
                ['label' => 'COORDENADOR', 'value' => $variables['coordenador']],
                ['label' => 'REVISÃO', 'value' => $variables['revisao']],
                ['label' => 'CLIENTE', 'value' => $variables['cliente']],
                ['label' => 'GERENTE', 'value' => $variables['gerente']],
                ['label' => 'RELATÓRIO GERADO EM', 'value' => $variables['gerado_em']],
            ],
        ]));
    }

    /**
     * @param array<string, mixed>  $section
     * @param array<string, mixed>  $config
     * @param list<string>          $sections
     * @param array<string, string> $variables
     * @param array<string, int>    $pages
     */
    private static function renderSumario(
        AttachmentPdfDocument $pdf,
        array $section,
        array $config,
        array $sections,
        array $variables,
        array $pages
    ): void {
        $entries = [];
        foreach ($sections as $key) {
            if (in_array($key, self::NOT_IN_TOC, true)) {
                continue;
            }

            $title = self::plain($config['sections'][$key]['title'], $variables);
            $entries[] = [
                'title' => $title,
                // "2.1 ..." is a sub-item of "2. ...", as in the template.
                'sub'   => preg_match('/^\d+\.\d+/', $title) === 1,
                'page'  => $pages[$key] ?? 0,
            ];
        }

        $pdf->Ln(6);
        $pdf->writeBody(self::template('sumario', [
            'toc_title'  => self::plain($section['toc_title'], $variables),
            'entries'    => $entries,
            'title'      => self::plain($section['title'], $variables),
            'paragraphs' => self::paragraphs(self::plain($section['text'], $variables)),
        ]));
    }

    /**
     * @param array<string, mixed>  $section
     * @param array<string, mixed>  $data
     * @param array<string, string> $variables
     */
    private static function renderIndicadores(AttachmentPdfDocument $pdf, array $section, array $data, array $variables): void
    {
        $pdf->heading(self::plain($section['title'], $variables));

        if ($section['show_kpis']) {
            $pdf->drawSummaryCards(ReportPdf::dashboardCards($data['dashboard']));
        }

        $pdf->heading(self::plain($section['subtitle'], $variables), 10, true);
        $pdf->writeBody(self::template('painel', [
            'panel_title' => self::plain($section['panel_title'], $variables),
            'band'        => 'PAINEL DE INDICADORES',
            'periodo'     => $variables['periodo'],
            'indicators'  => AttachmentIndicators::compute(
                $data['summary'],
                (float) $section['meta_atendimento'],
                (float) $section['meta_solucao']
            ),
            'obs'         => self::plain($section['obs'], $variables),
        ]));
    }

    /**
     * The ticket dashboard, styled as the template's "Relatório de Chamados":
     * cards and charts where the template only had a picture, then the table.
     *
     * @param array<string, mixed>  $section
     * @param array<string, mixed>  $data
     * @param array<string, string> $variables
     */
    private static function renderChamados(AttachmentPdfDocument $pdf, array $section, array $data, array $variables): void
    {
        $dashboard = $data['dashboard'];

        $pdf->heading(self::plain($section['title'], $variables));

        if ($section['show_kpis']) {
            $pdf->drawSummaryCards(ReportPdf::dashboardCards($dashboard));
        }

        if ($section['show_charts']) {
            $pdf->drawDonutPanels([
                ['title' => 'SLA Atendimento', 'caption' => 'chamados', 'series' => $dashboard['sla_atendimento_series']],
                ['title' => 'SLA Solução', 'caption' => 'chamados', 'series' => $dashboard['sla_series']],
                self::hoursPanel($data['summary']),
                ['title' => 'Tipo do Chamado', 'caption' => 'chamados', 'series' => $dashboard['tipo_series']],
            ]);
        }

        $columns = ReportPdf::pdfColumns($section['columns']);

        // Half a point below the dashboard PDF: the attachment's margins are wider.
        $font_size = ReportPdf::dashboardFontSize(count($columns)) - 0.5;

        $pdf->writeBody(self::template('chamados', $dashboard + [
            'columns'          => $columns,
            'font_size'        => $font_size,
            'header_font_size' => max(5.0, $font_size - 1.5),
        ]));
    }

    /**
     * "Horas por Categoria" donut: the biggest categories, the rest grouped.
     *
     * @param array<string, mixed> $summary DashboardReport::summarize() output
     * @return array{title: string, caption: string, total_display: string, series: list<array{label: string, value: int, pct: string, color: string, display: string}>}
     */
    public static function hoursPanel(array $summary): array
    {
        $categorias = $summary['categorias'];
        usort($categorias, static fn (array $a, array $b): int => $b['segundos'] <=> $a['segundos']);

        $slices = array_slice($categorias, 0, self::HOURS_SLICES);
        $rest   = array_sum(array_column(array_slice($categorias, self::HOURS_SLICES), 'segundos'));
        if ($rest > 0) {
            $slices[] = ['categoria' => 'Outras', 'segundos' => $rest];
        }

        $total  = array_sum(array_column($slices, 'segundos'));
        $series = [];
        foreach ($slices as $index => $slice) {
            // The last segment is enough to tell categories apart in a legend.
            $segments = explode('>', $slice['categoria']);
            $series[] = [
                'label'   => trim((string) end($segments)),
                'value'   => $slice['segundos'],
                'pct'     => DashboardRenderer::formatPercent($total > 0 ? ($slice['segundos'] / $total) * 100 : 0.0),
                'color'   => self::HOURS_COLORS[$index % count(self::HOURS_COLORS)],
                'display' => self::hoursMinutes($slice['segundos']),
            ];
        }

        return [
            'title'         => 'Horas por Categoria',
            'caption'       => 'horas',
            'total_display' => self::hoursMinutes($total),
            'series'        => $series,
        ];
    }

    /**
     * @param array<string, mixed>  $section
     * @param array<string, mixed>  $data
     * @param array<string, string> $variables
     */
    private static function renderComercial(AttachmentPdfDocument $pdf, array $section, array $data, array $variables): void
    {
        $pdf->heading(self::plain($section['title'], $variables));

        $comercial = $data['comercial'];
        if ($comercial === null) {
            $pdf->writeBody(self::template('message', [
                'message' => 'Não há contrato com horas para este cliente no período de referência.',
            ]));
            return;
        }

        $contrato = $comercial['contrato'];
        $pdf->drawSummaryCards([
            ['label' => 'Horas contratadas', 'value' => ReportRenderer::formatHours((float) $contrato['horas_contratadas'])],
            ['label' => 'Horas trabalhadas', 'value' => ReportRenderer::formatHours((float) $contrato['horas_trabalhadas'])],
            [
                'label' => 'Consumo',
                'value' => number_format((float) $contrato['percentual'], 1, ',', '.') . '%',
                'color' => ReportRenderer::usageColor((float) $contrato['percentual']),
            ],
            ['label' => 'Chamados', 'value' => (string) count($comercial['tickets'])],
            [
                'label' => 'SLA',
                'value' => number_format((float) $comercial['sla_percentual'], 1, ',', '.') . '%',
                'color' => $comercial['sla_cor'],
            ],
        ]);

        $pdf->writeBody(self::template('comercial', $comercial + [
            'show_tickets' => $section['show_tickets'],
        ]));
    }

    /**
     * The panel uses the goals of the "indicadores" section, so both panels
     * always agree even when only one of them is included.
     *
     * @param array<string, mixed>  $section
     * @param array<string, mixed>  $config
     * @param array<string, mixed>  $data
     * @param array<string, string> $variables
     */
    private static function renderJustificativas(
        AttachmentPdfDocument $pdf,
        array $section,
        array $config,
        array $data,
        array $variables
    ): void {
        $metas = $config['sections']['indicadores'];

        $pdf->heading(self::plain($section['title'], $variables));

        $violated = [];
        foreach ($data['dashboard']['rows'] as $row) {
            if ($row['sla'] !== DashboardReport::SLA_VIOLATED && $row['sla_atendimento'] !== DashboardReport::SLA_VIOLATED) {
                continue;
            }
            // One line per ticket, even when several analysts worked on it.
            if (isset($violated[$row['id']])) {
                if (!in_array($row['analista'], $violated[$row['id']]['analistas'], true)) {
                    $violated[$row['id']]['analistas'][] = $row['analista'];
                }
                continue;
            }
            $violated[$row['id']] = $row + ['analistas' => [$row['analista']]];
        }

        $pdf->writeBody(self::template('justificativas', [
            'panel_title' => self::plain($section['panel_title'], $variables),
            'band'        => 'PAINEL DE INDICADORES',
            'periodo'     => $variables['periodo'],
            'indicators'  => AttachmentIndicators::compute(
                $data['summary'],
                (float) $metas['meta_atendimento'],
                (float) $metas['meta_solucao']
            ),
            'obs'         => '',
            'paragraphs'  => self::paragraphs(self::plain($section['text'], $variables)),
            'violated'    => array_values($violated),
            'empty_text'  => self::plain($section['empty_text'], $variables),
        ]));
    }

    /**
     * @param array<string, mixed>  $section
     * @param array<string, string> $variables
     */
    private static function renderAceite(AttachmentPdfDocument $pdf, array $section, array $variables): void
    {
        $pdf->heading(self::plain($section['title'], $variables));

        $pdf->writeBody(self::template('aceite', [
            'paragraphs' => self::paragraphs(self::plain($section['text'], $variables)),
        ]));

        // Room to sign above the lines.
        $pdf->Ln(28);
        $pdf->writeBody(self::template('assinaturas', [
            'signers'    => [
                [
                    'name'    => $variables['responsavel_mindworks'],
                    'role'    => $variables['cargo_mindworks'],
                    'company' => 'Mindworks Informática Ltda.',
                ],
                [
                    'name'    => $variables['responsavel_cliente'],
                    'role'    => $variables['cargo_cliente'],
                    'company' => $variables['cliente'],
                ],
            ],
            'ano'        => $variables['ano'],
        ]));
    }

    /**
     * A configured text with its placeholders resolved, not escaped: Twig
     * escapes it when it reaches the HTML, TCPDF's native calls take it as is.
     *
     * @param array<string, string> $variables
     */
    private static function plain(mixed $text, array $variables): string
    {
        return ReportMailer::renderTemplate((string) $text, $variables, false);
    }

    /**
     * Blank lines separate paragraphs.
     *
     * @return list<string>
     */
    private static function paragraphs(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\n\s*\n/', $text) ?: []),
            static fn (string $paragraph): bool => $paragraph !== ''
        ));
    }

    private static function hoursMinutes(int $seconds): string
    {
        return substr(ReportRenderer::formatSeconds($seconds), 0, -3);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function template(string $name, array $context): string
    {
        return TemplateRenderer::getInstance()->render(
            '@relatorioglpicomercial/pdf/attachment/' . $name . '.html.twig',
            $context
        );
    }
}
