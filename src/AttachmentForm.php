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

use Html;
use Plugin;

/**
 * "Anexo PDF" card of the {@see TransmissionGroup} form: which pages of the
 * Mindworks report go into the attachment, in which order, their texts, and a
 * live preview of the resulting PDF.
 *
 * Every input is named attachment[...] and lands in TransmissionGroup's
 * validateInput(), which normalizes it through {@see AttachmentConfig}. The
 * preview posts the very same form to ajax/attachment_preview.php, so it
 * shows unsaved changes too.
 */
class AttachmentForm
{
    /**
     * @param array<string, mixed>                          $fields   The group's fields
     * @param array<int, array{entities_id: int, nome: string}> $entities Clients selectable in the preview
     */
    public static function showCard(array $fields, array $entities, bool $is_new): void
    {
        $config  = AttachmentConfig::fromFields($fields);
        $catalog = AttachmentConfig::getSections();

        echo "<div class='card mb-3'>";
        echo "<div class='card-header'>";
        echo "<h3 class='card-title'>" . __s('Anexo PDF', 'relatorioglpicomercial') . "</h3>";
        echo "<div class='card-actions form-hint mt-0'>"
            . __s('Enviado quando "Anexar PDF" estiver marcado.', 'relatorioglpicomercial') . "</div>";
        echo "</div>";
        echo "<div class='card-body'>";
        echo "<div class='row g-4'>";

        // ------------------------------------------------------ configuration --
        echo "<div class='col-xl-5'>";

        echo "<div class='row mb-3'>";
        echo "<div class='col-md-7'>";
        echo "<label class='form-label'>" . __s('Nome do arquivo', 'relatorioglpicomercial') . "</label>";
        echo "<input type='text' class='form-control' name='attachment[filename]' maxlength='" . AttachmentConfig::MAX_LINE . "'"
            . " value='" . htmlescape((string) $config['filename']) . "'>";
        echo "</div>";
        echo "<div class='col-md-5'>";
        echo "<label class='form-label'>" . __s('Nº do contrato (padrão)', 'relatorioglpicomercial') . "</label>";
        echo "<input type='text' class='form-control' name='attachment[contract_number]' maxlength='" . AttachmentConfig::MAX_LINE . "'"
            . " value='" . htmlescape((string) $config['contract_number']) . "'>";
        echo "<div class='form-hint'>" . __s('Cada cliente pode ter o seu em "Dados do anexo".', 'relatorioglpicomercial') . "</div>";
        echo "</div>";
        echo "</div>";

        echo "<label class='form-label'>" . __s('Páginas do anexo', 'relatorioglpicomercial') . "</label>";
        echo "<div class='list-group mb-3' id='relatoriotransmissiongroup-sections'>";
        foreach ($config['order'] as $key) {
            self::showSection($key, $catalog[$key], $config['sections'][$key]);
        }
        echo "</div>";

        // No Bootstrap "small" class anywhere in this card: GLPI's legacy CSS
        // (css/legacy/includes/_styles.scss) redefines .small as "width: 1%".
        echo "<div class='alert alert-info mb-0'><div>";
        echo "<strong>" . __s('Marcadores disponíveis nos textos do anexo', 'relatorioglpicomercial') . "</strong>";
        echo "<div class='d-flex flex-wrap gap-1 my-1'>";
        foreach (AttachmentConfig::VARIABLES as $variable) {
            echo "<code class='text-nowrap'>{{ " . htmlescape($variable) . " }}</code>";
        }
        echo "</div>";
        echo "<div class='form-hint mt-0'>"
            . __s('Nos textos longos, uma linha em branco separa os parágrafos.', 'relatorioglpicomercial') . "</div>";
        echo "</div></div>";

        echo "</div>";

        // ------------------------------------------------------------ preview --
        echo "<div class='col-xl-7'>";
        echo "<label class='form-label'>" . __s('Pré-visualização', 'relatorioglpicomercial') . "</label>";

        if ($is_new || $entities === []) {
            echo "<div class='alert alert-secondary'>"
                . ($is_new
                    ? __s('Salve o grupo para pré-visualizar o anexo.', 'relatorioglpicomercial')
                    : __s('Nenhum cliente elegível para pré-visualizar o anexo.', 'relatorioglpicomercial'))
                . "</div>";
        } else {
            echo "<div class='row row-cols-auto g-2 align-items-center mb-2'>";
            echo "<div class='col'>";
            \Dropdown::showFromArray('preview_entities_id', array_column($entities, 'nome', 'entities_id'), [
                'value' => $entities[0]['entities_id'],
                'width' => '260px',
            ]);
            echo "</div>";
            echo "<div class='col'>";
            echo "<button type='button' class='btn btn-outline-primary' id='relatoriotransmissiongroup-preview-btn'"
                . " onclick='plugin_relatorioglpicomercial_previewAttachment();'>"
                . "<i class='ti ti-refresh me-1'></i>" . __s('Atualizar pré-visualização', 'relatorioglpicomercial') . "</button>";
            echo "</div>";
            echo "<div class='col'>";
            echo "<a class='btn btn-ghost-secondary d-none' id='relatoriotransmissiongroup-preview-open' target='_blank' rel='noopener'>"
                . "<i class='ti ti-external-link me-1'></i>" . __s('Abrir em nova aba', 'relatorioglpicomercial') . "</a>";
            echo "</div>";
            echo "</div>";

            echo "<div class='form-hint mb-2'>"
                . __s('Usa os valores do formulário, mesmo os ainda não salvos, e a janela de dados do agendamento.', 'relatorioglpicomercial')
                . "</div>";
            echo "<div class='alert alert-danger d-none' id='relatoriotransmissiongroup-preview-error'></div>";
            echo "<div class='border rounded' style='height:720px;background:#cbd5e1;'>";
            echo "<iframe id='relatoriotransmissiongroup-preview' title='" . __s('Pré-visualização do anexo', 'relatorioglpicomercial') . "'"
                . " style='width:100%;height:100%;border:0;'></iframe>";
            echo "</div>";
        }

        echo "</div>";

        echo "</div></div></div>";

        $preview_url = Plugin::getWebDir('relatorioglpicomercial') . '/ajax/attachment_preview.php';
        echo Html::scriptBlock(
            'window.plugin_relatorioglpicomercial_preview_url = ' . json_encode($preview_url, JSON_HEX_TAG | JSON_HEX_AMP) . ';'
        );
        echo Html::scriptBlock(<<<'JS'
function plugin_relatorioglpicomercial_moveSection(button, direction) {
    var item = button.closest('.list-group-item');
    var sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
    if (sibling === null) {
        return;
    }
    item.parentNode.insertBefore(item, direction < 0 ? sibling : sibling.nextElementSibling);
}

function plugin_relatorioglpicomercial_previewAttachment() {
    var button = document.getElementById('relatoriotransmissiongroup-preview-btn');
    var frame  = document.getElementById('relatoriotransmissiongroup-preview');
    var open   = document.getElementById('relatoriotransmissiongroup-preview-open');
    var error  = document.getElementById('relatoriotransmissiongroup-preview-error');

    button.disabled = true;
    error.classList.add('d-none');

    fetch(window.plugin_relatorioglpicomercial_preview_url, {
        method: 'POST',
        body: new FormData(document.getElementById('relatoriotransmissiongroup-form')),
        headers: {
            'X-Glpi-Csrf-Token': getAjaxCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    }).then(function (response) {
        var type = response.headers.get('Content-Type') || '';
        if (!response.ok || type.indexOf('application/pdf') !== 0) {
            return response.text().then(function (text) {
                throw new Error(text.replace(/<[^>]*>/g, ' ').trim().substring(0, 500) || response.statusText);
            });
        }
        return response.blob();
    }).then(function (blob) {
        if (frame.dataset.url) {
            URL.revokeObjectURL(frame.dataset.url);
        }
        var url = URL.createObjectURL(blob);
        frame.dataset.url = url;
        frame.src = url;
        open.href = url;
        open.classList.remove('d-none');
    }).catch(function (e) {
        error.textContent = e.message;
        error.classList.remove('d-none');
    }).finally(function () {
        button.disabled = false;
    });
}
JS);
    }

    /**
     * One page of the attachment: switch, label, move buttons and its
     * collapsible settings.
     *
     * @param array{label: string, title: string, footer: string, fields: array<string, array{label: string, type: string, default: mixed}>} $definition
     * @param array<string, mixed> $values
     */
    private static function showSection(string $key, array $definition, array $values): void
    {
        $prefix   = "attachment[sections][$key]";
        $collapse = 'relatoriotransmissiongroup-section-' . $key;

        echo "<div class='list-group-item p-2'>";
        echo Html::hidden('attachment[order][]', ['value' => $key]);

        echo "<div class='d-flex align-items-center gap-2'>";
        echo "<label class='form-check form-switch mb-0 flex-grow-1'>";
        echo "<input type='hidden' name='{$prefix}[enabled]' value='0'>";
        echo "<input type='checkbox' class='form-check-input' name='{$prefix}[enabled]' value='1'" . (!empty($values['enabled']) ? ' checked' : '') . ">";
        echo "<span class='form-check-label fw-bold'>" . htmlescape($definition['label']) . "</span>";
        echo "</label>";
        echo "<button type='button' class='btn btn-sm btn-ghost-secondary' title='" . __s('Mover para cima', 'relatorioglpicomercial') . "'"
            . " onclick='plugin_relatorioglpicomercial_moveSection(this, -1);'><i class='ti ti-arrow-up'></i></button>";
        echo "<button type='button' class='btn btn-sm btn-ghost-secondary' title='" . __s('Mover para baixo', 'relatorioglpicomercial') . "'"
            . " onclick='plugin_relatorioglpicomercial_moveSection(this, 1);'><i class='ti ti-arrow-down'></i></button>";
        echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-bs-toggle='collapse' data-bs-target='#$collapse'>"
            . "<i class='ti ti-adjustments me-1'></i>" . __s('Configurar', 'relatorioglpicomercial') . "</button>";
        echo "</div>";

        echo "<div class='collapse' id='$collapse'><div class='pt-3 pb-1 px-1'>";

        if ($key !== 'capa') {
            echo "<div class='row g-2 mb-2'>";
            echo "<div class='col-md-6'>";
            echo "<label class='form-label'>" . __s('Título', 'relatorioglpicomercial') . "</label>";
            self::input("{$prefix}[title]", (string) $values['title']);
            echo "</div>";
            echo "<div class='col-md-6'>";
            echo "<label class='form-label'>" . __s('Rodapé', 'relatorioglpicomercial') . "</label>";
            self::input("{$prefix}[footer]", (string) $values['footer']);
            echo "</div>";
            echo "</div>";
        }

        foreach ($definition['fields'] as $name => $field) {
            echo "<div class='mb-2'>";
            self::showField("{$prefix}[$name]", $field, $values[$name]);
            echo "</div>";
        }

        echo "</div></div>";
        echo "</div>";
    }

    /**
     * @param array{label: string, type: string, default: mixed} $field
     */
    private static function showField(string $name, array $field, mixed $value): void
    {
        $label = htmlescape($field['label']);

        switch ($field['type']) {
            case 'bool':
                echo "<label class='form-check mb-0'>";
                echo "<input type='hidden' name='$name' value='0'>";
                echo "<input type='checkbox' class='form-check-input' name='$name' value='1'" . ($value ? ' checked' : '') . ">";
                echo "<span class='form-check-label'>$label</span>";
                echo "</label>";
                return;

            case 'percent':
                echo "<label class='form-label'>$label</label>";
                echo "<input type='number' class='form-control' style='max-width:140px;' name='$name' min='0' max='100' step='0.1'"
                    . " value='" . htmlescape((string) $value) . "'>";
                return;

            case 'text':
                echo "<label class='form-label'>$label</label>";
                echo "<textarea class='form-control' name='$name' rows='4' maxlength='" . AttachmentConfig::MAX_TEXT . "'>"
                    . htmlescape((string) $value) . "</textarea>";
                return;

            case 'columns':
                $selected = array_flip((array) $value);
                $catalog  = DashboardColumns::getCatalog();
                echo "<label class='form-label'>$label</label>";
                echo "<div class='row'>";
                foreach (DashboardColumns::getGroups() as $group => $group_label) {
                    echo "<div class='col-sm-6 mb-2'>";
                    echo "<div class='form-hint fw-bold mt-0 mb-1'>" . htmlescape($group_label) . "</div>";
                    foreach ($catalog as $column_key => $column) {
                        if ($column['group'] !== $group) {
                            continue;
                        }
                        echo "<label class='form-check mb-1'>";
                        echo "<input type='checkbox' class='form-check-input' name='{$name}[]' value='" . htmlescape($column_key) . "'"
                            . (isset($selected[$column_key]) ? ' checked' : '') . ">";
                        echo "<span class='form-check-label'>" . htmlescape($column['label']) . "</span>";
                        echo "</label>";
                    }
                    echo "</div>";
                }
                echo "</div>";
                return;

            default:
                echo "<label class='form-label'>$label</label>";
                self::input($name, (string) $value);
        }
    }

    private static function input(string $name, string $value): void
    {
        echo "<input type='text' class='form-control' name='$name' maxlength='" . AttachmentConfig::MAX_LINE . "'"
            . " value='" . htmlescape($value) . "'>";
    }
}
