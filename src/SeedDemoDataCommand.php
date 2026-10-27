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

use Auth;
use Contract;
use DateInterval;
use DateTime;
use Entity;
use Glpi\Console\AbstractCommand;
use ITILCategory;
use Location;
use Session;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ticket;
use TicketTask;
use User;

/**
 * Creates (or resets) sample entities, contracts, categories and tickets so
 * the commercial reports and the tickets dashboard have realistic data to
 * display. The tickets are spread over a single month (the current one by
 * default), up to "now".
 *
 * Usage:
 *   php bin/console plugins:relatorioglpicomercial:seed_demo_data
 *   php bin/console plugins:relatorioglpicomercial:seed_demo_data --month=2026-09
 *   php bin/console plugins:relatorioglpicomercial:seed_demo_data --reset
 */
class SeedDemoDataCommand extends AbstractCommand
{
    /** Marks every object created by this command, so it can be found again or purged. */
    private const SEED_PREFIX = '[Seed] ';

    protected function configure()
    {
        $this->setName('plugins:relatorioglpicomercial:seed_demo_data');
        $this->setDescription(
            'Cria entidades, contratos, categorias e chamados de exemplo para testar o plugin Relatorio Comercial'
        );
        $this->addOption(
            'month',
            'm',
            InputOption::VALUE_REQUIRED,
            'Mês dos chamados de exemplo, no formato AAAA-MM (padrão: mês atual)',
            date('Y-m')
        );
        $this->addOption(
            'reset',
            'r',
            InputOption::VALUE_NONE,
            'Remove os dados de exemplo criados anteriormente por este comando antes de recriá-los'
        );
        $this->addOption(
            'username',
            'u',
            InputOption::VALUE_REQUIRED,
            'Usuário GLPI usado para criar os dados (precisa de direitos de super-admin)',
            'glpi'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $month = (string) $input->getOption('month');
        [$month_start, $window_end] = $this->resolveMonth($month);

        $this->loadUserSession($input->getOption('username'));

        if ($input->getOption('reset')) {
            $this->purge($output);
        }

        $categories_id = [
            'Suporte Técnico' => $this->getOrCreateCategory('Suporte Técnico'),
            'Desenvolvimento' => $this->getOrCreateCategory('Desenvolvimento'),
            'Infraestrutura'  => $this->getOrCreateCategory('Infraestrutura'),
        ];
        $locations_id    = [
            $this->getOrCreateLocation('Matriz'),
            $this->getOrCreateLocation('Filial'),
        ];
        $requesttypes_id = $this->getRequestTypePool();

        $actors = $this->getActorPool();
        $output->writeln(sprintf(
            '<comment>Usando %d usuário(s) existente(s) como requerente/técnico.</comment>',
            count($actors)
        ));

        // [contract hours, worked-hours factor]. The factor scales the hours of
        // the base ticket plan so each client lands on a different color of the
        // general report (< 80% green, 80-99% yellow, >= 100% red).
        //
        // "Gamma INATIVOS" has a contract but must be excluded from the general
        // report (Report::getGeneralReport filters out names containing INATIVOS).
        // "Delta Sem Contrato" has tickets but no contract, so it must not appear
        // in the general report either.
        $entities = [
            'Cliente Alpha'              => [160, 2.5],
            'Cliente Beta'               => [80, 2.25],
            'Cliente Epsilon Excedido'   => [30, 1.2],
            'Cliente Gamma INATIVOS'     => [40, 1.0],
            'Cliente Delta Sem Contrato' => [null, 1.0],
        ];

        $now             = new DateTime('now');
        $created_tickets = 0;
        $salt            = 0;

        foreach ($entities as $name => [$hours, $factor]) {
            $entities_id = $this->getOrCreateEntity($name);

            if ($hours !== null) {
                $this->getOrCreateContract($entities_id, $name, $hours, $month_start);
            }

            if ($this->entityAlreadyHasSeedTickets($entities_id, $month)) {
                $output->writeln(sprintf(
                    '<comment>Entidade "%s" já possui chamados de exemplo em %s, pulando (use --reset para recriar).</comment>',
                    $name,
                    $month
                ));
                continue;
            }

            $created_tickets += $this->seedTicketsForEntity(
                $entities_id,
                $name,
                $month,
                $categories_id,
                $locations_id,
                $requesttypes_id,
                $actors,
                $month_start,
                $window_end,
                $now,
                $factor,
                $salt++
            );
        }

        $output->writeln(sprintf(
            '<info>Concluído: %d chamado(s) de exemplo criado(s) em %s.</info>',
            $created_tickets,
            $month
        ));
        $output->writeln(
            '<info>Acesse Ferramentas > Relatório Comercial e Dashboard de Chamados para visualizar os dados.</info>'
        );

        return 0;
    }

    /**
     * @return array{0: DateTime, 1: DateTime} First instant of the month and the
     *                                         last instant tickets may be placed at
     *                                         (the end of the month, or "now" for the current one).
     */
    private function resolveMonth(string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('A opção --month deve estar no formato AAAA-MM (ex.: 2026-10).');
        }

        $start = new DateTime($month . '-01 00:00:00');
        $now   = new DateTime('now');
        if ($start > $now) {
            throw new InvalidArgumentException('A opção --month não pode ser um mês futuro.');
        }

        $end = (clone $start)->modify('last day of this month')->setTime(23, 59, 59);

        return [$start, min($end, $now)];
    }

