<?php

declare(strict_types=1);

namespace Puzzlebook2Print;

require_once __DIR__ . '/../libs/fpdf/fpdf.php';
require_once __DIR__ . '/../../lib/Fingerprint.php';

use Puzzlebook2\Fingerprint;

/**
 * The REAL series renderer: one constructed book (build/series/bookNN/
 * book.json + chain.json) laid out with the full series design -- title with
 * its book number, the real rules, the real word grid, the two pages of
 * honour with a real answer fingerprint, the per-book Mystery procedure, the
 * spoiler-guarded real counts, and (for the Capstone) the address and claim
 * pages.
 *
 * It supersedes MockSeriesPdf (which used LOREM + placeholder rules) now that
 * real books exist. Books 1-20 print their full rule list (the spine, the
 * low-cardinality flavor tier, and the rotated middle -- about thirty-odd
 * rules, paginated as needed); the Capstone prints the borrowed-diagonal
 * manifest of twenty instead, because it cannot be solved without the others.
 *
 * Phase 1 note: the Mystery WORD is not chosen yet (that is the cross-book
 * plant work, Phase 3), so the Mystery box shows the procedure and a blank to
 * fill, and its fingerprint line says it is still to come. The ANSWER
 * fingerprint is real -- the answer is known from the build.
 */
final class SeriesBookPdf extends \FPDF
{
    private const COL_SLACK = 0.96;

    /**
     * Easter-egg lookup puzzle: a short message spelled out by letters pulled
     * from the grid via page:row:column:letter coordinates. Each book gets its
     * own message. Ian supplies the real per-book text here (key = book number);
     * until then every book uses the prototype placeholder below. The puzzle is
     * read-only -- it points at letters already on the grid, so it never affects
     * play or the build.
     *
     * @var array<int,string>
     */
    private const EASTER_EGG_MESSAGES = [
        1 => 'THE ELIMINOID IS A REAL OBJECT YOU CAN WIN',
    ];
    private const EASTER_EGG_PROTOTYPE = 'LOREM IPSUM DOLOR SINC';

    private float $trimW;
    private float $trimH;
    private float $margin;
    private float $gridPt;

    private int $bookNo;
    private int $total;
    private bool $isCapstone;
    private array $book;
    private array $chain;

    private string $fontFamily = 'Helvetica';
    private string $gridFamily = 'Courier';
    private string $gridStyle = '';

    private ?string $mysteryWord;
    private array $address;

    public function __construct(int $bookNo, int $total, array $book, array $chain, array $print = [], ?string $mysteryWord = null, array $address = [])
    {
        $this->address = $address;
        $this->trimW = (float) ($print['trim_width_mm'] ?? 139.7);
        $this->trimH = (float) ($print['trim_height_mm'] ?? 215.9);
        $this->margin = (float) ($print['margin_mm'] ?? 9.0);
        $this->gridPt = (float) ($print['grid_font_pt'] ?? 9.5);
        $this->bookNo = $bookNo;
        $this->total = $total;
        $this->isCapstone = ($bookNo === $total);
        $this->book = $book;
        $this->chain = $chain;
        $this->mysteryWord = $mysteryWord;

        parent::__construct('P', 'mm', [$this->trimW, $this->trimH]);
        $this->fontFamily = $this->loadFace($print['text_font'] ?? null, 'Helvetica');
        $grid = $print['grid_font'] ?? null;
        $this->gridFamily = $this->loadFace($grid, 'Courier');
        $this->gridStyle = $grid['style'] ?? '';

        $this->SetMargins($this->margin, $this->margin);
        $this->SetAutoPageBreak(false);
        $this->SetTitle('Eliminoid -- Book ' . $bookNo);
        $this->SetCreator('puzzlebook2 print/render-series.php');
        $this->AliasNbPages();
    }

    public function render(): void
    {
        $this->titlePage();
        $this->howItWorksPage();
        $this->rulesPages();
        $this->bodyPages();
        $this->answerPage();
        $this->questPage();
        $this->mysteryPage();
        $this->easterEggPage();
        if ($this->isCapstone) {
            $this->addressPage();
            $this->claimPage();
        }
        $this->appendixPages();
    }

    // ----------------------------------------------------------- front matter

