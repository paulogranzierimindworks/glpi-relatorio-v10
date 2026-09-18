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

use GLPIMailer;

/**
 * Per-entity side of the scheduled report: whether the client is included, the
 * addresses that receive it, and the period of the last successful send (the
 * idempotency stamp described in {@see ReportScheduler}).
 *
 * Recipients are free-form addresses typed by whoever configures the schedule,
 * not GLPI users: the people who should get a commercial report are often not
 * users of the instance at all.
 */
class EntityMailConfig
{
    public const TABLE_CONFIG = 'glpi_plugin_relatorioglpicomercial_entityconfigs';

    /**
     * Every configured entity, keyed by entities_id.
     *
     * @return array<int, array{
     *     entities_id: int,
     *     is_active: int,
     *     emails: string,
     *     last_sent_period: ?string,
     * }>
     */
    public static function getAll(): array
    {
        global $DB;

        $configs = [];
        foreach ($DB->request(['FROM' => self::TABLE_CONFIG]) as $row) {
            $configs[(int) $row['entities_id']] = [
                'entities_id'      => (int) $row['entities_id'],
                'is_active'        => (int) $row['is_active'],
                'emails'           => (string) ($row['emails'] ?? ''),
                'last_sent_period' => $row['last_sent_period'],
            ];
        }

        return $configs;
    }

    /**
     * Only the entities included in the scheduled send.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getActive(): array
    {
        return array_filter(self::getAll(), static fn (array $row): bool => $row['is_active'] === 1);
    }

    /**
     * Persist the configuration page's per-entity table.
     *
     * Only the entities listed in $entity_ids are touched, so an entity that is
     * filtered out of the page keeps its settings.
     *
     * @param array<int, int>   $entity_ids Entities rendered by the form
     * @param array<int, mixed> $active     entities_id => truthy when included
     * @param array<int, mixed> $emails     entities_id => addresses, free-form
     * @param string            $period     Period key to stamp on newly configured entities
     */
    public static function saveAll(array $entity_ids, array $active, array $emails, string $period): void
    {
        global $DB;

        $existing = self::getAll();
        $now      = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        foreach ($entity_ids as $entities_id) {
            $entities_id = (int) $entities_id;
            if ($entities_id <= 0) {
                continue;
            }

            $is_active = empty($active[$entities_id]) ? 0 : 1;
            $addresses = self::normalizeEmails((string) ($emails[$entities_id] ?? ''));

            if (isset($existing[$entities_id])) {
                // An entity that was never sent to is stamped with the current
                // period so that enabling it mid-period does not fire at once.
                $last_sent = $existing[$entities_id]['last_sent_period'];
                $DB->update(
                    self::TABLE_CONFIG,
                    [
                        'is_active'        => $is_active,
                        'emails'           => $addresses,
                        'last_sent_period' => $last_sent ?? ($is_active === 1 ? $period : null),
                        'date_mod'         => $now,
                    ],
                    ['entities_id' => $entities_id]
                );
            } else {
                $DB->insert(
                    self::TABLE_CONFIG,
                    [
                        'entities_id'      => $entities_id,
                        'is_active'        => $is_active,
                        'emails'           => $addresses,
                        'last_sent_period' => $is_active === 1 ? $period : null,
                        'date_creation'    => $now,
                        'date_mod'         => $now,
                    ]
                );
            }
        }
    }

    public static function markSent(int $entities_id, string $period): void
    {
        global $DB;

        $DB->update(
            self::TABLE_CONFIG,
            [
                'last_sent_period' => $period,
                'date_mod'         => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ],
            ['entities_id' => $entities_id]
        );
    }

    /**
     * Valid, deduplicated addresses for an entity.
     *
     * Rejected addresses are reported instead of silently dropped, so the cron
     * log can tell a typo from an empty list.
     *
     * @param array<string, mixed> $entity_config A row from getAll()
     * @return array{emails: array<int, string>, skipped: array<int, string>}
     */
    public static function resolveRecipients(array $entity_config): array
    {
        $valid   = [];
        $skipped = [];

        foreach (self::splitEmails((string) $entity_config['emails']) as $email) {
            if (!GLPIMailer::validateAddress($email)) {
                $skipped[] = sprintf('%s (endereço inválido)', $email);
                continue;
            }
            $valid[strtolower($email)] = $email;
        }

        return ['emails' => array_values($valid), 'skipped' => $skipped];
    }

    /**
     * @return array<int, string>
     */
    public static function splitEmails(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $v): bool => $v !== ''));
    }

    /** Store the addresses in a single, predictable shape. */
    private static function normalizeEmails(string $raw): string
    {
        return implode(', ', self::splitEmails($raw));
    }
}
