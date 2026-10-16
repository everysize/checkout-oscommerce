<?php

/**
 * everysize Checkout — database access on osCommerce 4's Yii connection.
 *
 * All module SQL goes through here, always parameterized (the core's
 * DbInterface contract). Column names were verified against the
 * osCommerce 4.14.63493 database schema.
 */

declare(strict_types=1);

namespace Everysize\Checkout\osCommerce;

use Everysize\Checkout\Db\DbInterface;
use Yii;
use yii\db\Exception;

class Db implements DbInterface
{
    public function fetchValue(string $sql, array $params = []): ?string
    {
        $value = Yii::$app->db->createCommand($sql, $params)->queryScalar();

        return $value === false ? null : (string) $value;
    }

    /**
     * Rows keyed by column name upper case (MySQL returns the case as
     * written in the query — the core asks for AS UPPER_CASE aliases).
     *
     * @return list<array<string, string|null>>
     */
    public function fetchRows(string $sql, array $params = []): array
    {
        $rows = [];
        foreach (Yii::$app->db->createCommand($sql, $params)->query() as $row) {
            $normalized = [];
            foreach ($row as $key => $value) {
                $normalized[strtoupper((string) $key)] = $value;
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    public function execute(string $sql, array $params = []): void
    {
        Yii::$app->db->createCommand($sql, $params)->execute();
    }

    public function lastInsertId(): ?string
    {
        try {
            $id = Yii::$app->db->getLastInsertID();
        } catch (Exception $e) {
            return null;
        }

        return $id !== null ? (string) $id : null;
    }

    /** A transaction closure: rollback on any Throwable, rethrow. */
    public function transaction(callable $fn): void
    {
        $tx = Yii::$app->db->beginTransaction();
        try {
            $fn();
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }
}
