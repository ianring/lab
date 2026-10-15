<?php
declare(strict_types=1);

/**
 * TCPDF document with an FPDI import layer and our own footer.
 *
 * This file extends a class that lives in vendor/, so it must only be required
 * AFTER PdfSupport::load() has succeeded - PHP resolves the parent class at
 * declaration time, and requiring this too early is a fatal error.
 */
final class PuzzlePdfDocument extends \setasign\Fpdi\Tcpdf\Fpdi
{
    /** @var array<string,mixed> */
    private array $layout = [];

    public function configure(array $layout): void
    {
        $this->layout = $layout;
    }

    public function Footer(): void // phpcs:ignore
    {
        $showNumber = !empty($this->layout['showPageNumber']);
        $text       = (string) ($this->layout['footer'] ?? '');
        if (!$showNumber && $text === '') {
            return;
        }

        $margins = $this->getMargins();
        $width   = $this->getPageWidth() - $margins['left'] - $margins['right'];

        $this->SetY(-16);
        $this->SetFont((string) ($this->layout['font'] ?? 'helvetica'), '', 8);
        $this->SetTextColor(110, 110, 115);

        $this->Cell($width / 2, 6, $text, 0, 0, 'L');
        $this->Cell(
            $width / 2,
            6,
            $showNumber ? (string) ((int) ($this->layout['pageNumber'] ?? 1)) : '',
            0,
            0,
            'R'
        );
    }
}
