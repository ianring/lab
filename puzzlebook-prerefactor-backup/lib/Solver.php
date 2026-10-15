<?php

declare(strict_types=1);

namespace Puzzlebook;

/**
 * The forward solver: reads a built book the way a reader would, one step
 * at a time, and reports what it finds -- including every reveal's exact
 * text, so a mismatch is visible at the step it happens rather than only at
 * the end.
 *
 * This is deliberately independent of ChainBuilder::verify(): that check
 * runs once, at construction time, against the layout bin/build.php just
 * produced. This one re-reads build/book.json from disk, so it also catches
 * a book.json that was hand-edited or produced by some other means.
 */
final class Solver
{
    /**
     * @param array{grid: array{pages:int,rows:int,cols:int}, words: string[]} $book
     * @return array{ok:bool, lines:string[]}
     */
    public static function run(array $book, array $chain): array
    {
        $grid = new Grid($book['grid']['pages'], $book['grid']['rows'], $book['grid']['cols']);
        $words = $book['words'];
        $total = count($words);
        $keep = array_fill(0, $total, true);
        $lines = [];
        $ok = true;

        foreach ($chain['steps'] as $step) {
            if ($step['kind'] === 'eliminate') {
                $before = Engine::countSurvivors($keep);
                $keep = Engine::applyRule($words, $grid, $keep, $step['rule']);
                $after = Engine::countSurvivors($keep);
                $removed = $before - $after;
                $pctOfBook = $total > 0 ? $removed / $total * 100 : 0.0;
                $pctOfRemaining = $before > 0 ? $removed / $before * 100 : 0.0;

                $lines[] = sprintf(
                    'Step %d (eliminate): removed %d of %d words (%s%% of the whole book, %s%% of what remained)'
                        . ' -- %d left.',
                    $step['id'],
                    $removed,
                    $total,
                    number_format($pctOfBook, 1),
                    number_format($pctOfRemaining, 1),
                    $after
                );

                if (!empty($step['final'])) {
                    $survivors = Engine::survivingIndices($keep);
                    if (count($survivors) === 1) {
                        $finalWord = $words[$survivors[0]];
                        if ($finalWord === $chain['answer']) {
                            $lines[] = "Final answer: '{$finalWord}' (matches config/chain.php)";
                        } else {
                            $ok = false;
                            $lines[] = "Final answer: '{$finalWord}' -- MISMATCH, expected '{$chain['answer']}'";
                        }
                    } else {
                        $ok = false;
                        $survivorWords = array_map(static fn (int $i): string => $words[$i], $survivors);
                        $lines[] = 'FINAL STEP LEFT ' . count($survivors) . ' WORDS: ' . implode(', ', $survivorWords);
                    }
                }
                continue;
            }

            // $step['kind'] === 'reveal'
            $pattern = Engine::describeReveal($step['reveal']);
            $lines[] = "Step {$step['id']} (reveal): {$pattern}";
            try {
                $revealed = Engine::computeReveal($words, $grid, $keep, $step['reveal'])['text'];
            } catch (\RuntimeException $e) {
                $ok = false;
                $lines[] = '  FAILED: ' . $e->getMessage();
                continue;
            }
            $expected = $step['expected'];
            if ($revealed === $expected) {
                $lines[] = "  revealed: '{$revealed}' (matches expected)";
            } else {
                $ok = false;
                $lines[] = "  revealed: '{$revealed}' -- MISMATCH, expected '{$expected}'";
            }
        }

        return ['ok' => $ok, 'lines' => $lines];
    }
}
