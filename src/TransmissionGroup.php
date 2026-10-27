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

use CommonDBTM;
use Glpi\Toolbox\Sanitizer;
use Html;
use Plugin;
use Session;

/**
 * A named "Grupo de Transmissão": one report type, one schedule, one message
 * template and the PDF attachment to send, whose pages are configured in
 * {@see AttachmentConfig}. The entities that receive it, and their recipients,
 * live in {@see TransmissionGroupEntity}.
 *
 * Replaces the single global {@see MailConfig} singleton: an installation can
 * now have several groups, each targeting a different report and audience.
 */
class TransmissionGroup extends CommonDBTM
{
    /** Reuses the existing right: no new profile checkbox is introduced. */
    public static $rightname = Report::RIGHTNAME;

    public const REPORT_COMERCIAL = 'comercial';
    public const REPORT_DASHBOARD = 'dashboard';

    public $dohistory = true;

    /** Relative to the plugin directory; plain HTML, not Twig — see ReportMailer::renderTemplate(). */
    private const DEFAULT_BODY_FILES = [
        self::REPORT_COMERCIAL => '/templates/mail/default_body_comercial.html',
        self::REPORT_DASHBOARD => '/templates/mail/default_body_dashboard.html',
    ];

    private const DEFAULT_SUBJECTS = [
        self::REPORT_COMERCIAL => 'Relatório de Horas - {{ cliente }} - {{ periodo }}',
        self::REPORT_DASHBOARD => 'Dashboard de Chamados - {{ cliente }} - {{ periodo }}',
    ];

    public static function getTypeName($nb = 0): string
    {
        return _n('Grupo de Transmissão', 'Grupos de Transmissão', $nb, 'relatorioglpicomercial');
    }

    public function getName($options = [])
    {
        return (string) ($this->fields['name'] ?? '');
    }

