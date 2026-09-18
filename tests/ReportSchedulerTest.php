<?php

/**
 * -------------------------------------------------------------------------
 * Relatorio Comercial plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Relatorio Comercial plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Relatorioglpicomercial\Tests;

use DateTimeImmutable;
use GlpiPlugin\Relatorioglpicomercial\MailConfig;
use GlpiPlugin\Relatorioglpicomercial\ReportMailer;
use GlpiPlugin\Relatorioglpicomercial\ReportScheduler;
use PHPUnit\Framework\TestCase;

class ReportSchedulerTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return $overrides + MailConfig::getDefaults();
    }

    public function testDailyFiresOnceTheHourIsReached(): void
    {
        $config = $this->config([
            'frequency' => ReportScheduler::FREQ_DAILY,
            'send_hour' => 8,
        ]);

        $this->assertNull(
            ReportScheduler::periodKey($config, new DateTimeImmutable('2026-09-17 07:59:00')),
            'must not fire before the configured hour'
        );
        $this->assertSame(
            '2026-09-17',
            ReportScheduler::periodKey($config, new DateTimeImmutable('2026-09-17 08:00:00'))
        );
        $this->assertSame(
            '2026-09-17',
            ReportScheduler::periodKey($config, new DateTimeImmutable('2026-09-17 23:30:00')),
            'a late cron still catches up within the same day'
        );
    }

    public function testWeeklyOnlyFiresOnTheConfiguredWeekday(): void
    {
        // 2026-09-14 is a Monday, 2026-09-15 a Tuesday.
        $config = $this->config([
            'frequency'    => ReportScheduler::FREQ_WEEKLY,
            'send_weekday' => 1,
            'send_hour'    => 10,
        ]);

        $this->assertSame(
            '2026-W38',
            ReportScheduler::periodKey($config, new DateTimeImmutable('2026-09-14 10:00:00'))
        );
        $this->assertNull(
            ReportScheduler::periodKey($config, new DateTimeImmutable('2026-09-15 10:00:00'))
        );
        $this->assertNull(
            ReportScheduler::periodKey($config, new DateTimeImmutable('2026-09-14 09:00:00'))
        );
    }

    public function testMonthlyFiresOnTheConfiguredDay(): void
    {
        $config = $this->config([
            'frequency' => ReportScheduler::FREQ_MONTHLY,
            'send_day'  => 5,
            'send_hour' => 8,
        ]);

        $this->assertSame(
            '2026-09',
            ReportScheduler::periodKey($config, new DateTimeImmutable('2026-09-05 08:00:00'))
        );
        $this->assertNull(
            ReportScheduler::periodKey($config, new DateTimeImmutable('2026-09-04 23:00:00'))
        );
    }

    public function testMonthlyDayBeyondMonthLengthFallsBackToLastDay(): void
    {
        $config = $this->config([
            'frequency' => ReportScheduler::FREQ_MONTHLY,
            'send_day'  => 31,
            'send_hour' => 0,
        ]);

        // February 2026 has 28 days: the send lands on the 28th, not silently skipped.
        $this->assertNull(ReportScheduler::periodKey($config, new DateTimeImmutable('2026-02-27 12:00:00')));
        $this->assertSame(
            '2026-02',
            ReportScheduler::periodKey($config, new DateTimeImmutable('2026-02-28 12:00:00'))
        );
        $this->assertSame(
            '2026-01',
            ReportScheduler::periodKey($config, new DateTimeImmutable('2026-01-31 12:00:00'))
        );
    }

    public function testCurrentPeriodKeyIgnoresTheDueConditions(): void
    {
        $config = $this->config([
            'frequency' => ReportScheduler::FREQ_MONTHLY,
            'send_day'  => 1,
            'send_hour' => 8,
        ]);

        $now = new DateTimeImmutable('2026-09-17 15:00:00');

        $this->assertNull(ReportScheduler::periodKey($config, $now));
        $this->assertSame('2026-09', ReportScheduler::currentPeriodKey($config, $now));
    }

    public function testWindowCurrentMonth(): void
    {
        $config = $this->config(['window_type' => ReportScheduler::WINDOW_CURRENT_MONTH]);

        $this->assertSame(
            ['2026-09-01', '2026-09-17'],
            ReportScheduler::resolveWindow($config, new DateTimeImmutable('2026-09-17 08:00:00'))
        );
    }

    public function testWindowPreviousMonthHandlesShortMonths(): void
    {
        $config = $this->config(['window_type' => ReportScheduler::WINDOW_PREVIOUS_MONTH]);

        $this->assertSame(
            ['2026-08-01', '2026-08-31'],
            ReportScheduler::resolveWindow($config, new DateTimeImmutable('2026-09-01 08:00:00'))
        );

        // The 31st is the classic overflow trap: February must not become March.
        $this->assertSame(
            ['2026-02-01', '2026-02-28'],
            ReportScheduler::resolveWindow($config, new DateTimeImmutable('2026-03-31 08:00:00'))
        );
    }

    public function testWindowLastNDaysIsInclusive(): void
    {
        $config = $this->config([
            'window_type' => ReportScheduler::WINDOW_LAST_N_DAYS,
            'window_days' => 30,
        ]);

        $this->assertSame(
            ['2026-08-19', '2026-09-17'],
            ReportScheduler::resolveWindow($config, new DateTimeImmutable('2026-09-17 08:00:00'))
        );
    }

    public function testRenderTemplateResolvesOnlyKnownPlaceholders(): void
    {
        $variables = ['cliente' => 'ACME', 'percentual' => '82.5'];

        $this->assertSame(
            'ACME usou 82.5% - ',
            ReportMailer::renderTemplate('{{ cliente }} usou {{percentual}}% - {{ desconhecido }}', $variables, false)
        );
    }

    public function testRenderTemplateEscapesValuesInHtmlContext(): void
    {
        $variables = ['cliente' => '<script>alert(1)</script>'];

        $html = ReportMailer::renderTemplate('<p>{{ cliente }}</p>', $variables, true);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testValidateClampsAndFallsBackToDefaults(): void
    {
        $validated = MailConfig::validate([
            'frequency'   => 'hourly',
            'send_hour'   => 99,
            'send_day'    => 0,
            'window_type' => 'whatever',
            'window_days' => 5000,
        ]);

        $this->assertSame(MailConfig::getDefaults()['frequency'], $validated['frequency']);
        $this->assertSame(MailConfig::getDefaults()['window_type'], $validated['window_type']);
        $this->assertSame(23, $validated['send_hour']);
        $this->assertSame(1, $validated['send_day']);
        $this->assertSame(365, $validated['window_days']);
        $this->assertSame(0, $validated['is_active']);
    }
}
