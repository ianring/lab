<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/Fixtures/TinyBoard.php';
require __DIR__ . '/Rules/FixedLengthRule.php';
require __DIR__ . '/Rules/NoSharedFirstLetterConstraint.php';
require __DIR__ . '/Rules/NoSharedFirstLetterPairRule.php';
require __DIR__ . '/Rules/FixedGroupsRule.php';
require __DIR__ . '/Rules/ExactAdjacentPairRule.php';

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Board\HorizontalAdjacency;
use Puzzlebook2\Board\VerticalAdjacency;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Chain\Engine;
use Puzzlebook2\Feasibility\FeasibilityAnalyzer;
use Puzzlebook2\Rules\GeometricRules\CompoundTripleOnPageRule;
use Puzzlebook2\Solver\Board;
use Puzzlebook2\Solver\ReservationQueue;
use Puzzlebook2\Solver\ReservationSlot;
use Puzzlebook2\Solver\SolveOutcome;
use Puzzlebook2\Solver\Solver;
use Puzzlebook2Tests\Fixtures\TinyBoard;
use Puzzlebook2Tests\Rules\ExactAdjacentPairRule;
use Puzzlebook2Tests\Rules\FixedGroupsRule;
use Puzzlebook2Tests\Rules\FixedLengthRule;
use Puzzlebook2Tests\Rules\NoSharedFirstLetterPairRule;

$failures = 0;

function line(string $s): void
{
    echo $s . "\n";
}

function check(bool $ok, string $what): void
{
    global $failures;
    if ($ok) {
        line("  OK: {$what}");
    } else {
        $failures++;
        line("  FAIL: {$what}");
    }
}

// --- Scenario 1: happy path -----------------------------------------
// 4 rows x 3 cols x 1 page = 12 cells. 6 single cells need a 4-letter
// word; 3 vertically-adjacent pairs need 3-letter words, with a
// cross-rule constraint forbidding a first-letter clash with a neighbour.
line('=== Scenario 1: happy path ===');

$spec = new BoardSpec(pages: 1, rows: 4, cols: 3);
$vertical = new VerticalAdjacency();

$fourLetter = ['tree', 'frog', 'blue', 'gold', 'pink', 'navy', 'jade', 'peak'];
$threeLetter = ['cat', 'dog', 'sun', 'ice', 'owl', 'fox', 'bee', 'ant'];

$rules = [
    new FixedLengthRule('len4', 4, 6, $fourLetter),
    new NoSharedFirstLetterPairRule('pair3', 3, $threeLetter, $vertical, $spec),
];

[$board, $queue, $ctx] = TinyBoard::build($spec, $rules, seed: 42);

$feasibility = (new FeasibilityAnalyzer())->check($rules, $ctx);
check($feasibility->isFeasible(), 'analyzer finds no problems');

$result = (new Solver())->solve($board, $queue);
check($result->isSuccess(), "solved (steps {$result->stepsTaken}, backtracks {$result->backtracks})");
if ($result->isSuccess()) {
    foreach ($spec->allCells() as $cell) {
        [$page, $row, $col] = $spec->coordsOf($cell);
        line(sprintf('    [%d,%d,%d] = %s', $page, $row, $col, $result->board->wordAt($cell)));
    }
    $words = $result->board->allWords();
    check(count($words) === count(array_unique($words)), 'no duplicate words');

    // The rule's own invariant, verified independently: no cell vertically
    // adjacent to a pair cell (outside the pair itself) shares the pair
    // word's first letter.
    $violations = 0;
    foreach ($spec->allCells() as $cell) {
        if (strlen($result->board->wordAt($cell)) !== 3) {
            continue;
        }
        foreach ($vertical->neighboursOf($cell, $spec) as $n) {
            $nw = $result->board->wordAt($n);
            if (strlen($nw) === 3) {
                continue; // the partner (or another pair cell) -- exempt
            }
            if ($nw[0] === $result->board->wordAt($cell)[0]) {
                $violations++;
            }
        }
    }
    check($violations === 0, 'pair-rule invariant holds on the finished board');
}

// --- Scenario 2: genuine supply shortfall ----------------------------
// The 4-letter pool only has 2 words for 6 slots -- caught BEFORE the
// solver ever runs.
line('');
line('=== Scenario 2: supply shortfall (caught analytically) ===');

