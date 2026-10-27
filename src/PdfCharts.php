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

/**
 * KPI cards and donut panels drawn through TCPDF's native API, shared by the
 * on-screen report PDFs ({@see ReportPdfDocument}) and the email attachment
 * ({@see AttachmentPdfDocument}). Each document only supplies its brand color.
 *
 * Must be used by a TCPDF subclass.
 */
trait PdfCharts
{
    /** @var array{0: int, 1: int, 2: int} */
    private const PANEL_COLOR = [245, 246, 248];

    /** Height of a legend item when its value goes under its label. */
    private const STACKED_LINE = 7.4;

    /**
     * Color of the card values and of the donut totals.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    abstract protected function brandColor(): array;

    /**
     * Row of rounded summary cards, laid out edge to edge over the content width.
     *
     * An optional "color" (CSS hex, as used by the HTML reports) overrides the
     * brand color of the value, so SLA/violation cards read the same in both
     * outputs.
     *
     * @param array<int, array{label: string, value: string, color?: string}> $cards
     */
    public function drawSummaryCards(array $cards): void
    {
        if ($cards === []) {
            return;
        }

        $gap    = 6.0;
        $height = 17.0;
        $left   = $this->getMargins()['left'];

        $this->checkPageBreak($height + 6);
        $top   = $this->GetY();
        $width = ($this->contentWidth() - ($gap * (count($cards) - 1))) / count($cards);

        foreach (array_values($cards) as $index => $card) {
            $x = $left + ($index * ($width + $gap));

            $this->SetFillColor(...self::PANEL_COLOR);
            $this->RoundedRect($x, $top, $width, $height, 1.8, '1111', 'F');

            $this->SetFont('helvetica', 'B', 7);
            $this->SetTextColor(110, 118, 129);
            $this->setFontSpacing(0.35);
            $this->SetXY($x + 5, $top + 3.5);
            $this->Cell($width - 10, 4, mb_strtoupper($card['label']), 0, 0, 'L', false, '', 1);
            $this->setFontSpacing(0);

            $this->SetFont('helvetica', 'B', 15);
            $this->SetTextColor(...$this->rgb($card['color'] ?? null));
            $this->SetXY($x + 5, $top + 8);
            $this->Cell($width - 10, 7, $card['value'], 0, 0, 'L');
        }

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 8);
        $this->SetXY($left, $top + $height + 6);
    }

    /**
     * Row of panels, each with a donut chart, its total in the middle and a
     * legend (label, percentage and count) beside it. Drawn natively because
     * TCPDF's HTML engine cannot render charts.
     *
     * The donut shrinks with the panel width, so four panels fit a landscape
     * page as well as three; when the legend gets too narrow for a label and its
     * value side by side, each value goes under its label and the panels grow
     * taller. A series item may carry a "display" text shown in the legend
     * instead of its raw value (e.g. hours instead of seconds), and a panel a
     * "total_display" shown in the middle of the donut.
     *
     * @param array<int, array{title: string, caption: string, total_display?: string, series: list<array{label: string, value: int, pct: string, color: string, display?: string}>}> $panels
     */
    public function drawDonutPanels(array $panels): void
    {
        if ($panels === []) {
            return;
        }

        $gap     = 6.0;
        $left    = $this->getMargins()['left'];
        $width   = ($this->contentWidth() - ($gap * (count($panels) - 1))) / count($panels);
        $radius  = min(15.0, $width * 0.18);
        $ring    = $radius * (6.5 / 15.0);
        $value   = min(20.0, $width * 0.25);
        $legend  = $width - 5 - (2 * $radius) - 6 - 4;
        $stacked = $legend - 4 - $value < 14.0;
        $longest = max(array_map(static fn (array $panel): int => count($panel['series']), $panels));
        $height  = $stacked ? max(46.0, 13 + ($longest * self::STACKED_LINE)) : 46.0;

        $this->checkPageBreak($height + 6);
        $top = $this->GetY();

        foreach (array_values($panels) as $index => $panel) {
            $x = $left + ($index * ($width + $gap));

            $this->SetFillColor(...self::PANEL_COLOR);
            $this->RoundedRect($x, $top, $width, $height, 1.8, '1111', 'F');

            $this->SetFont('helvetica', 'B', 7);
            $this->SetTextColor(110, 118, 129);
            $this->setFontSpacing(0.35);
            $this->SetXY($x + 5, $top + 3.5);
            $this->Cell($width - 10, 4, mb_strtoupper($panel['title']), 0, 0, 'L', false, '', 1);
            $this->setFontSpacing(0);

            if ($panel['series'] === []) {
                $this->SetFont('helvetica', '', 8);
                $this->SetXY($x, $top + ($height / 2));
                $this->Cell($width, 4, 'Sem dados', 0, 0, 'C');
                continue;
            }

            $cx = $x + 5 + $radius;
            $cy = $top + 10 + 15.0 + 2;
            $this->drawDonut($cx, $cy, $radius, $ring, $panel['series'], $panel['caption'], $panel['total_display'] ?? null);
            $this->drawLegend($cx + $radius + 6, $top + 11, $x + $width - 4, $panel['series'], $value, $stacked);
        }

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 8);
        $this->SetXY($left, $top + $height + 6);
    }

    /**
     * @param list<array{label: string, value: int, pct: string, color: string, display?: string}> $series
     */
    private function drawDonut(
        float $cx,
        float $cy,
        float $radius,
        float $ring,
        array $series,
        string $caption,
        ?string $total_display
    ): void {
        $total = array_sum(array_column($series, 'value'));
        $angle = 0.0;

        $this->SetDrawColor(...self::PANEL_COLOR);
        $this->SetLineWidth(0.4);
        foreach ($series as $item) {
            if ($item['value'] <= 0 || $total <= 0) {
                continue;
            }

            $this->SetFillColor(...$this->rgb($item['color']));
            $sweep = ($item['value'] / $total) * 360;
            if ($sweep >= 360) {
                $this->Circle($cx, $cy, $radius, 0, 360, 'F');
            } else {
                $this->PieSector($cx, $cy, $radius, $angle, $angle + $sweep, 'FD', true, 90);
            }
            $angle += $sweep;
        }

        // The hole: same color as the panel background.
        $this->SetFillColor(...self::PANEL_COLOR);
        $this->Circle($cx, $cy, $radius - $ring, 0, 360, 'F');

        $inner = 2 * ($radius - $ring);
        $this->SetTextColor(...$this->brandColor());
        $this->SetFont('helvetica', 'B', $total_display !== null ? 8 : 12);
        $this->SetXY($cx - ($inner / 2), $cy - 4.5);
        $this->Cell($inner, 5, $total_display ?? (string) $total, 0, 0, 'C', false, '', 1);

        $this->SetTextColor(110, 118, 129);
        $this->SetFont('helvetica', 'B', 5);
        $this->SetXY($cx - ($inner / 2), $cy + 1);
        $this->Cell($inner, 3, mb_strtoupper($caption), 0, 0, 'C', false, '', 1);
    }

    /**
     * @param list<array{label: string, value: int, pct: string, color: string, display?: string}> $series
     * @param bool $stacked Value under its label instead of beside it
     */
    private function drawLegend(float $x, float $y, float $right, array $series, float $value, bool $stacked): void
    {
        $line  = $stacked ? self::STACKED_LINE : 5.0;
        $label = $stacked ? max(1.0, $right - $x - 4) : max(1.0, $right - $x - 4 - $value);

        foreach ($series as $index => $item) {
            $top  = $y + ($index * $line);
            $text = $item['pct'] . ' (' . ($item['display'] ?? (string) $item['value']) . ')';

            $this->SetFillColor(...$this->rgb($item['color']));
            $this->Circle($x + 1.2, $top + 2.3, 1.2, 0, 360, 'F');

            $this->SetFont('helvetica', '', 7);
            $this->SetTextColor(51, 65, 85);
            $this->SetXY($x + 4, $top);
            $this->Cell($label, 4.6, $item['label'], 0, 0, 'L', false, '', 1);

            $this->SetFont('helvetica', 'B', 7);
            if ($stacked) {
                $this->SetXY($x + 4, $top + 3.2);
                $this->Cell($label, 3.6, $text, 0, 0, 'L', false, '', 1);
            } else {
                $this->Cell($value, 4.6, $text, 0, 0, 'R', false, '', 1);
            }
        }
    }

    protected function contentWidth(): float
    {
        $margins = $this->getMargins();

        return $this->getPageWidth() - $margins['left'] - $margins['right'];
    }

    /**
     * "#rrggbb" to an RGB triplet, falling back to the brand color when absent
     * or malformed.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    protected function rgb(?string $hex): array
    {
        if ($hex === null || preg_match('/^#[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return $this->brandColor();
        }

        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }
}
