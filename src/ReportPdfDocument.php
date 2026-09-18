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
 * A4 portrait document with the report chrome (title band, KPI cards, footer)
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

    public function __construct(string $eyebrow, string $heading, string $period, string $generated_at)
    {
        $this->eyebrow      = $eyebrow;
        $this->heading      = $heading;
        $this->period       = $period;
        $this->generated_at = $generated_at;

        parent::__construct([
            'orientation'   => 'P',
            'format'        => 'A4',
            'font'          => 'helvetica',
            'font_size'     => 8,
            'margin_left'   => self::MARGIN_X,
            'margin_right'  => self::MARGIN_X,
            'margin_top'    => self::BAND_HEIGHT + 7,
            'margin_bottom' => 18,
            'margin_header' => 0,
            'margin_footer' => 10,
        ], null, null, false);

        $this->SetTitle($heading);
        $this->SetSubject($eyebrow);
        $this->AddPage();
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
            $this->Cell($width - 10, 4, mb_strtoupper($card['label']), 0, 0, 'L');
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
