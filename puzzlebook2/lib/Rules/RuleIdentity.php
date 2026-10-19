<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

/** id() = the class's short name, which is also the config 'type' string. */
trait RuleIdentity
{
    public function id(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    public function ruleText(): string
    {
        return 'Cross out ' . $this->describe() . '.';
    }
}
