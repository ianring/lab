<?php

declare(strict_types=1);

namespace Puzzlebook\Construction;

use Puzzlebook\Grid;

/**
 * Stops one word on a page from containing another, except where a rule put
 * the pair there on purpose.
 *
 * Why this can't use UniquePageSignature: containment has no canonical key.
 * Two anagrams share their sorted letters, two reversals share a canonical
 * form -- so for those, "has this signature appeared on this page already?"
 * is a hash lookup. But ROCK and SPROCKET have nothing in common to hash, so
 * the only way to know is to compare a candidate against the words actually
 * on the page, both directions: does it contain one of them, or does one of
 * them contain it?
 *
 * That makes it the most expensive invariant here -- up to 120 string
 * comparisons per candidate word rather than one hash lookup. Still cheap in
 * absolute terms, and the length guard below skips most of the work: a word
 * shorter than the minimum can't be the contained half, and containment is
 * only possible between words of different lengths.
 */
final class NoContainmentOnPage implements FillInvariant
{
    /** @var array<int, string[]> page => words placed there so far */
    private array $byPage = [];

    /** @param array<int,bool> $exempt cells holding a deliberately planted pair */
    public function __construct(
        private int $minLength,
        private int $cellsPerPage,
        private array $exempt
    ) {
    }

    public function allows(string $word, int $cell, array $placed, Grid $grid): bool
    {
        if (isset($this->exempt[$cell])) {
            return true;
        }

        $page = intdiv($cell, $this->cellsPerPage);
        foreach ($this->byPage[$page] ?? [] as $other) {
            $longer = strlen($word) >= strlen($other) ? $word : $other;
            $shorter = strlen($word) >= strlen($other) ? $other : $word;

            if (strlen($shorter) < $this->minLength || strlen($shorter) === strlen($longer)) {
                continue;
            }
            if (str_contains($longer, $shorter)) {
                return false;
            }
        }

        return true;
    }

    public function record(string $word, int $cell, Grid $grid): void
    {
        $this->byPage[intdiv($cell, $this->cellsPerPage)][] = $word;
    }

    public function describe(): string
    {
        return 'the rule about one word hiding inside another on the same page';
    }
}
