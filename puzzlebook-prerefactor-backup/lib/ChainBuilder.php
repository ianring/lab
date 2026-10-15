<?php

declare(strict_types=1);

namespace Puzzlebook;

use Puzzlebook\Rules\SpellingRule;

/**
 * Builds the book: decides which rule is supposed to eliminate each cell,
 * then fills in real words that actually have the properties those decisions
 * require, then verifies the result by replaying the whole chain with Engine.
 *
 * THE CENTRAL IDEA -- keys first, victims derived.
 *
 * The knight's-move rule (step 7) is the hard one to construct, because it
 * reads "every surviving word a knight's move from a SURVIVING five-letter
 * word". The naive approach is to decide which words survive and then hunt
 * for a five-letter launch pad near each one. That does not work: it's a
 * global covering problem (the surviving set must cover itself), survivors
 * are sparse, and a cell has only 6-8 knight-neighbours, so most survivors
 * end up with nowhere to put a launch pad. Two earlier versions of this file
 * failed exactly that way.
 *
 * So this version inverts it. Place the KEYS (the five-letter launch pads)
 * first, wherever they like, and then DERIVE the set of words that survive
 * to step 7: it is precisely the keys, plus everything a knight's move from
 * a key, plus the answer. Nothing else is left alive that far, so there is
 * nothing to cover and nothing to search for. Every decision is local.
 *
 * The answer gets a protected zone: no key, and nothing a key would kill,
 * may sit within a knight's move of it. That's what guarantees the answer
 * is the one word step 7 can't touch.
 *
 * Because key placement is geometric, the exact number of survivors falls
 * out of where the keys land rather than being chosen up front. The
 * keep_fractions in config/chain.php therefore set the PACING of steps 1-5,
 * and the last spelling step absorbs whatever difference is needed to leave
 * exactly the right number alive.
 *
 * If a particular arrangement violates a constraint (a key landing where a
 * revealed word needs to go, say), that attempt is abandoned and another
 * seed is tried -- see ConstructionRetry. A build only fails after every
 * attempt fails, and then it names the constraint that blocked it most.
 */
final class ChainBuilder
{
    /** How many different arrangements to try before declaring the rule set unbuildable. */
    private const MAX_ATTEMPTS = 400;

    private const ANSWER_SHUFFLE_SEED = 31000;
    private const KEY_SHUFFLE_SEED = 32000;
    private const PAIR_SHUFFLE_SEED = 33000;
    private const STAGE_SHUFFLE_SEED = 34000;

