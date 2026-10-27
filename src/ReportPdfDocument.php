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
 * drawn through TCPDF's native API (cards and donuts come from {@see PdfCharts}).
 * Only the data tables go through writeHTML(), which is the part TCPDF's HTML
 * engine handles reliably.
 */
class ReportPdfDocument extends GLPIPDF
{
    use PdfCharts;

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

    public function writeBody(string $html): void
    {
        $this->writeHTML($html, true, false, true, false, '');
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    protected function brandColor(): array
    {
        return self::COLOR_BRAND;
    }
}
