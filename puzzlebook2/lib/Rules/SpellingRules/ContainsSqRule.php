<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/** Cross out every word containing "sq" -- a self-contained card in the substring family. */
final class ContainsSqRule extends ContainsSubstringRule
{
    /** Auto-computed by bin/compute-incompatibilities.php -- do not hand-edit; rerun the tool. */
    public const INCOMPATIBLE_WITH = ['ContainsQuRule'];





    public function __construct()
    {
        parent::__construct('sq');
    }
}
