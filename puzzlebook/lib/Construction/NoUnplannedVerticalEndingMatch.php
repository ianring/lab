<?php

declare(strict_types=1);

namespace Puzzlebook\Construction;

use Puzzlebook\Grid;
use Puzzlebook\WordTags;

/**
 * Keeps a word from accidentally sharing its last letter with an EXISTING
 * literal directly above or below it -- SameEndingVerticalPairRule's own
 * criterion, which (unlike MatchingNeighbourEndsRule's matching-ends check)
 * had no fill-time protection at all before this: no native check in
 * fillWords(), no invariant. An accidental vertical match with ANY other
 * word -- another rule's reserved pair-half, ordinary filler, even a
 * different one of this rule's own pairs slipping past its construction-time
 * check -- would silently kill one half during verification, orphaning its
 * partner in a way that's only ever discovered as an expensive, unattributed
 * "wrong final survivor count" failure, not a cheap local retry.
 *
 * $partnerOf maps each of this rule's own claimed cells to its OWN
 * deliberate vertical partner -- the one neighbour a shared ending is
 * WANTED, not accidental. Checked LIVE against $canvas (see
 * NoLengthAdjacentToOpposingLiteral's docblock for why that's safe across
 * backtracking and registration order), so it correctly sees every literal
 * any rule places for the rest of the attempt, before or after this one.
 */
final class NoUnplannedVerticalEndingMatch implements FillInvariant
{
    private Canvas $canvas;

    /** @var array<int,int> cell => its own deliberate vertical partner cell */
    private array $partnerOf;

    /** @param array<int,int> $partnerOf cell => partner cell */
    public function __construct(Canvas $canvas, array $partnerOf)
    {
        $this->canvas = $canvas;
        $this->partnerOf = $partnerOf;
    }

    public function allows(string $word, int $cell, array $placed, Grid $grid): bool
    {
        $lastLetter = WordTags::lastLetter($word);
        $partner = $this->partnerOf[$cell] ?? null;
        [, $row] = $grid->coordsOf($cell);

        if ($row > 0) {
            $above = $cell - $grid->cols;
            if ($above !== $partner) {
                $aboveWord = $this->canvas->planFor($above)?->literalWord();
                if ($aboveWord !== null && WordTags::lastLetter($aboveWord) === $lastLetter) {
                    return false;
                }
            }
        }
        if ($row < $grid->rows - 1) {
            $below = $cell + $grid->cols;
            if ($below !== $partner) {
                $belowWord = $this->canvas->planFor($below)?->literalWord();
                if ($belowWord !== null && WordTags::lastLetter($belowWord) === $lastLetter) {
                    return false;
                }
            }
        }
        return true;
    }

    public function record(string $word, int $cell, Grid $grid): void
    {
    }

    public function describe(): string
    {
        return 'a word must not share an ending letter with an existing reserved vertical neighbour';
    }
}
