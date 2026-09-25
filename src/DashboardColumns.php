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

use Config;

/**
 * Columns of the ticket table of the "Dashboard de Chamados" and which of them
 * each user chose to see. The choice is stored per user in glpi_configs (one
 * comma-separated list of column keys), so it follows the user across browsers
 * and needs no table of its own.
 */
class DashboardColumns
{
    public const CONTEXT = 'plugin:relatorioglpicomercial:dashboard';

    /** Column groups, in the order shown by the column picker. */
    public const GROUP_TICKET = 'ticket';
    public const GROUP_PEOPLE = 'people';
    public const GROUP_DATES  = 'dates';
    public const GROUP_HOURS  = 'hours';

    /** Database column(s) each column is read from, shown as a tooltip in the picker. */
    private const SOURCES = [
        'id'                => 'glpi_tickets.id',
        'tipo'              => 'glpi_tickets.type',
        'entidade'          => 'glpi_entities.completename',
        'solicitante'       => 'glpi_tickets_users.users_id (type 1) → glpi_users',
        'grupo_solicitante' => 'glpi_groups_tickets.groups_id (type 1) → glpi_groups.completename',
        'categoria'         => 'glpi_itilcategories.completename',
        'analista'          => 'glpi_tickettasks.users_id_tech → glpi_users',
        'grupo_atribuido'   => 'glpi_groups_tickets.groups_id (type 2) → glpi_groups.completename',
        'titulo'            => 'glpi_tickets.name',
        'prioridade'        => 'glpi_tickets.urgency',
        'prioridade_glpi'   => 'glpi_tickets.priority',
        'impacto'           => 'glpi_tickets.impact',
        'origem'            => 'glpi_requesttypes.name',
        'localizacao'       => 'glpi_locations.completename',
        'status'            => 'glpi_tickets.status',
        'abertura'          => 'glpi_tickets.date_creation',
        'data'              => 'glpi_tickets.date',
        'atualizacao'       => 'glpi_tickets.date_mod',
        'tempo_atendimento' => 'glpi_tickets.time_to_own',
        'solucao'           => 'glpi_tickets.solvedate',
        'tempo_solucao'     => 'glpi_tickets.time_to_resolve',
        'fechamento'        => 'glpi_tickets.closedate',
        'atendimento_stat'  => 'glpi_tickets.takeintoaccount_delay_stat',
        'solucao_stat'      => 'glpi_tickets.solve_delay_stat',
        'horas_previstas'   => 'glpi_plugin_fields_ticketprevisodehoras.horasprevistasfieldtwo',
        'total'             => 'glpi_tickets.actiontime',
        'periodo'           => 'SUM(glpi_tickettasks.actiontime) no período',
        'sla_atendimento'   => 'calculado: glpi_tickets.status, time_to_own e takeintoaccountdate',
        'sla'               => 'calculado: glpi_tickets.status, time_to_resolve e solvedate',
        'nota'              => 'glpi_ticketsatisfactions.satisfaction',
    ];

