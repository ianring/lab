<?php
declare(strict_types=1);

/**
 * Composes one puzzle onto a US Letter portrait page with TCPDF, importing the
 * LilyPond-engraved music as a vector PDF page via FPDI.
 *
 * Two things keep the music from degrading:
 *
 *  1. The score is engraved at exactly the page's content width, so FPDI places
 *     it at scale 1.0 rather than resampling a mismatched box.
 *  2. If the LilyPond PDF is newer than 1.4 - which the free FPDI cannot parse -
 *     it is rewritten by Ghostscript first. Vector line art survives that
 *     losslessly; there are no raster images in a score to be downsampled.
 */
final class PdfPage
{
    /** US Letter portrait, millimetres. */
    public const PAGE_W = 215.9;
    public const PAGE_H = 279.4;

    /** Vertical space kept clear at the foot of the page for the footer line. */
    private const FOOTER_RESERVE = 20.0;

    public const DEFAULTS = [
        'title'          => '12-Tone Sudoku',
        'subtitle'       => '',
        'intro'          => '',
        'notes'          => '',
        'footer'         => '',
        'author'         => '',
        'pageNumber'     => 1,
        'showPageNumber' => true,
        'showRule'       => true,
        'showQr'         => true,
        'qrSize'         => 22.0,
        'qrCaption'      => 'Answers and worked solution',
        'margin'         => 19.05,      // 0.75 inch
        'font'           => 'helvetica',
        'titleSize'      => 20,
        'subtitleSize'   => 11,
        'bodySize'       => 10,
    ];

    /** @var array<string,mixed> */
    private array $o;

    /** @var string[] */
    private array $notes = [];

    public function __construct(array $opts = [])
    {
        $clean = [];
        foreach ($opts as $k => $v) {
            if ($v !== null && array_key_exists($k, self::DEFAULTS)) {
                $clean[$k] = $v;
            }
        }
        $this->o = array_merge(self::DEFAULTS, $clean);
    }

    public function options(): array
    {
        return $this->o;
    }

    /** @return string[] anything worth telling the user about this build */
    public function notes(): array
    {
        return $this->notes;
    }

    /** Printable width between the margins - also the width to engrave at. */
    public function contentWidth(): float
    {
        return self::PAGE_W - 2 * (float) $this->o['margin'];
    }

    /**
     * Engrave the music sized for this page. Returns the StaffRenderer result.
     */
    public function engrave(
        Puzzle $puzzle,
        string $cacheDir,
        bool $solved = false,
        int $staffSize = 20,
        string $clef = StaffRenderer::DEFAULT_CLEF,
        string $spelling = 'sharps',
        string $rangeMode = StaffRenderer::DEFAULT_RANGE
    ): array {
        $ly = StaffRenderer::lilypond(
            $puzzle,
            $solved,
            $staffSize,
            $this->contentWidth(),
            $clef,
            $spelling,
            $rangeMode
        );
        return StaffRenderer::render($ly, $cacheDir, 'pdf');
    }

