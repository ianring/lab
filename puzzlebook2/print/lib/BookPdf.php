<?php

declare(strict_types=1);

namespace Puzzlebook2Print;

require_once __DIR__ . '/../libs/fpdf/fpdf.php';

/**
 * Renders a built book (build/book.json + build/chain.json) as a print-ready
 * PDF at mass-market-paperback trim: 5.25" x 7" (133.35 x 177.8 mm). Front
 * matter first -- title, how-it-works, the numbered rules -- then one PDF
 * page per book page of words.
 *
 * Only steps marked printed:true appear in the rules section, numbered in
 * READER order (the unprinted decoy step is engine bookkeeping; the reader
 * must never be told words are being held back).
 */
final class BookPdf extends \FPDF
{
    /** Fraction of a column a word may occupy -- breathing room between columns. */
    private const COL_SLACK = 0.96;

    private float $trimW;
    private float $trimH;
    private float $margin;
    private float $gridPt;

    private string $title;
    private string $fontFamily = 'Helvetica';
    private string $gridFamily = 'Helvetica';
    private string $gridStyle = '';

    /**
     * Every design knob lives in config/layout.json and arrives here as
     * $print -- trim_width_mm, trim_height_mm, margin_mm, grid_font_pt
     * (rows/cols/pages live beside them in the same file's "grid" block).
     * The defaults below only cover a layout.json that predates the print
     * block. The renderer never shrinks text below grid_font_pt; a word
     * that will not fit its column is an error, because the fix is fewer
     * columns or re-running print/fit-wordlist.php, not smaller type.
     */
    public function __construct(string $title, array $print = [])
    {
        $this->trimW = (float) ($print['trim_width_mm'] ?? 133.35);
        $this->trimH = (float) ($print['trim_height_mm'] ?? 177.8);
        $this->margin = (float) ($print['margin_mm'] ?? 9.0);
        $this->gridPt = (float) ($print['grid_font_pt'] ?? 9.0);
        parent::__construct('P', 'mm', [$this->trimW, $this->trimH]);
        $this->title = $title;

        // Two faces, both layout.json knobs (print.text_font /
        // print.grid_font), both converted by print/libs/fpdf/makefont
        // into print/fonts/. text_font carries everything EXCEPT the word
        // grids; grid_font is the puzzle itself. A missing block falls
        // back to the core Helvetica/Courier metrics.
        $this->fontFamily = $this->loadFace($print['text_font'] ?? null, 'Helvetica');
        $grid = $print['grid_font'] ?? null;
        $this->gridFamily = $this->loadFace($grid, 'Courier');
        $this->gridStyle = $grid['style'] ?? '';
        $this->SetMargins($this->margin, $this->margin);
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
        $this->SetFont($this->fontFamily, '', 8);
        $this->Cell(0, 4, (string) $this->PageNo(), 0, 0, 'C');
    }

    public function render(array $book, array $chain): void
    {
        $this->titlePage();
        $this->howItWorksPage();
        $this->rulesPages($chain);
        $this->wordPages($book);
        $this->answerPage();
        $this->questPages();
        $this->appendixPages($book, $chain);
    }

    // ----------------------------------------------------------- front matter

    private function titlePage(): void
    {
        $this->AddPage();
        $this->SetY(52);
        $this->SetFont($this->fontFamily, 'B', 26);
        $this->MultiCell(0, 11, strtoupper($this->title), 0, 'C');
        $this->Ln(4);
        $this->SetFont($this->fontFamily, 'I', 10);
        $this->MultiCell(0, 5, 'A word elimination puzzle', 0, 'C');
        $this->SetY(-30);
        $this->SetFont($this->fontFamily, '', 8);
        $this->MultiCell(0, 4, 'One word in this book survives every rule.', 0, 'C');
    }