    private function titlePage(): void
    {
        $this->AddPage();
        $this->SetY(48);
        $this->SetFont($this->fontFamily, 'B', 30);
        $this->MultiCell(0, 13, 'ELIMINOID', 0, 'C');
        $this->Ln(3);
        $this->SetFont($this->fontFamily, '', 12);
        $this->MultiCell(0, 6, $this->isCapstone ? 'THE CAPSTONE' : 'BOOK ' . $this->bookNo, 0, 'C');
        $this->SetFont($this->fontFamily, 'I', 10);
        $this->MultiCell(0, 5, 'Book ' . $this->bookNo . ' of ' . $this->total, 0, 'C');
        $this->Ln(8);
        $this->SetFont($this->fontFamily, 'I', 10.5);
        $this->MultiCell(0, 5, 'A word-elimination puzzle', 0, 'C');
        $this->SetY(-32);
        $this->SetFont($this->fontFamily, '', 8.5);
        $this->MultiCell(0, 4, $this->isCapstone
            ? 'The last book. It cannot be solved without the other twenty.'
            : 'One word in this book survives every rule.', 0, 'C');
    }

    private function howItWorksPage(): void
    {
        $this->AddPage();
        $this->heading('How it works');
        if ($this->isCapstone) {
            $this->bodyParas([
                'This is the Capstone, the twenty-first and final book. It is a single puzzle like '
                . 'the others, but it prints no rules of its own. Its rules are borrowed: one from '
                . 'each of the twenty books before it. You cannot solve it without them beside you.',
                'Work the borrowed rules in order, crossing out words, until one word is left '
                . 'standing. That word is the Capstone\'s answer.',
                'Cross out words so you can still read them -- a highlighter or one light pencil '
                . 'line. You will need to read crossed-out words here and in other books.',
                'When you have the answer, the back of this book shows how to find the Mystery '
                . 'Word, build the mailing address, and claim the prize.',
            ]);
        } else {
            $this->bodyParas([
                'This book is one large puzzle. Every page is a grid of words, and the pages that '
                . 'follow this one give you the rules, in order.',
                'Work through the rules one at a time, applying each to the whole book before moving '
                . 'to the next. When a rule matches a word, cross that word out. A crossed-out word '
                . 'is gone for good: later rules only look at the words still standing.',
                'A tip before you start: cross out words so you can still read them. A highlighter or '
                . 'one light pencil line works well. Do not blot words out. You never need to read a '
                . 'dead word to finish THIS book -- but a quest runs through the whole series, and '
                . 'other books will ask you to look up words in this one, dead or alive.',
                'Some rules are about spelling; others are about position -- neighbours, columns, '
                . 'corners, pairs on a page -- so keep an eye on the grid, not just the words.',
                'Apply every rule faithfully and exactly one word in the whole book is left standing. '
                . 'That word is the answer. The last pages explain the quest.',
            ]);
        }
    }

    private function rulesPages(): void
    {
        $this->AddPage();
        $this->heading($this->isCapstone ? 'The rules (borrowed)' : 'The rules');

        if ($this->isCapstone) {
            $this->SetFont($this->fontFamily, '', 10);
            $this->MultiCell(0, 4.9, 'Each rule below is taken from one earlier book. Open that book '
                . 'to the rule named and apply it here, in this order.', 0, 'J');
            $this->Ln(3);
            for ($n = 1; $n <= 20; $n++) {
                if ($this->GetY() + 6 > $this->trimH - 14) {
                    $this->AddPage();
                }
                $this->SetFont($this->fontFamily, 'B', 10);
                $this->Cell(22, 5.4, "Rule {$n}", 0, 0);
                $this->SetFont($this->fontFamily, '', 10);
                $this->Cell(0, 5.4, "apply Book {$n}'s Rule {$n}.", 0, 1);
            }
            return;
        }

        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(0, 4.9, 'Apply them in this order. Finish each rule across the whole book '
            . 'before starting the next.', 0, 'J');
        $this->Ln(2);

        // Standing rules (the BOOM reaction) apply the whole way through, not at
        // one position, so print them first -- before the numbered sequence.
        foreach ($this->chain['steps'] as $step) {
            if (empty($step['printed']) || ($step['rule']['type'] ?? '') !== 'BoomReactionRule') {
                continue;
            }
            $this->SetFont($this->fontFamily, 'B', 10.5);
            $this->Cell(0, 5.2, 'Keep this in mind the whole way through:', 0, 2);
            $this->writeRich($step['rule_text'] ?? $step['rule']['type'], 4.7, 10);
            $this->Ln(3.8);
        }

        $number = 0;
        foreach ($this->chain['steps'] as $step) {
            if (empty($step['printed']) || ($step['rule']['type'] ?? '') === 'BoomReactionRule') {
                continue;
            }
            $number++;
            $text = $step['rule_text'] ?? $step['rule']['type'];
            $grid = $step['boggle_grid'] ?? null;

            $bodyLines = $this->countWrappedLines($this->stripRichMarkup($text), $this->contentWidth(), 10);
            $gridH = $grid !== null ? 5.0 + count($grid) * self::BOGGLE_CELL + 2.5 : 0.0;
            $blockH = 5.2 + $gridH + $bodyLines * 4.7 + 3.8;
            if ($this->GetY() + $blockH > $this->trimH - 14) {
                $this->AddPage();
            }
            $this->SetFont($this->fontFamily, 'B', 10.5);
            $this->Cell(0, 5.2, "Rule {$number}", 0, 2);
            if ($grid !== null) {
                $this->SetFont($this->fontFamily, '', 10);
                $this->Cell(0, 5.0, 'Here is a letter board:', 0, 2);
                $this->drawBoggleGrid($grid);
            }
            $this->writeRich($text, 4.7, 10);
            $this->Ln(3.8);
        }
    }

