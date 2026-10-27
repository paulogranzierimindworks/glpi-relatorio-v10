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

use DateTimeImmutable;
use Glpi\Toolbox\Sanitizer;

/**
 * What goes into the PDF attached by a {@see TransmissionGroup}: which pages
 * of the Mindworks report are included, in which order, and the texts/data
 * each one shows. Stored as JSON in the group's "attachment_config" column and
 * rendered by {@see AttachmentComposer}.
 *
 * Pure logic, no I/O: the shape is always normalized through normalize(), so
 * the composer and the form never have to deal with missing or unknown keys.
 */
class AttachmentConfig
{
    public const MAX_LINE = 255;
    public const MAX_TEXT = 4000;

    public const DEFAULT_FILENAME = 'Relatorio_{{ cliente }}_{{ periodo_curto }}.pdf';

    /** Ticket columns of the "Relatório de Chamados" table of the Mindworks template. */
    public const DEFAULT_COLUMNS = [
        'id',
        'tipo',
        'solicitante',
        'categoria',
        'analista',
        'titulo',
        'prioridade',
        'status',
        'abertura',
        'tempo_atendimento',
        'solucao',
        'total',
        'sla_atendimento',
        'sla',
    ];

    /** Placeholders available in every text of the attachment and in the file name. */
    public const VARIABLES = [
        'cliente',
        'ano',
        'periodo',
        'periodo_curto',
        'data_inicio',
        'data_fim',
        'gerado_em',
        'contrato',
        'versao',
        'revisao',
        'coordenador',
        'gerente',
        'responsavel_mindworks',
        'cargo_mindworks',
        'responsavel_cliente',
        'cargo_cliente',
    ];

    /** Fields of a client's row in the group that override the group defaults. */
    public const ENTITY_FIELDS = ['contract_number', 'client_rep_name', 'client_rep_title'];

