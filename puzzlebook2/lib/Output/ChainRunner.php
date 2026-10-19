<?php

declare(strict_types=1);

namespace Puzzlebook2\Output;

use Puzzlebook2\Chain\ChainPlan;
use Puzzlebook2\Chain\Engine;
use Puzzlebook2\Solver\FilledBoard;

/**
 * The verification pass: walk the finished board through every step of the
 * chain, exactly as a reader would, and confirm the one survivor is the
 * answer. This runs the SAME Engine::apply() the web preview uses -- the
 * book that verifies here is the book the preview shows, with no second
 * implementation to drift.
 */
final class ChainRunner
{
    /**
     * @return array{
     *     ok: bool,
     *     lines: string[],
     *     eliminated: array<int,string[]>,   // step id => words removed
     *     survivors: string[],
     * }
     */
    public static function run(FilledBoard $board, ChainPlan $plan): array
    {
        $words = $board->allWords();
        $total = count($words);
        $keep = array_fill(0, $total, true);

        $lines = [];
        $eliminated = [];
        $lines[] = sprintf('%d words in play.', $total);

        foreach ($plan->steps() as $pos => $step) {
            $rule = $plan->rules()[$pos];
            $before = Engine::countSurvivors($keep);
            $keepAfter = Engine::apply($rule, $board, $keep);

            $removedWords = [];
            foreach ($keep as $i => $wasKept) {
                if ($wasKept && !$keepAfter[$i]) {
                    $removedWords[] = $words[$i];
                }
            }
            $eliminated[$step['id']] = $removedWords;
            $after = Engine::countSurvivors($keepAfter);
            $lines[] = sprintf(
                'step %2d  %-32s removed %4d  (%d -> %d)',
                $step['id'],
                $rule->id(),
                count($removedWords),
                $before,
                $after,
            );
            $keep = $keepAfter;
        }

        $survivors = array_map(
            static fn (int $i): string => $words[$i],
            Engine::survivingIndices($keep),
        );
        $ok = count($survivors) === 1 && $survivors[0] === $plan->answer();
        $lines[] = $ok
            ? sprintf("FINAL: exactly one survivor, '%s' -- the answer. Verified.", $survivors[0])
            : sprintf(
                'FINAL: %d survivor(s) [%s], expected exactly [%s]. BROKEN.',
                count($survivors),
                implode(', ', array_slice($survivors, 0, 12)),
                $plan->answer(),
            );

        return ['ok' => $ok, 'lines' => $lines, 'eliminated' => $eliminated, 'survivors' => $survivors];
    }
}