$scarceRules = [
    new FixedLengthRule('len4-scarce', 4, 6, ['tree', 'frog']),
    new NoSharedFirstLetterPairRule('pair3', 3, $threeLetter, $vertical, $spec),
];

[, , $ctx2] = TinyBoard::build($spec, $scarceRules, seed: 42);
$feasibility2 = (new FeasibilityAnalyzer())->check($scarceRules, $ctx2);
check(!$feasibility2->isFeasible(), 'analyzer reports the shortfall');
foreach ($feasibility2->problems() as $p) {
    line('    - ' . $p);
}

// --- Scenario 3: structural (geometric) deadlock ----------------------
// 4 cells in a row. Rule "needs-0-and-1" demands BOTH of its single-cell
// groups {0} and {1}; rule "needs-0-and-3" has exactly one group, {0,3}.
// Cell 0 is unavoidably double-booked. Pools are plentiful and the cell
// budget balances, so the analyzer can't see it -- only the solver's
// systematic search can, by exhausting every alternative back to the
// empty board. Before the search was systematic this burned the whole
// step budget and returned Inconclusive; now it must be a PROOF.
line('');
line('=== Scenario 3: structural deadlock (must be a proof, not a timeout) ===');

$deadlockSpec = new BoardSpec(pages: 1, rows: 1, cols: 4);
$fillerWords = ['abcd', 'efgh', 'ijkl', 'mnop', 'qrst', 'uvwx'];

$deadlockRules = [
    new FixedGroupsRule('needs-0-and-1', [['cell' => 0], ['cell' => 1]], 2, $fillerWords),
    new FixedGroupsRule('needs-0-and-3', [['a' => 0, 'b' => 3]], 1, $fillerWords),
];

[$board3, $queue3, $ctx3] = TinyBoard::build($deadlockSpec, $deadlockRules, seed: 7);
$feasibility3 = (new FeasibilityAnalyzer())->check($deadlockRules, $ctx3);
check($feasibility3->isFeasible(), 'analyzer (correctly) cannot see this conflict');

$result3 = (new Solver())->solve($board3, $queue3);
check(
    $result3->outcome === SolveOutcome::Infeasible,
    "solver PROVES infeasibility (outcome {$result3->outcome->name}, steps {$result3->stepsTaken}, backtracks {$result3->backtracks})",
);
line('    reason: ' . ($result3->reason ?? '-'));

// --- Scenario 4: recoverable collision -> successful backtrack --------
// Pair rule P commits {0,1} or {2,3}; rule Q needs exactly cell {0}. When
// P's shuffle picks {0,1} first, Q conflicts and the solver must undo P,
// resume at P's NEXT candidate ({2,3}), and finish. Run across 10 seeds:
// every one must solve, and at least one must have taken that backtrack
// path (both shuffle orders occur across 10 seeds).
line('');
line('=== Scenario 4: recoverable collision (backtrack must recover) ===');

$spec4 = new BoardSpec(pages: 1, rows: 1, cols: 4);
$solvedAll = true;
$backtrackRuns = 0;
foreach (range(1, 10) as $seed4) {
    $rules4 = [
        new FixedGroupsRule('P-pair', [['a' => 0, 'b' => 1], ['a' => 2, 'b' => 3]], 1, ['echo', 'lima', 'kilo', 'golf']),
        new FixedGroupsRule('Q-zero', [['c' => 0]], 1, ['alpha', 'bravo', 'delta', 'hotel', 'oscar']),
        new FixedLengthRule('R-fill', 4, 1, ['tango', 'romeo', 'sierra', 'victor', 'whisky', 'zulu']),
    ];
    [$b4, $q4, $c4] = TinyBoard::build($spec4, $rules4, seed: $seed4);
    $r4 = (new Solver())->solve($b4, $q4);
    if (!$r4->isSuccess()) {
        $solvedAll = false;
        line("    seed {$seed4}: {$r4->outcome->name} -- {$r4->reason}");
        continue;
    }
    if ($r4->backtracks > 0) {
        $backtrackRuns++;
    }
}
check($solvedAll, 'all 10 seeds solved');
check($backtrackRuns > 0, "at least one seed recovered via backtracking ({$backtrackRuns}/10 did)");

