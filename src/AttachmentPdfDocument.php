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
use Plugin;

/**
 * A4 landscape document with the chrome of the Mindworks report template
 * (docs/email_tamplates): a dark cover with the cyan "circuit" line and the
 * blue logo band, and on every other page the "RELATÓRIO {CLIENTE}" header
 * with the year badge and a footer with the page's label and the logo.
 *
 * The chrome is drawn through TCPDF's native API because its HTML engine
 * cannot render the template's positioning, rounded borders or SVG; only the
 * section bodies go through writeHTML() (see {@see AttachmentComposer}).
 */
class AttachmentPdfDocument extends GLPIPDF
{
    use PdfCharts;

    public const MARGIN_X = 12.0;

    private const MARGIN_TOP    = 21.0;
    private const MARGIN_BOTTOM = 17.0;
    private const HEADER_LINE_Y = 13.0;
    private const FOOTER_LINE_Y = 11.5;
    /** Height of the logo band at the bottom of the cover: 18% of the page, as in the template. */
    private const COVER_BAND = 37.8;

    /** @var array{0: int, 1: int, 2: int} */
    private const COLOR_NAVY = [0, 14, 39];
    /** @var array{0: int, 1: int, 2: int} */
    private const COLOR_BLUE = [0, 82, 149];
    /** @var array{0: int, 1: int, 2: int} */
    private const COLOR_CYAN = [107, 227, 247];
    /** @var array{0: int, 1: int, 2: int} End of the cover band's gradient. */
    private const COVER_BAND_END = [3, 28, 73];
    /** @var array{0: int, 1: int, 2: int} #2f64f7 at 15% opacity over the navy. */
    private const COVER_GLOW = [7, 27, 70];
    /** Reach of the cover's left glow (500px box shifted by half, blurred by 120px). */
    private const COVER_GLOW_WIDTH = 110.0;

    private const LOGO_FILE  = '/templates/pdf/attachment/mindworks-logo.svg';
    private const LOGO_COLOR = '#005295';
    /** Width / height of the logo's viewBox. */
    private const LOGO_RATIO = 204.721 / 28.571;

    private string $client;
    private string $year;
    private bool $cover;
    private string $footer_label = '';

    /** The page GLPIPDF's constructor adds is still unused. */
    private bool $fresh = true;

    private static ?string $logo = null;

    /**
     * @param bool $starts_with_cover GLPIPDF's constructor already adds (and
     *                                draws the header of) the first page, so
     *                                its kind must be known upfront.
     */
    public function __construct(string $client, string $year, bool $starts_with_cover, string $title)
    {
        $this->client = $client;
        $this->year   = $year;
        $this->cover  = $starts_with_cover;

        parent::__construct([
            'orientation'   => 'L',
            'format'        => 'A4',
            'font'          => 'helvetica',
            'font_size'     => 8,
            'margin_left'   => self::MARGIN_X,
            'margin_right'  => self::MARGIN_X,
            'margin_top'    => self::MARGIN_TOP,
            'margin_bottom' => self::MARGIN_BOTTOM,
            'margin_header' => 0,
            'margin_footer' => 0,
        ]);

        $this->SetTitle($title);
        $this->SetSubject('Relatório ' . $client);
        $this->SetAuthor('Mindworks Informática Ltda');
        $this->setHtmlVSpace([
            'p' => [['h' => 0, 'n' => 0], ['h' => 1.2, 'n' => 1]],
        ]);
    }

    public function startCover(): void
    {
        $this->newPage(true, '');
    }

    public function startSection(string $footer_label): void
    {
        $this->newPage(false, $footer_label);
    }

    private function newPage(bool $cover, string $footer_label): void
    {
        if ($this->fresh) {
            $this->fresh        = false;
            $this->footer_label = $footer_label;
            return;
        }

        // The footer of the page being closed must still show its own label.
        $this->endPage();
        $this->cover        = $cover;
        $this->footer_label = $footer_label;
        $this->AddPage();
    }

