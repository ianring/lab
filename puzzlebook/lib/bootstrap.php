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
require __DIR__ . '/JsonConfig.php';

// The construction hand-off: what rules write their layout decisions into.
require __DIR__ . '/Construction/CellPlan.php';
require __DIR__ . '/Construction/FillInvariant.php';
require __DIR__ . '/Construction/UniquePageSignature.php';
require __DIR__ . '/Construction/LengthByPageInvariant.php';
require __DIR__ . '/Construction/NoContainmentOnPage.php';
require __DIR__ . '/Construction/NoUnplannedCornerLetter.php';
require __DIR__ . '/Construction/NoLengthAdjacentToOpposingLiteral.php';
require __DIR__ . '/Construction/NoUnplannedVerticalEndingMatch.php';
require __DIR__ . '/Construction/Canvas.php';
require __DIR__ . '/Construction/ConstructionContext.php';

// Rule contracts, then the rules themselves. SpellingRule implements both
// contracts, so both have to be loaded before it.
require __DIR__ . '/Rules/RuleInterface.php';
require __DIR__ . '/Rules/ConstructsCells.php';
require __DIR__ . '/Rules/SpellingRule.php';
require __DIR__ . '/Rules/PageSignaturePairRule.php';

require __DIR__ . '/Rules/PalindromeRule.php';
require __DIR__ . '/Rules/ConsonantRunRule.php';
require __DIR__ . '/Rules/VowelRunRule.php';
require __DIR__ . '/Rules/StartsWithTwoVowelsRule.php';
require __DIR__ . '/Rules/DoubledConsonantRule.php';
require __DIR__ . '/Rules/VowelBothEndsRule.php';
require __DIR__ . '/Rules/AlphabetAdjacentEndsRule.php';
require __DIR__ . '/Rules/AlphabeticalOrderRule.php';
require __DIR__ . '/Rules/BothEndsInSetRule.php';
require __DIR__ . '/Rules/BothEndPairsInSetRule.php';
require __DIR__ . '/Rules/ContainsSubstringRule.php';
require __DIR__ . '/Rules/ContainsZRule.php';
require __DIR__ . '/Rules/ContainsDoubleERule.php';
require __DIR__ . '/Rules/DoubleEFewVowelsRule.php';
require __DIR__ . '/Rules/ThreeDistinctLettersRule.php';
require __DIR__ . '/Rules/FourConsonantsOneVowelRule.php';
require __DIR__ . '/Rules/LetterCountRule.php';
require __DIR__ . '/Rules/ConditionalCellRule.php';
require __DIR__ . '/Rules/SameCellNextPageRule.php';
require __DIR__ . '/Rules/LengthAndEndingRule.php';
require __DIR__ . '/Rules/LetterAtPositionRule.php';
require __DIR__ . '/Rules/SecondLetterARule.php';
require __DIR__ . '/Rules/RestrictedAlphabetLengthRule.php';
require __DIR__ . '/Rules/UsesOnlyLettersRule.php';
require __DIR__ . '/Rules/ConsonantVowelPatternRule.php';
require __DIR__ . '/Rules/RepeatedLetterRule.php';
require __DIR__ . '/Rules/RepeatedBlockRule.php';
require __DIR__ . '/Rules/LetterSumRule.php';
require __DIR__ . '/Rules/WordLengthRule.php';
require __DIR__ . '/Rules/WordLength3Rule.php';
require __DIR__ . '/Rules/WordLength4Rule.php';
require __DIR__ . '/Rules/WordLength5Rule.php';
require __DIR__ . '/Rules/WordLength6Rule.php';
require __DIR__ . '/Rules/WordLength7Rule.php';
require __DIR__ . '/Rules/WordLength8Rule.php';
require __DIR__ . '/Rules/WordLength9Rule.php';
require __DIR__ . '/Rules/WordLength10Rule.php';
require __DIR__ . '/Rules/AnagramPairOnPageRule.php';
require __DIR__ . '/Rules/SamePrefixOnPageRule.php';
require __DIR__ . '/Rules/SameVowelsOnPageRule.php';
require __DIR__ . '/Rules/ReversalOnPageRule.php';
require __DIR__ . '/Rules/ContainedWordOnPageRule.php';
require __DIR__ . '/Rules/LengthByPageRule.php';
require __DIR__ . '/Rules/ScrabbleScoreRule.php';
require __DIR__ . '/Rules/VowelFractionRule.php';
require __DIR__ . '/Rules/PrimePositionRule.php';
require __DIR__ . '/Rules/EveryNthSurvivorRule.php';
require __DIR__ . '/Rules/EveryTenthSurvivorRule.php';
require __DIR__ . '/Rules/KnightAdjacentInitialRule.php';
require __DIR__ . '/Rules/KnightAdjacentSurvivingLengthRule.php';
require __DIR__ . '/Rules/DecoyLengthRule.php';
require __DIR__ . '/Rules/DecoyLength3Rule.php';
require __DIR__ . '/Rules/DecoyLength4Rule.php';
require __DIR__ . '/Rules/DecoyLength5Rule.php';
require __DIR__ . '/Rules/DecoyLength6Rule.php';
require __DIR__ . '/Rules/DecoyLength7Rule.php';
require __DIR__ . '/Rules/DecoyLength8Rule.php';
require __DIR__ . '/Rules/DecoyLength9Rule.php';
require __DIR__ . '/Rules/DecoyLength10Rule.php';
require __DIR__ . '/Rules/MatchingNeighbourEndsRule.php';
require __DIR__ . '/Rules/SameEndingVerticalPairRule.php';
require __DIR__ . '/Rules/LongShortNeighbourRule.php';
require __DIR__ . '/Rules/CornerContainsLetterRule.php';
require __DIR__ . '/Rules/SurroundedCaptureRule.php';

require __DIR__ . '/Reveals/RevealInterface.php';
require __DIR__ . '/Reveals/EveryNthWordReveal.php';
require __DIR__ . '/Reveals/AcrosticFirstCellPerPageReveal.php';
require __DIR__ . '/Reveals/LastSurvivorOnPageReveal.php';
require __DIR__ . '/Reveals/ColumnLastLettersReveal.php';

require __DIR__ . '/Engine.php';
require __DIR__ . '/ChainPlan.php';
require __DIR__ . '/Dictionary.php';
require __DIR__ . '/WordIndexCache.php';
require __DIR__ . '/ChainBuilder.php';
require __DIR__ . '/Solver.php';
