<?php
declare(strict_types=1);

/**
 * MinHeap - a binary heap ordered by a caller supplied comparator.
 *
 * The comparator follows the spaceship convention:
 *   result < 0  =>  $a has higher priority (comes out first)
 *   result > 0  =>  $b has higher priority
 *
 * The heap is used by the dashboard Top-K widget and by the debt graph
 * smoother. It is stored in a flat array where children of index i live at
 * 2i + 1 and 2i + 2.
 */
class MinHeap
{
    /** @var array<int, mixed> */
    private array $heap = [];

    /** @var callable */
    private $compare;

    public function __construct(?callable $compare = null)
    {
        $this->compare = $compare ?? static function ($a, $b): int {
            return $a <=> $b;
        };
    }

    public function insert($item): void
    {
        $this->heap[] = $item;
        $this->siftUp(count($this->heap) - 1);
    }

    public function peek()
    {
        return $this->heap[0] ?? null;
    }

    public function extract()
    {
        if ($this->isEmpty()) {
            return null;
        }
        $root = $this->heap[0];
        $last = array_pop($this->heap);
        if (!$this->isEmpty()) {
            $this->heap[0] = $last;
            $this->siftDown(0);
        }
        return $root;
    }

    /** Replace the current root (used by the bounded Top-K heap). */
    public function replaceRoot($item): void
    {
        if ($this->isEmpty()) {
            $this->insert($item);
            return;
        }
        $this->heap[0] = $item;
        $this->siftDown(0);
    }

    public function build(array $items): void
    {
        $this->heap = array_values($items);
        for ($i = intdiv(count($this->heap), 2) - 1; $i >= 0; $i--) {
            $this->siftDown($i);
        }
    }

    public function isEmpty(): bool
    {
        return count($this->heap) === 0;
    }

    public function size(): int
    {
        return count($this->heap);
    }

    /** @return array<int, mixed> Heap order, not fully sorted. */
    public function toArray(): array
    {
        return $this->heap;
    }

    /** @return array<int, mixed> Fully sorted, smallest priority first. */
    public function toSortedArray(): array
    {
        $clone = new self($this->compare);
        $clone->build($this->heap);
        $out = [];
        while (!$clone->isEmpty()) {
            $out[] = $clone->extract();
        }
        return $out;
    }

    /** Convenience wrapper: keep the K highest priority items. */
    public static function topK(array $items, int $k, callable $compare): array
    {
        $bounded = new TopK($k, $compare);
        foreach ($items as $item) {
            $bounded->offer($item);
        }
        return $bounded->toSortedArray(true);
    }

    private function siftUp(int $index): void
    {
        while ($index > 0) {
            $parent = intdiv($index - 1, 2);
            if ($this->compare($this->heap[$index], $this->heap[$parent]) >= 0) {
                break;
            }
            [$this->heap[$parent], $this->heap[$index]] = [$this->heap[$index], $this->heap[$parent]];
            $index = $parent;
        }
    }

    private function siftDown(int $index): void
    {
        $size = count($this->heap);
        while (true) {
            $left = $index * 2 + 1;
            $right = $left + 1;
            $smallest = $index;

            if ($left < $size && $this->compare($this->heap[$left], $this->heap[$smallest]) < 0) {
                $smallest = $left;
            }
            if ($right < $size && $this->compare($this->heap[$right], $this->heap[$smallest]) < 0) {
                $smallest = $right;
            }
            if ($smallest === $index) {
                break;
            }
            [$this->heap[$index], $this->heap[$smallest]] = [$this->heap[$smallest], $this->heap[$index]];
            $index = $smallest;
        }
    }

    private function compare($a, $b): int
    {
        return ($this->compare)($a, $b);
    }
}

/**
 * TopK - bounded min-heap of capacity K.
 *
 * The root is the *weakest* item currently kept. A new item can only enter if
 * it beats that root, which is what makes the algorithm O(N log K) instead of
 * sorting the whole collection in O(N log N).
 */
class TopK
{
    private MinHeap $heap;
    private int $k;
    /** @var callable */
    private $compare;

    public function __construct(int $k, callable $compare)
    {
        $this->k = max(0, $k);
        $this->compare = $compare;
        $this->heap = new MinHeap($compare);
    }

    public function offer($item): bool
    {
        if ($this->k === 0) {
            return false;
        }
        if ($this->heap->size() < $this->k) {
            $this->heap->insert($item);
            return true;
        }
        if (($this->compare)($item, $this->heap->peek()) > 0) {
            $this->heap->replaceRoot($item);
            return true;
        }
        return false;
    }

    public function size(): int
    {
        return $this->heap->size();
    }

    public function peekWorst()
    {
        return $this->heap->peek();
    }

    /** @return array<int, mixed> Highest priority first. */
    public function toSortedArray(bool $descending = true): array
    {
        $sorted = $this->heap->toSortedArray();
        return $descending ? array_reverse($sorted) : $sorted;
    }
}
