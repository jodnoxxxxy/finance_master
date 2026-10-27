<?php
declare(strict_types=1);

/** AVL node: one key can hold several transactions (same calendar day). */
class BSTNode
{
    public int $key;
    /** @var array<int, mixed> */
    public array $values;
    public int $height = 1;
    public ?BSTNode $left = null;
    public ?BSTNode $right = null;

    public function __construct(int $key, $value)
    {
        $this->key = $key;
        $this->values = [$value];
    }
}

/**
 * BST - a self-balancing binary search tree (AVL) keyed by UNIX timestamp.
 *
 * Storing ledger rows by timestamp allows an in-order range traversal to return
 * only the rows inside [from, to]. Because the tree is AVL-balanced, a lookup
 * path is O(log N) even when transactions arrive in chronological order.
 */
class BST
{
    private ?BSTNode $root = null;
    private int $nodeCount = 0;
    private int $valueCount = 0;

    public function insert(int $key, $value): void
    {
        $this->root = $this->insertNode($this->root, $key, $value);
        $this->valueCount++;
    }

    /** @return array<int, mixed> Values stored at an exact key. */
    public function search(int $key): array
    {
        $node = $this->root;
        while ($node !== null) {
            if ($key === $node->key) {
                return $node->values;
            }
            $node = $key < $node->key ? $node->left : $node->right;
        }
        return [];
    }

    /**
     * In-order range traversal. Branches that cannot contain a match are
     * pruned, so the cost is O(log N + M) for M matches.
     *
     * @return array<int, mixed>
     */
    public function rangeQuery(int $min, int $max): array
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        $result = [];
        $this->collectRange($this->root, $min, $max, $result);
        return $result;
    }

    /** @return array<int, mixed> */
    public function inOrder(): array
    {
        $result = [];
        $this->collectRange($this->root, PHP_INT_MIN, PHP_INT_MAX, $result);
        return $result;
    }

    /** @return array<int, int> */
    public function inOrderKeys(): array
    {
        $keys = [];
        $this->collectKeys($this->root, $keys);
        return $keys;
    }

    public function minKey(): ?int
    {
        if ($this->root === null) {
            return null;
        }
        $node = $this->root;
        while ($node->left !== null) {
            $node = $node->left;
        }
        return $node->key;
    }

    public function maxKey(): ?int
    {
        if ($this->root === null) {
            return null;
        }
        $node = $this->root;
        while ($node->right !== null) {
            $node = $node->right;
        }
        return $node->key;
    }

    public function height(): int
    {
        return $this->nodeHeight($this->root);
    }

    public function nodeCount(): int
    {
        return $this->nodeCount;
    }

    public function valueCount(): int
    {
        return $this->valueCount;
    }

    public function isEmpty(): bool
    {
        return $this->root === null;
    }

    /** A balanced AVL tree keeps |balance factor| <= 1 everywhere. */
    public function isBalanced(): bool
    {
        return $this->checkBalance($this->root);
    }

    private function insertNode(?BSTNode $node, int $key, $value): BSTNode
    {
        if ($node === null) {
            $this->nodeCount++;
            return new BSTNode($key, $value);
        }

        if ($key < $node->key) {
            $node->left = $this->insertNode($node->left, $key, $value);
        } elseif ($key > $node->key) {
            $node->right = $this->insertNode($node->right, $key, $value);
        } else {
            $node->values[] = $value;
            return $node;
        }

        $this->updateHeight($node);
        return $this->rebalance($node);
    }

    private function collectRange(?BSTNode $node, int $min, int $max, array &$result): void
    {
        if ($node === null) {
            return;
        }
        if ($node->key > $min) {
            $this->collectRange($node->left, $min, $max, $result);
        }
        if ($node->key >= $min && $node->key <= $max) {
            foreach ($node->values as $value) {
                $result[] = $value;
            }
        }
        if ($node->key < $max) {
            $this->collectRange($node->right, $min, $max, $result);
        }
    }

    private function collectKeys(?BSTNode $node, array &$keys): void
    {
        if ($node === null) {
            return;
        }
        $this->collectKeys($node->left, $keys);
        $keys[] = $node->key;
        $this->collectKeys($node->right, $keys);
    }

    private function rebalance(BSTNode $node): BSTNode
    {
        $balance = $this->balanceFactor($node);

        // Left heavy
        if ($balance > 1) {
            if ($this->balanceFactor($node->left) < 0) {
                $node->left = $this->rotateLeft($node->left);
            }
            return $this->rotateRight($node);
        }

        // Right heavy
        if ($balance < -1) {
            if ($this->balanceFactor($node->right) > 0) {
                $node->right = $this->rotateRight($node->right);
            }
            return $this->rotateLeft($node);
        }

        return $node;
    }

    private function rotateLeft(BSTNode $node): BSTNode
    {
        $pivot = $node->right;
        if ($pivot === null) {
            return $node;
        }
        $transfer = $pivot->left;
        $pivot->left = $node;
        $node->right = $transfer;
        $this->updateHeight($node);
        $this->updateHeight($pivot);
        return $pivot;
    }

    private function rotateRight(BSTNode $node): BSTNode
    {
        $pivot = $node->left;
        if ($pivot === null) {
            return $node;
        }
        $transfer = $pivot->right;
        $pivot->right = $node;
        $node->left = $transfer;
        $this->updateHeight($node);
        $this->updateHeight($pivot);
        return $pivot;
    }

    private function updateHeight(BSTNode $node): void
    {
        $node->height = 1 + max($this->nodeHeight($node->left), $this->nodeHeight($node->right));
    }

    private function nodeHeight(?BSTNode $node): int
    {
        return $node === null ? 0 : $node->height;
    }

    private function balanceFactor(?BSTNode $node): int
    {
        if ($node === null) {
            return 0;
        }
        return $this->nodeHeight($node->left) - $this->nodeHeight($node->right);
    }

    private function checkBalance(?BSTNode $node): bool
    {
        if ($node === null) {
            return true;
        }
        if (abs($this->balanceFactor($node)) > 1) {
            return false;
        }
        return $this->checkBalance($node->left) && $this->checkBalance($node->right);
    }
}
