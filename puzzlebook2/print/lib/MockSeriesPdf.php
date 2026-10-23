<?php

declare(strict_types=1);

namespace Puzzlebook2Print;

require_once __DIR__ . '/../libs/fpdf/fpdf.php';
require_once __DIR__ . '/../../lib/Fingerprint.php';

use Puzzlebook2\Fingerprint;

/**
 * SCAFFOLDING renderer for the whole 21-book Eliminoid series.
 *
 * This is NOT the real book renderer (that is BookPdf, which typesets a
 * constructed book.json). This produces the empty SHELL of each book so the
 * design -- front matter, the two pages of honour, the spoiler-guarded counts,
 * the per-book Mystery procedure, the Capstone's address/claim pages -- can be
 * seen and iterated before any word grid or rule exists. The body is a few
 * pages of LOREM; the rules and counts are illustrative placeholders.
 *
 * Reflects the locked design decisions (see SERIES-MAP.md / VENTURE.md D10,D11):
 *   - Mystery found by FIND, no arithmetic in the derivation.
 *   - Verification is a salted hash checked on eliminoid.com (not a letter-sum).
 *   - Every book: one ANSWER, one MYSTERY. Capstone (Book 21) adds the address.
 */
final class MockSeriesPdf extends \FPDF
{
    private const MOCK_BODY_PAGES = 3;   // real book has 100
    private const REAL_PAGES = 100;

    private float $trimW;
    private float $trimH;
    private float $margin;
    private float $gridPt;

    private int $bookNo;
    private int $total;
    private bool $isCapstone;

    private string $fontFamily = 'Helvetica';
    private string $gridFamily = 'Courier';
    private string $gridStyle = '';

    public function __construct(int $bookNo, int $total, array $print = [])
    {
        $this->trimW = (float) ($print['trim_width_mm'] ?? 139.7);
        $this->trimH = (float) ($print['trim_height_mm'] ?? 215.9);
        $this->margin = (float) ($print['margin_mm'] ?? 9.0);
        $this->gridPt = (float) ($print['grid_font_pt'] ?? 9.5);
        $this->bookNo = $bookNo;
        $this->total = $total;
        $this->isCapstone = ($bookNo === $total);

        parent::__construct('P', 'mm', [$this->trimW, $this->trimH]);
        $this->fontFamily = $this->loadFace($print['text_font'] ?? null, 'Helvetica');
        $grid = $print['grid_font'] ?? null;
        $this->gridFamily = $this->loadFace($grid, 'Courier');
        $this->gridStyle = $grid['style'] ?? '';

        $this->SetMargins($this->margin, $this->margin);
        $this->SetAutoPageBreak(false);
        $this->SetTitle('Eliminoid -- Book ' . $bookNo);
        $this->SetCreator('puzzlebook2 print/render-series.php (scaffold)');
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
        $label = $this->isCapstone
            ? 'THE CAPSTONE'
            : 'BOOK ' . $this->bookNo;
        $this->MultiCell(0, 6, $label, 0, 'C');
        $this->SetFont($this->fontFamily, 'I', 10);
        $this->MultiCell(0, 5, 'Book ' . $this->bookNo . ' of ' . $this->total, 0, 'C');

        $this->Ln(8);
        $this->SetFont($this->fontFamily, 'I', 10.5);
        $this->MultiCell(0, 5, 'A word-elimination puzzle', 0, 'C');

        $this->SetY(-32);
        $this->SetFont($this->fontFamily, '', 8.5);
        $tag = $this->isCapstone
            ? 'The last book. It cannot be solved without the other twenty.'
            : 'One word in this book survives every rule.';
        $this->MultiCell(0, 4, $tag, 0, 'C');
    }

