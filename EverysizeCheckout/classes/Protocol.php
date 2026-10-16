<?php

/**
 * everysize Checkout — sync protocol stored in the shop database.
 *
 * Written by Sync (only runs that moved something or failed), read by the
 * admin protocol page.
 */

declare(strict_types=1);

namespace Everysize\Checkout\osCommerce;

use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\Protocol as ProtocolInterface;

class Protocol implements ProtocolInterface
{
    private DbInterface $db;

    public function __construct(DbInterface $db)
    {
        $this->db = $db;
    }

    public function add(string $title, string $message, bool $success): void
    {
        $this->db->execute(
            'INSERT INTO everysize_checkout_protocol (title, message, state, created_at)
             VALUES (:title, :message, :state, :created)',
            [
                ':title' => mb_substr($title, 0, 255),
                ':message' => mb_substr($message, 0, 65535),
                ':state' => $success ? 1 : 0,
                // Module tables store UTC; the admin renders shop time
                // (EverysizeCheckoutController::formatUtc).
                ':created' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    public function cleanup(int $days = ProtocolInterface::RETENTION_DAYS): void
    {
        $this->db->execute(
            'DELETE FROM everysize_checkout_protocol WHERE created_at < :oldest',
            [':oldest' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }

    /**
     * Protocol rows for the admin page (newest first).
     *
     * @return list<array{created: string, state: bool, title: string, message: string}>
     */
    public function list(string $filter = '', int $limit = 50, int $offset = 0): array
    {
        $where = '';
        if ($filter === 'ok') {
            $where = 'WHERE state = 1';
        } elseif ($filter === 'error') {
            $where = 'WHERE state = 0';
        }

        $rows = [];
        foreach ($this->db->fetchRows(
            "SELECT created_at AS CREATED, state AS STATE, title AS TITLE, message AS MESSAGE
             FROM everysize_checkout_protocol $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset"
        ) as $row) {
            $rows[] = [
                'created' => (string) $row['CREATED'],
                'state' => (string) $row['STATE'] === '1',
                'title' => (string) $row['TITLE'],
                'message' => (string) $row['MESSAGE'],
            ];
        }

        return $rows;
    }

    public function count(string $filter = ''): int
    {
        $where = '';
        if ($filter === 'ok') {
            $where = 'WHERE state = 1';
        } elseif ($filter === 'error') {
            $where = 'WHERE state = 0';
        }

        return (int) ($this->db->fetchValue("SELECT COUNT(*) FROM everysize_checkout_protocol $where") ?? '0');
    }
}
