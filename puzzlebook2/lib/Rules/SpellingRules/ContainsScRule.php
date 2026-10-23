<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/** Cross out every word containing "sc" -- a self-contained card in the substring family. */
final class ContainsScRule extends ContainsSubstringRule
{
    /** Auto-computed by bin/compute-incompatibilities.php -- do not hand-edit; rerun the tool. */
    public const INCOMPATIBLE_WITH = ['ContainsSchRule'];



    public function __construct()
    {
        parent::__construct('sc');
    }
}