    private function howItWorksPage(): void
    {
        $this->AddPage();
        $this->heading('How it works');

        if ($this->isCapstone) {
            $paras = [
                'This is the Capstone, the twenty-first and final book. It is a single puzzle like '
                . 'the others, but it prints no rules of its own. Its rules are borrowed: one from '
                . 'each of the twenty books before it. You cannot solve it without them on the shelf '
                . 'beside you.',
                'Work the borrowed rules in order, crossing out words, until one word is left '
                . 'standing. That word is the Capstone\'s answer.',
                'Cross out words so you can still read them -- a highlighter or one light pencil '
                . 'line. You will need to read crossed-out words here and in other books.',
                'When you have the answer, the back of this book shows you how to find the Mystery '
                . 'Word, build the mailing address, and claim the prize.',
            ];
        } else {
            $paras = [
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
            ];
        }
        $this->bodyParas($paras);
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
        $this->Ln(1);
        $this->SetFont($this->fontFamily, 'I', 8.5);
        $this->MultiCell(0, 4.3, '[Placeholder rules -- real rule chains are built later, one per book.]', 0, 'J');
        $this->Ln(3);

        $number = 0;
        foreach ($this->mockRules() as $rule) {
            $number++;
            $text = $rule['text'];
            $checkpoint = $rule['checkpoint'] ?? null;

            $bodyLines = $this->countWrappedLines($text, $this->contentWidth(), 10);
            $blockH = 5.2 + $bodyLines * 4.7 + 3.8 + ($checkpoint ? 8 : 0);
            if ($this->GetY() + $blockH > $this->trimH - 14) {
                $this->AddPage();
            }

            if ($checkpoint) {
                $this->SetFont($this->fontFamily, 'B', 9);
                $this->SetFillColor(235, 235, 235);
                $this->MultiCell(0, 4.6, 'CHECKPOINT  ' . $checkpoint, 0, 'L', true);
                $this->Ln(1.5);
            }
            $this->SetFont($this->fontFamily, 'B', 10.5);
            $this->Cell(0, 5.2, "Rule {$number}", 0, 2);
            $this->SetFont($this->fontFamily, '', 10);
            $this->MultiCell(0, 4.7, $text, 0, 'J');
            $this->Ln(3.8);
        }
    }

    // ------------------------------------------------------------- body (mock)

