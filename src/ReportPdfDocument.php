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

use GLPIPDF;

/**
 * A4 document (portrait unless told otherwise) with the report chrome (title band, KPI cards, footer)
 * drawn through TCPDF's native API. Only the data tables go through
 * writeHTML(), which is the part TCPDF's HTML engine handles reliably.
 */
class ReportPdfDocument extends GLPIPDF
{
    private const MARGIN_X    = 12.0;
    private const BAND_HEIGHT = 26.0;

    /** @var array{0: int, 1: int, 2: int} */
    private const COLOR_BRAND = [26, 58, 92];

    private string $eyebrow;
    private string $heading;
    private string $period;
    private string $generated_at;

    public function __construct(
        string $eyebrow,
        string $heading,
        string $period,
        string $generated_at,
        string $orientation = 'P'
    ) {
        $this->eyebrow      = $eyebrow;
        $this->heading      = $heading;
        $this->period       = $period;
        $this->generated_at = $generated_at;

        parent::__construct([
            'orientation'   => $orientation,
            'format'        => 'A4',
            'font'          => 'helvetica',
            'font_size'     => 8,
            'margin_left'   => self::MARGIN_X,
            'margin_right'  => self::MARGIN_X,
            'margin_top'    => self::BAND_HEIGHT + 7,
            'margin_bottom' => 18,
            'margin_header' => 0,
            'margin_footer' => 10,
        ]);

        // GLPIPDF::__construct() already calls AddPage() internally in GLPI
        // 10.x (it has no $addpage flag to opt out), so no extra call here.
        $this->SetTitle($heading);
        $this->SetSubject($eyebrow);
    }