    /**
     * Load a user in session, so add() calls have an active profile/entities.
     *
     * GLPI 10's Glpi\Console\AbstractCommand has no such helper (it was added
     * later); this mirrors the private method of the same name in core's
     * Glpi\Console\Plugin\InstallCommand.
     */
    private function loadUserSession(string $username): void
    {
        $user = new User();
        if (!$user->getFromDBbyName($username)) {
            throw new InvalidArgumentException(
                __('User name defined by --username option is invalid.')
            );
        }

        $lang = $_SESSION['glpilanguage'];
        $session_use_mode = $_SESSION['glpi_use_mode'];

        $auth = new Auth();
        $auth->auth_succeded = true;
        $auth->user = $user;
        Session::init($auth);

        $_SESSION['glpilanguage'] = $lang;
        $_SESSION['glpi_use_mode'] = $session_use_mode;
        Session::loadLanguage();
    }

    private function purge(OutputInterface $output): void
    {
        global $DB;

        $entity_ids = [];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => Entity::getTable(), 'WHERE' => ['name' => ['LIKE', self::SEED_PREFIX . '%']]]) as $row) {
            $entity_ids[] = (int) $row['id'];
        }

        if (empty($entity_ids)) {
            $output->writeln('<comment>Nenhum dado de exemplo encontrado para remover.</comment>');
            return;
        }

        $ticket_ids = [];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity_ids]]) as $row) {
            $ticket_ids[] = (int) $row['id'];
        }

        if (!empty($ticket_ids)) {
            $DB->delete('glpi_tickettasks', ['tickets_id' => $ticket_ids]);
            $DB->delete('glpi_ticketsatisfactions', ['tickets_id' => $ticket_ids]);
            $DB->delete('glpi_tickets_users', ['tickets_id' => $ticket_ids]);
            $DB->delete('glpi_tickets', ['id' => $ticket_ids]);
        }

        $DB->delete('glpi_contracts', ['entities_id' => $entity_ids]);
        $DB->delete('glpi_itilcategories', ['name' => ['LIKE', self::SEED_PREFIX . '%']]);
        $DB->delete(Location::getTable(), ['name' => ['LIKE', self::SEED_PREFIX . '%']]);
        $DB->delete(Entity::getTable(), ['id' => $entity_ids]);

        $output->writeln(sprintf(
            '<comment>Removidos: %d entidade(s), %d chamado(s) e seus dados relacionados.</comment>',
            count($entity_ids),
            count($ticket_ids)
        ));
    }

    private function getOrCreateEntity(string $name): int
    {
        $full_name = self::SEED_PREFIX . $name;

        $entity = new Entity();
        if ($entity->getFromDBByCrit(['name' => $full_name, 'entities_id' => 0])) {
            return (int) $entity->getID();
        }

        $entities_id = $entity->add([
            'entities_id' => 0,
            'name'        => $full_name,
        ]);

        if (!$entities_id) {
            throw new \RuntimeException("Falha ao criar a entidade \"{$full_name}\".");
        }

        // The session's active entities are computed once at login; refresh them so
        // this newly created sub-entity is immediately usable for further inserts.
        \Session::changeActiveEntities('all', true);

        return (int) $entities_id;
    }

    private function getOrCreateCategory(string $name): int
    {
        $full_name = self::SEED_PREFIX . $name;

        $category = new ITILCategory();
        if ($category->getFromDBByCrit(['name' => $full_name])) {
            return (int) $category->getID();
        }

        $categories_id = $category->add([
            'entities_id'  => 0,
            'is_recursive' => 1,
            'name'         => $full_name,
        ]);

        if (!$categories_id) {
            throw new \RuntimeException("Falha ao criar a categoria \"{$full_name}\".");
        }

        return (int) $categories_id;
    }

    private function getOrCreateLocation(string $name): int
    {
        $full_name = self::SEED_PREFIX . $name;

        $location = new Location();
        if ($location->getFromDBByCrit(['name' => $full_name])) {
            return (int) $location->getID();
        }

        $locations_id = $location->add([
            'entities_id'  => 0,
            'is_recursive' => 1,
            'name'         => $full_name,
        ]);

        if (!$locations_id) {
            throw new \RuntimeException("Falha ao criar a localização \"{$full_name}\".");
        }

        return (int) $locations_id;
    }

    private function getOrCreateContract(int $entities_id, string $entity_name, int $hours, DateTime $begin): void
    {
        global $DB;

        $existing = $DB->request([
            'SELECT' => 'id',
            'FROM'   => Contract::getTable(),
            'WHERE'  => ['entities_id' => $entities_id],
        ]);
        if (count($existing) > 0) {
            return;
        }

        $contract = new Contract();
        $contract->add([
            'entities_id' => $entities_id,
            'name'        => self::SEED_PREFIX . 'Contrato ' . $entity_name,
            'num'         => (string) $hours,
            'begin_date'  => $begin->format('Y-m-d'),
            'duration'    => 12,
        ]);
    }

    /**
     * @return int[] Up to 4 distinct, active user ids usable as requester/technician.
     */
    private function getActorPool(): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_users',
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => 'id ASC',
            'LIMIT'  => 4,
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }

        if (empty($ids)) {
            $ids[] = (int) \Session::getLoginUserID();
        }

        return $ids;
    }

    /**
     * @return int[] Ids of the request types (origem) available in this GLPI.
     */
    private function getRequestTypePool(): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_requesttypes',
            'WHERE'  => ['is_active' => 1, 'is_ticketheader' => 1],
            'ORDER'  => 'id ASC',
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids ?: [0];
    }

    private function entityAlreadyHasSeedTickets(int $entities_id, string $month): bool
    {
        global $DB;

        $count = $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['entities_id' => $entities_id, 'name' => ['LIKE', self::SEED_PREFIX . '% - ' . $month . ' - %']],
        ]);

        return count($count) > 0;
    }

    /**
     * Base ticket plan of one month. "pos" is where the ticket opens inside the
     * available window (0 = first day, 1 = now), so the plan fits whatever day of
     * the month the command runs. Tickets that end up late (SLA) sit early in the
     * window so their solution date is not clamped to "now".
     *
     * sla: on_time | late_own (late attendance only) | late (both late) | null (still open).
     * satisfaction: 1-5 survey answer, or null for no answer.
     *
     * @return list<array{pos: float, type: int, status: int, hours: float[], sla: ?string, satisfaction: ?int}>
     */
    private function getMonthPlan(): array
    {
        return [
            ['pos' => 0.02, 'type' => Ticket::INCIDENT_TYPE, 'status' => Ticket::CLOSED,   'hours' => [2, 1],    'sla' => 'on_time',  'satisfaction' => 5],
            ['pos' => 0.06, 'type' => Ticket::DEMAND_TYPE,   'status' => Ticket::CLOSED,   'hours' => [4],       'sla' => 'late',     'satisfaction' => 3],
            ['pos' => 0.10, 'type' => Ticket::INCIDENT_TYPE, 'status' => Ticket::CLOSED,   'hours' => [1, 1, 1], 'sla' => 'on_time',  'satisfaction' => 4],
            ['pos' => 0.15, 'type' => Ticket::DEMAND_TYPE,   'status' => Ticket::CLOSED,   'hours' => [3, 2],    'sla' => 'on_time',  'satisfaction' => 5],
            ['pos' => 0.22, 'type' => Ticket::INCIDENT_TYPE, 'status' => Ticket::SOLVED,   'hours' => [2],       'sla' => 'late',     'satisfaction' => null],
            ['pos' => 0.30, 'type' => Ticket::DEMAND_TYPE,   'status' => Ticket::CLOSED,   'hours' => [5],       'sla' => 'on_time',  'satisfaction' => 4],
            ['pos' => 0.38, 'type' => Ticket::INCIDENT_TYPE, 'status' => Ticket::SOLVED,   'hours' => [1, 2],    'sla' => 'late_own', 'satisfaction' => 2],
            ['pos' => 0.46, 'type' => Ticket::DEMAND_TYPE,   'status' => Ticket::SOLVED,   'hours' => [3],       'sla' => 'late',     'satisfaction' => 3],
            ['pos' => 0.55, 'type' => Ticket::INCIDENT_TYPE, 'status' => Ticket::ASSIGNED, 'hours' => [2],       'sla' => null,       'satisfaction' => null],
            ['pos' => 0.65, 'type' => Ticket::DEMAND_TYPE,   'status' => Ticket::PLANNED,  'hours' => [1],       'sla' => null,       'satisfaction' => null],
            ['pos' => 0.80, 'type' => Ticket::INCIDENT_TYPE, 'status' => Ticket::WAITING,  'hours' => [1],       'sla' => null,       'satisfaction' => null],
            ['pos' => 0.92, 'type' => Ticket::INCIDENT_TYPE, 'status' => Ticket::INCOMING, 'hours' => [],        'sla' => null,       'satisfaction' => null],
        ];
    }

    /**
     * @param array<string, int> $categories_id
     * @param int[]              $locations_id
     * @param int[]              $requesttypes_id
     * @param int[]              $actors
     */
    private function seedTicketsForEntity(
        int $entities_id,
        string $entity_name,
        string $month,
        array $categories_id,
        array $locations_id,
        array $requesttypes_id,
        array $actors,
        DateTime $month_start,
        DateTime $window_end,
        DateTime $now,
        float $hours_factor,
        int $salt
    ): int {
        $category_names = array_keys($categories_id);
        $span           = $window_end->getTimestamp() - $month_start->getTimestamp();

        $created = 0;
        foreach ($this->getMonthPlan() as $index => $item) {
            // Business hours (08h-17h), varied per ticket/entity, never after "now".
            $date = (new DateTime())->setTimestamp($month_start->getTimestamp() + (int) ($item['pos'] * $span));
            $date->setTime(8 + (($index * 5 + $salt) % 10), ($index * 17) % 60);
            if ($date > $window_end) {
                $date = (clone $window_end)->sub(new DateInterval('PT2H'));
            }
            if ($date < $month_start) {
                $date = clone $month_start;
            }
            $date_str = $date->format('Y-m-d H:i:s');

            $category_name = $category_names[($index + $salt) % count($category_names)];
            $requester     = $actors[($index + $salt) % count($actors)];
            $technician    = $actors[($index + $salt + 1) % count($actors)];

            $tickets_id = (new Ticket())->add([
                'name'                => self::SEED_PREFIX . $entity_name . ' - ' . $month . ' - Chamado ' . ($index + 1),
                'content'             => 'Chamado de exemplo gerado por plugins:relatorioglpicomercial:seed_demo_data.',
                'entities_id'         => $entities_id,
                'type'                => $item['type'],
                'status'              => $item['status'],
                'itilcategories_id'   => $categories_id[$category_name],
                'requesttypes_id'     => $requesttypes_id[($index + $salt) % count($requesttypes_id)],
                'locations_id'        => $locations_id[$index % count($locations_id)],
                'date'                => $date_str,
                '_users_id_requester' => $requester,
                '_users_id_assign'    => $technician,
                '_skip_auto_assign'   => true,
            ]);

            if (!$tickets_id) {
                continue;
            }
            $created++;

            foreach ($item['hours'] as $task_index => $hours) {
                $task_date = (clone $date)->add(new DateInterval('PT' . ($task_index + 1) . 'H'));
                (new TicketTask())->add([
                    'tickets_id'    => $tickets_id,
                    'content'       => 'Atendimento técnico registrado para testes.',
                    'actiontime'    => (int) round($hours * $hours_factor * HOUR_TIMESTAMP),
                    'users_id'      => $technician,
                    'users_id_tech' => $technician,
                    'state'         => \Planning::DONE,
                    'begin'         => min($task_date, $now)->format('Y-m-d H:i:s'),
                ]);
            }

            // Applied last: Ticket::add() and TicketTask::add() can both silently
            // rewrite the ticket status (e.g. bumping it to ASSIGNED once a
            // technician actor or a done task is present), so the intended
            // status/dates are (re-)applied here, after all side effects have run.
            [$time_to_resolve, $solvedate, $closedate, $time_to_own, $takeintoaccountdate] = $this->computeSlaDates($date, $item['sla'], $item['status'], $now);
            $this->applyHistoricalDates((int) $tickets_id, $item['status'], $date_str, $time_to_resolve, $solvedate, $closedate, $time_to_own, $takeintoaccountdate);

            if ($item['satisfaction'] !== null && $solvedate !== null) {
                $this->addSatisfaction((int) $tickets_id, $item['satisfaction'], $solvedate, $now);
            }
        }

        return $created;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string} [time_to_resolve, solvedate, closedate, time_to_own, takeintoaccountdate]
     */
    private function computeSlaDates(DateTime $open_date, ?string $sla, int $status, DateTime $now): array
    {
        if ($sla === null) {
            return [null, null, null, null, null];
        }

        // Attendance SLA: 4h deadline, first response 1h (on time) or 6h (late) after opening.
        $time_to_own = (clone $open_date)->add(new DateInterval('PT4H'));
        $takeintoaccountdate = (clone $open_date)->add(new DateInterval(in_array($sla, ['late', 'late_own'], true) ? 'PT6H' : 'PT1H'));

        // Solution SLA: 2 days deadline, solved 1 day (on time) or 3 days (late) after opening.
        $time_to_resolve = (clone $open_date)->add(new DateInterval('P2D'));
        $solvedate = $sla === 'late'
            ? (clone $open_date)->add(new DateInterval('P3D'))
            : (clone $open_date)->add(new DateInterval('P1D'));
        $closedate = $status === Ticket::CLOSED
            ? (clone $solvedate)->add(new DateInterval('P1D'))
            : null;

        // Nothing can have happened after "now".
        $solvedate = min($solvedate, $now);
        if ($closedate !== null) {
            $closedate = min($closedate, $now);
        }

        return [
            $time_to_resolve->format('Y-m-d H:i:s'),
            $solvedate->format('Y-m-d H:i:s'),
            $closedate?->format('Y-m-d H:i:s'),
            $time_to_own->format('Y-m-d H:i:s'),
            $takeintoaccountdate->format('Y-m-d H:i:s'),
        ];
    }

    private function applyHistoricalDates(
        int $tickets_id,
        int $status,
        string $date,
        ?string $time_to_resolve,
        ?string $solvedate,
        ?string $closedate,
        ?string $time_to_own,
        ?string $takeintoaccountdate
    ): void
    {
        global $DB;

        // Ticket::add() can silently rewrite status (e.g. INCOMING/CLOSED get bumped
        // to ASSIGNED) once a technician actor is present, so the intended status is
        // re-applied here directly, alongside the historical dates.
        $DB->update('glpi_tickets', [
            'status'              => $status,
            'date'                => $date,
            'date_creation'       => $date,
            'date_mod'            => $closedate ?? $solvedate ?? $date,
            'time_to_own'         => $time_to_own,
            'takeintoaccountdate' => $takeintoaccountdate,
            'time_to_resolve'     => $time_to_resolve,
            'solvedate'           => $solvedate,
            'closedate'           => $closedate,
        ], ['id' => $tickets_id]);
    }

    /**
     * Records an answered satisfaction survey for a ticket (shown as "nota" in
     * the dashboard and averaged in its indicators).
     */
    private function addSatisfaction(int $tickets_id, int $score, string $solvedate, DateTime $now): void
    {
        global $DB;

        $answered = min((new DateTime($solvedate))->add(new DateInterval('P1D')), $now);

        $DB->insert('glpi_ticketsatisfactions', [
            'tickets_id'    => $tickets_id,
            'type'          => 1,
            'date_begin'    => $solvedate,
            'date_answered' => $answered->format('Y-m-d H:i:s'),
            'satisfaction'  => $score,
        ]);
    }
}
