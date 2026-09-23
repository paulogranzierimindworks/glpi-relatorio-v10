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

use DbUtils;
use QueryExpression;

/**
 * Data layer of the "Dashboard de Chamados" report (Power BI-style page with
 * SLA/type donuts, hours per category, status KPIs and a detailed ticket
 * table).
 *
 * The rules and enums mirror docs/feature/Tabela GLPI.txt and
 * docs/feature/Query_atualizada_07_2024 1.txt. Everything that does not touch
 * the database (labels, SLA rule, aggregation) is a pure static function so it
 * can be unit tested.
 *
 * A ticket belongs to the report when it has worked time (ticket tasks) inside
 * the period OR was opened inside the period. The detail table has one row per
 * ticket and analyst (the technician of each task); "periodo_segundos" is the
 * time that analyst logged inside the period.
 */
class DashboardReport
{
    public const SLA_OK          = 'No Prazo';
    public const SLA_VIOLATED    = 'Violado';
    public const SLA_NO_DEADLINE = 'Sem prazo definido';
    public const SLA_TO_VALIDATE = 'A validar';

    /** Status filter keys => glpi_tickets.status values. */
    public const STATUS_GROUPS = [
        'novo'        => [1],
        'atendimento' => [2, 3],
        'pendente'    => [4],
        'solucionado' => [5],
        'fechado'     => [6],
    ];

    /** Optional table of the "fields" plugin holding the expected hours. */
    private const FIELDS_TABLE  = 'glpi_plugin_fields_ticketprevisodehoras';
    private const FIELDS_COLUMN = 'horasprevistasfieldtwo';

    public static function typeLabel(int $type): string
    {
        return match ($type) {
            1       => 'Incidente',
            2       => 'Requisição',
            default => 'Outro',
        };
    }

    public static function urgencyLabel(int $urgency): string
    {
        return match ($urgency) {
            1       => 'Muito Baixa',
            2       => 'Baixa',
            3       => 'Média',
            4       => 'Alta',
            5       => 'Muito Alta',
            default => 'Outro',
        };
    }

    public static function statusLabel(int $status): string
    {
        return match ($status) {
            1       => 'Novo',
            2, 3    => 'Em atendimento',
            4       => 'Pendente',
            5       => 'Solucionado',
            6       => 'Fechado',
            default => 'Outro',
        };
    }

    /**
     * SLA of the solution deadline (Tabela GLPI, item 17).
     *
     * Closed tickets follow the table literally. Tickets still open are
     * "Violado" once the deadline has passed and "No Prazo" otherwise, which is
     * what the reference dashboard shows for pending tickets.
     *
     * @param string $now current time as "Y-m-d H:i:s"
     */
    public static function slaLabel(int $status, ?string $time_to_resolve, ?string $solvedate, string $now): string
    {
        $time_to_resolve = self::nullIfEmpty($time_to_resolve);
        $solvedate       = self::nullIfEmpty($solvedate);

        if ($time_to_resolve === null) {
            return self::SLA_NO_DEADLINE;
        }

        if (in_array($status, [5, 6], true)) {
            if ($solvedate === null) {
                return self::SLA_TO_VALIDATE;
            }
            return $solvedate > $time_to_resolve ? self::SLA_VIOLATED : self::SLA_OK;
        }

        return $now > $time_to_resolve ? self::SLA_VIOLATED : self::SLA_OK;
    }

