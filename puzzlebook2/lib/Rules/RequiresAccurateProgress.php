<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

/**
 * Marker interface: this rule's outcome depends on the CURRENT STATE of
 * the page, not just each word's own spelling -- "both still standing"
 * pair rules, surrounded-capture, every-Nth-survivor, and kin.
 *
 * Why it matters to a reader: the spelling rules are forgiving -- miss a
 * word, catch it on a re-read, nothing downstream changes meaning. But a
 * state-dependent rule read against a page with missed crossings sees
 * phantom standing words, and the reader will eliminate pairs the book
 * never planned -- corrupting everything after. BookWriter injects a
 * CHECKPOINT line (with the exact expected crossed-out count, computed
 * from the real built book) ahead of any rule declaring this interface,
 * so the reader is told to catch up BEFORE the damage, not after.
 */
interface RequiresAccurateProgress
{
}
