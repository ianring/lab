<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\GeometricRules;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Board\PageCorners;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\KeepMask;
use Puzzlebook2\Constraints\StandingPatternConstraint;
use Puzzlebook2\Rules\Arity;
use Puzzlebook2\Rules\Demand;
use Puzzlebook2\Rules\Generators\FixedGroupGenerator;
use Puzzlebook2\Rules\Reservation;
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Rules\RuleIdentity;
use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Solver\Domain;
use Puzzlebook2\Solver\FilledBoard;

/**
 * Cross out every word sitting in one of a page's four corners if it
 * contains the letter E.
 *
 * CONSTRUCTION. A budgeted subset of corners deliberately gets a word
 * containing E (the ones this rule eliminates). Every OTHER corner is left
 * for the rest of the chain to fill normally -- but its word must NOT
 * contain E, or this rule would cross out a cell some later step budgeted
 * for. In v1 that promise was a bespoke NoUnplannedCornerLetter invariant;
 * here it is this rule's globalConstraints() instance of the generic
 * StandingPatternConstraint: a single-cell "pattern" (contains E) over
 * corner scopes, exempting this rule's own claims.
 */
final class CornerContainsLetterRule implements Rule
{
    use RuleIdentity;

    private const LETTER = 'e';

    public function describe(): string
    {
        return "corner words containing '" . self::LETTER . "'";
    }

    public function eliminatedCells(FilledBoard $board, Scope $scope, KeepMask $keep): array
    {
        $cell = $scope->cell('cell');
        if ($keep->isKept($cell) && str_contains($board->wordAt($cell), self::LETTER)) {
            return [$cell];
        }
        return [];
    }

    public function allScopes(BoardSpec $spec): iterable
    {
        foreach (PageCorners::all($spec) as $cell) {
            yield new Scope(['cell' => $cell]);
        }
    }

    public function demand(ChainContext $ctx): Demand
    {
        $board = $ctx->board();
        $id = $this->id();
        $wants = static fn (string $w): bool => str_contains($w, self::LETTER);
        $pool = $ctx->makePool($wants);

        $groups = [];
        foreach (PageCorners::all($board->spec()) as $corner) {
            $groups[] = ['cell' => $corner];
        }

        return new Demand(
            label: $id,
            count: $ctx->budget(),
            arity: Arity::Shaped,
            generator: new FixedGroupGenerator($groups),
            cellsPerScope: 1,
            rawSupplyEstimate: $ctx->countSupply($wants),
            geometricCapacity: count($groups),
            domainFactory: static function (string $role, int $cell) use ($board, $pool, $id): Domain {
                return new Domain($pool, $cell, $board->constraints, $board, $id);
            },
        );
    }

    public function constraintsFor(Reservation $reservation): iterable
    {
        return [];
    }

    public function globalConstraints(ChainContext $ctx): iterable
    {
        $spec = $ctx->board()->spec();
        $corners = array_fill_keys(PageCorners::all($spec), true);

        yield new StandingPatternConstraint(
            ownerRuleId: $this->id(),
            stepIndex: $ctx->stepIndex(),
            resolver: $ctx->resolver(),
            spec: $spec,
            scopesTouching: static function (int $cell) use ($corners): iterable {
                if (isset($corners[$cell])) {
                    yield new Scope(['cell' => $cell]);
                }
            },
            pattern: static fn (array $words): bool => str_contains($words['cell'], self::LETTER),
            label: "no unplanned corner word may contain '" . self::LETTER . "'",
        );
    }

    public function kind(): string
    {
        return 'eliminate';
    }

    /** The printed instruction -- the ONE authoritative copy, kept beside failsWord() so text and logic cannot drift apart. */
    public function ruleText(): string
    {
        return 'Look at the four corners of every page (top-left, top-right, bottom-left, bottom-right). Cross out any corner word containing the letter E.';
    }
}
