<?php
declare(strict_types=1);

/**
 * Stack - array backed LIFO container.
 *
 * Used by the multi-level Undo / Redo engine. Every mutating ledger action is
 * pushed onto the undo stack. Undo pops the action, applies its inverse and
 * pushes it onto the redo stack (and vice-versa).
 */
class Stack
{
    /** @var array<int, mixed> */
    private array $items = [];

    public function push($item): void
    {
        $this->items[] = $item;
    }

    public function pop()
    {
        if ($this->isEmpty()) {
            return null;
        }
        return array_pop($this->items);
    }

    public function peek()
    {
        if ($this->isEmpty()) {
            return null;
        }
        return $this->items[count($this->items) - 1];
    }

    public function isEmpty(): bool
    {
        return count($this->items) === 0;
    }

    public function size(): int
    {
        return count($this->items);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    /** @return array<int, mixed> */
    public function toArray(): array
    {
        return $this->items;
    }

    /** @param array<int, mixed> $items */
    public static function fromArray(array $items): self
    {
        $stack = new self();
        $stack->items = array_values($items);
        return $stack;
    }
}

/**
 * UndoManager - a pair of LIFO stacks (undo + redo).
 *
 * Keeping this logic in one object makes the invariant explicit:
 *   - a brand new action clears the redo stack
 *   - undo moves one action from undo -> redo
 *   - redo moves one action from redo -> undo
 */
class UndoManager
{
    private Stack $undo;
    private Stack $redo;
    private int $maxDepth;

    /**
     * @param array<int, array<string, mixed>> $undo
     * @param array<int, array<string, mixed>> $redo
     */
    public function __construct(int $maxDepth = 50, array $undo = [], array $redo = [])
    {
        $this->maxDepth = max(1, $maxDepth);
        $this->undo = Stack::fromArray($undo);
        $this->redo = Stack::fromArray($redo);
        $this->trimUndo();
    }

    /** Record a new action and invalidate the redo history. */
    public function record(array $action): void
    {
        $this->undo->push($action);
        $this->redo->clear();
        $this->trimUndo();
    }

    public function popUndo(): ?array
    {
        $action = $this->undo->pop();
        return is_array($action) ? $action : null;
    }

    public function popRedo(): ?array
    {
        $action = $this->redo->pop();
        return is_array($action) ? $action : null;
    }

    public function pushUndo(array $action): void
    {
        $this->undo->push($action);
        $this->trimUndo();
    }

    public function pushRedo(array $action): void
    {
        $this->redo->push($action);
    }

    public function undoCount(): int
    {
        return $this->undo->size();
    }

    public function redoCount(): int
    {
        return $this->redo->size();
    }

    public function peekUndo(): ?array
    {
        $action = $this->undo->peek();
        return is_array($action) ? $action : null;
    }

    public function peekRedo(): ?array
    {
        $action = $this->redo->peek();
        return is_array($action) ? $action : null;
    }

    public function clear(): void
    {
        $this->undo->clear();
        $this->redo->clear();
    }

    /** @return array{undo: array<int, mixed>, redo: array<int, mixed>} */
    public function snapshot(): array
    {
        return [
            'undo' => $this->undo->toArray(),
            'redo' => $this->redo->toArray(),
        ];
    }

    private function trimUndo(): void
    {
        if ($this->undo->size() <= $this->maxDepth) {
            return;
        }
        $items = $this->undo->toArray();
        $items = array_slice($items, -$this->maxDepth);
        $this->undo = Stack::fromArray($items);
    }
}