    private const MONTHS = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];

    /**
     * Every page the attachment can contain, in the default order.
     *
     * "fields" are the section's own settings: "line" is a one-line text,
     * "text" a multi-line one, "percent" a number between 0 and 100, "bool" a
     * switch and "columns" a set of {@see DashboardColumns} keys.
     *
     * @return array<string, array{
     *     label: string,
     *     title: string,
     *     footer: string,
     *     fields: array<string, array{label: string, type: string, default: mixed}>,
     * }>
     */
    public static function getSections(): array
    {
        $field = static fn (string $label, string $type, mixed $default): array => [
            'label'   => $label,
            'type'    => $type,
            'default' => $default,
        ];

        return [
            'capa' => [
                'label'  => __('Capa', 'relatorioglpicomercial'),
                'title'  => 'Capa',
                'footer' => '',
                'fields' => [
                    'subtitle' => $field(__('Subtítulo', 'relatorioglpicomercial'), 'line', 'SUPORTE ESPECIALIZADO'),
                ],
            ],
            'confidencialidade' => [
                'label'  => __('Aviso de confidencialidade', 'relatorioglpicomercial'),
                'title'  => 'Aviso de Confidencialidade',
                'footer' => 'Confidencial e Privilegiado',
                'fields' => [
                    'text' => $field(__('Texto', 'relatorioglpicomercial'), 'text', implode("\n\n", [
                        'Todas as informações contidas neste Relatório são consideradas privilegiadas e pertencentes a {{ cliente }} para uso interno. '
                        . 'Este material inclui método de trabalho considerado sigiloso e a sua divulgação só deverá ser praticada com a finalidade '
                        . 'específica de avaliação de seu conteúdo para aprovação e contratação destes serviços. Sendo assim, nenhuma parte deste '
                        . 'relatório poderá ser reproduzida, por quaisquer meios, sem a permissão da Mindworks Informática Ltda.',
                        'As informações contidas neste documento representam a visão atual da Mindworks Informática em relação às questões de '
                        . 'segurança discutidas até a data de sua divulgação e publicação.',
                        'As logomarcas utilizadas são registradas pelos respectivos fabricantes.',
                    ])),
                ],
            ],
            'identificacao' => [
                'label'  => __('Identificação e controle do documento', 'relatorioglpicomercial'),
                'title'  => 'Identificação e Controle do Documento',
                'footer' => 'Identificação e Controle do Documento',
                'fields' => [
                    'versao'      => $field(__('Versão', 'relatorioglpicomercial'), 'line', '1.0'),
                    'revisao'     => $field(__('Revisão', 'relatorioglpicomercial'), 'line', '{{ gerado_em }}'),
                    'coordenador' => $field(__('Coordenador', 'relatorioglpicomercial'), 'line', ''),
                    'gerente'     => $field(__('Gerente', 'relatorioglpicomercial'), 'line', ''),
                ],
            ],
            'sumario' => [
                'label'  => __('Sumário e visão geral', 'relatorioglpicomercial'),
                'title'  => '1. Visão Geral',
                'footer' => 'Sumário e Visão Geral',
                'fields' => [
                    'toc_title' => $field(__('Título do sumário', 'relatorioglpicomercial'), 'line', 'Sumário'),
                    'text'      => $field(
                        __('Texto da visão geral', 'relatorioglpicomercial'),
                        'text',
                        'Foram executados serviços de suporte especializado ao ambiente de firewall e monitoramento, prestados pelo '
                        . 'Nível 3 de TI, em atendimento ao usuário final e à equipe técnica de {{ cliente }}. Neste documento estão '
                        . 'descritas as demandas atendidas, em conformidade com o contrato vigente.'
                    ),
                ],
            ],
            'indicadores' => [
                'label'  => __('Detalhamento: painel de indicadores', 'relatorioglpicomercial'),
                'title'  => '2. Detalhamento',
                'footer' => 'Detalhamento • Painel de Indicadores',
                'fields' => [
                    'subtitle'         => $field(__('Subtítulo', 'relatorioglpicomercial'), 'line', '2.1 Painel de Indicadores'),
                    'panel_title'      => $field(__('Título do painel', 'relatorioglpicomercial'), 'line', 'RELATÓRIO DE ATENDIMENTO - CONTRATO - {{ contrato }}'),
                    'show_kpis'        => $field(__('Mostrar cards de resumo', 'relatorioglpicomercial'), 'bool', true),
                    'meta_atendimento' => $field(__('Meta SLA de atendimento (%)', 'relatorioglpicomercial'), 'percent', 90.0),
                    'meta_solucao'     => $field(__('Meta SLA de solução (%)', 'relatorioglpicomercial'), 'percent', 90.0),
                    'obs'              => $field(
                        __('Observação', 'relatorioglpicomercial'),
                        'text',
                        'Os resultados dos SLAs neste painel refletem os dados registrados na ferramenta de gerenciamento de chamados.'
                    ),
                ],
            ],
            'chamados' => [
                'label'  => __('Relatório de chamados (Dashboard)', 'relatorioglpicomercial'),
                'title'  => '2.2 Relatório de Chamados',
                'footer' => 'Relatório de Chamados',
                'fields' => [
                    // Off by default: the "indicadores" page already shows them.
                    'show_kpis'   => $field(__('Mostrar cards de resumo', 'relatorioglpicomercial'), 'bool', false),
                    'show_charts' => $field(__('Mostrar gráficos', 'relatorioglpicomercial'), 'bool', true),
                    'columns'     => $field(__('Colunas da tabela', 'relatorioglpicomercial'), 'columns', self::DEFAULT_COLUMNS),
                ],
            ],
            'comercial' => [
                'label'  => __('Relatório comercial (horas do contrato)', 'relatorioglpicomercial'),
                'title'  => 'Relatório Comercial',
                'footer' => 'Relatório Comercial • Horas do Contrato',
                'fields' => [
                    'show_tickets' => $field(__('Mostrar tabela de chamados', 'relatorioglpicomercial'), 'bool', true),
                ],
            ],
            'justificativas' => [
                'label'  => __('Justificativas', 'relatorioglpicomercial'),
                'title'  => '2.3 Relatório de Justificativas',
                'footer' => 'Relatório de Justificativas',
                'fields' => [
                    'panel_title' => $field(__('Título do painel', 'relatorioglpicomercial'), 'line', 'RELATÓRIO DE CHAMADOS - CONTRATO - {{ contrato }}'),
                    'text'        => $field(__('Justificativas', 'relatorioglpicomercial'), 'text', ''),
                    'empty_text'  => $field(
                        __('Texto quando não há violações', 'relatorioglpicomercial'),
                        'text',
                        'Nenhuma ocorrência fora do prazo registrada para o período de referência. Todos os chamados foram '
                        . 'solucionados dentro dos limites contratuais estabelecidos.'
                    ),
                ],
            ],
            'aceite' => [
                'label'  => __('Formalização e aceite', 'relatorioglpicomercial'),
                'title'  => '3. Formalização e Aceite dos Serviços',
                'footer' => 'Termo de Aceite e Formalização de Serviços',
                'fields' => [
                    'text' => $field(
                        __('Texto', 'relatorioglpicomercial'),
                        'text',
                        'Pelo presente instrumento, as partes atestam que os serviços de suporte especializado discriminados neste '
                        . 'relatório técnico foram devidamente executados em conformidade com as normas, prazos e Acordos de Nível de '
                        . 'Serviço (SLA) estabelecidos entre as partes contratantes, servindo este termo como validação e aceite formal '
                        . 'do período correspondente.'
                    ),
                    'responsavel_mindworks' => $field(__('Responsável Mindworks', 'relatorioglpicomercial'), 'line', ''),
                    'cargo_mindworks'       => $field(__('Cargo do responsável Mindworks', 'relatorioglpicomercial'), 'line', ''),
                    'responsavel_cliente'   => $field(__('Responsável do cliente (padrão)', 'relatorioglpicomercial'), 'line', ''),
                    'cargo_cliente'         => $field(__('Cargo do responsável do cliente (padrão)', 'relatorioglpicomercial'), 'line', ''),
                ],
            ],
        ];
    }

    /**
     * The configuration of a group that never saved one: every page, except
     * the report that does not match the group's report type.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $report_type): array
    {
        $sections = [];
        foreach (self::getSections() as $key => $section) {
            $sections[$key] = [
                'enabled' => match ($key) {
                    'chamados'  => $report_type === TransmissionGroup::REPORT_DASHBOARD,
                    'comercial' => $report_type !== TransmissionGroup::REPORT_DASHBOARD,
                    default     => true,
                },
                'title'   => $section['title'],
                'footer'  => $section['footer'],
            ];
            foreach ($section['fields'] as $name => $field) {
                $sections[$key][$name] = $field['default'];
            }
        }

        return [
            'filename'        => self::DEFAULT_FILENAME,
            'contract_number' => '',
            'order'           => array_keys($sections),
            'sections'        => $sections,
        ];
    }

    /**
     * Brings submitted or stored values to the canonical shape: unknown keys
     * are dropped, texts are trimmed and capped, numbers are clamped, and
     * anything missing takes its default.
     *
     * A section present in the input with no "enabled" key is disabled: that is
     * how an unchecked checkbox arrives.
     *
     * @param array<mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw, string $report_type): array
    {
        $config  = self::defaults($report_type);
        $catalog = self::getSections();

        $filename = self::line($raw['filename'] ?? null);
        if ($filename !== '') {
            $config['filename'] = $filename;
        }
        if (array_key_exists('contract_number', $raw)) {
            $config['contract_number'] = self::line($raw['contract_number']);
        }

        if (isset($raw['order']) && is_array($raw['order'])) {
            $order = array_values(array_unique(array_filter(
                $raw['order'],
                static fn (mixed $key): bool => is_string($key) && isset($catalog[$key])
            )));
            // Sections the input forgot keep their default relative position at the end.
            $config['order'] = array_merge($order, array_values(array_diff(array_keys($catalog), $order)));
        }

        $raw_sections = isset($raw['sections']) && is_array($raw['sections']) ? $raw['sections'] : [];

        foreach ($catalog as $key => $section) {
            if (!isset($raw_sections[$key]) || !is_array($raw_sections[$key])) {
                continue;
            }
            $input = $raw_sections[$key];

            $config['sections'][$key]['enabled'] = !empty($input['enabled']);

            foreach (['title', 'footer'] as $name) {
                $value = self::line($input[$name] ?? null);
                if ($value !== '') {
                    $config['sections'][$key][$name] = $value;
                }
            }

            foreach ($section['fields'] as $name => $field) {
                $config['sections'][$key][$name] = self::normalizeField($field, $input[$name] ?? null);
            }
        }

        return $config;
    }

    /**
     * @param array{label: string, type: string, default: mixed} $field
     */
    private static function normalizeField(array $field, mixed $value): mixed
    {
        switch ($field['type']) {
            case 'bool':
                return !empty($value);

            case 'percent':
                if (is_string($value)) {
                    $value = str_replace(',', '.', trim($value));
                }
                return is_numeric($value) ? max(0.0, min(100.0, (float) $value)) : $field['default'];

            case 'columns':
                $keys = is_array($value) ? array_filter($value, 'is_string') : [];
                return $keys === [] ? self::DEFAULT_COLUMNS : DashboardColumns::sanitize($keys);

            case 'text':
                return $value === null ? $field['default'] : self::text($value);

            default:
                return $value === null ? $field['default'] : self::line($value);
        }
    }

    /**
     * The configuration stored on a group, normalized; the defaults of its
     * report type when nothing (or something unreadable) was stored.
     *
     * @param array<string, mixed> $fields A TransmissionGroup's fields
     * @return array<string, mixed>
     */
    public static function fromFields(array $fields): array
    {
        $report_type = (string) ($fields['report_type'] ?? TransmissionGroup::REPORT_COMERCIAL);

        return self::fromJson((string) ($fields['attachment_config'] ?? ''), $report_type);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromJson(string $json, string $report_type): array
    {
        if (trim($json) === '') {
            return self::defaults($report_type);
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            // Value that went through GLPI's input sanitizer before being stored.
            $decoded = json_decode((string) Sanitizer::unsanitize($json), true);
        }

        return is_array($decoded) ? self::normalize($decoded, $report_type) : self::defaults($report_type);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function toJson(array $config): string
    {
        return (string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Keys of the enabled sections, in the configured order.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function enabledSections(array $config): array
    {
        return array_values(array_filter(
            $config['order'],
            static fn (string $key): bool => !empty($config['sections'][$key]['enabled'])
        ));
    }

    /**
     * Values of the placeholders for one client. A value set on the client's
     * row of the group wins over the group's default.
     *
     * Values that are themselves templates (e.g. a revision of
     * "{{ gerado_em }}") are resolved against the plain values first.
     *
     * @param array<string, mixed> $config        A normalized configuration
     * @param array<string, mixed> $entity_config The client's row of the group (see TransmissionGroupEntity)
     * @return array<string, string>
     */
    public static function buildVariables(
        array $config,
        string $cliente,
        string $date_start,
        string $date_end,
        array $entity_config = [],
        ?DateTimeImmutable $now = null
    ): array {
        $now         = $now ?? new DateTimeImmutable();
        $data_inicio = ReportRenderer::formatDate($date_start);
        $data_fim    = ReportRenderer::formatDate($date_end);
        $override    = static fn (string $key, string $fallback): string => trim((string) ($entity_config[$key] ?? '')) !== ''
            ? trim((string) $entity_config[$key])
            : $fallback;

        $base = [
            'cliente'       => $cliente,
            'ano'           => substr($date_end, 0, 4),
            'periodo'       => $data_inicio . ' - ' . $data_fim,
            'periodo_curto' => self::shortPeriod($date_start, $date_end),
            'data_inicio'   => $data_inicio,
            'data_fim'      => $data_fim,
            'gerado_em'     => $now->format('d/m/Y H:i'),
            'contrato'      => $override('contract_number', (string) $config['contract_number']),
        ];

        $identificacao = $config['sections']['identificacao'];
        $aceite        = $config['sections']['aceite'];
        $derived = [
            'versao'                => (string) $identificacao['versao'],
            'revisao'               => (string) $identificacao['revisao'],
            'coordenador'           => (string) $identificacao['coordenador'],
            'gerente'               => (string) $identificacao['gerente'],
            'responsavel_mindworks' => (string) $aceite['responsavel_mindworks'],
            'cargo_mindworks'       => (string) $aceite['cargo_mindworks'],
            'responsavel_cliente'   => $override('client_rep_name', (string) $aceite['responsavel_cliente']),
            'cargo_cliente'         => $override('client_rep_title', (string) $aceite['cargo_cliente']),
        ];

        foreach ($derived as $key => $value) {
            $derived[$key] = ReportMailer::renderTemplate($value, $base, false);
        }
        $base['contrato'] = ReportMailer::renderTemplate($base['contrato'], ['cliente' => $cliente], false);

        $variables = $base + $derived;

        // Same order as VARIABLES, the list shown on the form.
        return array_replace(array_fill_keys(self::VARIABLES, ''), $variables);
    }

    /**
     * "SET/2026" when the period is a whole calendar month, the explicit
     * dates otherwise.
     */
    public static function shortPeriod(string $date_start, string $date_end): string
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date_start);
        $end   = DateTimeImmutable::createFromFormat('!Y-m-d', $date_end);
        if ($start === false || $end === false) {
            return $date_start . ' – ' . $date_end;
        }

        if (
            $start->format('j') === '1'
            && $start->format('Y-m') === $end->format('Y-m')
            && $end->format('j') === $end->format('t')
        ) {
            return self::MONTHS[(int) $start->format('n') - 1] . '/' . $start->format('Y');
        }

        if ($start->format('Y') === $end->format('Y')) {
            return $start->format('d/m') . ' – ' . $end->format('d/m/Y');
        }

        return $start->format('d/m/Y') . ' – ' . $end->format('d/m/Y');
    }

    /**
     * The attachment's file name: the configured pattern with its placeholders
     * resolved, stripped of what mail clients and file systems refuse.
     *
     * @param array<string, mixed>  $config
     * @param array<string, string> $variables
     */
    public static function filename(array $config, array $variables): string
    {
        $name = ReportMailer::renderTemplate((string) $config['filename'], $variables, false);
        $name = str_replace(['/', '\\', '–', '—'], '-', $name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F:*?"<>|]+/u', '', $name);
        $name = (string) preg_replace('/\s+/u', '_', trim($name));
        $name = trim($name, '._-');

        if ($name === '') {
            return 'relatorio.pdf';
        }

        return preg_match('/\.pdf$/i', $name) === 1 ? $name : $name . '.pdf';
    }

    private static function line(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);

        return mb_substr(trim($value), 0, self::MAX_LINE);
    }

    private static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $value);

        return mb_substr(trim($value), 0, self::MAX_TEXT);
    }
}
