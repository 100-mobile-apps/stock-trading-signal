<?php
declare(strict_types=1);

namespace SignalLab;

/**
 * Creates/updates tables. Safe to run on every deploy (install.php runs it).
 * Works on MySQL 5.7/8 and MariaDB: columns are added only when missing.
 */
final class Migrator
{
    public static function run(): array
    {
        $done = [];
        Db::pdo()->exec((string) file_get_contents(__DIR__ . '/../sql/schema.sql'));
        $cols = [
            'users' => [
                'role' => "ENUM('user','admin') NOT NULL DEFAULT 'user'",
                'status' => "ENUM('active','suspended') NOT NULL DEFAULT 'active'",
                'referral_code' => 'VARCHAR(16) NULL',
                'referred_by' => 'INT UNSIGNED NULL',
                'referred_at' => 'DATETIME NULL',
                'premium_until' => 'DATETIME NULL',
                'signup_source' => "VARCHAR(10) NOT NULL DEFAULT 'app'",
            ],
        ];
        foreach ($cols as $table => $defs) {
            foreach ($defs as $col => $def) {
                if (!self::hasColumn($table, $col)) {
                    Db::pdo()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                    $done[] = "$table.$col";
                }
            }
        }
        if (!self::hasIndex('users', 'uq_users_refcode')) {
            Db::pdo()->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_refcode (referral_code)');
            $done[] = 'users.uq_users_refcode';
        }
        if (!self::hasIndex('users', 'idx_users_referred_by')) {
            Db::pdo()->exec('ALTER TABLE users ADD KEY idx_users_referred_by (referred_by)');
        }
        Plans::seed();
        return $done;
    }

    private static function hasColumn(string $table, string $col): bool
    {
        return (bool) Db::value(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $col]
        );
    }

    private static function hasIndex(string $table, string $index): bool
    {
        return (bool) Db::value(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        );
    }
}
