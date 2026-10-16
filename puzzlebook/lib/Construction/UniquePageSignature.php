<?php

declare(strict_types=1);

namespace Puzzlebook\Construction;

use Puzzlebook\Grid;

/**
 * Keeps a signature unique within each page, so a coincidence-based rule only
 * ever fires where it was meant to.
 *
 * This is the reusable machinery for every "two words on a page that share
 * X" rule -- the shape differs only in what the signature is:
 *
 *   anagram pair      sorted letters          "listen" -> "eilnst"
 *   shared prefix     first three letters     "console" -> "con"
 *   same vowels       vowels in order         "paradox" -> "aao"
 *   reversal present  word vs reverse, sorted canonically
 *
 * Cells the rule deliberately planted are exempt: they're *supposed* to
 * collide, and they're the only pairs that should. Their signatures are still
 * recorded, so a third word can't stumble into the same value and get crossed
 * out alongside them.
 *
 * A signature returning null means "this word can't take part" -- a word
 * shorter than the prefix length, say -- and is neither checked nor recorded.
 */
final class UniquePageSignature implements FillInvariant
{
    /** @var callable(string): ?string */
    private $signature;

    private string $what;

    /** @var array<int,bool> cells allowed to duplicate a signature */
    private array $exempt;

    /** @var array<int, array<string,bool>> page => signature => taken */
    private array $seen = [];

    /**
     * @param callable(string): ?string $signature
     * @param array<int,bool> $exempt cell index => true, for deliberately planted cells
     */
    public function __construct(callable $signature, string $what, array $exempt = [])
    {
        $this->signature = $signature;
        $this->what = $what;
        $this->exempt = $exempt;
    }

    public function allows(string $word, int $cell, array $placed, Grid $grid): bool
    {
        if (isset($this->exempt[$cell])) {
            return true;
        }
        $sig = ($this->signature)($word);
        if ($sig === null) {
            return true;
        }
        [$page] = $grid->coordsOf($cell);
        return !isset($this->seen[$page][$sig]);
    }

    public function record(string $word, int $cell, Grid $grid): void
    {
        $sig = ($this->signature)($word);
        if ($sig === null) {
            return;
        }
        [$page] = $grid->coordsOf($cell);
        $this->seen[$page][$sig] = true;
    }

    public function describe(): string
    {
        return $this->what;
    }
}