    /**
     * Build the composed page.
     *
     * @param string $musicPdf path to the LilyPond PDF for this puzzle
     * @return string raw PDF bytes
     */
    public function build(Puzzle $puzzle, string $musicPdf): string
    {
        if (!PdfSupport::load()) {
            throw new \RuntimeException(
                "TCPDF and FPDI are not installed.\n\n" . PdfSupport::installHint()
            );
        }
        require_once __DIR__ . '/PuzzlePdfDocument.php';

        if (!is_file($musicPdf) || filesize($musicPdf) === 0) {
            throw new \RuntimeException("The engraved music PDF is missing: $musicPdf");
        }

        $m    = (float) $this->o['margin'];
        $w    = $this->contentWidth();
        $font = (string) $this->o['font'];

        $pdf = new PuzzlePdfDocument('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->configure($this->o);
        $pdf->SetCreator('12-tone sudoku');
        $pdf->SetTitle((string) $this->o['title']);
        if ((string) $this->o['author'] !== '') {
            $pdf->SetAuthor((string) $this->o['author']);
        }
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins($m, $m, $m);
        $pdf->SetFooterMargin(16);
        $pdf->SetAutoPageBreak(false, $m);
        $pdf->AddPage();

        // ------------------------------------------------------------- title
        if ((string) $this->o['title'] !== '') {
            $pdf->SetFont($font, 'B', (int) $this->o['titleSize']);
            $pdf->SetTextColor(20, 20, 22);
            $pdf->MultiCell($w, 0, (string) $this->o['title'], 0, 'C', false, 1);
        }

        if ((string) $this->o['subtitle'] !== '') {
            $pdf->SetFont($font, '', (int) $this->o['subtitleSize']);
            $pdf->SetTextColor(100, 100, 106);
            $pdf->MultiCell($w, 0, (string) $this->o['subtitle'], 0, 'C', false, 1);
        }

        if (!empty($this->o['showRule'])) {
            $y = $pdf->GetY() + 2.5;
            $pdf->SetDrawColor(170, 170, 178);
            $pdf->SetLineWidth(0.3);
            $pdf->Line($m, $y, $m + $w, $y);
            $pdf->SetY($y + 4);
        }

        // ------------------------------------------------------------- intro
        if ((string) $this->o['intro'] !== '') {
            $pdf->SetFont($font, '', (int) $this->o['bodySize']);
            $pdf->SetTextColor(45, 45, 50);
            $pdf->writeHTMLCell($w, 0, $m, $pdf->GetY(), (string) $this->o['intro'], 0, 1, false, true, 'L');
            $pdf->SetY($pdf->GetY() + 1.5);
        }

        // ------------------------------------------------------------- music
        $top = $pdf->GetY() + 3;

        // Reserve room for the trailing notes before deciding how tall the
        // music may be, so a long note block shrinks the score instead of
        // colliding with it.
        $notesHeight = 0.0;
        $notesHtml   = (string) $this->o['notes'];
        if ($notesHtml !== '') {
            $plain = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $notesHtml)));
            $pdf->SetFont($font, '', (int) $this->o['bodySize']);
            $notesHeight = (float) $pdf->getStringHeight($w, $plain) + 4.0;
        }

        // The QR block is anchored to the bottom of the text area, so its
        // height comes out of the music's budget rather than colliding with it.
        $qrSize  = (float) $this->o['qrSize'];
        $showQr  = !empty($this->o['showQr']) && $qrSize > 0;
        $qrBlock = $showQr ? $qrSize + 6.0 : 0.0;

        $available = (self::PAGE_H - self::FOOTER_RESERVE) - $top - $notesHeight - $qrBlock;

        try {
            $pdf->setSourceFile($this->sourceFor($musicPdf));
            $tpl = $pdf->importPage(1);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "FPDI could not read the engraved PDF (" . $e->getMessage() . ").\n"
                . 'The file reports itself as PDF ' . (PdfSupport::pdfVersion($musicPdf) ?? '?')
                . "; the free FPDI reads 1.4 and below.\n"
                . (PdfSupport::ghostscript() === null
                    ? 'Ghostscript would convert it automatically but was not found - install it, or set GS_BIN.'
                    : 'Ghostscript is present but the conversion did not help.'),
                0,
                $e
            );
        }

        $size = $pdf->getTemplateSize($tpl);
        $mw   = $w;
        $mh   = (float) $size['height'] * ($mw / (float) $size['width']);

        if ($available > 0 && $mh > $available) {
            $mh = $available;
            $mw = (float) $size['width'] * ($mh / (float) $size['height']);
            $this->notes[] = sprintf(
                'The score was scaled to %.0f%% to fit the remaining page height. '
                . 'Reduce the staff size or trim the text to keep it at full size.',
                100 * $mw / $w
            );
        }

        $pdf->useTemplate($tpl, $m + ($w - $mw) / 2, $top, $mw, $mh);
        $pdf->SetY($top + $mh + 4);

        // ------------------------------------------------------------- notes
        if ($notesHtml !== '') {
            $pdf->SetFont($font, '', (int) $this->o['bodySize']);
            $pdf->SetTextColor(60, 60, 66);
            $pdf->writeHTMLCell($w, 0, $m, $pdf->GetY(), $notesHtml, 0, 1, false, true, 'L');
        }

        // ---------------------------------------------------------- qr code
        if ($showQr) {
            $qrTop = self::PAGE_H - self::FOOTER_RESERVE - $qrSize - 2.0;
            $qrX   = $m + $w - $qrSize;

            $caption = trim((string) $this->o['qrCaption']);
            $textW   = $w - $qrSize - 4.0;
            if ($textW > 20.0) {
                $pdf->SetFont($font, '', 8);
                $pdf->SetTextColor(110, 110, 115);
                $pdf->SetXY($m, $qrTop + ($qrSize / 2) - 4.5);
                $pdf->MultiCell(
                    $textW,
                    4,
                    ($caption !== '' ? $caption . "\n" : '') . PuzzleLink::display($puzzle),
                    0,
                    'R',
                    false,
                    1
                );
            }

            try {
                $pdf->write2DBarcode(
                    PuzzleLink::url($puzzle),
                    'QRCODE,M',
                    $qrX,
                    $qrTop,
                    $qrSize,
                    $qrSize,
                    [
                        'border'        => false,
                        'vpadding'      => 'auto',
                        'hpadding'      => 'auto',
                        'fgcolor'       => [0, 0, 0],
                        'bgcolor'       => false,
                        'module_width'  => 1,
                        'module_height' => 1,
                    ],
                    'N'
                );
            } catch (\Throwable $e) {
                // A missing barcode module must not cost you the whole worksheet.
                $this->notes[] = 'The QR code could not be drawn (' . $e->getMessage()
                    . '). The URL is still printed beside it.';
            }
        }

        return (string) $pdf->Output('sudoku.pdf', 'S');
    }

    /**
     * Path FPDI should actually read: the original when it is PDF 1.4 or older,
     * otherwise a Ghostscript-converted copy cached beside it.
     */
    private function sourceFor(string $file): string
    {
        $version = PdfSupport::pdfVersion($file);
        if ($version === null || version_compare($version, '1.4', '<=')) {
            return $file;
        }

        $alt = preg_replace('/\.pdf$/i', '', $file) . '.v14.pdf';
        if (is_file($alt) && filesize($alt) > 0) {
            return $alt;
        }

        if (PdfSupport::downgrade($file, $alt)) {
            $this->notes[] = "LilyPond emitted PDF $version, which the free FPDI cannot parse; "
                . 'Ghostscript rewrote it as 1.4. Output is still fully vector.';
            return $alt;
        }

        $this->notes[] = "LilyPond emitted PDF $version and Ghostscript was unavailable to convert it.";
        return $file;
    }
}