    /**
     * Rows of the detail table (one per ticket and analyst).
     *
     * @param array{
     *     entities_id?: int,
     *     date_start: string,
     *     date_end: string,
     *     itilcategories_id?: int,
     *     status?: string,
     *     users_id_tech?: int,
     * } $params
     *
     * @return list<array{
     *     id: int,
     *     tipo: string,
     *     tipo_code: int,
     *     solicitante: string,
     *     categoria: string,
     *     analista_id: int,
     *     analista: string,
     *     titulo: string,
     *     prioridade: string,
     *     status_code: int,
     *     status: string,
     *     abertura: ?string,
     *     tempo_atendimento: ?string,
     *     solucao: ?string,
     *     tempo_solucao: ?string,
     *     horas_previstas: string,
     *     total_segundos: int,
     *     periodo_segundos: int,
     *     sla: string,
     *     nota: ?int,
     * }>
     */
    public static function getRows(array $params): array
    {
        global $DB;

        $entities_id       = (int) ($params['entities_id'] ?? 0);
        $itilcategories_id = (int) ($params['itilcategories_id'] ?? 0);
        $users_id_tech     = (int) ($params['users_id_tech'] ?? 0);
        $status_group      = (string) ($params['status'] ?? '');
        $start             = $params['date_start'] . ' 00:00:00';
        $end               = $params['date_end'] . ' 23:59:59';

        // 1. Worked time per ticket and technician inside the period.
        $tasks_by_ticket = [];
        $tasks = $DB->request([
            'SELECT'  => [
                'glpi_tickettasks.tickets_id',
                'glpi_tickettasks.users_id_tech',
                new QueryExpression('SUM(glpi_tickettasks.actiontime) AS segundos'),
            ],
            'FROM'    => 'glpi_tickettasks',
            'WHERE'   => [
                new QueryExpression('glpi_tickettasks.date >= ' . $DB::quoteValue($start)),
                new QueryExpression('glpi_tickettasks.date <= ' . $DB::quoteValue($end)),
            ],
            'GROUPBY' => ['glpi_tickettasks.tickets_id', 'glpi_tickettasks.users_id_tech'],
        ]);
        foreach ($tasks as $task) {
            $tasks_by_ticket[(int) $task['tickets_id']][(int) $task['users_id_tech']] = (int) $task['segundos'];
        }

        // 2. Tickets: worked in the period or opened in it. The opening date is
        // date_creation (Tabela GLPI, item 9), falling back to "date" for the
        // legacy tickets created before date_creation existed, so the filter
        // always matches the date shown in the report.
        $opened_at  = 'COALESCE(glpi_tickets.date_creation, glpi_tickets.date)';
        $period_sql = $opened_at . ' >= ' . $DB::quoteValue($start)
            . ' AND ' . $opened_at . ' <= ' . $DB::quoteValue($end);
        if (!empty($tasks_by_ticket)) {
            $period_sql = '(' . $period_sql . ') OR glpi_tickets.id IN ('
                . implode(',', array_map('intval', array_keys($tasks_by_ticket))) . ')';
        }

        $where = [
            'glpi_tickets.is_deleted' => 0,
            new QueryExpression('(' . $period_sql . ')'),
        ];

        $entity_criteria = self::entityCriteria($entities_id);
        if (!empty($entity_criteria)) {
            $where[] = $entity_criteria;
        }
        if ($itilcategories_id > 0) {
            $where['glpi_tickets.itilcategories_id'] = array_values(
                (new DbUtils())->getSonsOf('glpi_itilcategories', $itilcategories_id)
            );
        }
        if (isset(self::STATUS_GROUPS[$status_group])) {
            $where['glpi_tickets.status'] = self::STATUS_GROUPS[$status_group];
        }

        $select = [
            'glpi_tickets.id',
            'glpi_tickets.name',
            'glpi_tickets.type',
            'glpi_tickets.status',
            'glpi_tickets.urgency',
            'glpi_tickets.date',
            'glpi_tickets.date_creation',
            'glpi_tickets.time_to_own',
            'glpi_tickets.solvedate',
            'glpi_tickets.time_to_resolve',
            'glpi_tickets.actiontime',
            'glpi_itilcategories.completename AS categoria',
            'glpi_ticketsatisfactions.satisfaction AS nota',
        ];
        $joins = [
            'glpi_itilcategories'      => [
                'ON' => ['glpi_tickets' => 'itilcategories_id', 'glpi_itilcategories' => 'id'],
            ],
            'glpi_ticketsatisfactions' => [
                'ON' => ['glpi_tickets' => 'id', 'glpi_ticketsatisfactions' => 'tickets_id'],
            ],
        ];

        // The expected hours live in a custom container of the "fields" plugin,
        // which may not be installed: degrade to an empty column.
        $has_expected_hours = $DB->tableExists(self::FIELDS_TABLE)
            && $DB->fieldExists(self::FIELDS_TABLE, self::FIELDS_COLUMN);
        if ($has_expected_hours) {
            $select[] = self::FIELDS_TABLE . '.' . self::FIELDS_COLUMN . ' AS horas_previstas';
            $joins[self::FIELDS_TABLE] = [
                'ON' => ['glpi_tickets' => 'id', self::FIELDS_TABLE => 'items_id'],
            ];
        }

        $tickets = [];
        $iterator = $DB->request([
            'SELECT'    => $select,
            'FROM'      => 'glpi_tickets',
            'LEFT JOIN' => $joins,
            'WHERE'     => $where,
            'ORDER'     => 'glpi_tickets.id ASC',
        ]);
        foreach ($iterator as $row) {
            $tickets[(int) $row['id']] = $row;
        }

        if (empty($tickets)) {
            return [];
        }

        // 3. Requester (type 1) and technicians (type 2) of those tickets.
        $requesters  = [];
        $technicians = [];
        $actors = $DB->request([
            'SELECT'     => [
                'glpi_tickets_users.tickets_id',
                'glpi_tickets_users.type',
                'glpi_users.id AS users_id',
                new QueryExpression("TRIM(CONCAT(COALESCE(glpi_users.firstname, ''), ' ', COALESCE(glpi_users.realname, ''))) AS nome"),
                'glpi_users.name AS login',
            ],
            'FROM'       => 'glpi_tickets_users',
            'INNER JOIN' => [
                'glpi_users' => ['ON' => ['glpi_tickets_users' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'      => [
                'glpi_tickets_users.tickets_id' => array_keys($tickets),
                'glpi_tickets_users.type'       => [1, 2],
            ],
            'ORDER'      => 'glpi_tickets_users.id ASC',
        ]);
        foreach ($actors as $actor) {
            $tickets_id = (int) $actor['tickets_id'];
            $nome       = $actor['nome'] !== '' ? (string) $actor['nome'] : (string) $actor['login'];
            if ((int) $actor['type'] === 1) {
                $requesters[$tickets_id] ??= $nome;
            } else {
                $technicians[$tickets_id][(int) $actor['users_id']] = $nome;
            }
        }

        // 4. Names of the technicians that logged tasks.
        $tech_ids = [];
        foreach (array_intersect_key($tasks_by_ticket, $tickets) as $per_tech) {
            foreach (array_keys($per_tech) as $tech_id) {
                if ($tech_id > 0) {
                    $tech_ids[$tech_id] = $tech_id;
                }
            }
        }
        $tech_names = self::userNames(array_values($tech_ids));

        $now  = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($tickets as $tickets_id => $ticket) {
            $status = (int) $ticket['status'];
            $base = [
                'id'                   => $tickets_id,
                'tipo'                 => self::typeLabel((int) $ticket['type']),
                'tipo_code'            => (int) $ticket['type'],
                'solicitante'          => $requesters[$tickets_id] ?? '-',
                'categoria'            => $ticket['categoria'] !== null ? (string) $ticket['categoria'] : 'Sem categoria',
                'titulo'               => (string) $ticket['name'],
                'prioridade'           => self::urgencyLabel((int) $ticket['urgency']),
                'status_code'          => $status,
                'status'               => self::statusLabel($status),
                'abertura'             => self::nullIfEmpty($ticket['date_creation'] ?? null) ?? self::nullIfEmpty($ticket['date']),
                'tempo_atendimento'    => self::nullIfEmpty($ticket['time_to_own']),
                'solucao'              => self::nullIfEmpty($ticket['solvedate']),
                'tempo_solucao'        => self::nullIfEmpty($ticket['time_to_resolve']),
                'horas_previstas'      => isset($ticket['horas_previstas']) ? trim((string) $ticket['horas_previstas']) : '',
                'total_segundos'       => (int) $ticket['actiontime'],
                'sla'                  => self::slaLabel($status, $ticket['time_to_resolve'], $ticket['solvedate'], $now),
                'nota'                 => $ticket['nota'] !== null ? (int) $ticket['nota'] : null,
            ];

            $analysts = [];
            foreach ($tasks_by_ticket[$tickets_id] ?? [] as $tech_id => $segundos) {
                $analysts[] = [
                    'analista_id'      => $tech_id,
                    'analista'         => $tech_id > 0 ? ($tech_names[$tech_id] ?? '-') : '-',
                    'periodo_segundos' => $segundos,
                ];
            }
            if (empty($analysts)) {
                // Opened in the period without worked time: show the assigned technician.
                $tech_id = (int) (array_key_first($technicians[$tickets_id] ?? []) ?? 0);
                $analysts[] = [
                    'analista_id'      => $tech_id,
                    'analista'         => $technicians[$tickets_id][$tech_id] ?? '-',
                    'periodo_segundos' => 0,
                ];
            }

            usort($analysts, static fn (array $a, array $b): int => strcasecmp($a['analista'], $b['analista']));

            foreach ($analysts as $analyst) {
                if ($users_id_tech > 0 && $analyst['analista_id'] !== $users_id_tech) {
                    continue;
                }
                $rows[] = $base + $analyst;
            }
        }

        return $rows;
    }

    /**
     * KPIs, donut series and hours per category computed from the detail rows.
     * Ticket-level figures count each ticket once, even with several analysts.
     *
     * @param list<array<string, mixed>> $rows rows from getRows()
     *
     * @return array{
     *     total: int,
     *     em_atendimento: int,
     *     pendente: int,
     *     fechado: int,
     *     violado: int,
     *     no_prazo: int,
     *     media_satisfacao: ?float,
     *     sla: array<string, int>,
     *     tipos: array<string, int>,
     *     categorias: list<array{categoria: string, segundos: int}>,
     *     total_periodo_segundos: int,
     * }
     */
    public static function summarize(array $rows): array
    {
        $seen = [];
        $sla = [
            self::SLA_OK          => 0,
            self::SLA_VIOLATED    => 0,
            self::SLA_NO_DEADLINE => 0,
            self::SLA_TO_VALIDATE => 0,
        ];
        $tipos = [];
        $categorias = [];
        $total_periodo = 0;
        $notas = [];
        $summary = [
            'total'          => 0,
            'em_atendimento' => 0,
            'pendente'       => 0,
            'fechado'        => 0,
        ];

        foreach ($rows as $row) {
            $periodo = (int) $row['periodo_segundos'];
            $total_periodo += $periodo;
            if ($periodo > 0) {
                $categorias[$row['categoria']] = ($categorias[$row['categoria']] ?? 0) + $periodo;
            }

            if (isset($seen[$row['id']])) {
                continue;
            }
            $seen[$row['id']] = true;

            $summary['total']++;
            $sla[$row['sla']] = ($sla[$row['sla']] ?? 0) + 1;
            $tipos[$row['tipo']] = ($tipos[$row['tipo']] ?? 0) + 1;
            if ($row['nota'] !== null) {
                $notas[] = (int) $row['nota'];
            }

            switch ((int) $row['status_code']) {
                case 2:
                case 3:
                    $summary['em_atendimento']++;
                    break;
                case 4:
                    $summary['pendente']++;
                    break;
                case 6:
                    $summary['fechado']++;
                    break;
            }
        }

        $categorias_list = [];
        foreach ($categorias as $categoria => $segundos) {
            $categorias_list[] = ['categoria' => (string) $categoria, 'segundos' => $segundos];
        }
        usort($categorias_list, static function (array $a, array $b): int {
            return [$a['segundos'], $a['categoria']] <=> [$b['segundos'], $b['categoria']];
        });

        return $summary + [
            'violado'                => $sla[self::SLA_VIOLATED],
            'no_prazo'               => $sla[self::SLA_OK],
            'media_satisfacao'       => count($notas) > 0 ? array_sum($notas) / count($notas) : null,
            'sla'                    => array_filter($sla, static fn (int $count): bool => $count > 0),
            'tipos'                  => $tipos,
            'categorias'             => $categorias_list,
            'total_periodo_segundos' => $total_periodo,
        ];
    }

    /**
     * Entity restriction: the chosen entity and its children, or every entity
     * the current session can see when none is chosen.
     *
     * @return array<mixed>
     */
    private static function entityCriteria(int $entities_id): array
    {
        $dbu = new DbUtils();

        if ($entities_id <= 0) {
            return $dbu->getEntitiesRestrictCriteria('glpi_tickets');
        }

        $ids = array_values($dbu->getSonsOf('glpi_entities', $entities_id));
        if (!empty($_SESSION['glpiactiveentities']) && is_array($_SESSION['glpiactiveentities'])) {
            $ids = array_values(array_intersect($ids, $_SESSION['glpiactiveentities']));
        }

        return $dbu->getEntitiesRestrictCriteria('glpi_tickets', '', $ids);
    }

    /**
     * @param list<int> $ids
     * @return array<int, string> user id => display name
     */
    private static function userNames(array $ids): array
    {
        global $DB;

        if (empty($ids)) {
            return [];
        }

        $names = [];
        $users = $DB->request([
            'SELECT' => [
                'glpi_users.id',
                new QueryExpression("TRIM(CONCAT(COALESCE(glpi_users.firstname, ''), ' ', COALESCE(glpi_users.realname, ''))) AS nome"),
                'glpi_users.name AS login',
            ],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['glpi_users.id' => $ids],
        ]);
        foreach ($users as $user) {
            $names[(int) $user['id']] = $user['nome'] !== '' ? (string) $user['nome'] : (string) $user['login'];
        }

        return $names;
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return ($value === null || $value === '' || $value === '0000-00-00 00:00:00') ? null : $value;
    }
}
