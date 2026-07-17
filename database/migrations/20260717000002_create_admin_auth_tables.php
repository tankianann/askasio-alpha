<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE admin_users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                last_login_at DATETIME(6) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                UNIQUE KEY uq_admin_users_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE admin_login_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                attempted_at DATETIME(6) NOT NULL,
                KEY idx_admin_login_attempts_username_time (username_hash, attempted_at),
                KEY idx_admin_login_attempts_ip_time (ip_hash, attempted_at),
                KEY idx_admin_login_attempts_time (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE admin_login_attempts');
        $pdo->exec('DROP TABLE admin_users');
    }
};