    /**
     * Catalog, in display order. "field" is the key of the prepared row holding
     * the text to show (see DashboardRenderer::prepareData()); "class" is added
     * to the cell; "source" names the database column it comes from.
     *
     * @return array<string, array{label: string, group: string, field: string, class: string, default: bool, source: string}>
     */
    public static function getCatalog(): array
    {
        $def = static fn (string $label, string $group, string $field, bool $default, string $class = ''): array => [
            'label'   => $label,
            'group'   => $group,
            'field'   => $field,
            'class'   => $class,
            'default' => $default,
            'source'  => '',
        ];

        $catalog = [
            'id'                => $def(__('ID', 'relatorioglpicomercial'), self::GROUP_TICKET, 'id', true, 'rgc-nowrap'),
            'tipo'              => $def(__('Tipo', 'relatorioglpicomercial'), self::GROUP_TICKET, 'tipo', true),
            'entidade'          => $def(__('Entidade', 'relatorioglpicomercial'), self::GROUP_TICKET, 'entidade', false),
            'solicitante'       => $def(__('Solicitante', 'relatorioglpicomercial'), self::GROUP_PEOPLE, 'solicitante', true),
            'grupo_solicitante' => $def(__('Grupo Solicitante', 'relatorioglpicomercial'), self::GROUP_PEOPLE, 'grupo_solicitante', false),
            'categoria'         => $def(__('Categoria', 'relatorioglpicomercial'), self::GROUP_TICKET, 'categoria', true),
            'analista'          => $def(__('Analista', 'relatorioglpicomercial'), self::GROUP_PEOPLE, 'analista', true),
            'grupo_atribuido'   => $def(__('Grupo Atribuído', 'relatorioglpicomercial'), self::GROUP_PEOPLE, 'grupo_atribuido', false),
            'titulo'            => $def(__('Título Chamado', 'relatorioglpicomercial'), self::GROUP_TICKET, 'titulo', true, 'rgc-left'),
            'prioridade'        => $def(__('Prioridade', 'relatorioglpicomercial'), self::GROUP_TICKET, 'prioridade', true),
            'prioridade_glpi'   => $def(__('Prioridade (GLPI)', 'relatorioglpicomercial'), self::GROUP_TICKET, 'prioridade_glpi', false),
            'impacto'           => $def(__('Impacto', 'relatorioglpicomercial'), self::GROUP_TICKET, 'impacto', false),
            'origem'            => $def(__('Origem da Requisição', 'relatorioglpicomercial'), self::GROUP_TICKET, 'origem', false),
            'localizacao'       => $def(__('Localização', 'relatorioglpicomercial'), self::GROUP_TICKET, 'localizacao', false),
            'status'            => $def(__('Status', 'relatorioglpicomercial'), self::GROUP_TICKET, 'status', true),
            'abertura'          => $def(__('Abertura', 'relatorioglpicomercial'), self::GROUP_DATES, 'abertura_fmt', true, 'rgc-nowrap'),
            'data'              => $def(__('Data do Chamado', 'relatorioglpicomercial'), self::GROUP_DATES, 'data_fmt', false, 'rgc-nowrap'),
            'atualizacao'       => $def(__('Última Atualização', 'relatorioglpicomercial'), self::GROUP_DATES, 'atualizacao_fmt', false, 'rgc-nowrap'),
            'tempo_atendimento' => $def(__('Atendimento', 'relatorioglpicomercial'), self::GROUP_DATES, 'tempo_atendimento_fmt', true, 'rgc-nowrap'),
            'solucao'           => $def(__('Solução', 'relatorioglpicomercial'), self::GROUP_DATES, 'solucao_fmt', true, 'rgc-nowrap'),
            'tempo_solucao'     => $def(__('Tempo para Solução', 'relatorioglpicomercial'), self::GROUP_DATES, 'tempo_solucao_fmt', true, 'rgc-nowrap'),
            'fechamento'        => $def(__('Fechamento', 'relatorioglpicomercial'), self::GROUP_DATES, 'fechamento_fmt', false, 'rgc-nowrap'),
            'atendimento_stat'  => $def(__('Tempo até o Atendimento', 'relatorioglpicomercial'), self::GROUP_HOURS, 'atendimento_stat_fmt', false, 'rgc-nowrap'),
            'solucao_stat'      => $def(__('Duração até a Solução', 'relatorioglpicomercial'), self::GROUP_HOURS, 'solucao_stat_fmt', false, 'rgc-nowrap'),
            'horas_previstas'   => $def(__('Horas Previstas', 'relatorioglpicomercial'), self::GROUP_HOURS, 'horas_previstas', true, 'rgc-nowrap'),
            'total'             => $def(__('Total Horas Apontadas', 'relatorioglpicomercial'), self::GROUP_HOURS, 'total_fmt', true, 'rgc-nowrap'),
            'periodo'           => $def(__('Horas Apontadas Período', 'relatorioglpicomercial'), self::GROUP_HOURS, 'periodo_fmt', true, 'rgc-nowrap'),
            'sla_atendimento'   => $def(__('SLA Atendimento', 'relatorioglpicomercial'), self::GROUP_TICKET, 'sla_atendimento', false, 'rgc-nowrap'),
            'sla'               => $def(__('SLA Solução', 'relatorioglpicomercial'), self::GROUP_TICKET, 'sla', true, 'rgc-nowrap'),
            'nota'              => $def(__('Nota', 'relatorioglpicomercial'), self::GROUP_TICKET, 'nota_fmt', true),
        ];

        foreach ($catalog as $key => $column) {
            $catalog[$key]['source'] = self::SOURCES[$key] ?? '';
        }

        return $catalog;
    }

