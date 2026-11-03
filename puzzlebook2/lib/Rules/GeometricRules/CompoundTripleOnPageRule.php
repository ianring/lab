<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Demand;
use Puzzlebook2\Rules\Generators\SamePageFreeTripleGenerator;
use Puzzlebook2\Rules\Reservation;
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Rules\RuleIdentity;
use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Solver\ConsumedWords;
use Puzzlebook2\Solver\FilledBoard;
use Puzzlebook2\Solver\Rng;

/**
 * Cross out all THREE words of any still-surviving triple on the same page
 * where one word is exactly the other two joined together -- LOOK + OUT spells
 * LOOKOUT, FOOT + BALL spells FOOTBALL, CUBI + TALE spells CUBITALE. A new level
 * of complexity: the solver hunts a page for a relationship ACROSS three words,
 * not a property of one. It is still checkable with no knowledge of meaning --
 * joining two strings and comparing is pure letters -- which keeps it inside
 * the accessibility bar; the difficulty is in the search, not the check.
 *
 * CONSTRUCTION. The three-cell sibling of SemordnilapPairOnPageRule /
 * AnagramPairOnPageRule: Exact arity, a real (whole, part, part) triple drawn
 * live from the prefix survivors (no precomputed list -- splitting a word is
 * cheap, and every piece must be a survivor anyway, exactly as the pair rules
 * draw their pairs from prefixPool), placed in any three free cells of one page
 * by SamePageFreeTripleGenerator. Parts are required to be at least MIN_PART
 * letters so the relationship is a genuine two-word join, not a one-letter
 * shaving.
 *
 * No globalConstraints() guard (unlike the pair rules). A pair rule guards
 * against an ACCIDENTAL standing pair sharing a page, because two unrelated
 * words being anagrams/reverses is common enough to happen by chance. An
 * accidental TRIPLE -- a page that by chance holds a word AND both of its parts,
 * all still standing -- is astronomically rarer (~0.004 expected across a whole
 * 100-page book), so the verify-and-retry the engine already does absorbs it
 * without a dedicated invariant. The generic StandingPatternConstraint would
 * cost a cubic per-cell scope walk here (~C(124,2) scopes per placement), far
 * too hot for the solver's inner loop; if activation ever shows accidental
 * triples actually biting, the right fix is a content-indexed guard (scan the
 * page's standing words once), not the generic quadratic one.
 */
final class CompoundTripleOnPageRule implements Rule, \Puzzlebook2\Rules\RequiresAccurateProgress
{
    use RuleIdentity;

    /** Shortest a part word may be, so a "join" is two real words, not a shaving. */
    public const MIN_PART = 3;
    /** Shortest the joined whole may be (two minimum parts). */
    private const MIN_WHOLE = 2 * self::MIN_PART;
    private const MAX_TRIPLES_PER_PAGE = 1.0;

    public function describe(): string
    {
        return 'triples of words on the same page where one is exactly the other two joined (parts ' . self::MIN_PART . '+ letters)';
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $spec = $board->spec();
        $perPage = $spec->rows * $spec->cols;
        $start = $scope->cell('start');

        // Index the page's still-standing words -> the cells that hold them.
        $byWord = [];
        for ($c = $start; $c < $start + $perPage; $c++) {
            if ($keep->isKept($c)) {
                $byWord[$board->wordAt($c)][] = $c;
            }
        }

        $kill = [];
        foreach ($byWord as $whole => $cells) {
            if (strlen((string) $whole) < self::MIN_WHOLE) {
                continue;
            }
            $len = strlen((string) $whole);
            for ($i = self::MIN_PART; $i <= $len - self::MIN_PART; $i++) {
                $a = substr((string) $whole, 0, $i);
                $b = substr((string) $whole, $i);
                if ($a !== $b && isset($byWord[$a], $byWord[$b])) {
                    $kill[$cells[0]] = true;
                    $kill[$byWord[$a][0]] = true;
                    $kill[$byWord[$b][0]] = true;
                }
            }
        }
        return array_keys($kill);
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        // One scope per page: eliminatedCells() scans that whole page at once,
        // which is both cheaper than enumerating every triple and the natural
        // snapshot unit (all three of a triple judged standing together).
        $perPage = $spec->rows * $spec->cols;
        for ($page = 0; $page < $spec->pages; $page++) {
            yield new Scope(['start' => $page * $perPage]);
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        $spec = $ctx->board()->spec();
        $maxTriples = (int) round($spec->pages * self::MAX_TRIPLES_PER_PAGE);
        $triplesWanted = max(0, min(intdiv($ctx->budget(), 3), $maxTriples));

        $pool = [];
        foreach ($ctx->prefixPool() as $w) {
            $pool[$w] = true;
        }

        // One (whole, part, part) triple per eligible whole among the survivors.
        $triples = [];
        foreach ($pool as $whole => $_) {
            $len = strlen((string) $whole);
            if ($len < self::MIN_WHOLE) {
                continue;
            }
            for ($i = self::MIN_PART; $i <= $len - self::MIN_PART; $i++) {
                $a = substr((string) $whole, 0, $i);
                $b = substr((string) $whole, $i);
                if ($a !== $b && isset($pool[$a], $pool[$b])) {
                    $triples[] = [(string) $whole, $a, $b];
                    break;
                }
            }
        }
        $triples = Rng::shuffle($triples, $ctx->seed());
        $supply = count($triples);

        return new Demand(
            label: $this->id(),
            count: $triplesWanted,
            arity: Arity::Exact,
            generator: new SamePageFreeTripleGenerator(),
            cellsPerScope: 3,
            rawSupplyEstimate: $supply,
            wordChooser: static function (ConsumedWords $consumed, int $seed, int $draw) use ($triples): ?array {
                $i = 0;
                foreach ($triples as [$whole, $a, $b]) {
                    if ($consumed->isConsumed($whole) || $consumed->isConsumed($a) || $consumed->isConsumed($b)) {
                        continue;
                    }
                    if ($i++ === $draw) {
                        return ['whole' => $whole, 'a' => $a, 'b' => $b];
                    }
                }
                return null;
            },
        );
    }

    public function constraintsFor(Reservation $reservation): iterable
    {
        return [];
    }

    public function globalConstraints(ChainContext $ctx): iterable
    {
        return [];
    }

    public function kind(): string
    {
        return 'eliminate';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside the logic so text and rule cannot drift apart. */
    public function ruleText(): string
    {
        return 'On every page, look for three words, all still standing, where one of them is exactly the other two joined together (`LOOK` and `OUT` make `LOOKOUT`; `FOOT` and `BALL` make `FOOTBALL`). Cross out all three words.';
    }
}
