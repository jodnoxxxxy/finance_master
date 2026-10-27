<?php
declare(strict_types=1);

require_once __DIR__ . '/../dsa/Stack.php';

/**
 * TransactionUndoEngine - persistence-side executor for the two-stack
 * Undo/Redo engine.
 *
 * The stacks live in the PHP session. Each stored action contains a complete
 * snapshot of the affected transaction, so an undo can restore the row and its
 * wallet balance even after the original request has finished.
 */
class TransactionUndoEngine
{
    private PDO $pdo;
    private int $userId;
    private UndoManager $manager;

    public function __construct(PDO $pdo, int $userId, ?UndoManager $manager = null)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->manager = $manager ?? new UndoManager();
    }

    public static function fromSession(PDO $pdo, int $userId, int $maxDepth = 50): self
    {
        $undo = isset($_SESSION['undo_stack']) && is_array($_SESSION['undo_stack']) ? $_SESSION['undo_stack'] : [];
        $redo = isset($_SESSION['redo_stack']) && is_array($_SESSION['redo_stack']) ? $_SESSION['redo_stack'] : [];
        return new self($pdo, $userId, new UndoManager($maxDepth, $undo, $redo));
    }

    public function recordAdd(array $transaction, string $label = ''): void
    {
        $this->manager->record([
            'op' => 'add',
            'txn' => $this->snapshot($transaction),
            'label' => $label !== '' ? $label : 'New transaction',
            'at' => time(),
        ]);
        $this->persist();
    }

    public function recordDelete(array $transaction, string $label = ''): void
    {
        $this->manager->record([
            'op' => 'delete',
            'txn' => $this->snapshot($transaction),
            'label' => $label !== '' ? $label : 'Deleted transaction',
            'at' => time(),
        ]);
        $this->persist();
    }

    /** @return array{ok: bool, message: string} */
    public function undo(): array
    {
        $action = $this->manager->popUndo();
        if ($action === null) {
            return ['ok' => false, 'message' => 'Nothing to undo.'];
        }

        try {
            $this->pdo->beginTransaction();
            if (($action['op'] ?? '') === 'add') {
                $this->deleteTransaction($action['txn']);
            } else {
                $this->insertTransaction($action['txn']);
            }
            $this->pdo->commit();

            $this->manager->pushRedo($action);
            $this->persist();

            return ['ok' => true, 'message' => 'Undone: ' . ($action['label'] ?? 'transaction')];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->manager->pushUndo($action);
            $this->persist();
            return ['ok' => false, 'message' => 'Undo failed: ' . $e->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string} */
    public function redo(): array
    {
        $action = $this->manager->popRedo();
        if ($action === null) {
            return ['ok' => false, 'message' => 'Nothing to redo.'];
        }

        try {
            $this->pdo->beginTransaction();
            if (($action['op'] ?? '') === 'add') {
                $this->insertTransaction($action['txn']);
            } else {
                $this->deleteTransaction($action['txn']);
            }
            $this->pdo->commit();

            $this->manager->pushUndo($action);
            $this->persist();

            return ['ok' => true, 'message' => 'Redone: ' . ($action['label'] ?? 'transaction')];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->manager->pushRedo($action);
            $this->persist();
            return ['ok' => false, 'message' => 'Redo failed: ' . $e->getMessage()];
        }
    }

    public function undoCount(): int
    {
        return $this->manager->undoCount();
    }

    public function redoCount(): int
    {
        return $this->manager->redoCount();
    }

    public function manager(): UndoManager
    {
        return $this->manager;
    }

    public function persist(): void
    {
        $snapshot = $this->manager->snapshot();
        $_SESSION['undo_stack'] = $snapshot['undo'];
        $_SESSION['redo_stack'] = $snapshot['redo'];
    }

    /** Keep only the columns needed to recreate the row. */
    private function snapshot(array $transaction): array
    {
        return [
            'id' => (int)$transaction['id'],
            'user_id' => (int)$this->userId,
            'account_id' => (int)$transaction['account_id'],
            'category_id' => (int)$transaction['category_id'],
            'type' => (string)$transaction['type'],
            'amount' => (float)$transaction['amount'],
            'date' => (string)$transaction['date'],
            'description' => (string)($transaction['description'] ?? ''),
        ];
    }

    private function insertTransaction(array $txn): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO transactions (id, user_id, account_id, category_id, type, amount, date, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int)$txn['id'],
            $this->userId,
            (int)$txn['account_id'],
            (int)$txn['category_id'],
            (string)$txn['type'],
            (float)$txn['amount'],
            (string)$txn['date'],
            (string)($txn['description'] ?? ''),
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Could not restore transaction #' . $txn['id']);
        }

        $delta = $txn['type'] === 'income' ? (float)$txn['amount'] : -(float)$txn['amount'];
        $this->adjustBalance((int)$txn['account_id'], $delta);
    }

    private function deleteTransaction(array $txn): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
        $stmt->execute([(int)$txn['id'], $this->userId]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Transaction #' . $txn['id'] . ' no longer exists');
        }

        $delta = $txn['type'] === 'income' ? -(float)$txn['amount'] : (float)$txn['amount'];
        $this->adjustBalance((int)$txn['account_id'], $delta);
    }

    private function adjustBalance(int $accountId, float $delta): void
    {
        $stmt = $this->pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$delta, $accountId, $this->userId]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Wallet #' . $accountId . ' could not be updated');
        }
    }
}