    private function howItWorksPage(): void
    {
        $this->AddPage();
        $this->SetFont($this->fontFamily, 'B', 13);
        $this->Cell(0, 8, 'How it works', 0, 2);
        $this->Ln(2);
        $this->SetFont($this->fontFamily, '', 10);
        $paragraphs = [
            'This book is one large puzzle. Every page is a grid of words, and the pages that follow '
            . 'this one give you the rules, in order.',
            'Work through the rules one at a time, applying each one to the whole book before moving '
            . 'to the next. When a rule matches a word, cross that word out. A crossed-out word is '
            . 'gone for good: later rules only ever look at the words still standing, and no rule '
            . 'will ever ask you to read a word you have already eliminated.',
            'A tip before you start: cross out words so you can still read them. A highlighter or '
            . 'one light pencil line works well. Do not blot words out with a marker, and do not '
            . 'scribble over them. You will never need to read a dead word to finish THIS book -- '
            . 'but a grand quest spans the whole series, and future books will ask you to look up '
            . 'words in this one, dead or alive. The last pages of this book explain.',
            'Some rules are about spelling, and you can check each word on its own. Others are about '
            . 'position -- neighbours, columns, corners, pairs on a page -- so keep an eye on the '
            . 'grid, not just the words.',
            'Apply every rule faithfully and exactly one word in the entire book will be left '
            . 'standing at the end. That word is the answer.',
        ];
        foreach ($paragraphs as $p) {
            $this->MultiCell(0, 4.9, $p, 0, 'J');
            $this->Ln(2.4);
        }
    }