    private function bodyPages(): void
    {
        $rows = 25;
        $cols = 5;
        $contentW = $this->contentWidth();
        $colW = $contentW / $cols;
        $headerH = 11.0;
        $gridTop = $this->margin + $headerH;
        $rowH = ($this->trimH - $gridTop - 11) / $rows;

        for ($page = 0; $page < self::MOCK_BODY_PAGES; $page++) {
            $this->AddPage();
            $this->SetFont($this->fontFamily, 'B', 9);
            $this->SetY($this->margin);
            $this->Cell(0, 5, sprintf('PAGE %d OF %d', $page + 1, self::REAL_PAGES), 0, 0, 'C');

            if ($page === 0) {
                $this->SetXY($this->margin, $this->margin + 5.2);
                $this->SetFont($this->fontFamily, 'I', 7);
                $this->SetTextColor(140, 140, 140);
                $this->Cell($contentW, 3.5, '(placeholder body -- real book has ' . self::REAL_PAGES
                    . ' constructed word grids)', 0, 0, 'C');
                $this->SetTextColor(0, 0, 0);
            }

            $this->SetFont($this->gridFamily, $this->gridStyle, $this->gridPt);
            for ($row = 0; $row < $rows; $row++) {
                $this->SetXY($this->margin, $gridTop + $row * $rowH);
                for ($col = 0; $col < $cols; $col++) {
                    $this->Cell($colW, $rowH, 'LOREM', 0, 0, 'C');
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
        $this->Ln(6);
        $this->hashNote('your answer');
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
            'The next page is how you find this book\'s Mystery Word.',
        ]);
    }

    private function mysteryPage(): void
    {
        $this->AddPage();
        $this->SetFont($this->fontFamily, 'B', 15);
        $this->Cell(0, 8, 'THE MYSTERY WORD', 0, 2, 'C');
        $this->Ln(3);
        $this->SetFont($this->fontFamily, '', 10);

        foreach ($this->mysterySteps() as $line) {
            if (str_starts_with($line, '#')) {
                $this->Ln(1);
                $this->SetFont($this->fontFamily, 'B', 10);
                $this->MultiCell(0, 4.9, substr($line, 1), 0, 'L');
                $this->SetFont($this->fontFamily, '', 10);
            } else {
                $this->MultiCell(0, 4.9, $line, 0, 'J');
                $this->Ln(1.6);
            }
        }

        $this->Ln(5);
        $this->drawWritingBox();
        $this->Ln(6);
        $this->hashNote('your Mystery Word');
    }

    private function addressPage(): void
    {
        $this->AddPage();
        $this->heading('Building the address');
        $this->bodyParas([
            'To claim the Eliminoid you mail one letter. No part of the address is printed here in '
            . 'plain words -- every line is read from the books, and this page tells you where.',
            'The ADDRESSEE line is hidden in Book 1: the first letters of a stepped diagonal on one '
            . 'page spell it out. Read it the way this page describes.',
            'The TOWN, the REGION, the country, and the POSTAL CODE are each hidden the same way in '
            . 'other books. This page names the exact page and line for each one.',
            'The PO BOX NUMBER comes from your Mystery Word for this book -- find that word where '
            . 'this page tells you, and the page it sits on is the box number. A post box cannot be '
            . 'delivered to without its number, so this is the one line nobody reaches without '
            . 'finishing all twenty-one books.',
        ]);
        $this->Ln(2);
        $this->SetFont($this->fontFamily, 'I', 8.5);
        $this->MultiCell(0, 4.3, '[Exact pages and lines filled in once all books are built.]', 0, 'J');
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

    // --------------------------------------------------------------- appendix

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
        $this->Ln(1);
        $this->SetFont($this->fontFamily, 'I', 8.5);
        $this->MultiCell(0, 4.3, '[Placeholder counts -- real numbers come from the built book.]', 0, 'J');
        $this->Ln(3);

        $total = self::REAL_PAGES * 25 * 5;
        $w = $this->contentWidth();
        $cw = [$w * 0.22, $w * 0.36, $w * 0.42];
        $rowH = 5.6;

        $this->SetFont($this->fontFamily, 'B', 9.5);
        $this->Cell($cw[0], $rowH, 'Rule', 0, 0);
        $this->Cell($cw[1], $rowH, 'Crosses out', 0, 0, 'R');
        $this->Cell($cw[2], $rowH, 'Total crossed out', 0, 1, 'R');
        $this->SetFont($this->fontFamily, '', 9.5);

        $number = 0;
        foreach ($this->mockCounts($total) as $c) {
            $number++;
            if ($this->GetY() + $rowH > $this->trimH - 14) {
                $this->AddPage();
                $this->SetFont($this->fontFamily, '', 9.5);
            }
            $this->Cell($cw[0], $rowH, 'Rule ' . $number, 0, 0);
            $this->Cell($cw[1], $rowH, number_format($c['removes']), 0, 0, 'R');
            $this->Cell($cw[2], $rowH, number_format($total - $c['after']), 0, 1, 'R');
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

    /** The per-book Mystery procedure, reflecting the locked FIND formula. */
    private function mysterySteps(): array
    {
        if ($this->bookNo === 1) {
            return [
                'This is Book 1, the root of the whole series. Its Mystery Word comes from its own '
                . 'answer, read right here in this book.',
                '#1. Find your answer -- the one word still standing. Note the page it is on.',
                '#2. Your Mystery Word is the word in the top-left corner of that page (row 1, '
                . 'column 1).',
                'Write it below and check it at eliminoid.com. Every later book\'s Mystery grows out '
                . 'of this one.',
            ];
        }

        $prev = $this->bookNo - 1;
        if ($this->isCapstone) {
            return [
                'The Capstone\'s Mystery Word is found by the same rule as every book. Because it '
                . 'needs this book\'s answer AND Book ' . $prev . '\'s Mystery Word, holding it '
                . 'proves you finished all twenty-one books. It is the word you mail to claim the '
                . 'prize.',
                '#1. Find your answer for this book -- the one word still standing. Note the page it '
                . 'is on.',
                '#2. Find Book ' . $prev . '\'s Mystery Word somewhere in this book. Note its row '
                . 'and its column.',
                '#3. Open Book ' . $prev . ' to that page, that row, that column. The word there is '
                . 'the Capstone\'s Mystery Word.',
                'Write it below and check it at eliminoid.com. It is also where your PO box number '
                . 'comes from -- see "Building the address".',
            ];
        }

        return [
            'From Book 2 on, every book finds its Mystery Word the same way. You need two things: '
            . 'your answer for THIS book, and the Mystery Word from Book ' . $prev . '.',
            '#1. Find your answer for this book -- the one word still standing. Note the page it is '
            . 'on.',
            '#2. Find Book ' . $prev . '\'s Mystery Word somewhere in this book. Note its row and '
            . 'its column.',
            '#3. Open Book ' . $prev . ' to that page, that row, that column. The word there is '
            . 'this book\'s Mystery Word.',
            'Write it below and check it at eliminoid.com.',
        ];
    }

    /** A line under a writing box describing the website hash check (D11). */
    private function hashNote(string $what): void
    {
        $this->SetFont($this->fontFamily, '', 9);
        $this->MultiCell(0, 4.4, 'To be sure of ' . $what . ', type it at eliminoid.com -- it will '
            . 'tell you whether it matches. It never reveals the word.', 0, 'C');
        $this->Ln(1.5);
        $this->SetFont($this->gridFamily, $this->gridStyle, 8.5);
        $this->SetTextColor(120, 120, 120);
        // Computed with the SAME shared class the website uses, so the two
        // can never drift. (Placeholder word until real answers exist.)
        $sample = Fingerprint::grouped(Fingerprint::of('FROG'));
        $this->Cell(0, 4, 'fingerprint:  ' . $sample, 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    /** Illustrative rule texts (one carries a CHECKPOINT to preview that feature). */
    private function mockRules(): array
    {
        return [
            ['text' => 'Cross out every word that contains the letter Z.'],
            ['text' => 'Cross out every word of eight letters or more.'],
            ['text' => 'Cross out every word that has no vowel among A, E, I, O, U.'],
            ['text' => 'Cross out every word that begins and ends with the same letter.'],
            ['text' => 'Cross out every word containing the pair of letters C then H, in that order.'],
            ['text' => 'Cross out every word with a run of three consonants in a row.'],
            ['text' => 'Cross out every word whose letters are all in the first half of the alphabet (A-M).'],
            ['text' => 'On each page, find the two words still standing that are nearest to each other '
                . 'as neighbours; cross out both.',
                'checkpoint' => 'before this rule, make sure exactly 9,140 words are crossed out so far.'],
            ['text' => 'Cross out every word that reads the same backward as forward.'],
            ['text' => 'Cross out every word still standing except the one nearest the centre of its page.'],
        ];
    }

    /** A descending count sequence from the full grid down to a single survivor. */
    private function mockCounts(int $total): array
    {
        $rules = $this->isCapstone ? 20 : count($this->mockRules());
        $survivors = $total;
        $rows = [];
        for ($i = 0; $i < $rules; $i++) {
            if ($i === $rules - 1) {
                $removes = $survivors - 1;           // land exactly on 1
            } else {
                $removes = (int) floor($survivors * 0.42) + ($i % 3);
                $removes = min($removes, $survivors - ($rules - $i)); // leave room
            }
            $survivors -= $removes;
            $rows[] = ['removes' => $removes, 'after' => $survivors];
        }
        return $rows;
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