    /** @return array{grid: array{pages:int,rows:int,cols:int}, words: string[], attempts: int} */
    public static function build(string $layoutPath, string $chainPath, string $wordlistPath): array
    {
        $layout = require $layoutPath;
        $chain = require $chainPath;
        $grid = new Grid($layout['grid']['pages'], $layout['grid']['rows'], $layout['grid']['cols']);

        $parsed = self::parseChain($chain);
        self::validateReservedWords($chain, $parsed);

        /** @var array<string,int> $retryReasons */
        $retryReasons = [];

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                $words = self::attempt($attempt, $grid, $parsed, $chain, $layout, $wordlistPath);
                self::verify($words, $grid, $chain);

                return [
                    'grid' => ['pages' => $grid->pages, 'rows' => $grid->rows, 'cols' => $grid->cols],
                    'words' => $words,
                    'attempts' => $attempt + 1,
                ];
            } catch (ConstructionRetry $e) {
                $reason = $e->getMessage();
                $retryReasons[$reason] = ($retryReasons[$reason] ?? 0) + 1;
            }
        }

        arsort($retryReasons);
        $detail = '';
        $shown = 0;
        foreach ($retryReasons as $reason => $n) {
            if ($shown++ >= 6) {
                $detail .= "\n  ... and " . (count($retryReasons) - 6) . ' other reason(s)';
                break;
            }
            $detail .= "\n  {$n}x  {$reason}";
        }
        throw new \RuntimeException(
            'Tried ' . self::MAX_ATTEMPTS . " arrangements and none satisfied every constraint. What blocked them:"
            . $detail
            . "\n\nRun bin/check.php for a breakdown of the arithmetic and word supply."
        );
    }

    // ------------------------------------------------------------------ one attempt

    /**
     * One complete arrangement: choose the answer's cell and the key cells, derive
     * everything else from them, then fill in words. Throws ConstructionRetry if this
     * arrangement can't work.
     *
     * @param array{0:array[],1:int,2:array,3:array,4:array,5:array} $parsed
     * @return string[]
     */
    private static function attempt(
        int $attempt,
        Grid $grid,
        array $parsed,
        array $chain,
        array $layout,
        string $wordlistPath
    ): array {
        [$spellingSteps, $spellingStepsBeforePair, $pairStep, $revealStep, $keyStep, $finalStep] = $parsed;

        $size = $grid->size();
        $answer = $chain['answer'];
        $revealWords = explode(' ', $revealStep['expected']);
        $revealN = $revealStep['reveal']['params']['n'];
        $keyLength = $keyStep['rule']['params']['length'];
        $keyEnding = $finalStep['rule']['params']['ending'];

        $spellingRules = array_map(
            static fn (array $step): SpellingRule => self::spellingRuleFor($step['rule']),
            $spellingSteps
        );
        $spellingCount = count($spellingRules);

        // The reveal reads survivors 4, 8, ... up to 11 of them, so the surviving set
        // has to reach at least that far. A little slack past it makes the last slots
        // easier to satisfy.
        $slotsNeeded = $revealN * count($revealWords);
        $targetAlive = $slotsNeeded + 8;

        // --- 1. The answer's cell, and its protected zone. ---

        $answerOrder = Rng::shuffle(range(0, $size - 1), self::ANSWER_SHUFFLE_SEED + $attempt);
        $answerIndex = $answerOrder[0];

        $protectedZone = [];
        foreach ($grid->knightNeighbours($answerIndex) as $n) {
            $protectedZone[$n] = true;
        }

        // --- 2. Key cells. A key is allowed only if neither it nor anything it would
        //        kill sits in the answer's protected zone. ---

        $keyOrder = Rng::shuffle(range(0, $size - 1), self::KEY_SHUFFLE_SEED + $attempt);

        /** @var array<int,bool> $isKey */
        $isKey = [];
        /** @var array<int,bool> $covered cells a knight's move from some key */
        $covered = [];
        /** @var array<int,bool> $aliveSet the union of the two -- a key can also be covered by another key */
        $aliveSet = [];

        foreach ($keyOrder as $candidate) {
            if (count($aliveSet) + 1 >= $targetAlive) {
                break;
            }
            if ($candidate === $answerIndex || isset($protectedZone[$candidate]) || isset($isKey[$candidate])) {
                continue;
            }

            $neighbours = $grid->knightNeighbours($candidate);
            // A cell near a page edge covers too little to be worth spending a key on.
            if (count($neighbours) < 5) {
                continue;
            }
            $safe = true;
            foreach ($neighbours as $n) {
                if ($n === $answerIndex || isset($protectedZone[$n])) {
                    $safe = false;
                    break;
                }
            }
            if (!$safe) {
                continue;
            }

            $isKey[$candidate] = true;
            $aliveSet[$candidate] = true;
            foreach ($neighbours as $n) {
                $covered[$n] = true;
                $aliveSet[$n] = true;
            }
        }

        // --- 3. Derive the surviving set: keys + what they cover + the answer. ---

        /** @var array<int,string|int> $role */
        $role = array_fill(0, $size, null);
        foreach ($covered as $i => $_) {
            $role[$i] = 'victim';
        }
        foreach ($isKey as $i => $_) {
            $role[$i] = 'key';   // a key that another key covers is still a key
        }
        $role[$answerIndex] = 'answer';

        $alive = [];
        for ($i = 0; $i < $size; $i++) {
            if ($role[$i] !== null) {
                $alive[] = $i;
            }
        }

        if (count($alive) < $slotsNeeded) {
            throw new ConstructionRetry(
                'the keys only kept ' . count($alive) . " words alive, but the reveal needs {$slotsNeeded}"
            );
        }

        // --- 4. The revealed words go in the slots the reveal will read. ---

        $placed = 0;
        foreach ($alive as $zeroBased => $i) {
            if ($placed >= count($revealWords)) {
                break;
            }
            if (($zeroBased + 1) % $revealN !== 0) {
                continue;
            }
            if ($role[$i] !== 'victim') {
                throw new ConstructionRetry(
                    "a reveal slot landed on a '{$role[$i]}' cell, which can't hold a revealed word"
                );
            }
            $placed++;
            $role[$i] = 'reveal' . $placed;
        }
        if ($placed !== count($revealWords)) {
            throw new ConstructionRetry("only {$placed} of " . count($revealWords) . ' reveal slots were available');
        }

        // --- 5. Share the remaining cells out among steps 1-5. The surviving count is
        //        fixed by the geometry above, so keep_fractions set the pacing and the
        //        last spelling step takes up the slack. ---

        $filler = $size - count($alive);
        [$counts, $nPairs] = self::planCounts($size, $spellingSteps, $pairStep);

        $lastSpellingId = $spellingSteps[$spellingCount - 1]['id'];
        $counts[$lastSpellingId] += $filler - (array_sum($counts) + 2 * $nPairs);
        if ($counts[$lastSpellingId] < 0) {
            throw new ConstructionRetry(
                "step {$lastSpellingId} would have to remove a negative number of words -- the earlier "
                . 'keep_fractions already remove more than the grid has to spare'
            );
        }

        // --- 6. Deliberate matching-neighbour pairs, chosen from cells that die anyway. ---

        $pairCandidates = [];
        for ($page = 0; $page < $grid->pages; $page++) {
            for ($row = 0; $row < $grid->rows; $row++) {
                foreach ([0, 2, 4] as $col0) {
                    if ($col0 + 1 >= $grid->cols) {
                        continue;
                    }
                    $left = $grid->linearIndex($page, $row, $col0);
                    $right = $grid->linearIndex($page, $row, $col0 + 1);
                    if ($role[$left] === null && $role[$right] === null) {
                        $pairCandidates[] = [$left, $right];
                    }
                }
            }
        }
        $pairCandidates = Rng::shuffle($pairCandidates, self::PAIR_SHUFFLE_SEED + $attempt);
        if ($nPairs > count($pairCandidates)) {
            throw new ConstructionRetry(
                "step {$pairStep['id']} needs {$nPairs} side-by-side pairs of doomed cells, but only "
                . count($pairCandidates) . ' such slots are free'
            );
        }

        /** @var array<int, array{0:string,1:int}> $pairRole */
        $pairRole = [];
        foreach (array_slice($pairCandidates, 0, $nPairs) as $pair) {
            [$left, $right] = $pair;
            $pairRole[$left] = ['left', $right];
            $pairRole[$right] = ['right', $left];
            $role[$left] = 'pair';
            $role[$right] = 'pair';
        }

        // --- 7. Everything still unassigned dies at one of the spelling steps. ---

        $tags = [];
        foreach ($spellingSteps as $step) {
            for ($n = 0; $n < $counts[$step['id']]; $n++) {
                $tags[] = $step['id'];
            }
        }
        $tags = Rng::shuffle($tags, self::STAGE_SHUFFLE_SEED + $attempt);

        $t = 0;
        for ($i = 0; $i < $size; $i++) {
            if ($role[$i] !== null) {
                continue;
            }
            if ($t >= count($tags)) {
                throw new ConstructionRetry('ran out of spelling-step slots before every cell had a role');
            }
            $role[$i] = $tags[$t++];
        }
        if ($t !== count($tags)) {
            throw new ConstructionRetry((count($tags) - $t) . ' spelling-step slots had no cell to go in');
        }

        // --- 8. Fill in real words, in reading order. ---

        return self::fillWords(
            $grid,
            $role,
            $pairRole,
            new Dictionary($wordlistPath, $layout['filler_excluded_initial'] ?? ''),
            $spellingSteps,
            $spellingRules,
            $spellingStepsBeforePair,
            $answer,
            $revealWords,
            $keyLength,
            $keyEnding
        );
    }

    // ------------------------------------------------------------------ word filling

    /**
     * @param array<int,string|int> $role
     * @param array<int, array{0:string,1:int}> $pairRole
     * @param array[] $spellingSteps
     * @param SpellingRule[] $spellingRules
     * @param string[] $revealWords
     * @return string[]
     */
    private static function fillWords(
        Grid $grid,
        array $role,
        array $pairRole,
        Dictionary $dictionary,
        array $spellingSteps,
        array $spellingRules,
        int $spellingStepsBeforePair,
        string $answer,
        array $revealWords,
        int $keyLength,
        string $keyEnding
    ): array {
        $size = $grid->size();
        $spellingCount = count($spellingRules);
        $words = array_fill(0, $size, null);

        // Claim the answer and the revealed words before any filler is handed out --
        // they survive every spelling rule, so they'd otherwise be valid filler for a
        // key or victim cell and get used up before the cell that needs them.
        $dictionary->reserveAll(array_merge([$answer], $revealWords));

        $spellingIndexById = [];
        foreach ($spellingSteps as $index => $step) {
            $spellingIndexById[$step['id']] = $index;
        }

        for ($i = 0; $i < $size; $i++) {
            $r = $role[$i];
            [, , $col] = $grid->coordsOf($i);
            $isPairLeft = isset($pairRole[$i]) && $pairRole[$i][0] === 'left';
            $isPairRight = isset($pairRole[$i]) && $pairRole[$i][0] === 'right';

            // Don't accidentally recreate a matching-neighbour pair: a word must not
            // start with the letter its left neighbour ended on (except where the pair
            // is deliberate), nor end on the letter its right neighbour must start with.
            $leftLastLetter = null;
            if ($col > 0 && $words[$i - 1] !== null && !$isPairRight) {
                $leftLastLetter = WordTags::lastLetter($words[$i - 1]);
            }
            $forbiddenLast = null;
            if ($col < $grid->cols - 1 && !$isPairLeft) {
                $forbiddenLast = self::requiredFirstLetter($role[$i + 1] ?? null, $answer, $revealWords);
            }

            if (isset($spellingIndexById[$r])) {
                $words[$i] = self::fillerDyingAt(
                    $dictionary,
                    $spellingRules,
                    $spellingIndexById[$r],
                    "stage{$r}",
                    $leftLastLetter,
                    $forbiddenLast
                );
                continue;
            }

            switch (true) {
                case $r === 'answer':
                    // Already reserved above, so it's assigned directly rather than taken.
                    if ($leftLastLetter !== null && $answer[0] === $leftLastLetter) {
                        throw new ConstructionRetry('the answer would pair with its left neighbour');
                    }
                    $words[$i] = $answer;
                    break;

                case is_string($r) && str_starts_with($r, 'reveal'):
                    $word = $revealWords[(int) substr($r, strlen('reveal')) - 1];
                    if ($leftLastLetter !== null && $word[0] === $leftLastLetter) {
                        throw new ConstructionRetry("the revealed word '{$word}' would pair with its left neighbour");
                    }
                    $words[$i] = $word;
                    break;

                case $r === 'key':
                    // A launch pad: exactly $keyLength letters, ending in $keyEnding, and it
                    // must survive every spelling step so it's still standing at step 7.
                    $words[$i] = $dictionary->fillerMatching(
                        static function (string $w) use (
                            $spellingRules,
                            $spellingCount,
                            $keyLength,
                            $keyEnding,
                            $leftLastLetter,
                            $forbiddenLast
                        ): bool {
                            if (strlen($w) !== $keyLength || WordTags::lastLetter($w) !== $keyEnding) {
                                return false;
                            }
                            if ($leftLastLetter !== null && $w[0] === $leftLastLetter) {
                                return false;
                            }
                            if ($forbiddenLast !== null && WordTags::lastLetter($w) === $forbiddenLast) {
                                return false;
                            }
                            return self::survivesThrough($spellingRules, $w, $spellingCount);
                        },
                        'key'
                    );
                    break;

                case $r === 'victim':
                    // Survives to step 7, where a key kills it. Kept clear of the launch-pad
                    // shape so the only words step 8 removes are the keys themselves.
                    $words[$i] = $dictionary->fillerMatching(
                        static function (string $w) use (
                            $spellingRules,
                            $spellingCount,
                            $keyLength,
                            $keyEnding,
                            $leftLastLetter,
                            $forbiddenLast
                        ): bool {
                            if (strlen($w) === $keyLength && WordTags::lastLetter($w) === $keyEnding) {
                                return false;
                            }
                            if ($leftLastLetter !== null && $w[0] === $leftLastLetter) {
                                return false;
                            }
                            if ($forbiddenLast !== null && WordTags::lastLetter($w) === $forbiddenLast) {
                                return false;
                            }
                            return self::survivesThrough($spellingRules, $w, $spellingCount);
                        },
                        'victim'
                    );
                    break;

                case $r === 'pair':
                    [$side, $partner] = $pairRole[$i];
                    if ($side === 'left') {
                        $words[$i] = $dictionary->fillerMatching(
                            static function (string $w) use ($spellingRules, $spellingStepsBeforePair, $leftLastLetter): bool {
                                if ($leftLastLetter !== null && $w[0] === $leftLastLetter) {
                                    return false;
                                }
                                return self::survivesThrough($spellingRules, $w, $spellingStepsBeforePair);
                            },
                            'pairleft'
                        );
                    } else {
                        if ($words[$partner] === null) {
                            throw new \RuntimeException("Pair partner at {$partner} wasn't filled before {$i}.");
                        }
                        $needed = WordTags::lastLetter($words[$partner]);
                        $words[$i] = $dictionary->fillerStartingWith(
                            $needed,
                            static function (string $w) use ($spellingRules, $spellingStepsBeforePair, $forbiddenLast): bool {
                                if ($forbiddenLast !== null && WordTags::lastLetter($w) === $forbiddenLast) {
                                    return false;
                                }
                                return self::survivesThrough($spellingRules, $w, $spellingStepsBeforePair);
                            },
                            'pairright'
                        );
                    }
                    break;

                default:
                    throw new \RuntimeException("Unhandled construction role '{$r}' at linear index {$i}.");
            }
        }

        return $words;
    }

    // ------------------------------------------------------------------ chain parsing

    /**
     * Splits a chain's steps into the shapes the builder knows how to construct.
     * Public so bin/check.php can validate a chain's structure the same way.
     *
     * @return array{0: array[], 1: int, 2: array, 3: array, 4: array, 5: array}
     *     [spellingSteps, spellingStepsBeforePair, pairStep, revealStep, keyStep, finalStep]
     */
    public static function parseChain(array $chain): array
    {
        $spellingSteps = [];
        $spellingStepsBeforePair = null;
        $pairStep = null;
        $revealStep = null;
        $keyStep = null;
        $finalStep = null;

        foreach ($chain['steps'] as $step) {
            if ($step['kind'] === 'reveal') {
                $revealStep = $step;
                continue;
            }

            $construction = $step['construction'] ?? null;

            if ($construction !== null && !empty($construction['keys'])) {
                $keyStep = $step;
                continue;
            }
            if (!empty($step['final'])) {
                $finalStep = $step;
                continue;
            }
            if ($construction !== null && !empty($construction['pairs'])) {
                $pairStep = $step;
                $spellingStepsBeforePair = count($spellingSteps);
                continue;
            }
            if ($construction !== null) {
                $spellingSteps[] = $step;
                continue;
            }

            throw new \RuntimeException(
                "Step {$step['id']} is an eliminate step with no 'construction' key, so the builder "
                . "doesn't know how to place its words. Give it a construction.keep_fraction (for a "
                . "spelling rule), construction.pairs (for the side-by-side rule), or "
                . "construction.keys (for the knight's-move rule) -- or mark it 'final' => true."
            );
        }

        $missing = [];
        if ($spellingSteps === []) {
            $missing[] = 'at least one spelling step (construction.keep_fraction)';
        }
        if ($pairStep === null) {
            $missing[] = 'one pair step (construction.pairs => true)';
        }
        if ($revealStep === null) {
            $missing[] = 'one reveal step';
        }
        if ($keyStep === null) {
            $missing[] = "one knight's-move step (construction.keys => true)";
        }
        if ($finalStep === null) {
            $missing[] = "one step marked 'final' => true";
        }
        if ($missing !== []) {
            throw new \RuntimeException('config/chain.php is missing ' . implode('; ', $missing) . '.');
        }

        return [$spellingSteps, $spellingStepsBeforePair, $pairStep, $revealStep, $keyStep, $finalStep];
    }

    /**
     * How many cells each step would remove, from its keep_fraction alone. This sets the
     * PACING; attempt() then adjusts the last spelling step so the exact number of
     * survivors matches what the key geometry produced.
     *
     * Shared with bin/check.php so the two always agree about the arithmetic.
     *
     * @param array[] $spellingSteps
     * @return array{0: array<int,int>, 1: int} [removalsByStepId, pairsNeeded]
     */
    public static function planCounts(int $size, array $spellingSteps, array $pairStep): array
    {
        $counts = [];
        $remaining = $size;
        foreach ($spellingSteps as $step) {
            $c = (int) round($remaining * (1 - $step['construction']['keep_fraction']));
            $counts[$step['id']] = $c;
            $remaining -= $c;
        }

        $pairRemove = (int) round($remaining * (1 - $pairStep['construction']['keep_fraction']));

        return [$counts, intdiv($pairRemove, 2)];
    }

    /** @param array{0:array[],1:int,2:array,3:array,4:array,5:array} $parsed */
    private static function validateReservedWords(array $chain, array $parsed): void
    {
        [$spellingSteps, , , $revealStep, $keyStep, $finalStep] = $parsed;

        $spellingRules = array_map(
            static fn (array $step): SpellingRule => self::spellingRuleFor($step['rule']),
            $spellingSteps
        );

        $answer = $chain['answer'];
        $revealWords = explode(' ', $revealStep['expected']);

        $declared = $revealStep['reveal']['params']['count'] ?? null;
        if ($declared !== null && $declared !== count($revealWords)) {
            throw new \RuntimeException(
                "Step {$revealStep['id']} declares count={$declared} but 'expected' splits into "
                . count($revealWords) . ' words.'
            );
        }

        $seen = [];
        foreach ($revealWords as $w) {
            if (isset($seen[$w])) {
                throw new \RuntimeException(
                    "The revealed phrase uses '{$w}' twice, but each word can only be placed in the book "
                    . 'once. Reword the phrase so every word in it is different.'
                );
            }
            $seen[$w] = true;
        }

        foreach (array_merge([$answer], $revealWords) as $word) {
            foreach ($spellingSteps as $index => $step) {
                if ($spellingRules[$index]->failsWord($word)) {
                    throw new \RuntimeException(
                        "'{$word}' has to survive every spelling rule to still be there when it's needed, "
                        . "but step {$step['id']} crosses it out. Reword it, or loosen that rule."
                    );
                }
            }
        }

        // The answer is the last word standing, so the final rule must not match it.
        $finalRule = self::spellingRuleFor($finalStep['rule']);
        if ($finalRule->failsWord($answer)) {
            throw new \RuntimeException(
                "The answer '{$answer}' is crossed out by the final step {$finalStep['id']}, so nothing would "
                . 'be left standing. Pick an answer that rule does not match.'
            );
        }

        // Keys are five-letter words ending in E *because* that's what the final rule
        // clears. If those two drift apart, the keys outlive the book.
        $keyLength = $keyStep['rule']['params']['length'] ?? null;
        $finalLength = $finalStep['rule']['params']['length'] ?? null;
        $finalEnding = $finalStep['rule']['params']['ending'] ?? null;
        if ($keyLength === null) {
            throw new \RuntimeException(
                "Step {$keyStep['id']} is the knight's-move step, so it needs params.length -- the length of "
                . 'the words it launches from.'
            );
        }
        if ($finalLength === null || $finalEnding === null) {
            throw new \RuntimeException(
                "Step {$finalStep['id']} is the final step, so it has to be the rule that clears the "
                . "knight's-move launch pads: it needs params.length and params.ending (the shape of those "
                . 'launch-pad words). Only a length_and_ending rule currently fits.'
            );
        }
        if ($keyLength !== $finalLength) {
            throw new \RuntimeException(
                "Step {$keyStep['id']} launches from {$keyLength}-letter words, but the final step "
                . "{$finalStep['id']} clears {$finalLength}-letter ones. They have to be the same length, "
                . "or the launch pads are never removed."
            );
        }
    }

    // ------------------------------------------------------------------ word predicates

    /**
     * Resolves a step's 'rule' config to the same SpellingRule instance the solver uses --
     * its failsWord() is this builder's word-selection predicate too, so a rule's "how it's
     * applied" and "how it's constructed" live in exactly one place (lib/Rules/*).
     * Public so bin/check.php can test candidate words against the chain's rules.
     */
    public static function spellingRuleFor(array $rule): SpellingRule
    {
        $instance = Engine::ruleFromConfig($rule);
        if (!$instance instanceof SpellingRule) {
            throw new \InvalidArgumentException(
                "Rule type '{$rule['type']}' isn't a pure spelling check, so it can't be used as one of "
                . "ChainBuilder's construction.keep_fraction steps (or as the final step)."
            );
        }
        return $instance;
    }

    /** @param SpellingRule[] $spellingRules True if $word passes the first $through spelling steps. */
    private static function survivesThrough(array $spellingRules, string $word, int $through): bool
    {
        for ($j = 0; $j < $through; $j++) {
            if ($spellingRules[$j]->failsWord($word)) {
                return false;
            }
        }
        return true;
    }

    /** @param SpellingRule[] $spellingRules A word that passes every step before $stepIndex and fails that one. */
    private static function fillerDyingAt(
        Dictionary $dictionary,
        array $spellingRules,
        int $stepIndex,
        string $label,
        ?string $forbiddenFirst,
        ?string $forbiddenLast
    ): string {
        $rule = $spellingRules[$stepIndex];
        return $dictionary->fillerMatching(
            static function (string $w) use ($spellingRules, $stepIndex, $rule, $forbiddenFirst, $forbiddenLast): bool {
                if ($forbiddenFirst !== null && $w[0] === $forbiddenFirst) {
                    return false;
                }
                if ($forbiddenLast !== null && WordTags::lastLetter($w) === $forbiddenLast) {
                    return false;
                }
                return self::survivesThrough($spellingRules, $w, $stepIndex) && $rule->failsWord($w);
            },
            $label
        );
    }

    /**
     * The first letter the cell at this role is going to start with, where that's already
     * decided -- so the word to its left can avoid ending on it.
     *
     * @param string[] $revealWords
     */
    private static function requiredFirstLetter($role, string $answer, array $revealWords): ?string
    {
        if ($role === 'answer') {
            return $answer[0];
        }
        if (is_string($role) && str_starts_with($role, 'reveal')) {
            return $revealWords[(int) substr($role, strlen('reveal')) - 1][0];
        }
        return null;
    }

    // ------------------------------------------------------------------ verification

    /**
     * Re-plays the whole chain with Engine and throws on the first thing that doesn't
     * match: a reveal spelling the wrong text, or the final step leaving the wrong
     * number of words (or the wrong word). This is the independent check on everything
     * above -- construction decides, this confirms.
     *
     * @param string[] $words
     */
    private static function verify(array $words, Grid $grid, array $chain): void
    {
        $keep = array_fill(0, count($words), true);

        foreach ($chain['steps'] as $step) {
            if ($step['kind'] === 'eliminate') {
                $keep = Engine::applyRule($words, $grid, $keep, $step['rule']);

                if (!empty($step['final'])) {
                    $survivors = Engine::survivingIndices($keep);
                    if (count($survivors) !== 1) {
                        $survivorWords = array_map(static fn (int $i): string => $words[$i], $survivors);
                        throw new ConstructionRetry(
                            'the final step left ' . count($survivors) . ' words instead of 1'
                            . (count($survivors) <= 6 && $survivors !== []
                                ? ' (' . implode(', ', $survivorWords) . ')'
                                : '')
                        );
                    }
                    if ($words[$survivors[0]] !== $chain['answer']) {
                        throw new ConstructionRetry(
                            "the last word standing was '{$words[$survivors[0]]}', not '{$chain['answer']}'"
                        );
                    }
                }
                continue;
            }

            $revealed = Engine::computeReveal($words, $grid, $keep, $step['reveal'])['text'];
            if ($revealed !== $step['expected']) {
                throw new ConstructionRetry(
                    "step {$step['id']}'s reveal read '{$revealed}' instead of the expected phrase"
                );
            }
        }
    }
}
