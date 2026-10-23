<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/** Cross out every word containing "sch" -- a self-contained card in the substring family. */
final class ContainsSchRule extends ContainsSubstringRule
{
    /** Auto-computed by bin/compute-incompatibilities.php -- do not hand-edit; rerun the tool. */
    public const INCOMPATIBLE_WITH = ['ContainsChRule', 'ContainsScRule'];



    public function __construct()
    {
        parent::__construct('sch');
    }
}