    private const BOGGLE_CELL = 8.0;

    /** Draws the Boggle board as real square cells with borders, letters centred. */
    private function drawBoggleGrid(array $grid): void
    {
        $size = self::BOGGLE_CELL;
        $startX = $this->margin;
        $this->SetFont($this->gridFamily, '', 12);
        foreach ($grid as $row) {
            $y = $this->GetY();
            $x = $startX;
            foreach ($row as $cell) {
                $disp = strlen((string) $cell) > 1 ? ucfirst((string) $cell) : strtoupper((string) $cell);
                $this->SetXY($x, $y);
                $this->Cell($size, $size, $disp, 1, 0, 'C');
                $x += $size;
            }
            $this->SetXY($startX, $y + $size);
        }
        $this->Ln(2.5);
    }

    /**
     * Writes a rule body that may carry inline markup: `...` renders in the grid
     * (Courier) font -- the same face the puzzle words are set in -- and
     * <u>...</u> underlines. Flowed with Write() so the typeface and underline
     * can change mid-paragraph. Left-aligned (Write() cannot justify).
     */
    private function writeRich(string $text, float $h, float $pt): void
    {
        $mono = false;
        $underline = false;
        $buffer = '';
        $flush = function () use (&$buffer, &$mono, &$underline, $h, $pt): void {
            if ($buffer === '') {
                return;
            }
            $this->SetFont($mono ? $this->gridFamily : $this->fontFamily, $underline ? 'U' : '', $pt);
            $this->Write($h, $buffer);
            $buffer = '';
        };
        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            if ($text[$i] === '`') {
                $flush();
                $mono = !$mono;
            } elseif (substr($text, $i, 3) === '<u>') {
                $flush();
                $underline = true;
                $i += 2;
            } elseif (substr($text, $i, 4) === '</u>') {
                $flush();
                $underline = false;
                $i += 3;
            } else {
                $buffer .= $text[$i];
            }
        }
        $flush();
        $this->Ln($h);
    }

    private function stripRichMarkup(string $text): string
    {
        return str_replace(['`', '<u>', '</u>'], '', $text);
    }

    // ------------------------------------------------------------- real body

    private function bodyPages(): void
    {
        $pages = (int) $this->book['grid']['pages'];
        $rows = (int) $this->book['grid']['rows'];
        $cols = (int) $this->book['grid']['cols'];
        $words = $this->book['words'];

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

            $start = $page * $rows * $cols;
            $this->SetFont($this->gridFamily, $this->gridStyle, $this->gridPt);
            for ($i = 0; $i < $rows * $cols; $i++) {
                $word = strtoupper($words[$start + $i]);
                if ($this->GetStringWidth($word) > $colW * self::COL_SLACK) {
                    throw new \RuntimeException("'{$word}' does not fit a " . round($colW, 1)
                        . "mm column at {$this->gridPt}pt -- reduce cols or re-run fit-wordlist.");
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

    // ----------------------------------------------------------- back matter

    private function answerPage(): void
    {
        $this->AddPage();
        $this->SetY(55);
        $this->SetFont($this->fontFamily, 'B', 15);
        $this->Cell(0, 8, 'THE ANSWER', 0, 2, 'C');
        $this->Ln(4);
        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(0, 4.9, 'When one word is left standing, write it here.', 0, 'C');
        $this->Ln(6);
        $this->drawWritingBox();
        $this->Ln(2);
        // Real fingerprint of the real answer (one-way; does not reveal it).
        $this->hashNote('your answer', Fingerprint::grouped(Fingerprint::of($this->chain['answer'])));
    }

    private function questPage(): void
    {
        $this->AddPage();
        $this->heading('The quest');
        $this->bodyParas([
            'Eliminoid is more than one book. Each book is a complete puzzle on its own, and you '
            . 'never need any other book to finish the one in your hands.',
            'But a quest runs through the whole series, book by book, to a single ending. At that '
            . 'ending there is a real prize: the Eliminoid itself, a one-of-a-kind ceramic piece '
            . 'made by the author\'s own hands. The first player to finish and claim it wins.',
            'Nothing in the quest is hidden without a reveal. Every secret has a printed '
            . 'instruction, in some book, that points to it, and the whole quest can be done with '
            . 'the books alone. If you are hunting for something no instruction has mentioned, you '
            . 'can stop: it is not there.',
            'To complete the quest, you will need the Mystery Word.',
        ]);
    }

    private function mysteryPage(): void
    {
        $this->AddPage();
        $this->SetFont($this->fontFamily, 'B', 15);
        $this->Cell(0, 8, 'THE MYSTERY WORD', 0, 2, 'C');
        $this->Ln(3);
        $this->SetFont($this->fontFamily, '', 10);

        if ($this->bookNo === 1) {
            $this->MultiCell(0, 4.9, 'Book 1 is the root of the series: its Mystery Word comes from its own '
                . 'answer. Turn each letter into a number (A=1, B=2, C=3, ...) and add them for the page; the '
                . 'number of letters is the row; the number of vowels (A, E, I, O, U) is the column. Write '
                . 'them here:', 0, 'L');
            $this->Ln(3);
            $this->drawCoordWorksheet(['The Answer to Book 1']);
        } else {
            $prev = $this->bookNo - 1;
            if ($this->isCapstone) {
                $this->MultiCell(0, 4.9, 'The Capstone\'s Mystery Word is found like every book\'s. Because it '
                    . 'needs this book\'s answer AND Book ' . $prev . '\'s Mystery Word, holding it proves you '
                    . 'finished all twenty-one books -- it is the word you mail to claim the prize.', 0, 'L');
                $this->Ln(3);
            }
            $this->MultiCell(0, 4.9, 'Find each of these in this book and write where it sits:', 0, 'L');
            $this->Ln(2);
            $this->drawCoordWorksheet([
                'The Answer to Book ' . $this->bookNo,
                'The Mystery Word from Book ' . $prev,
            ]);
            $this->Ln(2.5);
            $this->SetFont($this->fontFamily, 'B', 10.5);
            $this->Cell(0, 5, 'Add them together', 0, 1, 'C');
            $this->SetFont($this->fontFamily, 'I', 8.5);
            $this->MultiCell(0, 4, 'Add the two pages, the two rows, and the two columns. If a page total passes '
                . '100, subtract 100; if a row passes 25, subtract 25; if a column passes 5, subtract 5.', 0, 'C');
            $this->SetFont($this->fontFamily, '', 10);
            $this->Ln(2.5);
            $this->drawCoordWorksheet(['The result']);
        }

        $this->Ln(3);
        $this->MultiCell(0, 4.9, 'Find the word in this book at that page, row, and column, and write it here:', 0, 'C');
        $this->Ln(3);
        $this->drawWritingBox();
        $this->Ln(2);
        $fp = $this->mysteryWord !== null && $this->mysteryWord !== ''
            ? Fingerprint::grouped(Fingerprint::of($this->mysteryWord))
            : null;
        $this->hashNote('your Mystery Word', $fp);
    }

    /**
     * A fill-in worksheet: a Page / Row / Column header, then one row of empty
     * boxes per label for the player to write the coordinates in.
     *
     * @param string[] $rowLabels
     */
    private function drawCoordWorksheet(array $rowLabels): void
    {
        $labelW = 58.0;
        $cellW = 16.0;
        $cellH = 9.0;
        $cols = ['Page', 'Row', 'Column'];
        $totalW = $labelW + count($cols) * $cellW;
        $startX = ($this->trimW - $totalW) / 2;

        $this->SetFont($this->fontFamily, 'B', 9);
        $y = $this->GetY();
        $x = $startX + $labelW;
        foreach ($cols as $c) {
            $this->SetXY($x, $y);
            $this->Cell($cellW, 5, $c, 0, 0, 'C');
            $x += $cellW;
        }
        $this->SetY($y + 5.5);

        $this->SetLineWidth(0.3);
        foreach ($rowLabels as $label) {
            $ry = $this->GetY();
            $this->SetFont($this->fontFamily, '', 9);
            $this->SetXY($startX, $ry);
            $this->Cell($labelW - 2.5, $cellH, $label, 0, 0, 'R');
            $x = $startX + $labelW;
            foreach ($cols as $c) {
                $this->Rect($x + 1.5, $ry + 0.8, $cellW - 3, $cellH - 1.6);
                $x += $cellW;
            }
            $this->SetY($ry + $cellH + 1.5);
        }
    }

    // ------------------------------------------------- easter-egg lookup puzzle

    private function easterEggMessage(): string
    {
        return self::EASTER_EGG_MESSAGES[$this->bookNo] ?? self::EASTER_EGG_PROTOTYPE;
    }

    /**
     * The bonus page: a box per letter of the hidden message, each with a
     * page:row:column coordinate printed beneath it. The reader finds that word
     * in the grid and writes its FIRST letter in the box; read in order, the
     * boxes spell the message.
     */
    private function easterEggPage(): void
    {
        $lookups = $this->easterEggLookups($this->easterEggMessage());

        $this->AddPage();
        $this->SetFont($this->fontFamily, 'B', 15);
        $this->Cell(0, 8, 'A HIDDEN MESSAGE', 0, 2, 'C');
        $this->Ln(3);

        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(0, 4.9, 'Just for fun, and no puzzling needed. A short message is hidden in this '
            . 'book, one letter at a time. Each box below points to a word somewhere in the grid.', 0, 'L');
        $this->Ln(2);
        $this->writeRich('The coordinate under a box reads `page:row:column`. For example `15:21:3` is the '
            . 'word at row 21, column 3 on page 15 -- take its first letter.', 4.9, 10);
        $this->Ln(1);
        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(0, 4.9, 'Write each first letter in its box. Read the boxes in order, left to '
            . 'right, to reveal the message.', 0, 'L');
        $this->Ln(5);

        $this->drawEggBoxes($lookups);
    }

    /**
     * For each letter of $message, a real cell in this book's grid whose word
     * STARTS with it: [char, page, row, col], all 1-based. A space becomes a
     * word break (char === ' '). Deterministic per book and scattered across the
     * grid, so a book always yields the same lookup and the letters are not all
     * clustered on one page.
     *
     * @return list<array{char:string,page:int,row:int,col:int}>
     */
    private function easterEggLookups(string $message): array
    {
        $rows = (int) $this->book['grid']['rows'];
        $cols = (int) $this->book['grid']['cols'];
        $perPage = $rows * $cols;
        $words = $this->book['words'];

        // Index each cell by the first letter of its word.
        $byFirst = [];
        foreach ($words as $idx => $w) {
            $ch = strtoupper((string) $w)[0] ?? '';
            if ($ch >= 'A' && $ch <= 'Z') {
                $byFirst[$ch][] = $idx;
            }
        }

        $out = [];
        $letterNo = 0;
        foreach (str_split(strtoupper($message)) as $ch) {
            if ($ch < 'A' || $ch > 'Z') {
                $out[] = ['char' => ' ', 'page' => 0, 'row' => 0, 'col' => 0];
                continue;
            }
            $cands = $byFirst[$ch] ?? [];
            if ($cands === []) {
                // Letter absent from the grid (vanishingly unlikely) -- blank box.
                $out[] = ['char' => $ch, 'page' => 0, 'row' => 0, 'col' => 0];
                $letterNo++;
                continue;
            }
            $idx = $cands[$this->eggScatter($this->bookNo, $letterNo, count($cands))];
            $rem = $idx % $perPage;
            $out[] = [
                'char' => $ch,
                'page' => intdiv($idx, $perPage) + 1,
                'row' => intdiv($rem, $cols) + 1,
                'col' => $rem % $cols + 1,
            ];
            $letterNo++;
        }
        return $out;
    }

    /** Stable index in [0, n) from (book, letter), so coordinates scatter but never move between renders. */
    private function eggScatter(int $book, int $letterNo, int $n): int
    {
        return (int) (sprintf('%u', crc32("egg:{$book}:{$letterNo}")) % $n);
    }

    /**
     * Lay out the lookup boxes, keeping each word's boxes together and wrapping
     * whole words to the next row (a word too wide for a row wraps mid-word).
     * Each box is a small square to write the letter in, with its coordinate
     * printed in a caption beneath it.
     *
     * @param list<array{char:string,page:int,row:int,col:int}> $lookups
     */
    private function drawEggBoxes(array $lookups): void
    {
        $boxW = 11.0;
        $boxH = 11.0;
        $capH = 4.0;       // caption (coordinate) strip beneath each box
        $gap = 1.3;        // between boxes within a word
        $wordGap = 5.0;    // between words on the same row
        $rowGap = 5.0;     // between rows of boxes

        $left = $this->margin;
        $maxX = $this->trimW - $this->margin;
        $bottom = $this->trimH - $this->margin;
        $x = $left;
        $y = $this->GetY();
        $step = $boxH + $capH + $rowGap;

        // Group into words (drop the space markers).
        $wordsOfBoxes = [];
        $cur = [];
        foreach ($lookups as $lk) {
            if ($lk['char'] === ' ') {
                if ($cur !== []) {
                    $wordsOfBoxes[] = $cur;
                    $cur = [];
                }
                continue;
            }
            $cur[] = $lk;
        }
        if ($cur !== []) {
            $wordsOfBoxes[] = $cur;
        }

        foreach ($wordsOfBoxes as $word) {
            $wordWidth = count($word) * $boxW + (count($word) - 1) * $gap;
            if ($x > $left && $x + $wordWidth > $maxX) {   // whole word won't fit -- new row
                $x = $left;
                $y += $step;
            }
            foreach ($word as $lk) {
                if ($x > $left && $x + $boxW > $maxX) {    // word wider than a row -- wrap mid-word
                    $x = $left;
                    $y += $step;
                }
                if ($y + $boxH + $capH > $bottom) {         // guard long future messages
                    $this->AddPage();
                    $y = $this->margin;
                    $x = $left;
                }
                $this->drawEggBox($x, $y, $boxW, $boxH, $lk);
                $x += $boxW + $gap;
            }
            $x += $wordGap - $gap;   // swap the trailing intra-word gap for a word gap
        }
        $this->SetY($y + $step);
    }

    /** One lookup box: a blank square for the letter, its page:row:column caption beneath. */
    private function drawEggBox(float $x, float $y, float $w, float $h, array $lk): void
    {
        $this->SetLineWidth(0.3);
        $this->Rect($x, $y, $w, $h);

        if ($lk['page'] === 0) {
            return;   // letter absent from the grid (vanishingly unlikely) -- no caption
        }
        $coord = sprintf('%d:%d:%d', $lk['page'], $lk['row'], $lk['col']);
        $pt = 6.5;
        $this->SetFont($this->gridFamily, '', $pt);
        while ($pt > 4.5 && $this->GetStringWidth($coord) > $w) {
            $pt -= 0.5;
            $this->SetFont($this->gridFamily, '', $pt);
        }
        $this->SetXY($x, $y + $h + 0.3);
        $this->Cell($w, 3.2, $coord, 0, 0, 'C');
    }

    private function addressPage(): void
    {
        $this->AddPage();
        $this->heading('Building the address');
        $a = $this->address;
        $startCol = (int) ($a['acrostic_start_col'] ?? 2) + 1;   // 1-indexed for the reader
        $this->bodyParas([
            'To claim the Eliminoid you mail one letter. No line of the address is printed here in '
            . 'plain words -- each is read from a book as a wrapping diagonal. Turn to the page named, '
            . 'start at ROW 1, COLUMN ' . $startCol . '. Read one word per row, moving one column to '
            . 'the RIGHT each row; after column 5, wrap back to column 1. Take the first letter of '
            . 'each word -- those letters, in order, spell the line.',
        ]);

        $diag = fn (int $bk, int $pg): string =>
            "Book {$bk}, page {$pg} -- diagonal from row 1, column {$startCol}.";
        $lines = [];
        if (isset($a['acrostic_page'])) {
            $lines[] = ['ADDRESSEE', $diag(1, (int) $a['acrostic_page'] + 1)];
        }
        foreach (['town' => 'TOWN', 'region' => 'REGION', 'country' => 'COUNTRY'] as $key => $title) {
            if (isset($a['places'][$key])) {
                $p = $a['places'][$key];
                $lines[] = [$title, $diag((int) $p['book'], (int) $p['page'] + 1)];
            }
        }
        foreach ($lines as [$title, $text]) {
            $this->SetFont($this->fontFamily, 'B', 9.5);
            $this->Cell(30, 5, $title, 0, 0);
            $this->SetFont($this->fontFamily, '', 9.5);
            $this->MultiCell(0, 5, $text, 0, 'L');
        }

        $this->Ln(3);
        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(0, 4.9,
            'The PO BOX NUMBER is the one line no one reaches by owning the books alone: take your '
            . 'Mystery Word for this book, find it in Book 1, and the page it sits on is the box '
            . 'number. A post box cannot be delivered to without it.', 0, 'J');
        $this->Ln(1);
        $this->SetFont($this->fontFamily, 'I', 8.5);
        $this->MultiCell(0, 4.3, '(Placeholder address for this draft; the postal code is still to come.)', 0, 'J');
    }

    private function claimPage(): void
    {
        $this->AddPage();
        $this->heading('Claiming the prize');
        $this->bodyParas([
            'Put your Mystery Word for this book in the letter -- that one word is your proof you '
            . 'finished the whole series. Address the envelope with the lines you just built and '
            . 'mail it.',
            'A correct letter is not proof on its own, so every sender is checked before anyone '
            . 'wins. You will be asked for words at spots chosen on the spot -- the word at a given '
            . 'page, row, and column of a given book. Only someone holding the books can answer.',
            'The first sender who passes, by postmark, wins. The Eliminoid is then mailed to them, '
            . 'insured, packed by the person who made it.',
            'Or write to wherever eliminoid.com says to write, in case the post box has changed.',
        ]);
        $this->Ln(4);
        $this->SetFont($this->fontFamily, 'B', 13);
        $this->Cell(0, 8, 'THE MAILING ADDRESS', 0, 2, 'C');
        $this->Ln(2);
        $this->drawWritingBox(26);
    }

    // --------------------------------------------------------- real appendix

    private function appendixPages(): void
    {
        $this->spoilerPage(
            'The pages after this one list, for every rule, exactly how many words it crosses out. '
            . 'That makes finishing each rule easier to confirm -- and the hunt a little less wild. '
            . 'Turn the page only if you want that help.'
        );

        $this->AddPage();
        $this->heading('Appendix: the counts');
        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(0, 4.9, 'How many words each rule crosses out, and the running total once '
            . 'it is fully done. Use it to check that a rule is truly finished.', 0, 'J');
        $this->Ln(3);

        $total = count($this->book['words']);
        $w = $this->contentWidth();
        $cw = [$w * 0.22, $w * 0.36, $w * 0.42];
        $rowH = 5.6;

        $this->SetFont($this->fontFamily, 'B', 9.5);
        $this->Cell($cw[0], $rowH, 'Rule', 0, 0);
        $this->Cell($cw[1], $rowH, 'Crosses out', 0, 0, 'R');
        $this->Cell($cw[2], $rowH, 'Total crossed out', 0, 1, 'R');
        $this->SetFont($this->fontFamily, '', 9.5);

        $number = 0;
        foreach ($this->chain['steps'] as $step) {
            if (empty($step['printed']) || !isset($step['removes'])) {
                continue;
            }
            $number++;
            if ($this->GetY() + $rowH > $this->trimH - 14) {
                $this->AddPage();
                $this->SetFont($this->fontFamily, '', 9.5);
            }
            $this->Cell($cw[0], $rowH, 'Rule ' . $number, 0, 0);
            $this->Cell($cw[1], $rowH, number_format((int) $step['removes']), 0, 0, 'R');
            $this->Cell($cw[2], $rowH, number_format($total - (int) $step['survivors_after']), 0, 1, 'R');
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
        $this->SetFont($this->fontFamily, 'B', 22);
        $this->MultiCell(0, 10, 'SPOILER ALERT', 0, 'C');
        $this->Ln(6);
        $this->SetFont($this->fontFamily, '', 10);
        $this->MultiCell(0, 4.9, $text, 0, 'C');
    }

    // ----------------------------------------------------------- copy helpers

    private function mysterySteps(): array
    {
        if ($this->bookNo === 1) {
            return [
                'This is Book 1, the root of the whole series. Its Mystery Word comes from its own '
                . 'answer, read right here in this book.',
                '#1. Find your answer. Turn each letter into a number -- A=1, B=2, C=3, and so on '
                . '-- and add them up. That total is a PAGE of this book.',
                '#2. The number of letters in your answer is the ROW. The number of vowels (A, E, '
                . 'I, O, U) is the COLUMN.',
                '#3. The word at that page, row, and column is your Mystery Word.',
                'Write it below. Every later book\'s Mystery grows out '
                . 'of this one.',
            ];
        }
        $prev = $this->bookNo - 1;
        $intro = $this->isCapstone
            ? 'The Capstone\'s Mystery Word is found by the same rule as every book. Because it '
              . 'needs this book\'s answer AND Book ' . $prev . '\'s Mystery Word, holding it proves '
              . 'you finished all twenty-one books. It is the word you mail to claim the prize.'
            : 'From Book 2 on, every book finds its Mystery Word the same way. You need two things: '
              . 'your answer for THIS book, and the Mystery Word from Book ' . $prev . '.';
        $close = $this->isCapstone
            ? 'Write it below. It is also where your PO box number '
              . 'comes from -- see "Building the address".'
            : 'Write it below.';
        return [
            $intro,
            '#1. Find your answer in this book -- note its page, row, and column.',
            '#2. Find Book ' . $prev . '\'s Mystery Word in this book -- note its page, row, and column.',
            '#3. Add them: the two page numbers (if over 100, subtract 100); the two row numbers (if '
            . 'over 25, subtract 25); the two column numbers (if over 5, subtract 5).',
            '#4. The word at that page, row, and column in THIS book is your Mystery Word.',
            $close,
        ];
    }

    private function hashNote(string $what, ?string $fingerprint): void
    {
        $this->SetFont($this->fontFamily, '', 9);
        $this->MultiCell(0, 4.4, 'To check ' . $what . ', type it at eliminoid.com', 0, 'C');
        $this->Ln(1.5);
        $this->SetFont($this->gridFamily, $this->gridStyle, 8.5);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 4, $fingerprint !== null
            ? 'fingerprint:  ' . $fingerprint
            : 'fingerprint:  (added once the series Mystery chain is built)', 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    // --------------------------------------------------------------- plumbing

    public function Footer(): void
    {
        if ($this->PageNo() === 1) {
            return;
        }
        $this->SetY(-7.5);
        $this->SetFont($this->fontFamily, '', 8);
        $this->Cell(0, 4, (string) $this->PageNo(), 0, 0, 'C');
    }

    private function heading(string $text): void
    {
        $this->SetFont($this->fontFamily, 'B', 14);
        $this->Cell(0, 8, $text, 0, 2);
        $this->Ln(2);
    }

    private function bodyParas(array $paras): void
    {
        $this->SetFont($this->fontFamily, '', 10);
        foreach ($paras as $p) {
            $this->MultiCell(0, 4.9, $p, 0, 'J');
            $this->Ln(2.4);
        }
    }

    private function drawWritingBox(float $h = 16.0): void
    {
        $w = 80.0;
        $x = ($this->trimW - $w) / 2;
        $this->SetLineWidth(0.4);
        $this->Rect($x, $this->GetY(), $w, $h);
        $this->SetY($this->GetY() + $h);
    }

    private function contentWidth(): float
    {
        return $this->trimW - 2 * $this->margin;
    }

    private function countWrappedLines(string $text, float $width, float $fontPt): int
    {
        $this->SetFont($this->fontFamily, '', $fontPt);
        $lines = 0;
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (explode(' ', $paragraph) as $token) {
                $candidate = $line === '' ? $token : $line . ' ' . $token;
                if ($this->GetStringWidth($candidate) > $width && $line !== '') {
                    $lines++;
                    $line = $token;
                } else {
                    $line = $candidate;
                }
            }
            $lines++;
        }
        return $lines;
    }

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
