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
 * the two commercial reports have realistic data to display.
 *
 * Usage:
 *   php bin/console plugins:relatorioglpicomercial:seed_demo_data
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
        $this->loadUserSession($input->getOption('username'));

        if ($input->getOption('reset')) {
            $this->purge($output);
        }

        $categories_id = [
            'Suporte Técnico' => $this->getOrCreateCategory('Suporte Técnico'),
            'Desenvolvimento' => $this->getOrCreateCategory('Desenvolvimento'),
            'Infraestrutura'  => $this->getOrCreateCategory('Infraestrutura'),
        ];

        $actors = $this->getActorPool();
        $output->writeln(sprintf(
            '<comment>Usando %d usuário(s) existente(s) como requerente/técnico.</comment>',
            count($actors)
        ));

        // "Gamma INATIVOS" has a contract but must be excluded from the general
        // report (Report::getGeneralReport filters out names containing INATIVOS).
        // "Delta Sem Contrato" has tickets but no contract, so it must not appear
        // in the general report either.
        $entities = [
            'Cliente Alpha'              => 160,
            'Cliente Beta'               => 80,
            'Cliente Gamma INATIVOS'     => 40,
            'Cliente Delta Sem Contrato' => null,
        ];

        $today = new DateTime('today');
        $created_tickets = 0;

        foreach ($entities as $name => $hours) {
            $entities_id = $this->getOrCreateEntity($name);

            if ($hours !== null) {
                $this->getOrCreateContract($entities_id, $name, $hours);
            }

            if ($this->entityAlreadyHasSeedTickets($entities_id)) {
                $output->writeln(sprintf(
                    '<comment>Entidade "%s" já possui chamados de exemplo, pulando (use --reset para recriar).</comment>',
                    $name
                ));
                continue;
            }

            $created_tickets += $this->seedTicketsForEntity($entities_id, $name, $categories_id, $actors, $today);
        }

        $output->writeln(sprintf(
            '<info>Concluído: %d chamado(s) de exemplo criado(s).</info>',
            $created_tickets
        ));
        $output->writeln(
            '<info>Acesse Ferramentas > Relatório Comercial para visualizar os relatórios "Por Cliente" e "Geral".</info>'
        );

        return 0;
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
            $DB->delete('glpi_tickets_users', ['tickets_id' => $ticket_ids]);
            $DB->delete('glpi_tickets', ['id' => $ticket_ids]);
        }

        $DB->delete('glpi_contracts', ['entities_id' => $entity_ids]);
        $DB->delete('glpi_itilcategories', ['name' => ['LIKE', self::SEED_PREFIX . '%']]);
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

    private function getOrCreateContract(int $entities_id, string $entity_name, int $hours): void
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
            'begin_date'  => (new DateTime('first day of this month'))->format('Y-m-d'),
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

    private function entityAlreadyHasSeedTickets(int $entities_id): bool
    {
        global $DB;

        $count = $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['entities_id' => $entities_id, 'name' => ['LIKE', self::SEED_PREFIX . '%']],
        ]);

        return count($count) > 0;
    }

    /**
     * @param array<string, int> $categories_id
     * @param int[]              $actors
     */
    private function seedTicketsForEntity(
        int $entities_id,
        string $entity_name,
        array $categories_id,
        array $actors,
        DateTime $today
    ): int {
        $category_names = array_keys($categories_id);

        // [days ago, type, status, worked hours per task, sla outcome]
        $plan = [
            ['days_ago' => 45, 'type' => Ticket::INCIDENT_TYPE, 'status' => Ticket::CLOSED, 'hours' => [2, 1], 'sla' => 'on_time'],
            ['days_ago' => 40, 'type' => Ticket::DEMAND_TYPE, 'status' => Ticket::CLOSED, 'hours' => [4], 'sla' => 'late'],
            ['days_ago' => 33, 'type' => Ticket::INCIDENT_TYPE, 'status' => Ticket::SOLVED, 'hours' => [1, 1, 1], 'sla' => 'on_time'],
            ['days_ago' => 20, 'type' => Ticket::DEMAND_TYPE, 'status' => Ticket::CLOSED, 'hours' => [3], 'sla' => 'late'],
            ['days_ago' => 12, 'type' => Ticket::INCIDENT_TYPE, 'status' => Ticket::ASSIGNED, 'hours' => [2], 'sla' => null],
            ['days_ago' => 6,  'type' => Ticket::DEMAND_TYPE, 'status' => Ticket::SOLVED, 'hours' => [1, 2], 'sla' => 'on_time'],
            ['days_ago' => 2,  'type' => Ticket::INCIDENT_TYPE, 'status' => Ticket::INCOMING, 'hours' => [], 'sla' => null],
        ];

        $created = 0;
        foreach ($plan as $index => $item) {
            $date = (clone $today)->sub(new DateInterval('P' . $item['days_ago'] . 'D'));
            $date->setTime(9, 0);
            $date_str = $date->format('Y-m-d H:i:s');

            $category_name = $category_names[$index % count($category_names)];
            $requester = $actors[$index % count($actors)];
            $technician = $actors[($index + 1) % count($actors)];

            $tickets_id = (new Ticket())->add([
                'name'              => self::SEED_PREFIX . $entity_name . ' - Chamado ' . ($index + 1),
                'content'           => 'Chamado de exemplo gerado por plugins:relatorioglpicomercial:seed_demo_data.',
                'entities_id'       => $entities_id,
                'type'              => $item['type'],
                'status'            => $item['status'],
                'itilcategories_id' => $categories_id[$category_name],
                'date'              => $date_str,
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
                    'actiontime'    => (int) ($hours * HOUR_TIMESTAMP),
                    'users_id'      => $technician,
                    'users_id_tech' => $technician,
                    'state'         => \Planning::DONE,
                    'begin'         => $task_date->format('Y-m-d H:i:s'),
                ]);
            }

            // Applied last: Ticket::add() and TicketTask::add() can both silently
            // rewrite the ticket status (e.g. bumping it to ASSIGNED once a
            // technician actor or a done task is present), so the intended
            // status/dates are (re-)applied here, after all side effects have run.
            [$time_to_resolve, $solvedate, $closedate] = $this->computeSlaDates($date, $item['sla'], $item['status']);
            $this->applyHistoricalDates((int) $tickets_id, $item['status'], $date_str, $time_to_resolve, $solvedate, $closedate);
        }

        return $created;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [time_to_resolve, solvedate, closedate]
     */
    private function computeSlaDates(DateTime $open_date, ?string $sla, int $status): array
    {
        if ($sla === null) {
            return [null, null, null];
        }

        $time_to_resolve = (clone $open_date)->add(new DateInterval('P2D'));
        $solvedate = $sla === 'late'
            ? (clone $open_date)->add(new DateInterval('P3D'))
            : (clone $open_date)->add(new DateInterval('P1D'));
        $closedate = $status === Ticket::CLOSED
            ? (clone $solvedate)->add(new DateInterval('P1D'))
            : null;

        return [
            $time_to_resolve->format('Y-m-d H:i:s'),
            $solvedate->format('Y-m-d H:i:s'),
            $closedate?->format('Y-m-d H:i:s'),
        ];
    }

    private function applyHistoricalDates(int $tickets_id, int $status, string $date, ?string $time_to_resolve, ?string $solvedate, ?string $closedate): void
    {
        global $DB;

        // Ticket::add() can silently rewrite status (e.g. INCOMING/CLOSED get bumped
        // to ASSIGNED) once a technician actor is present, so the intended status is
        // re-applied here directly, alongside the historical dates.
        $DB->update('glpi_tickets', [
            'status'           => $status,
            'date'             => $date,
            'time_to_resolve'  => $time_to_resolve,
            'solvedate'        => $solvedate,
            'closedate'        => $closedate,
        ], ['id' => $tickets_id]);
    }
}