// --- Scenario 5: constraint vs existing assignments + deep recovery ---
// 3x2 board, 6 cells. Filler (4 words, incl. 'tree') resolves FIRST (its
// pool is smaller), claiming 4 cells. The pair rule then needs a free
// vertical pair, and its constraint forbids any vertical neighbour
// starting with 't' -- every pair word starts with 't', and so does
// 'tree'. The solver must backtrack through filler placements until the
// two free cells form a vertical pair AND 'tree' isn't vertically
// adjacent to it, with the new commit-time validation rejecting every
// arrangement where 'tree' already sits next door.
line('');
line('=== Scenario 5: constraint validated against existing words ===');

$spec5 = new BoardSpec(pages: 1, rows: 3, cols: 2);
$rules5 = [
    new FixedLengthRule('len4', 4, 4, ['tree', 'gold', 'pink', 'navy']),
    new NoSharedFirstLetterPairRule('t-pair', 1, ['tip', 'tan', 'toe', 'tag', 'tub', 'ten'], $vertical, $spec5),
];

[$b5, $q5, $c5] = TinyBoard::build($spec5, $rules5, seed: 11);
check((new FeasibilityAnalyzer())->check($rules5, $c5)->isFeasible(), 'analyzer finds no problems');

$r5 = (new Solver())->solve($b5, $q5);
check($r5->isSuccess(), "solved (steps {$r5->stepsTaken}, backtracks {$r5->backtracks})");
if ($r5->isSuccess()) {
    $pairCells = [];
    foreach ($spec5->allCells() as $cell) {
        if (strlen($r5->board->wordAt($cell)) === 3) {
            $pairCells[] = $cell;
        }
    }
    check(count($pairCells) === 2, 'exactly one pair placed');
    $adjacent = in_array($pairCells[1], $vertical->neighboursOf($pairCells[0], $spec5), true);
    check($adjacent, 'pair cells are vertically adjacent');

    $tViolations = 0;
    foreach ($pairCells as $cell) {
        foreach ($vertical->neighboursOf($cell, $spec5) as $n) {
            if (in_array($n, $pairCells, true)) {
                continue;
            }
            if ($r5->board->wordAt($n)[0] === 't') {
                $tViolations++;
            }
        }
    }
    check($tViolations === 0, "'tree' is not vertically adjacent to the pair");
}

// --- Scenario 6: Exact arity end to end -------------------------------
// A pre-chosen word pair (anagram-style) on horizontally adjacent cells,
// plus filler. Exercises the Exact path: seeded draw enumeration, pinned
// domains, candidate-word constraint checks before any claim.
line('');
line('=== Scenario 6: Exact word pair ===');

$spec6 = new BoardSpec(pages: 1, rows: 1, cols: 4);
$pairs6 = [
    ['a' => 'stop', 'b' => 'pots'],
    ['a' => 'evil', 'b' => 'vile'],
];
$rules6 = [
    new ExactAdjacentPairRule('anagram', $pairs6, 1, new HorizontalAdjacency()),
    new FixedLengthRule('fill', 4, 2, ['gold', 'pink', 'navy']),
];

[$b6, $q6, $c6] = TinyBoard::build($spec6, $rules6, seed: 99);
check((new FeasibilityAnalyzer())->check($rules6, $c6)->isFeasible(), 'analyzer finds no problems');

$r6 = (new Solver())->solve($b6, $q6);
check($r6->isSuccess(), "solved (steps {$r6->stepsTaken}, backtracks {$r6->backtracks})");
if ($r6->isSuccess()) {
    $all = $r6->board->allWords();
    $placedPair = null;
    foreach ($pairs6 as $p) {
        $ai = array_search($p['a'], $all, true);
        $bi = array_search($p['b'], $all, true);
        if ($ai !== false && $bi !== false) {
            $placedPair = [$ai, $bi];
        }
    }
    check($placedPair !== null, 'one of the given pairs is on the board');
    if ($placedPair !== null) {
        check(abs($placedPair[0] - $placedPair[1]) === 1, 'pair words are horizontally adjacent');
    }
}

