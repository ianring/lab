<?php

declare(strict_types=1);

namespace Puzzlebook\Construction;

use Puzzlebook\Grid;

/**
 * Keeps an ordinary word of length 9 or 3 from landing next to an EXISTING
 * literal pair-half (from AnagramPairOnPageRule, SameEndingVerticalPairRule,
 * or MatchingNeighbourEndsRule) of the opposing length -- protecting those
 * rules' own fragile "both halves must survive together" pairs from being
 * orphaned by an accident LongShortNeighbourRule's own apply() would catch.
 *
 * Checked LIVE against $canvas (the same Canvas instance this invariant was
 * registered on, kept for the rest of the attempt -- backtracking mutates
 * its internals in place via restore(), it never replaces the object, so
 * this reference is never stale) rather than from a one-time snapshot. That
 * matters because allows() only ever runs during fillWords(), AFTER every
 * rule in the chain has already finished construct() -- so by the time this
 * is checked, every literal any rule will ever place already exists,
 * regardless of which one ran before or after LongShortNeighbourRule itself.
 *
 * Deliberately does NOT protect LongShortNeighbourRule's own pairs from each
 * other, or from ordinary accidental matches -- those overlaps are the
 * explicit, wanted behaviour of that rule (a 3-letter word with three
 * 9-letter neighbours eliminates all four), not something to guard against.
 * $partnerOf is what makes that possible: every one of LongShortNeighbourRule's
 * own 9-letter cells has its OWN 3-letter partner sitting right next to it,
 * by design -- without excluding that specific neighbour, hasAdjacentLiteralOfLength()
 * (which now also checks CellPlan::fixedLength(), not just literal words) would
 * see that intentional adjacency as a violation of itself, rejecting every
 * single candidate at every one of this rule's own cells, unconditionally.
 */
final class NoLengthAdjacentToOpposingLiteral implements FillInvariant
{
    private const LONG = 9;
    private const SHORT = 3;

    private Canvas $canvas;

    /** @var array<int,int> cell => its own deliberate opposing-length partner cell */
    private array $partnerOf;

    /** @param array<int,int> $partnerOf cell => partner cell */
    public function __construct(Canvas $canvas, array $partnerOf = [])
    {
        $this->canvas = $canvas;
        $this->partnerOf = $partnerOf;
    }

    public function allows(string $word, int $cell, array $placed, Grid $grid): bool
    {
        $len = strlen($word);
        if ($len !== self::LONG && $len !== self::SHORT) {
            return true;
        }
        $opposing = $len === self::LONG ? self::SHORT : self::LONG;
        $partner = $this->partnerOf[$cell] ?? null;
        return !$this->canvas->hasAdjacentLiteralOfLength($cell, $opposing, $partner);
    }

    public function record(string $word, int $cell, Grid $grid): void
    {
    }

    public function describe(): string
    {
        return 'a word next to an existing reserved pair-half must not be the opposing length (9 vs 3)';
    }
}
