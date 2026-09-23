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
use Glpi\Toolbox\Sanitizer;
use Plugin;

/**
 * Global settings of the scheduled report email (schedule, data window,
 * subject and HTML body), stored in glpi_configs under a plugin context so the
 * plugin needs no table of its own for them.
 *
 * Per-entity settings (who receives what) live in {@see EntityMailConfig}.
 */
class MailConfig
{
    public const CONTEXT = 'plugin:relatorioglpicomercial';

    /** Relative to the plugin directory; plain HTML, not Twig — see renderTemplate(). */
    private const DEFAULT_BODY_FILE = '/templates/mail/default_body.html';

    /**
     * @return array<string, mixed>
     */
    public static function getDefaults(): array
    {
        return [
            'is_active'    => 0,
            'frequency'    => ReportScheduler::FREQ_MONTHLY,
            'send_hour'    => 8,
            'send_weekday' => 1,
            'send_day'     => 1,
            'window_type'  => ReportScheduler::WINDOW_PREVIOUS_MONTH,
            'window_days'  => 30,
            'subject'      => 'Relatório de Horas - {{ cliente }} - {{ periodo }}',
            'body_html'    => '',
        ];
    }

    /**
     * Stored values on top of the defaults, with the numeric ones cast.
     *
     * @return array<string, mixed>
     */
    public static function getAll(): array
    {
        $config = self::getDefaults();

        foreach (Config::getConfigurationValues(self::CONTEXT) as $name => $value) {
            if (array_key_exists($name, $config)) {
                $config[$name] = $value;
            }
        }

        foreach (['is_active', 'send_hour', 'send_weekday', 'send_day', 'window_days'] as $name) {
            $config[$name] = (int) $config[$name];
        }

        // Values saved before validate() decoded them are still HTML-encoded
        // (no literal "<" but "&#60;"): repair them on the fly.
        foreach (['subject', 'body_html'] as $name) {
            $value = (string) $config[$name];
            if (!str_contains($value, '<') && preg_match('/&#(?:60|62|38|34|39);/', $value) === 1) {
                $config[$name] = Sanitizer::unsanitize($value);
            }
        }

        return $config;
    }

    /**
     * Sanitize submitted values: unknown keys are dropped, enums fall back to
     * their default and numbers are clamped to a sane range.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validate(array $input): array
    {
        $defaults = self::getDefaults();

        $frequency = (string) ($input['frequency'] ?? '');
        if (!array_key_exists($frequency, ReportScheduler::getFrequencies())) {
            $frequency = $defaults['frequency'];
        }

        $window_type = (string) ($input['window_type'] ?? '');
        if (!array_key_exists($window_type, ReportScheduler::getWindowTypes())) {
            $window_type = $defaults['window_type'];
        }

        return [
            'is_active'    => empty($input['is_active']) ? 0 : 1,
            'frequency'    => $frequency,
            // A day beyond the month length is clamped at send time, so 29-31
            // are valid ways of saying "the last day of the month".
            'send_hour'    => self::clamp($input['send_hour'] ?? null, 0, 23, $defaults['send_hour']),
            'send_weekday' => self::clamp($input['send_weekday'] ?? null, 1, 7, $defaults['send_weekday']),
            'send_day'     => self::clamp($input['send_day'] ?? null, 1, 31, $defaults['send_day']),
            'window_type'  => $window_type,
            'window_days'  => self::clamp($input['window_days'] ?? null, 1, 365, $defaults['window_days']),
            // GLPI 10 HTML-encodes every $_POST value on the way in (< becomes
            // &#60;); stored as is, the body would reach the mail as literal text.
            'subject'      => trim(Sanitizer::unsanitize((string) ($input['subject'] ?? ''))),
            'body_html'    => trim(Sanitizer::unsanitize((string) ($input['body_html'] ?? ''))),
        ];
    }

    /**
     * @param array<string, mixed> $values Already through validate()
     */
    public static function save(array $values): void
    {
        // GLPI 10 does not quote values on its own: core expects them already
        // SQL-escaped (as Sanitizer::sanitize() leaves them), so a quote in the
        // HTML body would otherwise break — or inject into — the query.
        foreach ($values as $name => $value) {
            if (is_string($value)) {
                $values[$name] = Sanitizer::dbEscape($value);
            }
        }

        Config::setConfigurationValues(self::CONTEXT, $values);
    }

    public static function deleteAll(): void
    {
        Config::deleteConfigurationValues(self::CONTEXT, array_keys(self::getDefaults()));
    }

    /**
     * The shipped HTML body, used whenever the configured one is empty and
     * offered by the "restore default" button on the configuration page.
     */
    public static function getDefaultBody(): string
    {
        $dir = Plugin::getPhpDir('relatorioglpicomercial');
        if ($dir === false) {
            return '';
        }

        /** @phpstan-ignore theCodingMachineSafe.function (a missing template must not break the mailer) */
        $body = @file_get_contents($dir . self::DEFAULT_BODY_FILE);

        return $body === false ? '' : $body;
    }

    /**
     * The body actually used when sending: the configured one, or the shipped
     * default when it was left empty.
     *
     * @param array<string, mixed> $config
     */
    public static function getEffectiveBody(array $config): string
    {
        $body = trim((string) ($config['body_html'] ?? ''));

        return $body !== '' ? $body : self::getDefaultBody();
    }

    /**
     * The subject actually used when sending, never empty.
     *
     * @param array<string, mixed> $config
     */
    public static function getEffectiveSubject(array $config): string
    {
        $subject = trim((string) ($config['subject'] ?? ''));

        return $subject !== '' ? $subject : self::getDefaults()['subject'];
    }

    private static function clamp(mixed $value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