    /**
     * Brand band repeated on every page.
     *
     * @return void
     */
    public function Header()
    {
        $available = $this->contentWidth();

        $this->SetFillColor(...self::COLOR_BRAND);
        $this->Rect(0, 0, $this->getPageWidth(), self::BAND_HEIGHT, 'F');

        $this->SetFont('helvetica', 'B', 7.5);
        $this->SetTextColor(126, 173, 212);
        $this->setFontSpacing(0.45);
        $this->SetXY(self::MARGIN_X, 7);
        $this->Cell($available, 4, mb_strtoupper($this->eyebrow), 0, 0, 'L');
        $this->setFontSpacing(0);

        $this->SetFont('helvetica', '', 8.5);
        $this->SetTextColor(212, 232, 245);
        $this->SetXY(self::MARGIN_X, 7);
        $this->Cell($available, 4, $this->period, 0, 0, 'R');

        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(self::MARGIN_X, 13);
        $this->Cell($available, 8, $this->heading, 0, 0, 'L');

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 8);
    }

    /**
     * @return void
     */
    public function Footer()
    {
        $available = $this->contentWidth();

        $this->SetY(-14);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.2);
        $this->Line(self::MARGIN_X, $this->GetY(), self::MARGIN_X + $available, $this->GetY());

        $this->SetY($this->GetY() + 1.5);
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(148, 163, 184);
        $pagination = 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages();

        $this->Cell($available / 2, 5, 'Gerado em ' . $this->generated_at, 0, 0, 'L');
        $this->Cell($available / 2, 5, $pagination, 0, 0, 'R');
    }

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
        $top    = $this->GetY();
        $width  = ($this->contentWidth() - ($gap * (count($cards) - 1))) / count($cards);

        foreach (array_values($cards) as $index => $card) {
            $x = self::MARGIN_X + ($index * ($width + $gap));

            $this->SetFillColor(245, 246, 248);
            $this->RoundedRect($x, $top, $width, $height, 1.8, '1111', 'F');

            $this->SetFont('helvetica', 'B', 7);
            $this->SetTextColor(110, 118, 129);
            $this->setFontSpacing(0.35);
            $this->SetXY($x + 5, $top + 3.5);
            $this->Cell($width - 10, 4, mb_strtoupper($card['label']), 0, 0, 'L', false, '', 1);
            $this->setFontSpacing(0);

            $this->SetFont('helvetica', 'B', 15);
            $this->SetTextColor(...self::hexToRgb($card['color'] ?? null));
            $this->SetXY($x + 5, $top + 8);
            $this->Cell($width - 10, 7, $card['value'], 0, 0, 'L');
        }

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 8);
        $this->SetXY(self::MARGIN_X, $top + $height + 6);
    }

    /**
     * Row of panels, each with a donut chart, its total in the middle and a
     * legend (label, percentage and count) beside it. Drawn natively because
     * TCPDF's HTML engine cannot render charts.
     *
     * @param array<int, array{title: string, caption: string, series: list<array{label: string, value: int, pct: string, color: string}>}> $panels
     */
    public function drawDonutPanels(array $panels): void
    {
        if ($panels === []) {
            return;
        }

        $gap    = 6.0;
        $height = 46.0;
        $radius = 15.0;
        $ring   = 6.5;
        $width  = ($this->contentWidth() - ($gap * (count($panels) - 1))) / count($panels);

        $this->checkPageBreak($height + 6);
        $top = $this->GetY();

        foreach (array_values($panels) as $index => $panel) {
            $x = self::MARGIN_X + ($index * ($width + $gap));

            $this->SetFillColor(245, 246, 248);
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
            $cy = $top + 10 + $radius + 2;
            $this->drawDonut($cx, $cy, $radius, $ring, $panel['series'], $panel['caption']);
            $this->drawLegend($cx + $radius + 6, $top + 11, $x + $width - 4, $panel['series']);
        }

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 8);
        $this->SetXY(self::MARGIN_X, $top + $height + 6);
    }

    /**
     * @param list<array{label: string, value: int, pct: string, color: string}> $series
     */
    private function drawDonut(float $cx, float $cy, float $radius, float $ring, array $series, string $caption): void
    {
        $total = array_sum(array_column($series, 'value'));
        $angle = 0.0;

        $this->SetDrawColor(245, 246, 248);
        $this->SetLineWidth(0.4);
        foreach ($series as $item) {
            if ($item['value'] <= 0 || $total <= 0) {
                continue;
            }

            $this->SetFillColor(...self::hexToRgb($item['color']));
            $sweep = ($item['value'] / $total) * 360;
            if ($sweep >= 360) {
                $this->Circle($cx, $cy, $radius, 0, 360, 'F');
            } else {
                $this->PieSector($cx, $cy, $radius, $angle, $angle + $sweep, 'FD', true, 90);
            }
            $angle += $sweep;
        }

        // The hole: same color as the panel background.
        $this->SetFillColor(245, 246, 248);
        $this->Circle($cx, $cy, $radius - $ring, 0, 360, 'F');

        $inner = 2 * ($radius - $ring);
        $this->SetTextColor(...self::COLOR_BRAND);
        $this->SetFont('helvetica', 'B', 12);
        $this->SetXY($cx - ($inner / 2), $cy - 4.5);
        $this->Cell($inner, 5, (string) $total, 0, 0, 'C');

        $this->SetTextColor(110, 118, 129);
        $this->SetFont('helvetica', 'B', 5);
        $this->SetXY($cx - ($inner / 2), $cy + 1);
        $this->Cell($inner, 3, mb_strtoupper($caption), 0, 0, 'C', false, '', 1);
    }

    /**
     * @param list<array{label: string, value: int, pct: string, color: string}> $series
     */
    private function drawLegend(float $x, float $y, float $right, array $series): void
    {
        $line  = 5.0;
        $value = 20.0;
        $label = max(1.0, $right - $x - 4 - $value);

        foreach ($series as $index => $item) {
            $top = $y + ($index * $line);

            $this->SetFillColor(...self::hexToRgb($item['color']));
            $this->Circle($x + 1.2, $top + 2.3, 1.2, 0, 360, 'F');

            $this->SetFont('helvetica', '', 7);
            $this->SetTextColor(51, 65, 85);
            $this->SetXY($x + 4, $top);
            $this->Cell($label, 4.6, $item['label'], 0, 0, 'L', false, '', 1);

            $this->SetFont('helvetica', 'B', 7);
            $this->Cell($value, 4.6, $item['pct'] . ' (' . $item['value'] . ')', 0, 0, 'R', false, '', 1);
        }
    }

    public function writeBody(string $html): void
    {
        $this->writeHTML($html, true, false, true, false, '');
    }

    private function contentWidth(): float
    {
        return $this->getPageWidth() - (2 * self::MARGIN_X);
    }

    /**
     * "#rrggbb" to an RGB triplet, falling back to the brand color when absent
     * or malformed.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hexToRgb(?string $hex): array
    {
        if ($hex === null || preg_match('/^#[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return self::COLOR_BRAND;
        }

        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }
}
