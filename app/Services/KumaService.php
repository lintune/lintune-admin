<?php

namespace App\Services;

use PDO;

class KumaService
{
    private function connect(): PDO
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                env('KUMA_DB_HOST', 'db'),
                env('KUMA_DB_PORT', '3306'),
                env('KUMA_DB_NAME', 'kuma')
            ),
            env('KUMA_DB_USERNAME', 'lintune'),
            env('KUMA_DB_PASSWORD', '')
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    // Polls until Kuma has created its schema (user table present). Returns false on timeout.
    public function waitForDb(int $attempts = 30, int $sleepSeconds = 2): bool
    {
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $pdo = $this->connect();
                $pdo->query('SELECT 1 FROM `user` LIMIT 1');
                return true;
            } catch (\Throwable) {}
            sleep($sleepSeconds);
        }
        return false;
    }

    // Creates the first admin user if no user exists yet. Idempotent.
    public function ensureUser(string $username, string $password): int
    {
        $pdo = $this->connect();

        $existing = (int) $pdo->query('SELECT COUNT(*) FROM `user`')->fetchColumn();
        if ($existing > 0) {
            return (int) $pdo->query('SELECT id FROM `user` LIMIT 1')->fetchColumn();
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $stmt = $pdo->prepare('INSERT INTO `user` (username, password, active) VALUES (?, ?, 1)');
        $stmt->execute([$username, $hash]);
        return (int) $pdo->lastInsertId();
    }

    // Removes a monitor by exact name. No-op if not found.
    public function removeMonitor(string $name): void
    {
        try {
            $pdo = $this->connect();
            $pdo->prepare('DELETE FROM monitor WHERE name = ?')->execute([$name]);
        } catch (\Throwable) {}
    }

    // Adds an HTTP monitor. Idempotent by name — returns existing ID if already present.
    public function addMonitor(string $name, string $url, bool $ignoreTls = false): int
    {
        $pdo = $this->connect();

        $check = $pdo->prepare('SELECT id FROM monitor WHERE name = ?');
        $check->execute([$name]);
        if ($row = $check->fetch(PDO::FETCH_ASSOC)) {
            return (int) $row['id'];
        }

        $userId = $pdo->query('SELECT id FROM `user` LIMIT 1')->fetchColumn() ?: 1;

        $stmt = $pdo->prepare("
            INSERT INTO monitor
                (name, active, user_id, `interval`, url, type, weight, maxretries,
                 ignore_tls, retry_interval, method, accepted_statuscodes_json, created_date)
            VALUES
                (?, 1, ?, 60, ?, 'http', 2000, 1, ?, 60, 'GET', '[\"200-299\"]', NOW())
        ");
        $stmt->execute([$name, $userId, $url, $ignoreTls ? 1 : 0]);
        return (int) $pdo->lastInsertId();
    }

    // Returns [{id, name, status, url, admin_only}]
    // status: 0=down, 1=up, 2=pending/unknown, 3=maintenance
    // admin_only: true for monitors whose name contains "aio" (Nextcloud AIO master container)
    public function getStatus(): array
    {
        try {
            $pdo = $this->connect();
            $rows = $pdo->query("
                SELECT m.id, m.name, m.url,
                    COALESCE(h.status, 2) AS status
                FROM monitor m
                LEFT JOIN heartbeat h ON h.id = (
                    SELECT MAX(id) FROM heartbeat WHERE monitor_id = m.id
                )
                WHERE m.active = 1
                ORDER BY m.id
            ")->fetchAll(PDO::FETCH_ASSOC);

            return array_map(fn($row) => [
                'id'         => (int) $row['id'],
                'name'       => $row['name'],
                'status'     => (int) $row['status'],
                'url'        => $row['url'] ?? '',
                'admin_only' => str_contains(strtolower($row['name'] ?? ''), 'aio'),
            ], $rows);
        } catch (\Throwable) {
            return [];
        }
    }
}