    /**
     * @return array<string, string> group key => label
     */
    public static function getGroups(): array
    {
        return [
            self::GROUP_TICKET => __('Chamado', 'relatorioglpicomercial'),
            self::GROUP_PEOPLE => __('Pessoas e grupos', 'relatorioglpicomercial'),
            self::GROUP_DATES  => __('Datas e prazos', 'relatorioglpicomercial'),
            self::GROUP_HOURS  => __('Horas e tempos', 'relatorioglpicomercial'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getDefaultKeys(): array
    {
        return array_keys(array_filter(
            self::getCatalog(),
            static fn (array $column): bool => $column['default']
        ));
    }

    /**
     * Keeps only known keys, without duplicates, in catalog order. An empty
     * result falls back to the default columns: a table with no column at all
     * is never what the user wants.
     *
     * @param array<mixed> $keys
     * @return list<string>
     */
    public static function sanitize(array $keys): array
    {
        $wanted = array_flip(array_filter($keys, 'is_string'));
        $valid  = array_values(array_filter(
            array_keys(self::getCatalog()),
            static fn (string $key): bool => isset($wanted[$key])
        ));

        return $valid !== [] ? $valid : self::getDefaultKeys();
    }

    /**
     * @return list<string>
     */
    public static function getVisibleKeys(int $users_id): array
    {
        if ($users_id <= 0) {
            return self::getDefaultKeys();
        }

        $stored = Config::getConfigurationValue(self::CONTEXT, self::configName($users_id));
        if (!is_string($stored) || $stored === '') {
            return self::getDefaultKeys();
        }

        return self::sanitize(explode(',', $stored));
    }

    /**
     * @param array<mixed> $keys
     * @return list<string> the keys actually stored
     */
    public static function save(int $users_id, array $keys): array
    {
        $keys = self::sanitize($keys);

        if ($users_id > 0) {
            Config::setConfigurationValues(self::CONTEXT, [self::configName($users_id) => implode(',', $keys)]);
        }

        return $keys;
    }

    public static function reset(int $users_id): void
    {
        if ($users_id > 0) {
            Config::deleteConfigurationValues(self::CONTEXT, [self::configName($users_id)]);
        }
    }

    /**
     * Catalog entries flagged with the user's choice, ready for the template.
     *
     * @param list<string> $visible_keys
     * @return list<array{key: string, label: string, group: string, field: string, class: string, source: string, visible: bool}>
     */
    public static function describe(array $visible_keys): array
    {
        $columns = [];
        foreach (self::getCatalog() as $key => $column) {
            $columns[] = [
                'key'     => $key,
                'label'   => $column['label'],
                'group'   => $column['group'],
                'field'   => $column['field'],
                'class'   => $column['class'],
                'source'  => $column['source'],
                'visible' => in_array($key, $visible_keys, true),
            ];
        }

        return $columns;
    }

    private static function configName(int $users_id): string
    {
        return 'columns_' . $users_id;
    }
}