// --- Scenario 7: compound triple (whole = part + part) on one page ----
// The three-cell Exact path for CompoundTripleOnPageRule. The prefix pool
// holds a triple (LOOKOUT = LOOK + OUT); budget 3 => one triple wanted. The
// generator must find three free cells on the page and the chooser pin the
// three words. Then the finished board, read back THROUGH the rule, must
// cross out exactly those three -- construction and elimination agreeing.
line('');
line('=== Scenario 7: compound triple on a page ===');

$spec7 = new BoardSpec(pages: 1, rows: 1, cols: 3);
$board7 = new Board($spec7);
$ctx7 = new ChainContext(seed: 5, board: $board7, stepIndex: 0, budget: 3, prefixPool: ['lookout', 'look', 'out']);
$rule7 = new CompoundTripleOnPageRule();
$demand7 = $rule7->demand($ctx7);
check($demand7->count === 1, "one triple wanted (count {$demand7->count})");

$queue7 = new ReservationQueue();
for ($i = 0; $i < $demand7->count; $i++) {
    $queue7->push(new ReservationSlot($rule7, $demand7, $ctx7, $i));
}
$r7 = (new Solver())->solve($board7, $queue7);
check($r7->isSuccess(), "solved (steps {$r7->stepsTaken}, backtracks {$r7->backtracks})");
if ($r7->isSuccess()) {
    $words7 = [];
    foreach ($spec7->allCells() as $cell) {
        $words7[$cell] = $r7->board->wordAt($cell);
    }
    $placed = array_values($words7);
    sort($placed);
    check($placed === ['look', 'lookout', 'out'], 'the triple is on the page: ' . implode(',', $placed));

    $keep7 = [];
    foreach ($spec7->allCells() as $cell) {
        $keep7[$cell] = true;
    }
    $after7 = Engine::applyRule(array_values($words7), $spec7, $keep7, ['type' => 'CompoundTripleOnPageRule']);
    $survivors7 = [];
    foreach ($spec7->allCells() as $cell) {
        if ($after7[$cell]) {
            $survivors7[] = $words7[$cell];
        }
    }
    check($survivors7 === [], 'rule crosses out all three (survivors: ' . (implode(',', $survivors7) ?: 'none') . ')');
}

// --- Scenario 8: reaction-blast family (needle triggers a geometric blast) --
// Self-contained ReactionBlastRule subclasses: a word containing the needle is
// crossed out together with a fixed footprint around it. Read a hand-built page
// back through each rule and confirm the trigger AND exactly its footprint go,
// while untouched cells survive.
line('');
line('=== Scenario 8: reaction-blast family ===');

// Triggered rules are reactive: pre-cross the trigger (as some rule would, at
// some point) and confirm the footprint then falls while the trigger is left to
// its own killer -- never crossed by the blast itself.
$blastCase = static function (int $rows, int $cols, int $tr, int $tc, string $trigger, string $type, int $expectVictims) use (&$failures): void {
    $spec = new BoardSpec(pages: 1, rows: $rows, cols: $cols);
    $center = $spec->linearIndex(0, $tr, $tc);
    $words = [];
    foreach ($spec->allCells() as $cell) {
        $words[$cell] = $cell === $center ? $trigger : sprintf('inert%03d', $cell);
    }
    $keep = [];
    foreach ($spec->allCells() as $cell) {
        $keep[$cell] = true;
    }
    $keep[$center] = false; // the trigger has already been crossed, at some step
    $after = Engine::applyRule(array_values($words), $spec, $keep, ['type' => $type]);
    $newlyKilled = [];
    foreach ($spec->allCells() as $cell) {
        if ($keep[$cell] && !$after[$cell]) {
            $newlyKilled[] = $cell;
        }
    }
    check(!in_array($center, $newlyKilled, true), "{$type}: leaves the trigger to its killer");
    check(count($newlyKilled) === $expectVictims, "{$type}: blast hit " . count($newlyKilled) . " victims (expect {$expectVictims})");
};

$blastCase(7, 5, 3, 2, 'popgun', 'PopBlastRule', 4);
$blastCase(7, 5, 3, 2, 'powder', 'PowBlastRule', 4);
$blastCase(7, 5, 3, 2, 'bangle', 'BangBlastRule', 8);
$blastCase(7, 5, 3, 2, 'beamed', 'BeamBlastRule', 4);
$blastCase(7, 5, 3, 2, 'diagram', 'DiagBlastRule', 8);
$blastCase(7, 5, 3, 2, 'kaboom', 'BoomBlastRule', 8);
$blastCase(9, 5, 4, 2, 'ringer', 'RingBlastRule', 16);

