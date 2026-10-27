<?php
declare(strict_types=1);

/**
 * Creates the two tables used by the DSA features. Safe to call on every
 * request: CREATE TABLE IF NOT EXISTS is a no-op once the schema is present.
 *
 * The same definitions are appended to database.sql for fresh installations.
 */
function ensureDsaSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `wishlist_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `name` VARCHAR(150) NOT NULL,
            `cost` DECIMAL(12, 2) NOT NULL,
            `priority` INT NOT NULL DEFAULT 5,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_wishlist_user` (`user_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `debts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `debtor` VARCHAR(100) NOT NULL,
            `creditor` VARCHAR(100) NOT NULL,
            `amount` DECIMAL(12, 2) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_debts_user` (`user_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
}
