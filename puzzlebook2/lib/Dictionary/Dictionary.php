<?php

declare(strict_types=1);

namespace Puzzlebook2\Dictionary;

/**
 * The word list, loaded once. Deliberately thin: WordTags' functions are
 * cheap, pure computations on a string a caller already has in hand
 * (strlen, a character comparison) -- cheaper than a cache lookup would be,
 * confirmed by direct comparison during v1's development, not assumed here.
 * So there is no per-word tag cache. What IS worth caching -- because it's
 * genuinely expensive to recompute -- is group-level indexes built by
 * scanning the whole dictionary (anagram-signature groups, last-letter
 * groups) and prefix-survival pools; both live in their own classes
 * (RuleIndexCache, PrefixPools) below, keyed off this class's hash().
 */
final class Dictionary
{
    /** @var string[] */
    private array $words;

    private string $hash;

    public function __construct(array $words)
    {
        $this->words = array_values($words);
        $this->hash = hash('xxh3', implode("\n", $this->words));
    }

    public static function loadFromFile(string $path): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read word list at {$path}");
        }
        $words = array_filter(array_map('trim', explode("\n", $raw)), static fn (string $w): bool => $w !== '');
        return new self($words);
    }

    /** @return string[] */
    public function all(): array
    {
        return $this->words;
    }

    public function count(): int
    {
        return count($this->words);
    }

    /** Identifies this exact word list, for cache-keying group indexes and prefix pools. */
    public function hash(): string
    {
        return $this->hash;
    }
}
