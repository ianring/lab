<?php

declare(strict_types=1);

/**
 * Loads every class, in dependency order. A class that `extends` or
 * `implements` another needs it already loaded, so interfaces and base
 * classes come before their implementations; plain type hints are resolved
 * lazily and don't constrain the order.
 */

require __DIR__ . '/WordTags.php';
require __DIR__ . '/Rng.php';
require __DIR__ . '/Grid.php';
require __DIR__ . '/ConstructionRetry.php';

// The construction hand-off: what rules write their layout decisions into.
require __DIR__ . '/Construction/CellPlan.php';
require __DIR__ . '/Construction/FillInvariant.php';
require __DIR__ . '/Construction/UniquePageSignature.php';
require __DIR__ . '/Construction/Canvas.php';
require __DIR__ . '/Construction/ConstructionContext.php';

// Rule contracts, then the rules themselves. SpellingRule implements both
// contracts, so both have to be loaded before it.
require __DIR__ . '/Rules/RuleInterface.php';
require __DIR__ . '/Rules/ConstructsCells.php';
require __DIR__ . '/Rules/SpellingRule.php';

require __DIR__ . '/Rules/PalindromeRule.php';
require __DIR__ . '/Rules/ConsonantRunRule.php';
require __DIR__ . '/Rules/VowelRunRule.php';
require __DIR__ . '/Rules/AlphabetAdjacentEndsRule.php';
require __DIR__ . '/Rules/AlphabeticalOrderRule.php';
require __DIR__ . '/Rules/BothEndsInSetRule.php';
require __DIR__ . '/Rules/ContainsSubstringRule.php';
require __DIR__ . '/Rules/LetterCountRule.php';
require __DIR__ . '/Rules/ConditionalCellRule.php';
require __DIR__ . '/Rules/SameCellNextPageRule.php';
require __DIR__ . '/Rules/LengthAndEndingRule.php';
require __DIR__ . '/Rules/LetterAtPositionRule.php';
require __DIR__ . '/Rules/RestrictedAlphabetLengthRule.php';
require __DIR__ . '/Rules/UsesOnlyLettersRule.php';
require __DIR__ . '/Rules/ConsonantVowelPatternRule.php';
require __DIR__ . '/Rules/RepeatedLetterRule.php';
require __DIR__ . '/Rules/LetterSumRule.php';
require __DIR__ . '/Rules/ScrabbleScoreRule.php';
require __DIR__ . '/Rules/VowelFractionRule.php';
require __DIR__ . '/Rules/PrimePositionRule.php';
require __DIR__ . '/Rules/EveryNthSurvivorRule.php';
require __DIR__ . '/Rules/KnightAdjacentInitialRule.php';
require __DIR__ . '/Rules/KnightAdjacentSurvivingLengthRule.php';
require __DIR__ . '/Rules/MatchingNeighbourEndsRule.php';

require __DIR__ . '/Reveals/RevealInterface.php';
require __DIR__ . '/Reveals/EveryNthWordReveal.php';
require __DIR__ . '/Reveals/AcrosticFirstCellPerPageReveal.php';
require __DIR__ . '/Reveals/LastSurvivorOnPageReveal.php';
require __DIR__ . '/Reveals/ColumnLastLettersReveal.php';

require __DIR__ . '/Engine.php';
require __DIR__ . '/ChainPlan.php';
require __DIR__ . '/Dictionary.php';
require __DIR__ . '/ChainBuilder.php';
require __DIR__ . '/Solver.php';
