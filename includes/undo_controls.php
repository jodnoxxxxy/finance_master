<?php
$undoDepth = isset($_SESSION['undo_stack']) && is_array($_SESSION['undo_stack']) ? count($_SESSION['undo_stack']) : 0;
$redoDepth = isset($_SESSION['redo_stack']) && is_array($_SESSION['redo_stack']) ? count($_SESSION['redo_stack']) : 0;
?>
<div class="undo-controls">
    <form method="POST" action="transactions.php">
        <input type="hidden" name="action" value="undo">
        <button type="submit" class="btn btn-ghost undo-btn" <?= $undoDepth === 0 ? 'disabled' : '' ?>
                title="Pop the undo stack (LIFO)">
            ↶ Undo <span class="stack-count"><?= $undoDepth ?></span>
        </button>
    </form>
    <form method="POST" action="transactions.php">
        <input type="hidden" name="action" value="redo">
        <button type="submit" class="btn btn-ghost undo-btn" <?= $redoDepth === 0 ? 'disabled' : '' ?>
                title="Pop the redo stack (LIFO)">
            ↷ Redo <span class="stack-count"><?= $redoDepth ?></span>
        </button>
    </form>
</div>