    /**
     * The right that gates the scheduled-email configuration only has READ and
     * UPDATE bits (see hook.php); creating/deleting a group is just another
     * form of "configure the automatic sending", so both map to UPDATE instead
     * of the CREATE/PURGE bits CommonDBTM checks by default.
     */
    public static function canCreate(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    /**
     * @return array<string, string>
     */
    public static function getReportTypes(): array
    {
        return [
            self::REPORT_COMERCIAL => __('Relatório Comercial', 'relatorioglpicomercial'),
            self::REPORT_DASHBOARD => __('Dashboard de Chamados', 'relatorioglpicomercial'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getDefaults(): array
    {
        return [
            'name'              => '',
            'comment'           => '',
            'is_active'         => 0,
            'report_type'       => self::REPORT_COMERCIAL,
            'send_pdf'          => 0,
            'send_html'         => 0,
            'frequency'         => ReportScheduler::FREQ_MONTHLY,
            'send_hour'         => 8,
            'send_weekday'      => 1,
            'send_day'          => 1,
            'window_type'       => ReportScheduler::WINDOW_PREVIOUS_MONTH,
            'window_days'       => 30,
            'subject'           => self::DEFAULT_SUBJECTS[self::REPORT_COMERCIAL],
            'body_html'         => '',
            'attachment_config' => '',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->validateInput($input, true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->validateInput($input, false);
    }

    /**
     * Sanitizes submitted values: unknown enum values fall back to their
     * default, numbers are clamped to a sane range. A blank name aborts the
     * save (there is no default a group could take).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateInput(array $input, bool $is_add): array|false
    {
        $defaults = self::getDefaults();

        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(
                __s('Informe um nome para o grupo.', 'relatorioglpicomercial'),
                false,
                ERROR
            );
            return false;
        }

        if (array_key_exists('report_type', $input) && !array_key_exists($input['report_type'], self::getReportTypes())) {
            $input['report_type'] = $defaults['report_type'];
        }

        if (array_key_exists('frequency', $input) && !array_key_exists($input['frequency'], ReportScheduler::getFrequencies())) {
            $input['frequency'] = $defaults['frequency'];
        }

        if (array_key_exists('window_type', $input) && !array_key_exists($input['window_type'], ReportScheduler::getWindowTypes())) {
            $input['window_type'] = $defaults['window_type'];
        }

        foreach (
            [
                'is_active'    => [0, 1],
                'send_pdf'     => [0, 1],
                'send_html'    => [0, 1],
                'send_hour'    => [0, 23],
                'send_weekday' => [1, 7],
                // A day beyond the month length is clamped at send time, so 29-31
                // are valid ways of saying "the last day of the month".
                'send_day'     => [1, 31],
                'window_days'  => [1, 365],
            ] as $field => [$min, $max]
        ) {
            if (array_key_exists($field, $input)) {
                $input[$field] = self::clamp($input[$field] ?? null, $min, $max, (int) $defaults[$field]);
            } elseif (in_array($field, ['is_active', 'send_pdf', 'send_html'], true) && $is_add) {
                // Checkboxes are absent from $_POST entirely when unchecked.
                $input[$field] = 0;
            }
        }

        // The HTML attachment is no longer offered in the form; saving a group clears it.
        $input['send_html'] = 0;

        $report_type = (string) ($input['report_type'] ?? $this->fields['report_type'] ?? $defaults['report_type']);
        if (array_key_exists('attachment', $input)) {
            $attachment = Sanitizer::unsanitize($input['attachment']);
            $input['attachment_config'] = self::storableAttachment(
                AttachmentConfig::normalize(is_array($attachment) ? $attachment : [], $report_type)
            );
            unset($input['attachment']);
        } elseif ($is_add) {
            $input['attachment_config'] = self::storableAttachment(AttachmentConfig::defaults($report_type));
        }

        return $input;
    }

    /**
     * JSON ready for CommonDBTM: GLPI 10 expects input values already escaped
     * for SQL. Not HTML-encoded, so the stored JSON stays readable as is.
     *
     * @param array<string, mixed> $config
     */
    private static function storableAttachment(array $config): string
    {
        return Sanitizer::dbEscape(AttachmentConfig::toJson($config));
    }

    private static function clamp(mixed $value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    public function post_purgeItem(): void
    {
        TransmissionGroupEntity::deleteAllForGroup((int) $this->fields['id']);
    }

    /**
     * Values ready to feed {@see ReportScheduler}: it only expects
     * frequency/send_hour/send_weekday/send_day/window_type/window_days, which
     * this group's own fields already name identically.
     *
     * @return array<string, mixed>
     */
    public function schedulerConfig(): array
    {
        return $this->fields;
    }

    /**
     * The subject actually used when sending, never empty. Repairs a value
     * that is still HTML-encoded from being redisplayed as-is (see
     * getEffectiveBody()).
     */
    public function getEffectiveSubject(): string
    {
        return self::effectiveSubjectFor($this->fields);
    }

    /**
     * The body actually used when sending: the configured one, or the shipped
     * default for this report type when it was left empty.
     */
    public function getEffectiveBody(): string
    {
        return self::effectiveBodyFor($this->fields);
    }

    /**
     * Same as getEffectiveSubject(), for a plain fields array (e.g. a row
     * from find(), as the cron iterates without instantiating each group).
     *
     * @param array<string, mixed> $fields
     */
    public static function effectiveSubjectFor(array $fields): string
    {
        $subject = trim(self::repairEncoding((string) ($fields['subject'] ?? '')));

        return $subject !== '' ? $subject : self::getDefaultSubject((string) ($fields['report_type'] ?? self::REPORT_COMERCIAL));
    }

    /**
     * Same as getEffectiveBody(), for a plain fields array.
     *
     * @param array<string, mixed> $fields
     */
    public static function effectiveBodyFor(array $fields): string
    {
        $body = trim(self::repairEncoding((string) ($fields['body_html'] ?? '')));

        return $body !== '' ? $body : self::getDefaultBody((string) ($fields['report_type'] ?? self::REPORT_COMERCIAL));
    }

    public static function getDefaultSubject(string $report_type): string
    {
        return self::DEFAULT_SUBJECTS[$report_type] ?? self::DEFAULT_SUBJECTS[self::REPORT_COMERCIAL];
    }

    /**
     * The shipped HTML body for a report type, used whenever the configured
     * one is empty and offered by the "restore default" button on the form.
     */
    public static function getDefaultBody(string $report_type): string
    {
        $dir = Plugin::getPhpDir('relatorioglpicomercial');
        if ($dir === false) {
            return '';
        }

        $file = self::DEFAULT_BODY_FILES[$report_type] ?? self::DEFAULT_BODY_FILES[self::REPORT_COMERCIAL];

        /** @phpstan-ignore theCodingMachineSafe.function (a missing template must not break the mailer) */
        $body = @file_get_contents($dir . $file);

        return $body === false ? '' : $body;
    }

    /**
     * Some values were saved before this repair existed and are still
     * HTML-encoded (no literal "<" but "&#60;"); decode them on the fly so
     * they display and render correctly.
     */
    private static function repairEncoding(string $value): string
    {
        if (!str_contains($value, '<') && preg_match('/&#(?:60|62|38|34|39);/', $value) === 1) {
            return Sanitizer::unsanitize($value);
        }

        return $value;
    }

    /**
     * @param mixed $field
     * @param array<string, mixed>|string $values
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'report_type':
                return self::getReportTypes()[$values[$field]] ?? $values[$field];

            case 'frequency':
                return ReportScheduler::getFrequencies()[$values[$field]] ?? $values[$field];
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => 10,
            'table'    => self::getTable(),
            'field'    => 'is_active',
            'name'     => __('Ativo', 'relatorioglpicomercial'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => 11,
            'table'    => self::getTable(),
            'field'    => 'report_type',
            'name'     => __('Tipo de relatório', 'relatorioglpicomercial'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 12,
            'table'    => self::getTable(),
            'field'    => 'frequency',
            'name'     => __('Periodicidade', 'relatorioglpicomercial'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 16,
            'table'    => self::getTable(),
            'field'    => 'comment',
            'name'     => __('Comentários'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'            => 19,
            'table'         => self::getTable(),
            'field'         => 'date_mod',
            'name'          => __('Última atualização'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        return $tab;
    }

    public function showForm($ID, $options = [])
    {
        $this->initForm($ID, $options);

        $is_new = (int) $ID <= 0;
        if ($is_new) {
            $this->getEmpty();
            $this->fields = self::getDefaults() + $this->fields;
        }

        $self_url = self::getFormURL();

        $entities = $is_new ? [] : Report::getSelectableEntities((string) $this->fields['report_type']);

        echo "<form method='post' action='" . htmlescape($self_url) . "' id='relatoriotransmissiongroup-form'>";
        if (!$is_new) {
            echo Html::hidden('id', ['value' => (int) $ID]);
        }

        // -------------------------------------------------------------- tabs --
        // One tab per part of the configuration, all inside the same form so
        // "Salvar" keeps saving everything at once.
        $tabs = [
            'geral'       => ['ti ti-settings', __s('Geral', 'relatorioglpicomercial')],
            'agendamento' => ['ti ti-calendar-time', __s('Agendamento', 'relatorioglpicomercial')],
            'email'       => ['ti ti-mail', __s('Conteúdo do e-mail', 'relatorioglpicomercial')],
            'anexo'       => ['ti ti-file-type-pdf', __s('Anexo PDF', 'relatorioglpicomercial')],
        ];
        if (!$is_new) {
            $tabs['destinatarios'] = ['ti ti-users', __s('Destinatários', 'relatorioglpicomercial')];
        }

        echo "<ul class='nav nav-tabs mb-3' id='relatoriotransmissiongroup-tabs' role='tablist'>";
        foreach ($tabs as $key => [$icon, $label]) {
            $active = $key === array_key_first($tabs);
            echo "<li class='nav-item' role='presentation'>";
            echo "<button type='button' class='nav-link" . ($active ? ' active' : '') . "' data-bs-toggle='tab'"
                . " data-bs-target='#relatoriotransmissiongroup-tab-$key' role='tab'"
                . " aria-selected='" . ($active ? 'true' : 'false') . "'>"
                . "<i class='$icon me-2'></i>$label</button>";
            echo "</li>";
        }
        echo "</ul>";

        $pane = static function (string $key, bool $active = false): void {
            echo "<div class='tab-pane fade" . ($active ? ' show active' : '') . "' id='relatoriotransmissiongroup-tab-$key' role='tabpanel'>";
        };

        echo "<div class='tab-content'>";

        // ---------------------------------------------------------- identity --
        $pane('geral', true);
        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title'>" . __s('Grupo de Transmissão', 'relatorioglpicomercial') . "</h3></div>";
        echo "<div class='card-body'>";

        echo "<div class='row mb-3'>";
        echo "<div class='col-md-6'>";
        echo "<label class='form-label'>" . __s('Nome', 'relatorioglpicomercial') . "</label>";
        echo "<input type='text' class='form-control' name='name' required value='" . htmlescape((string) $this->fields['name']) . "'>";
        echo "</div>";

        echo "<div class='col-md-3'>";
        echo "<label class='form-label'>" . __s('Ativo', 'relatorioglpicomercial') . "</label><div>";
        Html::showCheckbox(['name' => 'is_active', 'checked' => (int) $this->fields['is_active'] === 1]);
        echo "</div></div>";

        echo "<div class='col-md-3'>";
        echo "<label class='form-label'>" . __s('Tipo de relatório', 'relatorioglpicomercial') . "</label>";
        \Dropdown::showFromArray('report_type', self::getReportTypes(), [
            'value'     => $this->fields['report_type'],
            'on_change' => 'plugin_relatorioglpicomercial_toggleReportType(this.value);',
        ]);
        echo "</div>";
        echo "</div>";

        echo "<div class='row mb-0'>";
        echo "<div class='col-md-8'>";
        echo "<label class='form-label'>" . __s('Comentário', 'relatorioglpicomercial') . "</label>";
        echo "<textarea class='form-control' name='comment' rows='2'>" . htmlescape((string) $this->fields['comment']) . "</textarea>";
        echo "</div>";

        echo "<div class='col-md-4'>";
        echo "<label class='form-label'>" . __s('Anexos', 'relatorioglpicomercial') . "</label><div>";
        Html::showCheckbox(['name' => 'send_pdf', 'title' => __s('Anexar PDF', 'relatorioglpicomercial'), 'checked' => (int) $this->fields['send_pdf'] === 1]);
        echo "&nbsp;" . __s('Anexar PDF', 'relatorioglpicomercial');
        echo "</div></div>";
        echo "</div>";

        echo "</div></div>";
        echo "</div>";

        // ---------------------------------------------------------- schedule --
        $hours = [];
        for ($h = 0; $h <= 23; $h++) {
            $hours[$h] = sprintf('%02d:00', $h);
        }
        $days = [];
        for ($d = 1; $d <= 31; $d++) {
            $days[$d] = (string) $d;
        }

        $pane('agendamento');
        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title'>" . __s('Agendamento', 'relatorioglpicomercial') . "</h3></div>";
        echo "<div class='card-body'>";

        echo "<div class='row mb-3'>";
        echo "<div class='col-md-3'>";
        echo "<label class='form-label'>" . __s('Periodicidade', 'relatorioglpicomercial') . "</label>";
        \Dropdown::showFromArray('frequency', ReportScheduler::getFrequencies(), [
            'value'     => $this->fields['frequency'],
            'on_change' => 'plugin_relatorioglpicomercial_toggleFrequency(this.value);',
        ]);
        echo "</div>";

        echo "<div class='col-md-3' id='relatoriotransmissiongroup-weekday'>";
        echo "<label class='form-label'>" . __s('Dia da semana', 'relatorioglpicomercial') . "</label>";
        \Dropdown::showFromArray('send_weekday', ReportScheduler::getWeekdays(), ['value' => $this->fields['send_weekday']]);
        echo "</div>";

        echo "<div class='col-md-3' id='relatoriotransmissiongroup-monthday'>";
        echo "<label class='form-label'>" . __s('Dia do mês', 'relatorioglpicomercial') . "</label>";
        \Dropdown::showFromArray('send_day', $days, ['value' => $this->fields['send_day']]);
        echo "<div class='form-hint'>" . __s('Dias 29 a 31 são ajustados para o último dia do mês.', 'relatorioglpicomercial') . "</div>";
        echo "</div>";

        echo "<div class='col-md-3'>";
        echo "<label class='form-label'>" . __s('Horário de envio', 'relatorioglpicomercial') . "</label>";
        \Dropdown::showFromArray('send_hour', $hours, ['value' => $this->fields['send_hour']]);
        echo "</div>";
        echo "</div>";

        echo "<div class='row mb-0'>";
        echo "<div class='col-md-3'>";
        echo "<label class='form-label'>" . __s('Janela de dados', 'relatorioglpicomercial') . "</label>";
        \Dropdown::showFromArray('window_type', ReportScheduler::getWindowTypes(), [
            'value'     => $this->fields['window_type'],
            'on_change' => 'plugin_relatorioglpicomercial_toggleWindow(this.value);',
        ]);
        echo "</div>";

        echo "<div class='col-md-3' id='relatoriotransmissiongroup-windowdays'>";
        echo "<label class='form-label'>" . __s('Quantidade de dias', 'relatorioglpicomercial') . "</label>";
        echo "<input type='number' class='form-control' name='window_days' min='1' max='365' value='" . htmlescape((string) $this->fields['window_days']) . "'>";
        echo "</div>";
        echo "</div>";

        echo "</div></div>";
        echo "</div>";

        // ----------------------------------------------------------- message --
        $pane('email');
        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title'>" . __s('Conteúdo do e-mail', 'relatorioglpicomercial') . "</h3></div>";
        echo "<div class='card-body'>";

        echo "<div class='mb-3'>";
        echo "<label class='form-label'>" . __s('Assunto', 'relatorioglpicomercial') . "</label>";
        echo "<input type='text' class='form-control' name='subject' value='" . htmlescape($this->getEffectiveSubject()) . "'>";
        echo "</div>";

        echo "<div class='mb-2'>";
        echo "<label class='form-label'>" . __s('Corpo do e-mail (HTML)', 'relatorioglpicomercial') . "</label>";
        echo "<textarea class='form-control font-monospace' name='body_html' id='relatoriotransmissiongroup-body' rows='14'>" . htmlescape($this->getEffectiveBody()) . "</textarea>";
        echo "</div>";

        echo "<div class='mb-3'>";
        echo "<button type='button' class='btn btn-outline-secondary btn-sm' onclick='plugin_relatorioglpicomercial_resetBody();'>"
            . __s('Restaurar padrão', 'relatorioglpicomercial') . "</button>";
        echo "</div>";

        echo "<div class='alert alert-info mb-0'>";
        echo "<strong>" . __s('Marcadores disponíveis', 'relatorioglpicomercial') . "</strong><br>";
        echo "<span id='relatoriotransmissiongroup-vars-comercial'" . ($this->fields['report_type'] !== self::REPORT_COMERCIAL ? " style='display:none;'" : "") . ">";
        foreach (ReportMailer::VARIABLES_COMERCIAL as $variable) {
            echo "<code class='me-2'>{{ " . htmlescape($variable) . " }}</code>";
        }
        echo "</span>";
        echo "<span id='relatoriotransmissiongroup-vars-dashboard'" . ($this->fields['report_type'] !== self::REPORT_DASHBOARD ? " style='display:none;'" : "") . ">";
        foreach (ReportMailer::VARIABLES_DASHBOARD as $variable) {
            echo "<code class='me-2'>{{ " . htmlescape($variable) . " }}</code>";
        }
        echo "</span>";
        echo "</div>";

        echo "</div></div>";
        echo "</div>";

        // -------------------------------------------------------- attachment --
        $pane('anexo');
        AttachmentForm::showCard($this->fields, $entities, $is_new);
        echo "</div>";

        // -------------------------------------------------------- recipients --
        if (!$is_new) {
            $entity_configs = TransmissionGroupEntity::getAllForGroup((int) $this->fields['id']);

            $pane('destinatarios');
            echo "<div class='card mb-3'>";
            echo "<div class='card-header'>";
            echo "<h3 class='card-title'>" . __s('Destinatários por cliente', 'relatorioglpicomercial') . "</h3>";
            echo "<div class='card-actions'>";
            echo "<input type='text' class='form-control form-control-sm' placeholder='" . __s('Filtrar clientes...', 'relatorioglpicomercial') . "'"
                . " onkeyup='plugin_relatorioglpicomercial_filter(this.value);'>";
            echo "</div></div>";

            if ($entities === []) {
                echo "<div class='card-body'><div class='alert alert-warning mb-0'>"
                    . __s('Nenhum cliente elegível foi encontrado para este tipo de relatório.', 'relatorioglpicomercial')
                    . "</div></div>";
            } else {
                echo "<div class='table-responsive'>";
                echo "<table class='table card-table'>";
                echo "<thead><tr>";
                echo "<th style='width:5%'>" . __s('Ativo', 'relatorioglpicomercial') . "</th>";
                echo "<th style='width:25%'>" . __s('Cliente', 'relatorioglpicomercial') . "</th>";
                echo "<th style='width:55%'>" . __s('E-mails dos destinatários', 'relatorioglpicomercial') . "</th>";
                echo "<th style='width:15%'>" . __s('Último envio', 'relatorioglpicomercial') . "</th>";
                echo "</tr></thead><tbody id='relatoriotransmissiongroup-clients'>";

                foreach ($entities as $entity) {
                    $entities_id   = $entity['entities_id'];
                    $entity_config = $entity_configs[$entities_id] ?? null;

                    echo "<tr data-name='" . htmlescape(mb_strtolower($entity['nome'])) . "'>";

                    echo "<td>";
                    echo Html::hidden('entities[]', ['value' => $entities_id]);
                    Html::showCheckbox([
                        'name'    => "active[$entities_id]",
                        'checked' => $entity_config !== null && $entity_config['is_active'] === 1,
                    ]);
                    echo "</td>";

                    echo "<td>" . htmlescape($entity['nome']);
                    echo "<div><button type='button' class='btn btn-link btn-sm p-0' data-bs-toggle='collapse'"
                        . " data-bs-target='#relatoriotransmissiongroup-attach-$entities_id'>"
                        . __s('Dados do anexo', 'relatorioglpicomercial') . "</button></div>";
                    echo "</td>";

                    echo "<td>";
                    echo "<input type='text' class='form-control' name='emails[$entities_id]'"
                        . " value='" . htmlescape($entity_config['emails'] ?? '') . "'"
                        . " placeholder='" . __s('fulano@cliente.com, gestor@cliente.com', 'relatorioglpicomercial') . "'>";
                    echo "<div class='mt-2' style='min-width:260px'>"
                        . "<div class='small text-muted mb-1' style='white-space:nowrap;word-break:keep-all;overflow-wrap:normal;writing-mode:horizontal-tb;width:auto'>"
                        . __s('Incluir e-mails dos usuários do grupo GLPI', 'relatorioglpicomercial') . "</div>";
                    \Group::dropdown([
                        'name'    => "glpi_groups[$entities_id]",
                        'value'   => (int) ($entity_config['groups_id'] ?? 0),
                        'entity'  => $entities_id,
                        'width'   => '100%',
                    ]);
                    echo "</div>";
                    echo "</td>";

                    echo "<td>" . htmlescape($entity_config['last_sent_period'] ?? '-') . "</td>";

                    echo "</tr>";

                    // Values of this client that override the attachment's defaults.
                    echo "<tr class='collapse' id='relatoriotransmissiongroup-attach-$entities_id'"
                        . " data-name='" . htmlescape(mb_strtolower($entity['nome'])) . "'>";
                    echo "<td></td><td colspan='3'><div class='row g-2'>";
                    foreach (
                        [
                            'contract_number'  => __s('Nº do contrato', 'relatorioglpicomercial'),
                            'client_rep_name'  => __s('Responsável do cliente (aceite)', 'relatorioglpicomercial'),
                            'client_rep_title' => __s('Cargo do responsável', 'relatorioglpicomercial'),
                        ] as $field => $label
                    ) {
                        echo "<div class='col-md-4'>";
                        echo "<label class='form-label mb-1'>" . $label . "</label>";
                        echo "<input type='text' class='form-control form-control-sm' maxlength='255' name='{$field}[$entities_id]'"
                            . " value='" . htmlescape($entity_config[$field] ?? '') . "'"
                            . " placeholder='" . __s('Padrão do grupo', 'relatorioglpicomercial') . "'>";
                        echo "</div>";
                    }
                    echo "</div></td></tr>";
                }

                echo "</tbody></table></div>";
            }

            echo "</div>";
            echo "</div>";
        }

        echo "</div>";

        echo "<div class='mb-3'>";
        echo Html::submit(_sx('button', 'Save'), ['name' => $is_new ? 'add' : 'update', 'class' => 'btn btn-primary']);
        if (!$is_new && static::canPurge()) {
            echo "&nbsp;";
            echo Html::submit(_sx('button', 'Delete permanently'), [
                'name'    => 'purge',
                'class'   => 'btn btn-outline-danger',
                'confirm' => __('Excluir este grupo de transmissão?', 'relatorioglpicomercial'),
            ]);
        }
        echo "</div>";

        Html::closeForm();

        // ----------------------------------------------------------- test mail --
        if (!$is_new && !empty($entities)) {
            echo "<form method='post' action='" . htmlescape($self_url) . "'>";
            echo Html::hidden('id', ['value' => $this->fields['id']]);
            echo "<div class='card'>";
            echo "<div class='card-header'><h3 class='card-title'>" . __s('Enviar e-mail de teste', 'relatorioglpicomercial') . "</h3></div>";
            echo "<div class='card-body'>";
            echo "<p class='text-muted'>" . __s('O e-mail de teste é enviado somente para o seu próprio endereço e não altera o controle de envios.', 'relatorioglpicomercial') . "</p>";
            echo "<div class='row row-cols-lg-auto g-2 align-items-end'>";

            echo "<div class='col-12'>";
            echo "<label class='form-label'>" . __s('Cliente', 'relatorioglpicomercial') . "</label>";
            \Dropdown::showFromArray('test_entities_id', array_column($entities, 'nome', 'entities_id'), [
                'value' => $entities[0]['entities_id'],
            ]);
            echo "</div>";

            echo "<div class='col-12'>";
            echo Html::submit(__s('Enviar teste', 'relatorioglpicomercial'), ['name' => 'send_test', 'class' => 'btn btn-outline-primary']);
            echo "</div>";

            echo "</div></div></div>";
            Html::closeForm();
        }

        echo Html::scriptBlock(
            'window.plugin_relatorioglpicomercial_default_bodies = '
            . json_encode(
                [
                    self::REPORT_COMERCIAL => self::getDefaultBody(self::REPORT_COMERCIAL),
                    self::REPORT_DASHBOARD => self::getDefaultBody(self::REPORT_DASHBOARD),
                ],
                JSON_HEX_TAG | JSON_HEX_AMP
            ) . ';'
        );

        echo Html::scriptBlock(<<<'JS'
function plugin_relatorioglpicomercial_toggleFrequency(value) {
    document.getElementById('relatoriotransmissiongroup-weekday').style.display  = (value === 'weekly') ? '' : 'none';
    document.getElementById('relatoriotransmissiongroup-monthday').style.display = (value === 'monthly') ? '' : 'none';
}

function plugin_relatorioglpicomercial_toggleWindow(value) {
    document.getElementById('relatoriotransmissiongroup-windowdays').style.display = (value === 'last_n_days') ? '' : 'none';
}

function plugin_relatorioglpicomercial_toggleReportType(value) {
    document.getElementById('relatoriotransmissiongroup-vars-comercial').style.display = (value === 'comercial') ? '' : 'none';
    document.getElementById('relatoriotransmissiongroup-vars-dashboard').style.display = (value === 'dashboard') ? '' : 'none';
}

function plugin_relatorioglpicomercial_resetBody() {
    var reportType = document.querySelector('[name="report_type"]').value;
    document.getElementById('relatoriotransmissiongroup-body').value
        = window.plugin_relatorioglpicomercial_default_bodies[reportType];
}

// Reopen the tab that was in use: saving reloads the page.
(function () {
    var tabs = document.getElementById('relatoriotransmissiongroup-tabs');
    var key  = 'relatoriotransmissiongroup-tab';

    tabs.addEventListener('shown.bs.tab', function (event) {
        try {
            sessionStorage.setItem(key, event.target.dataset.bsTarget);
        } catch (e) {}
    });

    var stored = null;
    try {
        stored = sessionStorage.getItem(key);
    } catch (e) {}
    var button = stored !== null ? tabs.querySelector('[data-bs-target="' + stored + '"]') : null;
    if (button !== null) {
        bootstrap.Tab.getOrCreateInstance(button).show();
    }

    // A required field in a hidden tab would block the save silently: show it.
    document.getElementById('relatoriotransmissiongroup-form').addEventListener('invalid', function (event) {
        var pane = event.target.closest('.tab-pane');
        if (pane !== null && !pane.classList.contains('active')) {
            bootstrap.Tab.getOrCreateInstance(tabs.querySelector('[data-bs-target="#' + pane.id + '"]')).show();
        }
    }, true);
})();

function plugin_relatorioglpicomercial_filter(term) {
    term = term.toLowerCase();
    document.querySelectorAll('#relatoriotransmissiongroup-clients tr').forEach(function (row) {
        row.style.display = row.dataset.name.indexOf(term) !== -1 ? '' : 'none';
    });
}

$(function() {
    plugin_relatorioglpicomercial_toggleFrequency(
        document.querySelector('[name="frequency"]').value
    );
    plugin_relatorioglpicomercial_toggleWindow(
        document.querySelector('[name="window_type"]').value
    );
});
JS);

        return true;
    }
}