    /**
     * @return void
     */
    public function Header()
    {
        $width = $this->getPageWidth();

        if ($this->cover) {
            $this->SetFillColor(...self::COLOR_NAVY);
            $this->Rect(0, 0, $width, $this->getPageHeight(), 'F');

            // .bg-color-capa: the blurred #2f64f7 glow (15% opacity) on the left edge,
            // approximated by a gradient from that tint over the navy to the navy itself.
            $this->LinearGradient(
                0,
                0,
                self::COVER_GLOW_WIDTH,
                $this->getPageHeight() - self::COVER_BAND,
                self::COVER_GLOW,
                self::COLOR_NAVY
            );
            return;
        }

        $this->SetFont('helvetica', 'B', 8.25);
        $this->SetTextColor(...self::COLOR_BLUE);
        $this->SetXY(self::MARGIN_X, 5);
        $this->Cell($width * 0.7, 5.5, mb_strtoupper('Relatório ' . $this->client), 0, 0, 'L', false, '', 1);

        $badge = $this->GetStringWidth($this->year) + 6.4;
        $x     = $width - self::MARGIN_X - $badge;
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.26);
        $this->RoundedRect($x, 5, $badge, 5.5, 0.8, '1111', 'D');
        $this->SetTextColor(100, 116, 139);
        $this->SetXY($x, 5);
        $this->Cell($badge, 5.5, $this->year, 0, 0, 'C');

