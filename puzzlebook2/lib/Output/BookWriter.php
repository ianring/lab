<?php

declare(strict_types=1);

namespace Puzzlebook2\Output;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainPlan;
use Puzzlebook2\Solver\FilledBoard;

/**
 * Writes exactly the artifacts v1's bin/build.php wrote, byte-compatible in
 * schema: build/book.json ({grid:{pages,rows,cols}, words}), build/chain.json
 * (the numbered step list + built_at), build/pages.txt (a plain-text proof of
 * every page), build/eliminated/ (one file per step listing what it removed,
 * plus summary.txt) -- and copies of book.json/chain.json into preview/ for the
 * preview page to fetch. The existing preview/index.html needs no changes.
 */
final class BookWriter
{
    /**
     * @param array{ok:bool, lines:string[], eliminated:array<int,string[]>} $verification
     * @return string[] paths written
     */
    /** @param array $print the layout.json "print" block -- exported into book.json so the web preview can render pages at the true trim, margin and grid font. */
    public static function write(string $root, FilledBoard $board, ChainPlan $plan, array $verification, array $print = []): array
    {
        $spec = $board->spec();
        [$bookJson, $chainJson] = self::encode($board, $plan, $verification, $print);
        $book = ['words' => array_values($board->allWords())];

        $written = [];
        foreach (['build', 'build/eliminated', 'preview'] as $dir) {
            if (!is_dir("{$root}/{$dir}")) {
                mkdir("{$root}/{$dir}", 0777, true);
            }
        }

        file_put_contents($written[] = "{$root}/build/book.json", $bookJson);
        file_put_contents($written[] = "{$root}/build/chain.json", $chainJson);
        file_put_contents($written[] = "{$root}/build/pages.txt", self::renderPages($spec, $book['words']));
        file_put_contents($written[] = "{$root}/preview/book.json", $bookJson);
        file_put_contents($written[] = "{$root}/preview/chain.json", $chainJson);

        $stepById = [];
        foreach ($plan->steps() as $pos => $step) {
            $stepById[$step['id']] = $plan->rules()[$pos];
        }
        foreach ($verification['eliminated'] as $stepId => $removedWords) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $stepById[$stepId]->describe()));
            $slug = substr(trim($slug, '-'), 0, 40);
            sort($removedWords);
            $file = sprintf('%s/build/eliminated/step-%02d-%s.txt', $root, $stepId, $slug);
            file_put_contents($written[] = $file, implode("\n", $removedWords) . "\n");
        }
        file_put_contents(
            $written[] = "{$root}/build/eliminated/summary.txt",
            implode("\n", $verification['lines']) . "\n",
        );

        return $written;
    }

    /**
     * Serialize a solved book to the two JSON strings (book.json, chain.json),
     * identical in schema to what write() persists. Factored out so a
     * series build can emit per-book files without duplicating this contract.
     *
     * @param array{ok:bool, lines:string[], eliminated:array<int,string[]>, counts:array<int,array{before:int,after:int}>} $verification
     * @return array{0:string, 1:string} [bookJson, chainJson]
     */
    public static function encode(FilledBoard $board, ChainPlan $plan, array $verification, array $print = []): array
    {
        $spec = $board->spec();
        $book = [
            'grid' => ['pages' => $spec->pages, 'rows' => $spec->rows, 'cols' => $spec->cols],
            'words' => array_values($board->allWords()),
        ];
        if ($print !== []) {
            $book['print'] = $print;
        }

        // rule_text lives on each Rule class (the one authoritative copy,
        // beside the logic it describes) -- injected into the export here.
        // The final step gets its positional closing line appended: that
        // sentence belongs to the chain's shape, not to the rule.
        $chainForExport = $plan->chainConfig();
        $total = count($board->allWords());
        foreach ($plan->rules() as $pos => $rule) {
            $text = $rule->ruleText();
            if ($pos === $plan->finalPos()) {
                $text .= ' One word is now left standing: that is the answer.';
            }
            // A state-dependent rule read against a page with missed
            // crossings corrupts everything after it -- so it gets a
            // checkpoint with the EXACT count from this verified build,
            // ahead of the instruction, where it can still help.
            $stepId = $chainForExport['steps'][$pos]['id'];
            if ($rule instanceof \Puzzlebook2\Rules\RequiresAccurateProgress
                && isset($verification['counts'][$stepId])) {
                $standing = $verification['counts'][$stepId]['before'];
                $crossed = $total - $standing;
                $text = sprintf(
                    'CHECKPOINT: this rule only works if every rule before it is fully done. Exactly %s words '
                    . 'should be crossed out by now, leaving %s still in play. If you have fewer crossed out, '
                    . 'go back over the earlier rules and catch what you missed before continuing.'
                    . "\n\n%s",
                    number_format($crossed),
                    number_format($standing),
                    $text,
                );
            }
            $chainForExport['steps'][$pos]['rule_text'] = $text;
            if (isset($verification['counts'][$stepId])) {
                $chainForExport['steps'][$pos]['removes'] = count($verification['eliminated'][$stepId] ?? []);
                $chainForExport['steps'][$pos]['survivors_after'] = $verification['counts'][$stepId]['after'];
            }
        }
        $chainForExport['built_at'] = date('D j M Y, H:i');

        return [
            json_encode($book, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            json_encode($chainForExport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        ];
    }

    /** @param string[] $words */
    private static function renderPages(BoardSpec $spec, array $words): string
    {
        $colWidth = 8 + max(array_map('strlen', $words));
        $out = '';
        for ($page = 0; $page < $spec->pages; $page++) {
            $out .= '=== Page ' . ($page + 1) . " ===\n";
            for ($row = 0; $row < $spec->rows; $row++) {
                $line = '';
                for ($col = 0; $col < $spec->cols; $col++) {
                    $line .= str_pad($words[$spec->linearIndex($page, $row, $col)], $colWidth);
                }
                $out .= rtrim($line) . "\n";
            }
            $out .= "\n";
        }
        return $out;
    }
}
