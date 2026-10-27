<?php
declare(strict_types=1);

/** A node in a separate-chaining bucket. */
class HashNode
{
    public string $key;
    public $value;
    public ?HashNode $next = null;

    public function __construct(string $key, $value)
    {
        $this->key = $key;
        $this->value = $value;
    }
}

/**
 * HashMap - hash table with separate chaining.
 *
 * The hash function is a polynomial rolling hash (base 31). Collisions are
 * stored in singly linked lists inside each bucket, and the table doubles to
 * the next prime once the load factor crosses 0.75.
 *
 * In the project it replaces SQL GROUP BY for category / month aggregations.
 */
class HashMap
{
    /** @var array<int, ?HashNode> */
    private array $buckets = [];
    private int $capacity;
    private int $size = 0;
    private int $collisions = 0;

    public function __construct(int $capacity = 101)
    {
        $this->capacity = max(5, $this->nextPrime($capacity));
        $this->buckets = array_fill(0, $this->capacity, null);
    }

    public function put(string $key, $value): void
    {
        $index = $this->hash($key);
        $node = $this->buckets[$index];

        while ($node !== null) {
            if ($node->key === $key) {
                $node->value = $value;
                return;
            }
            $node = $node->next;
        }

        if ($this->buckets[$index] !== null) {
            $this->collisions++;
        }
        $newNode = new HashNode($key, $value);
        $newNode->next = $this->buckets[$index];
        $this->buckets[$index] = $newNode;
        $this->size++;

        if ($this->loadFactor() > 0.75) {
            $this->resize($this->nextPrime($this->capacity * 2));
        }
    }

    public function get(string $key)
    {
        $index = $this->hash($key);
        $node = $this->buckets[$index];
        while ($node !== null) {
            if ($node->key === $key) {
                return $node->value;
            }
            $node = $node->next;
        }
        return null;
    }

    public function has(string $key): bool
    {
        $index = $this->hash($key);
        $node = $this->buckets[$index];
        while ($node !== null) {
            if ($node->key === $key) {
                return true;
            }
            $node = $node->next;
        }
        return false;
    }

    /** Convenience for aggregations: $map->addTo('food', 120.50). */
    public function addTo(string $key, float $delta, float $default = 0.0): float
    {
        $current = $this->has($key) ? (float)$this->get($key) : $default;
        $next = $current + $delta;
        $this->put($key, $next);
        return $next;
    }

    public function remove(string $key): bool
    {
        $index = $this->hash($key);
        $node = $this->buckets[$index];
        $previous = null;

        while ($node !== null) {
            if ($node->key === $key) {
                if ($previous === null) {
                    $this->buckets[$index] = $node->next;
                } else {
                    $previous->next = $node->next;
                }
                $this->size--;
                return true;
            }
            $previous = $node;
            $node = $node->next;
        }
        return false;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->buckets as $head) {
            $node = $head;
            while ($node !== null) {
                $keys[] = $node->key;
                $node = $node->next;
            }
        }
        return $keys;
    }

    /** @return array<int, array{key: string, value: mixed}> */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->buckets as $head) {
            $node = $head;
            while ($node !== null) {
                $entries[] = ['key' => $node->key, 'value' => $node->value];
                $node = $node->next;
            }
        }
        return $entries;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function loadFactor(): float
    {
        return $this->capacity > 0 ? $this->size / $this->capacity : 0.0;
    }

    /** Number of buckets that contain more than one key. */
    public function collisions(): int
    {
        return $this->collisions;
    }

    /** @return array<int, int> */
    public function bucketLengths(): array
    {
        $lengths = [];
        foreach ($this->buckets as $head) {
            $length = 0;
            $node = $head;
            while ($node !== null) {
                $length++;
                $node = $node->next;
            }
            $lengths[] = $length;
        }
        return $lengths;
    }

    public function maxChainLength(): int
    {
        $lengths = $this->bucketLengths();
        return $lengths ? max($lengths) : 0;
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        $lengths = $this->bucketLengths();
        $used = 0;
        foreach ($lengths as $length) {
            if ($length > 0) {
                $used++;
            }
        }
        return [
            'size' => $this->size,
            'capacity' => $this->capacity,
            'used_buckets' => $used,
            'load_factor' => $this->loadFactor(),
            'collisions' => $this->collisions,
            'max_chain' => $lengths ? max($lengths) : 0,
        ];
    }

    /** Polynomial rolling hash: h = (h * 31 + ASCII) mod capacity. */
    private function hash(string $key): int
    {
        $hash = 0;
        $length = strlen($key);
        for ($i = 0; $i < $length; $i++) {
            $hash = (($hash * 31) + ord($key[$i])) % $this->capacity;
        }
        return $hash;
    }

    private function resize(int $newCapacity): void
    {
        $oldBuckets = $this->buckets;
        $this->capacity = $newCapacity;
        $this->buckets = array_fill(0, $this->capacity, null);
        $this->collisions = 0;

        foreach ($oldBuckets as $head) {
            $node = $head;
            while ($node !== null) {
                $next = $node->next;
                $node->next = null;
                $this->insertRaw($node);
                $node = $next;
            }
        }
    }

    private function insertRaw(HashNode $node): void
    {
        $index = $this->hash($node->key);
        if ($this->buckets[$index] !== null) {
            $this->collisions++;
        }
        $node->next = $this->buckets[$index];
        $this->buckets[$index] = $node;
    }

    private function nextPrime(int $n): int
    {
        if ($n <= 2) {
            return 2;
        }
        if ($n % 2 === 0) {
            $n++;
        }
        while (!$this->isPrime($n)) {
            $n += 2;
        }
        return $n;
    }

    private function isPrime(int $n): bool
    {
        if ($n < 2) {
            return false;
        }
        if ($n % 2 === 0) {
            return $n === 2;
        }
        $limit = (int)floor(sqrt((float)$n));
        for ($i = 3; $i <= $limit; $i += 2) {
            if ($n % $i === 0) {
                return false;
            }
        }
        return true;
    }
}
