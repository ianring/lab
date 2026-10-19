<?php

declare(strict_types=1);

namespace Puzzlebook2Print;

require_once __DIR__ . '/../libs/fpdf/fpdf.php';

/**
 * Renders a built book (build/book.json + build/chain.json) as a print-ready
 * PDF at mass-market-paperback trim: 4.25" x 6.87" (108 x 175 mm). Front
 * matter first -- title, how-it-works, the numbered rules -- then one PDF
 * page per book page of words.
 *
 * Only steps marked printed:true appear in the rules section, numbered in
 * READER order (the unprinted decoy step is engine bookkeeping; the reader
 * must never be told words are being held back).
 */
final class BookPdf extends \FPDF
{
    private const TRIM_W = 108.0;
    private const TRIM_H = 175.0;
    private const MARGIN = 9.0;

    /** Grid words never render larger than this, however short the page's words are. */
    private const GRID_MAX_PT = 8.5;

    private string $title;

    public function __construct(string $title)
    {
        parent::__construct('P', 'mm', [self::TRIM_W, self::TRIM_H]);
        $this->title = $title;
        $this->SetMargins(self::MARGIN, self::MARGIN);
        $this->SetAutoPageBreak(false);
        $this->SetTitle($title);
        $this->SetCreator('puzzlebook2 print/render.php');
        $this->AliasNbPages();
    }

    /** Bottom-centre folio on every page but the title. */
    public function Footer(): void
    {
        if ($this->PageNo() === 1) {
            return;
        }
        $this->SetY(-7.5);
        $this->SetFont('Helvetica', '', 7);
        $this->Cell(0, 4, (string) $this->PageNo(), 0, 0, 'C');
    }

    public function render(array $book, array $chain): void
    {
        $this->titlePage();
        $this->howItWorksPage();
        $this->rulesPages($chain);
        $this->wordPages($book);
    }

    // ----------------------------------------------------------- front matter

    private function titlePage(): void
    {
        $this->AddPage();
        $this->SetY(52);
        $this->SetFont('Helvetica', 'B', 26);
        $this->MultiCell(0, 11, strtoupper($this->title), 0, 'C');
        $this->Ln(4);
        $this->SetFont('Helvetica', 'I', 10);
        $this->MultiCell(0, 5, 'A self-solving word-elimination puzzle', 0, 'C');
        $this->SetY(-30);
        $this->SetFont('Helvetica', '', 8);
        $this->MultiCell(0, 4, 'One word in this book survives every rule.', 0, 'C');
    }

    private function howItWorksPage(): void
    {
        $this->AddPage();
        $this->SetFont('Helvetica', 'B', 13);
        $this->Cell(0, 8, 'How it works', 0, 2);
        $this->Ln(2);
        $this->SetFont('Helvetica', '', 9);
        $paragraphs = [
            'This book is one large puzzle. Every page is a grid of words, and the pages that follow '
            . 'this one give you the rules, in order.',
            'Work through the rules one at a time, applying each one to the whole book before moving '
            . 'to the next. When a rule matches a word, cross that word out. A crossed-out word is '
            . 'gone for good: later rules only ever look at the words still standing, and no rule '
            . 'will ever ask you to read a word you have already eliminated.',
            'Some rules are about spelling, and you can check each word on its own. Others are about '
            . 'position -- neighbours, columns, corners, pairs on a page -- so keep an eye on the '
            . 'grid, not just the words.',
            'Apply every rule faithfully and exactly one word in the entire book will be left '
            . 'standing at the end. That word is the answer.',
        ];
        foreach ($paragraphs as $p) {
            $this->MultiCell(0, 4.4, $p, 0, 'J');
            $this->Ln(2.2);
        }
    }

    private function rulesPages(array $chain): void
    {
        $this->AddPage();
        $this->SetFont('Helvetica', 'B', 13);
        $this->Cell(0, 8, 'The rules', 0, 2);
        $this->Ln(1);
        $this->SetFont('Helvetica', '', 8.5);
        $this->MultiCell(0, 4.2, 'Apply them in this order. Finish each rule across the whole book before starting the next.', 0, 'J');
        $this->Ln(2);

        $number = 0;
        foreach ($chain['steps'] as $step) {
            if (empty($step['printed'])) {
                continue;
            }
            $number++;
            $text = $step['rule_text'];

            // Measure before placing so a rule never splits across pages.
            $headH = 4.6;
            $bodyLines = $this->countWrappedLines($text, $this->contentWidth(), 8.5);
            $blockH = $headH + $bodyLines * 4.0 + 3.5;
            if ($this->GetY() + $blockH > self::TRIM_H - 12) {
                $this->AddPage();
            }

            $this->SetFont('Helvetica', 'B', 9);
            $this->Cell(0, $headH, "Rule {$number}", 0, 2);
            $this->SetFont('Helvetica', '', 8.5);
            $this->MultiCell(0, 4.0, $text, 0, 'J');
            $this->Ln(3.5);
        }
    }

    // ------------------------------------------------------------- word pages

    private function wordPages(array $book): void
    {
        $pages = (int) $book['grid']['pages'];
        $rows = (int) $book['grid']['rows'];
        $cols = (int) $book['grid']['cols'];
        $words = $book['words'];

        $contentW = $this->contentWidth();
        $colW = $contentW / $cols;
        $headerH = 6.5;
        $gridTop = self::MARGIN + $headerH;
        $rowH = (self::TRIM_H - $gridTop - 11) / $rows;

        for ($page = 0; $page < $pages; $page++) {
            $this->AddPage();

            $this->SetFont('Helvetica', 'B', 8);
            $this->SetY(self::MARGIN);
            $this->Cell(0, 4.5, sprintf('PAGE %d OF %d', $page + 1, $pages), 0, 0, 'C');

            // Per-page font size: as large as the page's longest word allows.
            $start = $page * $rows * $cols;
            $size = self::GRID_MAX_PT;
            $this->SetFont('Helvetica', '', $size);
            for ($i = 0; $i < $rows * $cols; $i++) {
                $w = $this->GetStringWidth(strtoupper($words[$start + $i]));
                if ($w > $colW * 0.94) {
                    $size = min($size, $size * ($colW * 0.94) / $w);
                }
            }
            $this->SetFont('Helvetica', '', max(5.5, $size));

            for ($row = 0; $row < $rows; $row++) {
                $this->SetXY(self::MARGIN, $gridTop + $row * $rowH);
                for ($col = 0; $col < $cols; $col++) {
                    $word = strtoupper($words[$start + $row * $cols + $col]);
                    $this->Cell($colW, $rowH, $word, 0, 0, 'C');
                }
            }
        }
    }

    // --------------------------------------------------------------- plumbing

    private function contentWidth(): float
    {
        return self::TRIM_W - 2 * self::MARGIN;
    }

    /** How many lines MultiCell will use for $text at $width -- for keep-together blocks. */
    private function countWrappedLines(string $text, float $width, float $fontPt): int
    {
        $this->SetFont('Helvetica', '', $fontPt);
        $lines = 0;
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (explode(' ', $paragraph) as $wordToken) {
                $candidate = $line === '' ? $wordToken : $line . ' ' . $wordToken;
                if ($this->GetStringWidth($candidate) > $width && $line !== '') {
                    $lines++;
                    $line = $wordToken;
                } else {
                    $line = $candidate;
                }
            }
            $lines++;
        }
        return $lines;
    }
}