    private function rulesPages(array $chain): void
    {
        $this->AddPage();
        $this->SetFont($this->fontFamily, 'B', 13);
        $this->Cell(0, 8, 'The rules', 0, 2);
        $this->Ln(1);
        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(0, 4.9, 'Apply them in this order. Finish each rule across the whole book before starting the next.', 0, 'J');
        $this->Ln(2);

        $number = 0;
        foreach ($chain['steps'] as $step) {
            if (empty($step['printed'])) {
                continue;
            }
            $number++;
            $text = $step['rule_text'];

            // Measure before placing so a rule never splits across pages.
            $headH = 5.2;
            $bodyLines = $this->countWrappedLines($text, $this->contentWidth(), 10);
            $blockH = $headH + $bodyLines * 4.7 + 3.8;
            if ($this->GetY() + $blockH > $this->trimH - 12) {
                $this->AddPage();
            }

            $this->SetFont($this->fontFamily, 'B', 10.5);
            $this->Cell(0, $headH, "Rule {$number}", 0, 2);
            $this->SetFont($this->fontFamily, '', 10);
            $this->MultiCell(0, 4.7, $text, 0, 'J');
            $this->Ln(3.8);
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
        $gridTop = $this->margin + $headerH;
        $rowH = ($this->trimH - $gridTop - 11) / $rows;

        for ($page = 0; $page < $pages; $page++) {
            $this->AddPage();

            $this->SetFont($this->fontFamily, 'B', 9);
            $this->SetY($this->margin);
            $this->Cell(0, 5, sprintf('PAGE %d OF %d', $page + 1, $pages), 0, 0, 'C');

            // Fixed size, uppercase. Verify every word fits its column
            // rather than ever shrinking below GRID_PT.
            $start = $page * $rows * $cols;
            $this->useGridFont();
            for ($i = 0; $i < $rows * $cols; $i++) {
                $word = strtoupper($words[$start + $i]);
                if ($this->GetStringWidth($word) > $colW * self::COL_SLACK) {
                    throw new \RuntimeException(
                        "'{$word}' does not fit a " . round($colW, 1) . "mm column at " . $this->gridPt
                        . 'pt. Reduce cols in config/layout.json and rebuild.'
                    );
                }
            }

            for ($row = 0; $row < $rows; $row++) {
                $this->SetXY($this->margin, $gridTop + $row * $rowH);
                for ($col = 0; $col < $cols; $col++) {
                    $this->Cell($colW, $rowH, strtoupper($words[$start + $row * $cols + $col]), 0, 0, 'C');
                }
            }
        }
    }

    // --------------------------------------------------------------- plumbing

    private function contentWidth(): float
    {
        return $this->trimW - 2 * $this->margin;
    }

    /**
     * The widest a word may typeset (in mm, at the grid font, uppercase)
     * and still fit one column of a $cols-column grid -- the single source
     * of truth print/fit-wordlist.php filters the dictionary against, so
     * the build can never place a word this renderer would have to reject.
     */
    public function columnLimitMm(int $cols): float
    {
        return ($this->trimW - 2 * $this->margin) / $cols * self::COL_SLACK;
    }

    public function gridFontPt(): float
    {
        return $this->gridPt;
    }

    /** How many lines MultiCell will use for $text at $width -- for keep-together blocks. */
    private function countWrappedLines(string $text, float $width, float $fontPt): int
    {
        $this->SetFont($this->fontFamily, '', $fontPt);
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

    // ----------------------------------------------------------- back matter

    /** A centred box and nothing else: the page where the answer is written. */
    private function answerPage(): void
    {
        $this->AddPage();
        $this->SetY(70);
        $this->SetFont($this->fontFamily, 'B', 14);
        $this->Cell(0, 8, 'THE ANSWER', 0, 2, 'C');
        $this->Ln(4);
        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(0, 4.9, 'When one word is left standing, write it here.', 0, 'C');
        $this->Ln(6);
        $this->drawWritingBox();
    }

    /**
     * The forthright quest pages (per P4: say plainly what this is and
     * what the player should do). NOTE: this text is Book One's; when
     * Book Two exists, per-book quest/mystery copy moves into config.
     */
    private function questPages(): void
    {
        $this->AddPage();
        $this->SetFont($this->fontFamily, 'B', 13);
        $this->Cell(0, 8, 'The quest', 0, 2);
        $this->Ln(2);
        $this->SetFont($this->fontFamily, '', 10);
        $paragraphs = [
            'Eliminoid is more than one book. Each book is a complete puzzle on its own, and you '
            . 'never need any other book to finish the one in your hands.',
            'But a grand quest runs through the whole series. Future books will ask you to look up '
            . 'words in this one -- including words you have crossed out. That is why your dead '
            . 'words must stay readable.',
            'At the end of the quest there is a real prize: the Eliminoid itself, a one-of-a-kind '
            . 'piece made by the author\'s own hands. The first player to complete the final '
            . 'challenge will win it.',
            'Nothing in the quest is hidden without a reveal. Every secret has an instruction that '
            . 'points to it, printed in some book. The whole quest can be completed with the books '
            . 'alone. If you are hunting for something no instruction has mentioned, you can stop: '
            . 'it is not there.',
        ];
        foreach ($paragraphs as $para) {
            $this->MultiCell(0, 4.9, $para, 0, 'J');
            $this->Ln(2.4);
        }

        $this->Ln(6);
        $this->SetFont($this->fontFamily, 'B', 14);
        $this->Cell(0, 8, 'THE MYSTERY WORD', 0, 2, 'C');
        $this->Ln(2);
        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(
            0,
            4.9,
            'This is Book One, so its Mystery Word cannot be found yet: a later book will teach '
            . 'you how to find it. When you learn it, return to this page and write it down.',
            0,
            'J',
        );
        $this->Ln(6);
        $this->drawWritingBox();
    }

    /** A hand-writing box, centred: generous for marker-wielding hands. */
    private function drawWritingBox(): void
    {
        $w = 80.0;
        $h = 16.0;
        $x = ($this->trimW - $w) / 2;
        $this->SetLineWidth(0.4);
        $this->Rect($x, $this->GetY(), $w, $h);
        $this->SetY($this->GetY() + $h);
    }

    // ------------------------------------------------------------- appendix

    /**
     * The counts appendix: for every printed rule, exactly how many words
     * it crosses out and the running total -- so a reader can confirm a
     * rule is truly finished. Optional help that makes the book easier,
     * which is why it lives at the very back behind a SPOILER ALERT page
     * on EACH side: neither reading onward nor flipping in from the back
     * cover lands on the numbers unwarned.
     */
    private function appendixPages(array $book, array $chain): void
    {
        $this->spoilerPage(
            'The pages after this one list, for every rule, exactly how many words it crosses out. '
            . 'That makes finishing each rule much easier to confirm -- and the hunt a little less wild. '
            . 'Turn the page only if you want that help.'
        );

        $this->AddPage();
        $this->SetFont($this->fontFamily, 'B', 13);
        $this->Cell(0, 8, 'Appendix: the counts', 0, 2);
        $this->Ln(1);
        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(0, 4.9, 'How many words each rule crosses out, and the running total once it is fully done.', 0, 'J');
        $this->Ln(3);

        $total = count($book['words']);
        $w = $this->contentWidth();
        $cw = [$w * 0.22, $w * 0.36, $w * 0.42];
        $rowH = 5.6;

        $this->SetFont($this->fontFamily, 'B', 9.5);
        $this->Cell($cw[0], $rowH, 'Rule', 0, 0);
        $this->Cell($cw[1], $rowH, 'Crosses out', 0, 0, 'R');
        $this->Cell($cw[2], $rowH, 'Total crossed out', 0, 1, 'R');
        $this->SetFont($this->fontFamily, '', 9.5);

        $number = 0;
        foreach ($chain['steps'] as $step) {
            if (empty($step['printed']) || !isset($step['removes'])) {
                continue;
            }
            $number++;
            if ($this->GetY() + $rowH > $this->trimH - 12) {
                $this->AddPage();
                $this->SetFont($this->fontFamily, '', 9.5);
            }
            $this->Cell($cw[0], $rowH, 'Rule ' . $number, 0, 0);
            $this->Cell($cw[1], $rowH, number_format($step['removes']), 0, 0, 'R');
            $this->Cell($cw[2], $rowH, number_format($total - $step['survivors_after']), 0, 1, 'R');
        }

        $this->Ln(3);
        $this->SetFont($this->fontFamily, 'I', 9.5);
        $this->MultiCell(0, 4.9, 'After the final rule, exactly one word remains: the answer.', 0, 'L');

        $this->spoilerPage(
            'The pages before this one list, for every rule, exactly how many words it crosses out. '
            . 'If you came in from the back cover, stop here -- turn back only if you want that help.'
        );
    }

    private function spoilerPage(string $text): void
    {
        $this->AddPage();
        $this->SetY(60);
        $this->SetFont($this->fontFamily, 'B', 20);
        $this->MultiCell(0, 9, 'SPOILER ALERT', 0, 'C');
        $this->Ln(6);
        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(0, 4.9, $text, 0, 'C');
    }

    /** The exact face/style/size the grid typesets in -- fit-wordlist measures with this, so filter and page can never disagree. */
    public function useGridFont(): void
    {
        $this->SetFont($this->gridFamily, $this->gridStyle, $this->gridPt);
    }

    /** Registers a configured face's styles with FPDF; returns the family name to use. */
    private function loadFace(?array $face, string $coreFallback): string
    {
        if ($face === null || !isset($face['family'])) {
            return $coreFallback;
        }
        if (!empty($face['core'])) {
            return $face['family'];
        }
        $dir = __DIR__ . '/../' . ($face['dir'] ?? 'fonts');
        $this->AddFont($face['family'], '', $face['regular'], $dir);
        if (isset($face['bold'])) {
            $this->AddFont($face['family'], 'B', $face['bold'], $dir);
        }
        if (isset($face['italic'])) {
            $this->AddFont($face['family'], 'I', $face['italic'], $dir);
        }
        return $face['family'];
    }
}
