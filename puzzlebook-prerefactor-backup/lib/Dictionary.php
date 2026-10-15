<?php

declare(strict_types=1);

namespace Puzzlebook;

/**
 * The word pool the builder fills the book with. Clue and answer words are
 * reserved by their exact spelling (take()); everything else is filler,
 * found by fillerMatching() -- the "build()" counterpart to a spelling-only
 * rule's "solve()": give it the same predicate Engine::applyRule() would use
 * to decide pass/fail, and it hands back a real, unused word satisfying it.
 * fillerPalindrome() and fillerConsonantRun() are just fillerMatching() with
 * the two predicates already filled in, for convenience.
 *
 * fillerMatching() only works for rules that are a pure function of a
 * word's own spelling. A rule that looks at neighbouring cells
 * (knight's-move, matching-neighbour-ends) or at rank among survivors
 * (every-18th-word) can't be satisfied by picking a word in isolation --
 * ChainBuilder handles those by deciding which CELLS play which role first,
 * then using fillerStartingWith() (below) wherever a specific first letter
 * is required.
 *
 * Every filler word also skips anything starting with the layout's
 * filler_excluded_initial (see config/layout.php), so the only words with
 * that initial anywhere in the book are the ones placed on purpose as
 * knight's-move anchors.
 *
 * The whole word list is shuffled once, with a fixed seed, so filler words
 * come out spread across the alphabet instead of in data/wordlist.txt's
 * sorted order (which would otherwise make almost every filler start with
 * "a"), while a build stays reproducible from one run to the next. Each
 * named predicate gets its own scan position into that same shuffled list,
 * so repeated calls for the same predicate don't rescan from the start
 * every time, and two different predicates can't fight over the same word
 * (whichever claims it first marks it used; the other's scan just skips
 * past it).
 */
final class Dictionary
{
    private const POOL_SHUFFLE_SEED = 20261015;

    /** @var array<string,bool> */
    private array $used = [];

    /** @var string[] */
    private array $pool;

    /** @var array<string,int> predicate label => how far into $pool its scan has reached */
    private array $cursors = [];

    /** @var array<string,string[]> first letter => every pool word with that initial, in pool order */
    private array $byFirstLetter = [];

    /** @var array<string,int> "letter:label" => how far into that letter's bucket the scan has reached */
    private array $firstLetterCursors = [];

    private string $fillerExcludedInitial;

    public function __construct(string $wordlistPath, string $fillerExcludedInitial = '')
    {
        $lines = file($wordlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Could not read wordlist at {$wordlistPath}");
        }
        $this->pool = Rng::shuffle($lines, self::POOL_SHUFFLE_SEED);
        $this->fillerExcludedInitial = $fillerExcludedInitial;
    }

    /** Reserve a specific literal word (a clue or the answer). Errors if already placed. */
    public function take(string $word): string
    {
        if (isset($this->used[$word])) {
            throw new \RuntimeException("Word '{$word}' was already placed elsewhere in the book.");
        }
        $this->used[$word] = true;
        return $word;
    }

    /**
     * Claim every word that's needed at a specific cell later, BEFORE any filler is
     * handed out. Without this, a filler cell earlier in reading order can be given a
     * word the reveal was relying on -- the answer and the revealed words all survive
     * every spelling rule, which is exactly what makes them eligible as filler for the
     * cells that also have to survive everything.
     *
     * @param string[] $words
     */
    public function reserveAll(array $words): void
    {
        foreach ($words as $word) {
            if (isset($this->used[$word])) {
                throw new \RuntimeException(
                    "'{$word}' is needed in two places at once -- each word can only appear once in the book."
                );
            }
            $this->used[$word] = true;
        }
    }

    /**
     * An unused word for which $predicate is true -- the generic "build()"
     * side of a spelling-only rule. $label just names this predicate so its
     * scan position can be remembered between calls; it doesn't need to
     * match a rule's type string, but using that name is the obvious
     * convention.
     *
     * @param callable(string): bool $predicate
     */
    public function fillerMatching(callable $predicate, string $label): string
    {
        $pos = $this->cursors[$label] ?? 0;
        $poolSize = count($this->pool);

        while ($pos < $poolSize) {
            $word = $this->pool[$pos];
            $pos++;

            if (isset($this->used[$word])) {
                continue;
            }
            if ($this->fillerExcludedInitial !== '' && $word[0] === $this->fillerExcludedInitial) {
                continue;
            }
            if (!$predicate($word)) {
                continue;
            }

            $this->used[$word] = true;
            $this->cursors[$label] = $pos;
            return $word;
        }

        $this->cursors[$label] = $pos;
        throw new \RuntimeException(
            "Ran out of unused words matching '{$label}'"
            . ($this->fillerExcludedInitial !== '' ? " (excluding ones starting with '{$this->fillerExcludedInitial}')." : '.')
        );
    }

    /** An unused palindrome -- dies at the "crosses out palindromes" rule. */
    public function fillerPalindrome(): string
    {
        return $this->fillerMatching(static fn (string $w): bool => WordTags::isPalindrome($w), 'palindrome');
    }

    /** An unused non-palindrome word with three consonants in a row. */
    public function fillerConsonantRun(): string
    {
        return $this->fillerMatching(
            static fn (string $w): bool => !WordTags::isPalindrome($w) && WordTags::hasConsonantRun($w, 3),
            'consonant_run'
        );
    }

    /**
     * An unused word whose first letter is exactly $letter, and for which
     * $predicate is also true. Needed wherever a required first letter
     * varies from call to call (the right half of a matching-neighbour-ends
     * pair, a knight's-move anchor) -- fillerMatching()'s single scan
     * position per label doesn't work there, because a word list sorted
     * once can run past every remaining example of a rare letter before a
     * later call asks for it. This keeps one scan position per
     * letter-and-predicate instead, so each letter's own supply is used in
     * order regardless of what else has been requested in between.
     *
     * $label distinguishes DIFFERENT predicates asking for the SAME letter
     * (anchors try one predicate per spelling step against a single fixed
     * initial). Without it, one predicate running its letter's bucket dry
     * would strand every later caller using a different predicate on that
     * same letter, even though plenty of matching words remained.
     *
     * Unlike fillerMatching(), this does not apply filler_excluded_initial
     * -- the caller is asking for a specific letter on purpose (including
     * 'k', for anchors), so excluding it here would be self-defeating.
     *
     * @param callable(string): bool $predicate
     */
    public function fillerStartingWith(string $letter, callable $predicate, string $label = ''): string
    {
        if (!isset($this->byFirstLetter[$letter])) {
            $bucket = [];
            foreach ($this->pool as $word) {
                if ($word[0] === $letter) {
                    $bucket[] = $word;
                }
            }
            $this->byFirstLetter[$letter] = $bucket;
        }

        $cursorKey = $letter . ':' . $label;
        $bucket = $this->byFirstLetter[$letter];
        $pos = $this->firstLetterCursors[$cursorKey] ?? 0;
        $n = count($bucket);

        while ($pos < $n) {
            $word = $bucket[$pos];
            $pos++;

            if (isset($this->used[$word])) {
                continue;
            }
            if (!$predicate($word)) {
                continue;
            }

            $this->used[$word] = true;
            $this->firstLetterCursors[$cursorKey] = $pos;
            return $word;
        }

        $this->firstLetterCursors[$cursorKey] = $pos;
        throw new \RuntimeException("Ran out of unused words starting with '{$letter}'.");
    }
}
