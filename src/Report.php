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

use DateTime;
use Entity;
use QueryExpression;

/**
 * Builds the data used by the two commercial reports (per-client tickets/SLA
 * and general contracted-vs-worked hours), reading directly from the local
 * GLPI database.
 */
class Report
{
    public const RIGHTNAME = 'plugin_relatorioglpicomercial_report';

    /**
     * Validate a "Y-m-d" date string (round-trip check, rejects malformed input).
     */
    public static function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    public static function entityExists(int $entities_id): bool
    {
        return $entities_id > 0 && Entity::getById($entities_id) !== false;
    }

    /**
     * Tickets of a given entity in the given period, with aggregated worked
     * time, requester and technician.
     *
     * @return array<int, array{
     *     id: int,
     *     titulo: string,
     *     tipo: string,
     *     situacao: string,
     *     categoria: string,
     *     requerente: string,
     *     tecnico: string,
     *     data_abertura: ?string,
     *     data_solucao: ?string,
     *     data_fechamento: ?string,
     *     tempo_trabalhado_segundos: int,
     * }>
     */
    public static function getTicketsForEntity(int $entities_id, string $date_start, string $date_end): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'     => [
                'glpi_tickets.id',
                'glpi_tickets.name AS titulo',
                'glpi_tickets.type',
                'glpi_tickets.date AS data_abertura',
                'glpi_tickets.time_to_resolve',
                'glpi_tickets.solvedate AS data_solucao',
                'glpi_tickets.closedate AS data_fechamento',
                'glpi_itilcategories.completename AS categoria',
                new QueryExpression('COALESCE(SUM(glpi_tickettasks.actiontime), 0) AS tempo_trabalhado_segundos'),
            ],
            'FROM'       => 'glpi_tickets',
            'LEFT JOIN'  => [
                'glpi_itilcategories' => [
                    'ON' => ['glpi_tickets' => 'itilcategories_id', 'glpi_itilcategories' => 'id'],
                ],
                'glpi_tickettasks'    => [
                    'ON' => ['glpi_tickets' => 'id', 'glpi_tickettasks' => 'tickets_id'],
                ],
            ],
            'WHERE'      => [
                'glpi_tickets.is_deleted'  => 0,
                'glpi_tickets.entities_id' => $entities_id,
                new QueryExpression('glpi_tickets.date >= ' . $DB::quoteValue($date_start . ' 00:00:00')),
                new QueryExpression('glpi_tickets.date <= ' . $DB::quoteValue($date_end . ' 23:59:59')),
            ],
            'GROUPBY'    => 'glpi_tickets.id',
            'ORDER'      => 'glpi_tickets.date ASC',
        ]);

        $tickets = [];
        $ticket_ids = [];
        foreach ($iterator as $row) {
            $ticket_ids[] = (int) $row['id'];
            $tickets[(int) $row['id']] = [
                'id'                        => (int) $row['id'],
                'titulo'                    => (string) $row['titulo'],
                'tipo'                      => ((int) $row['type']) === 2 ? 'Requisição' : 'Incidente',
                'situacao'                  => ($row['data_solucao'] !== null && $row['time_to_resolve'] !== null && $row['data_solucao'] > $row['time_to_resolve'])
                    ? 'Violação'
                    : 'Normal',
                'categoria'                 => $row['categoria'] !== null ? (string) $row['categoria'] : 'Sem categoria',
                'requerente'                => '-',
                'tecnico'                   => '-',
                'data_abertura'             => $row['data_abertura'],
                'data_solucao'              => $row['data_solucao'],
                'data_fechamento'           => $row['data_fechamento'],
                'tempo_trabalhado_segundos' => (int) $row['tempo_trabalhado_segundos'],
            ];
        }

        if (empty($ticket_ids)) {
            return [];
        }

        // Single batched query for requester (type=1) and technician (type=2),
        // instead of two correlated LIMIT-1 subqueries per row.
        $actors = $DB->request([
            'SELECT'     => [
                'glpi_tickets_users.tickets_id',
                'glpi_tickets_users.type',
                new QueryExpression("CONCAT(glpi_users.firstname, ' ', glpi_users.realname) AS nome"),
            ],
            'FROM'       => 'glpi_tickets_users',
            'INNER JOIN' => [
                'glpi_users' => ['ON' => ['glpi_tickets_users' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'      => [
                'glpi_tickets_users.tickets_id' => $ticket_ids,
                'glpi_tickets_users.type'       => [1, 2],
            ],
        ]);

        foreach ($actors as $actor) {
            $tickets_id = (int) $actor['tickets_id'];
            if (!isset($tickets[$tickets_id])) {
                continue;
            }
            if ((int) $actor['type'] === 1 && $tickets[$tickets_id]['requerente'] === '-') {
                $tickets[$tickets_id]['requerente'] = (string) $actor['nome'];
            } elseif ((int) $actor['type'] === 2 && $tickets[$tickets_id]['tecnico'] === '-') {
                $tickets[$tickets_id]['tecnico'] = (string) $actor['nome'];
            }
        }

        return array_values($tickets);
    }

    /**
     * Aggregates worked time by category from an already-fetched ticket list
     * (no extra query — mirrors what was a second SQL query in the original
     * n8n flow).
     *
     * @param array<int, array{categoria: string, tempo_trabalhado_segundos: int}> $tickets
     * @return array<int, array{categoria: string, tempo_total_segundos: int}>
     */
    public static function getHoursByCategory(array $tickets): array
    {
        $byCategory = [];
        foreach ($tickets as $ticket) {
            $categoria = $ticket['categoria'];
            $byCategory[$categoria] = ($byCategory[$categoria] ?? 0) + $ticket['tempo_trabalhado_segundos'];
        }

        $result = [];
        foreach ($byCategory as $categoria => $segundos) {
            $result[] = ['categoria' => $categoria, 'tempo_total_segundos' => $segundos];
        }

        return $result;
    }

    /**
     * The entities the general report covers: those with at least one contract
     * worth some hours, excluding the "INATIVOS" branch.
     *
     * Shared by getGeneralReport() and by the scheduled-email configuration
     * page, which lists exactly the same clients.
     *
     * @return array<int, array{entities_id: int, nome: string, horas_contratadas: float}>
     */
    public static function getEntitiesWithContract(): array
    {
        global $DB;

        $names = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'completename'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['completename' => ['NOT LIKE', '%INATIVOS%']],
            ]) as $entity
        ) {
            $names[(int) $entity['id']] = $entity['completename'];
        }

        $contracted = [];
        foreach ($DB->request(['SELECT' => ['entities_id', 'num'], 'FROM' => 'glpi_contracts']) as $contract) {
            $entities_id = (int) $contract['entities_id'];
            $contracted[$entities_id] = ($contracted[$entities_id] ?? 0) + (float) $contract['num'];
        }

        $result = [];
        foreach ($contracted as $entities_id => $horas_contratadas) {
            if ($horas_contratadas <= 0 || !isset($names[$entities_id])) {
                continue;
            }
            $segments = explode('>', $names[$entities_id]);
            $result[] = [
                'entities_id'       => $entities_id,
                'nome'              => trim(end($segments)),
                'horas_contratadas' => $horas_contratadas,
            ];
        }

        usort($result, static fn (array $a, array $b): int => $a['entities_id'] <=> $b['entities_id']);

        return $result;
    }

    /**
     * Entities with an active contract, contracted vs. worked hours in the
     * given period, and the resulting percentage.
     *
     * @return array<int, array{
     *     entities_id: int,
     *     nome: string,
     *     horas_contratadas: float,
     *     horas_trabalhadas: float,
     *     percentual: float,
     * }>
     */
    public static function getGeneralReport(string $date_start, string $date_end): array
    {
        global $DB;

        $worked = [];
        $workedIterator = $DB->request([
            'SELECT'     => [
                'glpi_tickets.entities_id',
                new QueryExpression('SUM(COALESCE(glpi_tickettasks.actiontime, 0)) / 3600 AS horas_trabalhadas'),
            ],
            'FROM'       => 'glpi_tickets',
            'LEFT JOIN'  => [
                'glpi_tickettasks' => ['ON' => ['glpi_tickets' => 'id', 'glpi_tickettasks' => 'tickets_id']],
            ],
            'WHERE'      => [
                'glpi_tickets.is_deleted' => 0,
                new QueryExpression('glpi_tickets.date >= ' . $DB::quoteValue($date_start . ' 00:00:00')),
                new QueryExpression('glpi_tickets.date <= ' . $DB::quoteValue($date_end . ' 23:59:59')),
            ],
            'GROUPBY'    => 'glpi_tickets.entities_id',
        ]);
        foreach ($workedIterator as $row) {
            $worked[(int) $row['entities_id']] = (float) $row['horas_trabalhadas'];
        }

        $result = [];
        foreach (self::getEntitiesWithContract() as $entity) {
            $horas_trabalhadas = $worked[$entity['entities_id']] ?? 0.0;
            $result[] = $entity + [
                'horas_trabalhadas' => $horas_trabalhadas,
                'percentual'        => ($horas_trabalhadas / $entity['horas_contratadas']) * 100,
            ];
        }

        return $result;
    }
}
