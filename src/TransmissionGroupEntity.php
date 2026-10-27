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

use Glpi\Toolbox\Sanitizer;
use GLPIMailer;

/**
 * Per-entity side of a {@see TransmissionGroup}: whether the client is
 * included, the addresses that receive it, the period of the last successful
 * send (the idempotency stamp described in {@see ReportScheduler}) and the
 * client's own values for the PDF attachment ({@see AttachmentConfig::ENTITY_FIELDS}).
 *
 * Direct successor of the old EntityMailConfig, scoped by group instead of
 * being a single global list: the same entity can now belong to several
 * groups, each with its own is_active/emails/last_sent_period row.
 *
 * Recipients are free-form addresses typed by whoever configures the group,
 * not GLPI users: the people who should get a report are often not users of
 * the instance at all.
 */
class TransmissionGroupEntity
{
    public const TABLE = 'glpi_plugin_relatorioglpicomercial_transmissiongroups_entities';

    /**
     * Every configured entity of a group, keyed by entities_id.
     *
     * @return array<int, array{
     *     entities_id: int,
     *     is_active: int,
     *     emails: string,
     *     groups_id: int,
     *     last_sent_period: ?string,
     *     contract_number: string,
     *     client_rep_name: string,
     *     client_rep_title: string,
     * }>
     */
    public static function getAllForGroup(int $group_id): array
    {
        global $DB;

        $configs = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['transmissiongroups_id' => $group_id]]) as $row) {
            $configs[(int) $row['entities_id']] = [
                'entities_id'      => (int) $row['entities_id'],
                'is_active'        => (int) $row['is_active'],
                'emails'           => (string) ($row['emails'] ?? ''),
                'groups_id'        => (int) ($row['groups_id'] ?? 0),
                'last_sent_period' => $row['last_sent_period'],
            ];
            foreach (AttachmentConfig::ENTITY_FIELDS as $field) {
                $configs[(int) $row['entities_id']][$field] = (string) Sanitizer::unsanitize((string) ($row[$field] ?? ''));
            }
        }

        return $configs;
    }

    /**
     * Only the entities included in this group's scheduled send.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getActiveForGroup(int $group_id): array
    {
        return array_filter(self::getAllForGroup($group_id), static fn (array $row): bool => $row['is_active'] === 1);
    }

    /**
     * Persist the group form's per-entity table.
     *
     * Only the entities listed in $entity_ids are touched, so an entity that is
     * filtered out of the page keeps its settings.
     *
     * @param array<int, int>   $entity_ids Entities rendered by the form
     * @param array<int, mixed> $active     entities_id => truthy when included
     * @param array<int, mixed> $emails     entities_id => addresses, free-form
     * @param string            $period     Period key to stamp on newly configured entities
     * @param array<string, array<int, mixed>> $attachment Field of AttachmentConfig::ENTITY_FIELDS => entities_id => value,
     *                                                     as submitted (sanitized by GLPI)
     * @param array<int, mixed> $glpi_groups entities_id => GLPI group whose members' e-mails also receive the report
     */
    public static function saveAll(
        int $group_id,
        array $entity_ids,
        array $active,
        array $emails,
        string $period,
        array $attachment = [],
        array $glpi_groups = []
    ): void {
        global $DB;

        $existing = self::getAllForGroup($group_id);
        $now      = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        foreach ($entity_ids as $entities_id) {
            $entities_id = (int) $entities_id;
            if ($entities_id <= 0) {
                continue;
            }

            $is_active = empty($active[$entities_id]) ? 0 : 1;
            $addresses = self::normalizeEmails((string) ($emails[$entities_id] ?? ''));
            $groups_id = max(0, (int) ($glpi_groups[$entities_id] ?? 0));

            $overrides = [];
            foreach (AttachmentConfig::ENTITY_FIELDS as $field) {
                if (isset($attachment[$field]) && is_array($attachment[$field])) {
                    $overrides[$field] = self::storableLine($attachment[$field][$entities_id] ?? '');
                }
            }

            if (isset($existing[$entities_id])) {
                // An entity that was never sent to is stamped with the current
                // period so that enabling it mid-period does not fire at once.
                $last_sent = $existing[$entities_id]['last_sent_period'];
                $DB->update(
                    self::TABLE,
                    [
                        'is_active'        => $is_active,
                        'emails'           => $addresses,
                        'groups_id'        => $groups_id,
                        'last_sent_period' => $last_sent ?? ($is_active === 1 ? $period : null),
                        'date_mod'         => $now,
                    ] + $overrides,
                    ['transmissiongroups_id' => $group_id, 'entities_id' => $entities_id]
                );
            } else {
                $DB->insert(
                    self::TABLE,
                    [
                        'transmissiongroups_id' => $group_id,
                        'entities_id'           => $entities_id,
                        'is_active'             => $is_active,
                        'emails'                => $addresses,
                        'groups_id'             => $groups_id,
                        'last_sent_period'      => $is_active === 1 ? $period : null,
                        'date_creation'         => $now,
                        'date_mod'              => $now,
                    ] + $overrides
                );
            }
        }
    }

    public static function markSent(int $group_id, int $entities_id, string $period): void
    {
        global $DB;

        $DB->update(
            self::TABLE,
            [
                'last_sent_period' => $period,
                'date_mod'         => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ],
            ['transmissiongroups_id' => $group_id, 'entities_id' => $entities_id]
        );
    }

    public static function deleteAllForGroup(int $group_id): void
    {
        global $DB;

        $DB->delete(self::TABLE, ['transmissiongroups_id' => $group_id]);
    }

    /**
     * Valid, deduplicated addresses for an entity: the typed ones plus the
     * default e-mail of every active member of the selected GLPI group, read
     * at send time so membership changes apply to the next send.
     *
     * Rejected addresses are reported instead of silently dropped, so the cron
     * log can tell a typo from an empty list.
     *
     * @param array<string, mixed> $entity_config A row from getAllForGroup()
     * @return array{emails: array<int, string>, skipped: array<int, string>}
     */
    public static function resolveRecipients(array $entity_config): array
    {
        $valid   = [];
        $skipped = [];

        $candidates = self::splitEmails((string) $entity_config['emails']);

        $groups_id = (int) ($entity_config['groups_id'] ?? 0);
        if ($groups_id > 0) {
            $members = self::getGroupMemberEmails($groups_id);
            $candidates = [...$candidates, ...$members['emails']];
            $skipped    = $members['skipped'];
        }

        foreach ($candidates as $email) {
            if (!GLPIMailer::validateAddress($email)) {
                $skipped[] = sprintf('%s (endereço inválido)', $email);
                continue;
            }
            $valid[strtolower($email)] = $email;
        }

        return ['emails' => array_values($valid), 'skipped' => $skipped];
    }

    /**
     * Default e-mail of each active, non-deleted user of a GLPI group. Members
     * without an e-mail are reported in "skipped".
     *
     * @return array{emails: array<int, string>, skipped: array<int, string>}
     */
    public static function getGroupMemberEmails(int $groups_id): array
    {
        global $DB;

        $emails  = [];
        $skipped = [];

        $iterator = $DB->request([
            'SELECT'    => ['glpi_users.id', 'glpi_users.name', 'glpi_users.realname', 'glpi_users.firstname'],
            'FROM'      => 'glpi_groups_users',
            'INNER JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        'glpi_groups_users' => 'users_id',
                        'glpi_users'        => 'id',
                    ],
                ],
            ],
            'WHERE'     => [
                'glpi_groups_users.groups_id' => $groups_id,
                'glpi_users.is_active'        => 1,
                'glpi_users.is_deleted'       => 0,
            ],
        ]);

        foreach ($iterator as $user) {
            $email = \UserEmail::getDefaultForUser((int) $user['id']);
            if ($email === '') {
                $label = trim($user['firstname'] . ' ' . $user['realname']);
                $skipped[] = sprintf('%s (usuário do grupo sem e-mail)', $label !== '' ? $label : $user['name']);
                continue;
            }
            $emails[] = $email;
        }

        return ['emails' => $emails, 'skipped' => $skipped];
    }

    /**
     * @return array<int, string>
     */
    public static function splitEmails(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $v): bool => $v !== ''));
    }

    /**
     * A one-line value from the sanitized $_POST, trimmed and capped to the
     * column size, then escaped again for the query.
     */
    private static function storableLine(mixed $value): string
    {
        $value = is_scalar($value) ? (string) Sanitizer::unsanitize((string) $value) : '';
        $value = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, 255);

        return Sanitizer::sanitize($value);
    }

    /** Store the addresses in a single, predictable shape. */
    private static function normalizeEmails(string $raw): string
    {
        return implode(', ', self::splitEmails($raw));
    }
}
