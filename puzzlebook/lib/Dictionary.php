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
 * then using fillerFromPoolStartingWith() (below) wherever a specific first
 * letter is required.
 *
 * Every filler word also skips anything starting with the layout's
 * filler_excluded_initial (see config/layout.json), so the only words with
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
 *
 * fillerFromPool()/fillerFromPoolStartingWith() are the methods ChainBuilder
 * actually calls now (see their docblocks for why fillerMatching() alone
 * isn't safe at scale): the caller supplies its own candidate ARRAY --
 * already filtered to whatever's true for every word in it regardless of
 * which cell is asking -- and this class only ever remembers a FLOOR into
 * that array: the point before which every word is confirmed USED. A word
 * skipped because one particular cell's own quirk (its neighbours, what's
 * already on its page) rejected it is never mistaken for used, so the floor
 * never advances past it, and a different cell sharing the same label still
 * gets to see it. Only "used" is permanent and global; everything else a
 * predicate might reject is a property of ONE call, not of the word.
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

    /** @var array<string,int> label => how far into a CALLER-SUPPLIED pool array every word is confirmed used */
    private array $poolFloors = [];

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

    /**
     * Forget everything that has been handed out, ready for another build attempt.
     *
     * The pool itself is untouched -- reading and shuffling 133,000 words is the
     * expensive part, and it gives the same answer every time. Only what's been USED
     * and how far each scan has got need clearing. Before this existed a fresh
     * Dictionary was built for every attempt, which re-read and re-shuffled the whole
     * list each go.
     */
    public function reset(): void
    {
        $this->used = [];
        $this->cursors = [];
        $this->poolFloors = [];
    }

    /**
     * The whole pool, in this Dictionary's own shuffled order -- so a caller building
     * a filtered pool of its own (ChainBuilder, per prefix) keeps the same
     * "spread across the alphabet" property as every other filler draw, instead of
     * handing fillerFromPool() an array in the word list's original mostly-alphabetical
     * order.
     *
     * @return string[]
     */
    public function rawPool(): array
    {
        return $this->pool;
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

    /**
     * An unused word from $pool for which $extraPredicate is true -- the fix for a bug
     * that's hit this project three times under fillerMatching(): a cell's own quirk
     * (its left/right neighbours, what's already on its page) makes $extraPredicate
     * reject a word that's perfectly fine for a DIFFERENT cell sharing the same $label,
     * and a cursor that advances past every rejection -- not just used words -- then
     * hides that word from every later cell forever, even though it was never actually
     * used by anyone.
     *
     * The fix: $this->poolFloors[$label] only ever advances past words this call
     * confirms are USED (checked fresh every call, since "used" only grows and is global
     * -- true for every label, not just this one). A word $extraPredicate rejects is
     * left exactly where it was for the next call, which might have a different cell,
     * different neighbours, a different page -- and so a different answer.
     *
     * $pool itself still has to be something every word in it is equally eligible for,
     * independent of which cell is asking (ChainBuilder passes the words that survive a
     * step's own prefix of spelling rules) -- anything that varies by CELL, not by
     * WORD, belongs in $extraPredicate instead, never baked into $pool.
     *
     * $pool is NOT filtered for filler_excluded_initial here, deliberately: a letter-
     * pinned pool (fillerFromPoolStartingWith()) may legitimately BE that excluded
     * letter -- a knight's-move anchor is allowed to start with it on purpose. Whichever
     * pool the caller builds has to already reflect whether the exclusion applies to it;
     * this method only ever enforces "used", the one thing true for every pool alike.
     *
     * @param string[] $pool
     * @param callable(string): bool $extraPredicate
     */
    public function fillerFromPool(array $pool, callable $extraPredicate, string $label): string
    {
        $n = count($pool);
        $floor = $this->poolFloors[$label] ?? 0;
        while ($floor < $n && isset($this->used[$pool[$floor]])) {
            $floor++;
        }

        for ($pos = $floor; $pos < $n; $pos++) {
            $word = $pool[$pos];
            if (isset($this->used[$word])) {
                continue;
            }
            if (!$extraPredicate($word)) {
                continue;
            }

            $this->used[$word] = true;
            $this->poolFloors[$label] = $floor;
            return $word;
        }

        $this->poolFloors[$label] = $floor;
        throw new \RuntimeException("Ran out of unused words matching '{$label}'.");
    }

    /**
     * Same fix as fillerFromPool(), for the case where a specific first letter is also
     * required (a knight's-move anchor, the right half of a matching-neighbour-ends
     * pair): $pool is expected to already be restricted to that letter. $letter is
     * folded into the floor's key, not just $label, because the letter a call pins can
     * vary between cells that otherwise share the same $label (a pair rule's "right
     * half" wants whatever letter ITS OWN left neighbour happened to end on) -- without
     * the letter in the key, two such calls would read and advance the same floor as if
     * they were offsets into the SAME array, when they were actually given two
     * different ones.
     *
     * @param string[] $pool
     * @param callable(string): bool $extraPredicate
     */
    public function fillerFromPoolStartingWith(string $letter, array $pool, callable $extraPredicate, string $label): string
    {
        return $this->fillerFromPool($pool, $extraPredicate, $letter . ':' . $label);
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
}
