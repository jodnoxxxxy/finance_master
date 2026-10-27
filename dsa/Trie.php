<?php
declare(strict_types=1);

require_once __DIR__ . '/Sorting.php';

/** A single character node of the prefix tree. */
class TrieNode
{
    /** @var array<string, TrieNode> */
    public array $children = [];
    public bool $isEnd = false;
    public int $frequency = 0;
    public string $word = '';
}

/**
 * Trie - prefix tree for merchant / description autocomplete.
 *
 * A lookup only walks as many nodes as there are characters in the prefix
 * (O(L)) and then visits the matching subtree, so it never performs a full
 * table scan like SQL LIKE '%term%'.
 */
class Trie
{
    private TrieNode $root;
    private int $nodeCount = 1;
    private int $wordCount = 0;

    public function __construct()
    {
        $this->root = new TrieNode();
    }

    public function insert(string $phrase, int $weight = 1): void
    {
        $display = trim($phrase);
        if ($display === '') {
            return;
        }
        $normalized = strtolower($display);
        $node = $this->root;
        $length = strlen($normalized);

        for ($i = 0; $i < $length; $i++) {
            $char = $normalized[$i];
            if (!isset($node->children[$char])) {
                $node->children[$char] = new TrieNode();
                $this->nodeCount++;
            }
            $node = $node->children[$char];
        }

        if (!$node->isEnd) {
            $node->isEnd = true;
            $node->word = $display;
            $this->wordCount++;
        }
        $node->frequency += max(1, $weight);
    }

    /**
     * Insert a whole description plus its individual merchant words. This lets
     * "Sw" suggest both "Swiggy" and "Swiggy dinner order".
     */
    public function insertText(string $text, int $weight = 1): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->insert($text, $weight);
        $tokens = preg_split('/[^a-z0-9]+/i', $text) ?: [];
        foreach ($tokens as $token) {
            if (strlen($token) >= 2 && strtolower($text) !== strtolower($token)) {
                $this->insert($token, $weight);
            }
        }
    }

    public function contains(string $word): bool
    {
        $node = $this->findNode(strtolower(trim($word)));
        return $node !== null && $node->isEnd;
    }

    public function startsWith(string $prefix): bool
    {
        return $this->findNode(strtolower(trim($prefix))) !== null;
    }

    /**
     * @return array<int, array{word: string, frequency: int}>
     */
    public function autocompleteDetailed(string $prefix, int $limit = 8): array
    {
        $prefix = strtolower(trim($prefix));
        if ($prefix === '') {
            return [];
        }
        $start = $this->findNode($prefix);
        if ($start === null) {
            return [];
        }

        $matches = [];
        $stack = [$start];
        while ($stack) {
            /** @var TrieNode $node */
            $node = array_pop($stack);
            if ($node->isEnd) {
                $matches[] = ['word' => $node->word, 'frequency' => $node->frequency];
            }
            foreach ($node->children as $child) {
                $stack[] = $child;
            }
        }

        $matches = Sorting::mergeSort($matches, static function (array $a, array $b): int {
            $byFrequency = $b['frequency'] <=> $a['frequency'];
            return $byFrequency !== 0 ? $byFrequency : strcmp($a['word'], $b['word']);
        });

        return array_slice($matches, 0, max(0, $limit));
    }

    /**
     * @return array<int, string>
     */
    public function autocomplete(string $prefix, int $limit = 8): array
    {
        return array_map(static function (array $entry): string {
            return $entry['word'];
        }, $this->autocompleteDetailed($prefix, $limit));
    }

    public function countNodes(): int
    {
        return $this->nodeCount;
    }

    public function countWords(): int
    {
        return $this->wordCount;
    }

    private function findNode(string $text): ?TrieNode
    {
        $node = $this->root;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if (!isset($node->children[$char])) {
                return null;
            }
            $node = $node->children[$char];
        }
        return $node;
    }
}