        $this->SetDrawColor(229, 231, 235);
        $this->Line(0, self::HEADER_LINE_Y, $width, self::HEADER_LINE_Y);

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 8);
    }

    /**
     * @return void
     */
    public function Footer()
    {
        $width  = $this->getPageWidth();
        $height = $this->getPageHeight();

        if ($this->cover) {
            // .footer-capa: linear-gradient(90deg, #005295, rgb(3, 28, 73)).
            $this->LinearGradient(0, $height - self::COVER_BAND, $width, self::COVER_BAND, self::COLOR_BLUE, self::COVER_BAND_END);

            $logo_w = 61.0;
            $logo_h = $logo_w / self::LOGO_RATIO;
            $this->drawLogo('#ffffff', ($width - $logo_w) / 2, $height - (self::COVER_BAND / 2) - ($logo_h / 2), $logo_w);
            return;
        }

        $line_y = $height - self::FOOTER_LINE_Y;
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.26);
        $this->Line(0, $line_y, $width, $line_y);

        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(148, 163, 184);
        $this->SetXY(self::MARGIN_X, $line_y + 3);
        $label = $this->footer_label !== '' ? $this->footer_label . '   |   ' : '';
        $this->Cell($width * 0.6, 4.5, $label . 'Página ' . $this->getAliasNumPage(), 0, 0, 'L');

        $logo_w = 33.0;
        $this->drawLogo(self::LOGO_COLOR, $width - self::MARGIN_X - $logo_w, $line_y + 3.3, $logo_w);
    }

    /**
     * Content of the cover: eyebrow, subtitle, headline and the cyan
     * "circuit" line, vertically centered in the navy area above the band.
     */
    public function drawCover(string $eyebrow, string $subtitle, string $headline): void
    {
        $x     = 14.5;
        $width = $this->getPageWidth() - (2 * $x);
        $area  = $this->getPageHeight() - self::COVER_BAND;
        $y     = ($area - 50) / 2;

        $this->SetAutoPageBreak(false);

        $this->SetFont('helvetica', '', 10.5);
        $this->SetTextColor(...self::COLOR_CYAN);
        $this->setFontSpacing(0.66);
        $this->SetXY($x, $y);
        $this->Cell($width, 6, mb_strtoupper($eyebrow), 0, 0, 'L');

        $this->SetFont('helvetica', '', 19.5);
        $this->SetTextColor(255, 255, 255);
        $this->setFontSpacing(0.26);
        $this->SetXY($x, $y + 9);
        $this->Cell($width, 9, mb_strtoupper($subtitle), 0, 0, 'L', false, '', 1);
        $this->setFontSpacing(0);

        $this->SetFont('helvetica', 'B', 24);
        $this->SetXY($x, $y + 21);
        $this->Cell($width, 11, $headline, 0, 0, 'L', false, '', 1);

        // The template's 760px-wide SVG (viewBox 800x40), scaled to millimeters.
        $unit = 0.2514;
        $top  = $y + 39;
        $this->SetDrawColor(...self::COLOR_CYAN);
        $this->SetLineStyle(['width' => 2.5 * $unit, 'cap' => 'round', 'join' => 'round', 'color' => self::COLOR_CYAN]);
        $this->Line($x + (8 * $unit), $top + (8 * $unit), $x + (40 * $unit), $top + (32 * $unit));
        $this->Line($x + (40 * $unit), $top + (32 * $unit), $x + (780 * $unit), $top + (32 * $unit));

        $this->SetLineStyle(['width' => 2 * $unit, 'cap' => 'butt', 'join' => 'miter', 'color' => self::COLOR_NAVY]);
        $this->SetFillColor(...self::COLOR_CYAN);
        $this->Circle($x + (8 * $unit), $top + (8 * $unit), 7 * $unit, 0, 360, 'DF');
        $this->Circle($x + (780 * $unit), $top + (32 * $unit), 7 * $unit, 0, 360, 'DF');

        $this->SetLineStyle(['width' => 0.2, 'color' => [0, 0, 0]]);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 8);
        $this->SetAutoPageBreak(true, self::MARGIN_BOTTOM);
    }

    /**
     * Section heading in the brand blue.
     */
    public function heading(string $text, float $size = 12.0, bool $muted = false): void
    {
        $this->SetFont('helvetica', 'B', $size);
        if ($muted) {
            $this->SetTextColor(51, 65, 85);
        } else {
            $this->SetTextColor(...self::COLOR_BLUE);
        }
        $this->checkPageBreak($size * 0.6);
        $this->SetX(self::MARGIN_X);
        $this->Cell(0, $size * 0.5, $text, 0, 1, 'L');
        $this->Ln($size * 0.2);

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 8);
    }

    public function writeBody(string $html): void
    {
        $this->writeHTML($html, true, false, true, false, '');
    }

    /**
     * Writes the HTML so that it ends right above the footer, as the
     * confidentiality notice of the template (its text sits at the bottom of
     * the page). Falls back to the current position when it does not fit.
     */
    public function writeBodyAtBottom(string $html): void
    {
        $start = $this->GetY();

        $this->startTransaction();
        $this->writeBody($html);
        $height = $this->GetY() - $start;
        $fits   = $this->getPage() === $this->getNumPages() && $height > 0;
        $this->rollbackTransaction(true);

        $bottom = $this->getPageHeight() - self::MARGIN_BOTTOM - 6;
        if ($fits && $start + $height < $bottom) {
            $this->SetY($bottom - $height);
        }

        $this->writeBody($html);
    }

    /**
     * Writes the HTML and frames it with the rounded blue border of the
     * template's cards. Meant for short blocks that never span two pages.
     */
    public function writeBodyInCard(string $html): void
    {
        $this->checkPageBreak(20);
        $start = $this->GetY();
        $page  = $this->getPage();

        // No trailing line break: the frame must hug the table.
        $this->writeHTML($html, false, false, true, false, '');

        if ($this->getPage() === $page) {
            $this->SetLineStyle(['width' => 0.4, 'color' => [0, 80, 150]]);
            $this->RoundedRect(self::MARGIN_X, $start, $this->contentWidth(), $this->GetY() - $start, 3, '1111', 'D');
            $this->SetLineStyle(['width' => 0.2, 'color' => [0, 0, 0]]);
        }
    }

    private function drawLogo(string $color, float $x, float $y, float $width): void
    {
        $svg = self::logoSvg();
        if ($svg === '') {
            return;
        }

        $this->ImageSVG('@' . str_replace(self::LOGO_COLOR, $color, $svg), $x, $y, $width, $width / self::LOGO_RATIO);
    }

    private static function logoSvg(): string
    {
        if (self::$logo === null) {
            $dir = Plugin::getPhpDir('relatorioglpicomercial');
            /** @phpstan-ignore theCodingMachineSafe.function (a missing logo must not break the PDF) */
            $svg        = $dir !== false ? @file_get_contents($dir . self::LOGO_FILE) : false;
            self::$logo = $svg === false ? '' : $svg;
        }

        return self::$logo;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    protected function brandColor(): array
    {
        return self::COLOR_BLUE;
    }
}