// --- Scenario 9: reaction fixpoint / chain reaction (Engine::detonate) ------
// A POP blast clears a cell that holds a BANG word; detonate() must then set the
// BANG reaction off too (a chain reaction), reaching cells no single blast would.
line('');
line('=== Scenario 9: chain reaction (detonate fixpoint) ===');

$spec9 = new BoardSpec(pages: 1, rows: 9, cols: 5);
$pop9 = $spec9->linearIndex(0, 4, 2);     // popgun trigger (already crossed)
$bang9 = $spec9->linearIndex(0, 4, 3);    // bang word, right of popgun = a POP victim
$farUp = $spec9->linearIndex(0, 2, 3);    // two up from bang -- only BANG reaches it
$words9 = [];
foreach ($spec9->allCells() as $cell) {
    $words9[$cell] = match ($cell) {
        $pop9 => 'popgun',
        $bang9 => 'bangle',
        default => sprintf('inert%03d', $cell),
    };
}
$board9 = new \Puzzlebook2\Solver\FilledBoard($spec9, $words9);
$keep9 = [];
foreach ($spec9->allCells() as $cell) {
    $keep9[$cell] = true;
}
$keep9[$pop9] = false; // some step just crossed the POP trigger

$reactions9 = [new \Puzzlebook2\Rules\GeometricRules\PopBlastRule(), new \Puzzlebook2\Rules\GeometricRules\BangBlastRule()];
$after9 = Engine::detonate($reactions9, $board9, $keep9);

check(!$after9[$bang9], 'POP blast crosses the BANG word next to the trigger');
check(!$after9[$farUp], 'crossed BANG word then detonates (chain reaction reaches 2 cells away)');
// Idempotence: detonating again changes nothing.
$again9 = Engine::detonate($reactions9, $board9, $after9);
check($again9 === $after9, 'detonate is idempotent at the fixpoint');

// --- Scenario 10: PairOnPageRule base demand/construction -------------------
// Drives the refactored PairOnPageRule archetype through its own demand: a
// reversal pair drawn from the prefix survivors, placed on one page, then read
// back through the rule to confirm both cross out. Exercises the shared
// candidateGroups -> chooser -> StandingPatternConstraint path.
line('');
line('=== Scenario 10: PairOnPageRule construction (semordnilap) ===');

$spec10 = new BoardSpec(pages: 1, rows: 1, cols: 2);
$board10 = new Board($spec10);
$ctx10 = new ChainContext(seed: 3, board: $board10, stepIndex: 0, budget: 2, prefixPool: ['straw', 'warts']);
$rule10 = new \Puzzlebook2\Rules\GeometricRules\SemordnilapPairOnPageRule();
$demand10 = $rule10->demand($ctx10);
check($demand10->count === 1, "one pair wanted (count {$demand10->count})");

$queue10 = new ReservationQueue();
for ($i = 0; $i < $demand10->count; $i++) {
    $queue10->push(new ReservationSlot($rule10, $demand10, $ctx10, $i));
}
$r10 = (new Solver())->solve($board10, $queue10);
check($r10->isSuccess(), "solved (steps {$r10->stepsTaken}, backtracks {$r10->backtracks})");
if ($r10->isSuccess()) {
    $placed10 = [];
    foreach ($spec10->allCells() as $cell) {
        $placed10[$cell] = $r10->board->wordAt($cell);
    }
    $sorted10 = array_values($placed10);
    sort($sorted10);
    check($sorted10 === ['straw', 'warts'], 'the reversal pair is on the page: ' . implode(',', $sorted10));

    $keep10 = array_fill(0, $spec10->size(), true);
    $after10 = Engine::applyRule(array_values($placed10), $spec10, $keep10, ['type' => 'SemordnilapPairOnPageRule']);
    check(Engine::countSurvivors($after10) === 0, 'rule crosses out both words of the pair');
}

line('');
line($failures === 0 ? 'ALL SCENARIOS PASSED' : "{$failures} CHECK(S) FAILED");
exit($failures === 0 ? 0 : 1);
