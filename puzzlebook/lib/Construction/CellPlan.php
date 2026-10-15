<?php

declare(strict_types=1);

namespace Puzzlebook\Construction;

/**
 * What a single cell's word has to look like -- everything the word-filling
 * pass needs to know, with no idea which rule asked for it.
 *
 * This is the hand-off between a rule's construction logic (which decides
 * WHICH cells it wants and what shape their words must be) and the builder's
 * filling logic (which just satisfies plans). A rule that wants a new kind of
 * cell writes a CellPlan; nothing in the builder changes.
 */
final class CellPlan
{
    /** @var callable(string, array): bool */
    private $shape;

    /** @var (callable(array): ?string)|null */
    private $firstLetterHint;

    private int $survivesPrefix;
    private string $label;
    private ?string $literal;
    private ?string $staticFirstLetter;
    private bool $allowsLeftMatch;

    /**
     * @param int $survivesPrefix How many of the chain's spelling rules this word must
     *     pass. A word that dies at spelling rule #3 passes the first 3 and fails the
     *     4th; a word that lives to the end passes all of them.
     * @param callable(string, array): bool $shape Any further requirement -- "fails
     *     this rule", "is five letters ending in E". Receives the words placed so far,
     *     so it can depend on a neighbour that's already filled.
     * @param string $label Names this kind of request, so the dictionary can keep a
     *     separate scan position for it.
     * @param string|null $literal Set when the word is decided in advance. The builder
     *     places it directly instead of looking one up.
     * @param string|null $staticFirstLetter The first letter when it's known before
     *     anything is placed -- lets the cell to the LEFT avoid ending on it.
     * @param (callable(array): ?string)|null $firstLetterHint Resolves the required
     *     first letter at fill time, for a cell whose letter depends on a neighbour.
     *     Purely an optimisation: it lets the dictionary jump straight to that
     *     letter's words rather than scanning the pool, which matters at full size.
     * @param bool $allowsLeftMatch True only for a cell that WANTS to start on the
     *     letter its left neighbour ended on -- i.e. one half of a deliberate
     *     matching pair. Everywhere else that collision is an accident to avoid.
     */
    public function __construct(
        int $survivesPrefix,
        callable $shape,
        string $label,
        ?string $literal = null,
        ?string $staticFirstLetter = null,
        ?callable $firstLetterHint = null,
        bool $allowsLeftMatch = false
    ) {
        $this->survivesPrefix = $survivesPrefix;
        $this->shape = $shape;
        $this->label = $label;
        $this->literal = $literal;
        $this->staticFirstLetter = $staticFirstLetter;
        $this->firstLetterHint = $firstLetterHint;
        $this->allowsLeftMatch = $allowsLeftMatch;
    }

    /** A plan with no requirement beyond surviving the first $survivesPrefix rules. */
    public static function surviving(int $survivesPrefix, string $label): self
    {
        return new self($survivesPrefix, static fn (): bool => true, $label);
    }

    /** A plan for a word decided in advance -- the answer, or a revealed word. */
    public static function literal(string $word, int $survivesPrefix, string $label): self
    {
        return new self(
            $survivesPrefix,
            static fn (string $candidate): bool => $candidate === $word,
            $label,
            $word,
            $word[0]
        );
    }

    /**
     * A plan for the right half of a deliberate matching pair: it must start on the
     * letter the cell at $leftCell ended on, and that collision is wanted, not avoided.
     */
    public static function matchingRightHalf(int $survivesPrefix, string $label, int $leftCell): self
    {
        $needed = static function (array $placed) use ($leftCell): ?string {
            return $placed[$leftCell] === null ? null : substr($placed[$leftCell], -1);
        };

        return new self(
            $survivesPrefix,
            static function (string $word, array $placed) use ($needed): bool {
                $letter = $needed($placed);
                return $letter !== null && $word[0] === $letter;
            },
            $label,
            null,
            null,
            $needed,
            true
        );
    }

    public function survivesPrefix(): int
    {
        return $this->survivesPrefix;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** The whole word, when it was decided in advance; null if it's to be looked up. */
    public function literalWord(): ?string
    {
        return $this->literal;
    }

    /** The first letter if it's known before anything is placed, else null. */
    public function staticFirstLetter(): ?string
    {
        return $this->staticFirstLetter;
    }

    /** True if this cell deliberately starts on the letter its left neighbour ended on. */
    public function allowsLeftMatch(): bool
    {
        return $this->allowsLeftMatch;
    }

    /**
     * The first letter this cell's word must start with, resolved against what's been
     * placed so far -- or null if it isn't pinned to one letter.
     *
     * @param array $placedSoFar linear cell index => word, or null where unfilled
     */
    public function firstLetter(array $placedSoFar): ?string
    {
        if ($this->firstLetterHint === null) {
            return $this->staticFirstLetter;
        }
        return ($this->firstLetterHint)($placedSoFar);
    }

    /** @param array $placedSoFar linear cell index => word, or null where unfilled */
    public function shapeAllows(string $word, array $placedSoFar): bool
    {
        return ($this->shape)($word, $placedSoFar);
    }
}
