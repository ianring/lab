<?php

declare(strict_types=1);

namespace Puzzlebook\Construction;

use Puzzlebook\Grid;

/**
 * Keeps a chosen letter out of every corner cell NOT deliberately handed to
 * CornerContainsLetterRule -- so a later rule's ordinary filler can't land an
 * accidental match in a corner the corner rule meant to leave alone, which
 * would silently cross out a word nobody budgeted for.
 *
 * Unlike UniquePageSignature, this needs no running state: whether a word is
 * allowed at a given cell depends only on the word itself and whether that
 * cell is one of the deliberately-planted exceptions, never on anything
 * placed earlier. record() is a no-op.
 */
final class NoUnplannedCornerLetter implements FillInvariant
{
    private string $letter;

    /** @var array<int,bool> every corner cell in the book */
    private array $corners;

    /** @var array<int,bool> corners CornerContainsLetterRule deliberately claimed */
    private array $exempt;

    /**
     * @param int[] $corners every corner cell in the book
     * @param array<int,bool> $exempt cell index => true, for deliberately planted cells
     */
    public function __construct(string $letter, array $corners, array $exempt)
    {
        $this->letter = $letter;
        $this->corners = array_fill_keys($corners, true);
        $this->exempt = $exempt;
    }

    public function allows(string $word, int $cell, array $placed, Grid $grid): bool
    {
        if (!isset($this->corners[$cell]) || isset($this->exempt[$cell])) {
            return true;
        }
        return !str_contains($word, $this->letter);
    }

    public function record(string $word, int $cell, Grid $grid): void
    {
    }

    public function describe(): string
    {
        return "a corner not deliberately eliminated must not contain '{$this->letter}'";
    }
}
