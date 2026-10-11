<?php
declare(strict_types=1);
namespace FAS\Shipping;

/** Recovery for shipping ledgers whose lock is acquired with SQL BEGIN IMMEDIATE. */
final class ShippingTransaction
{
    public static function rollback(\PDO $db): void
    {
        // Older PDO SQLite drivers do not track transactions begun with SQL.
        // Always attempt rollback after our BEGIN succeeds, regardless of inTransaction().
        try { $db->exec('ROLLBACK'); }
        catch (\PDOException $rollbackError) {
            // SQLite can already have rolled back (for example RAISE(ROLLBACK)).
            // The caller must rethrow the original operation error, not this cleanup error.
        }
    }
}
