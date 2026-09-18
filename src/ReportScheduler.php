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

/**
 * Decides *when* the scheduled report goes out and *which period* it covers.
 *
 * GLPI's CronTask only knows about a frequency expressed in seconds, which
 * cannot express "every 1st of the month" nor "every Monday", and its
 * hourmin/hourmax are editable in Setup > Automatic actions — a second source
 * of truth for the hour the admin picked in our own configuration page. So the
 * task is registered to wake up hourly and this class decides whether it is
 * time.
 *
 * The decision is expressed as a *period key* (the day, the ISO week or the
 * month the run belongs to). Storing the key of the last successful send per
 * entity makes the whole thing idempotent — two cron ticks in the same hour
 * cannot send twice — while still catching up when the cron was late.
 *
 * Pure logic, no I/O: everything is derived from the config array and the
 * "current" date passed in.
 */
class ReportScheduler
{
    public const FREQ_DAILY   = 'daily';
    public const FREQ_WEEKLY  = 'weekly';
    public const FREQ_MONTHLY = 'monthly';

    public const WINDOW_CURRENT_MONTH  = 'current_month';
    public const WINDOW_PREVIOUS_MONTH = 'previous_month';
    public const WINDOW_LAST_N_DAYS    = 'last_n_days';

    /**
     * @return array<string, string>
     */
    public static function getFrequencies(): array
    {
        return [
            self::FREQ_DAILY   => __('Diário', 'relatorioglpicomercial'),
            self::FREQ_WEEKLY  => __('Semanal', 'relatorioglpicomercial'),
            self::FREQ_MONTHLY => __('Mensal', 'relatorioglpicomercial'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getWindowTypes(): array
    {
        return [
            self::WINDOW_CURRENT_MONTH  => __('Mês vigente', 'relatorioglpicomercial'),
            self::WINDOW_PREVIOUS_MONTH => __('Mês anterior', 'relatorioglpicomercial'),
            self::WINDOW_LAST_N_DAYS    => __('Últimos N dias', 'relatorioglpicomercial'),
        ];
    }

    /**
     * ISO-8601 weekday numbers (1 = Monday) mapped to their label.
     *
     * @return array<int, string>
     */
    public static function getWeekdays(): array
    {
        return [
            1 => __('Segunda-feira', 'relatorioglpicomercial'),
            2 => __('Terça-feira', 'relatorioglpicomercial'),
            3 => __('Quarta-feira', 'relatorioglpicomercial'),
            4 => __('Quinta-feira', 'relatorioglpicomercial'),
            5 => __('Sexta-feira', 'relatorioglpicomercial'),
            6 => __('Sábado', 'relatorioglpicomercial'),
            7 => __('Domingo', 'relatorioglpicomercial'),
        ];
    }

    /**
     * The period the given moment belongs to, whether or not it is a moment to
     * send. Used to stamp entities on save so that enabling the schedule in the
     * middle of a period does not fire an immediate send.
     *
     * @param array<string, mixed> $config
     */
    public static function currentPeriodKey(array $config, DateTimeImmutable $now): string
    {
        return match ($config['frequency']) {
            self::FREQ_WEEKLY  => $now->format('o-\WW'),
            self::FREQ_MONTHLY => $now->format('Y-m'),
            default            => $now->format('Y-m-d'),
        };
    }

    /**
     * The period the given moment belongs to, or null when this is not a moment
     * to send (wrong weekday, wrong day of month, or too early in the day).
     *
     * A day-of-month beyond the length of the current month falls back to the
     * last day of that month, so "day 31" still fires in February.
     *
     * @param array<string, mixed> $config
     */
    public static function periodKey(array $config, DateTimeImmutable $now): ?string
    {
        if ((int) $now->format('G') < (int) $config['send_hour']) {
            return null;
        }

        switch ($config['frequency']) {
            case self::FREQ_WEEKLY:
                if ((int) $now->format('N') !== (int) $config['send_weekday']) {
                    return null;
                }
                break;

            case self::FREQ_MONTHLY:
                $target_day = min((int) $config['send_day'], (int) $now->format('t'));
                if ((int) $now->format('j') !== $target_day) {
                    return null;
                }
                break;
        }

        return self::currentPeriodKey($config, $now);
    }

    /**
     * Date range (Y-m-d, both bounds inclusive) the report should cover.
     *
     * @param array<string, mixed> $config
     * @return array{0: string, 1: string} [date_start, date_end]
     */
    public static function resolveWindow(array $config, DateTimeImmutable $now): array
    {
        switch ($config['window_type']) {
            case self::WINDOW_PREVIOUS_MONTH:
                // "first/last day of" neutralizes the day overflow, so this is
                // correct on the 31st too (31/03 -> 01/02..28/02).
                return [
                    $now->modify('first day of last month')->format('Y-m-d'),
                    $now->modify('last day of last month')->format('Y-m-d'),
                ];

            case self::WINDOW_LAST_N_DAYS:
                $days = max(1, (int) $config['window_days']);
                return [
                    $now->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
                    $now->format('Y-m-d'),
                ];

            default:
                return [$now->format('Y-m-01'), $now->format('Y-m-d')];
        }
    }
}
