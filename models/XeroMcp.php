<?php

declare(strict_types=1);

namespace Osmium\Services\Xero\Models;

use Osmium\Core\Library\Masking;

/**
 * Xero's tool for the /mcp endpoint, offered through core's `mcp.tools` hook: one read-only list of paid orders
 * that have not been pushed to Xero yet. The order reference is what identifies an order. The customer's name and
 * email appear masked only, and addresses, phone numbers, notes, IPs, Xero tokens and organisation details are
 * never returned. There are no write tools: pushing creates a real invoice, so it stays a button in the admin.
 */
class XeroMcp
{
    private const LIST_LIMIT = 20;

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>> tool descriptors
     */
    public static function tools(array $payload): array
    {
        return [
            [
                'name' => 'xero_unsynced_orders',
                'description' => 'Paid, dispatched or refunded orders that have not been pushed to Xero yet (test payments left out): how many, their total value including VAT, the oldest and how many have waited over 7 days, plus whether a Xero organisation is connected (if not, every order counts as waiting). Lists the 20 oldest by order reference with status, total, date, days waiting and the customer name and email masked to first and last letters. Addresses, phone numbers, notes, IPs and Xero credentials are never returned. Read only: orders can only be pushed with the button on the order page in the admin.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'handler' => self::class . '::unsyncedOrders',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function unsyncedOrders(array $call): array
    {
        $orders = new XeroUnsynced($call['dataSource']);
        $cutoff = \date('Y-m-d H:i:s', \strtotime('-7 days'));

        return self::shape($orders->isConnected(), $orders->totals($cutoff), $orders->oldest(self::LIST_LIMIT), \date('Y-m-d'));
    }

    /**
     * @param array<string, mixed> $totals from xero-unsynced-totals.sql
     * @param array<int, array<string, mixed>> $rows from xero-unsynced-list.sql, oldest first
     * @param string $today As Y-m-d
     * @return array<string, mixed>
     */
    public static function shape(bool $connected, array $totals, array $rows, string $today): array
    {
        return [
            'xero_connected' => $connected,
            'orders_waiting' => (int) ($totals['orders'] ?? 0),
            'value_waiting_inc_vat' => \round((float) ($totals['value'] ?? 0), 2),
            'oldest_waiting' => self::date($totals['oldest'] ?? null),
            'waiting_over_7_days' => (int) ($totals['older_than_a_week'] ?? 0),
            'oldest_orders' => \array_map(static fn(array $row): array => self::order($row, $today), \array_slice($rows, 0, self::LIST_LIMIT)),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function order(array $row, string $today): array
    {
        $since = self::date($row['waiting_since'] ?? null);
        $days = $since === null ? null : (int) (new \DateTimeImmutable($since))->diff(new \DateTimeImmutable($today))->days;

        return [
            'order_ref' => (string) $row['order_ref'],
            'status' => (string) $row['status'],
            'total_inc_vat' => \round((float) $row['total_inc_tax'], 2),
            'currency' => (string) $row['currency'],
            'waiting_since' => $since,
            'days_waiting' => $days,
            'customer' => Masking::name((string) ($row['customer_name'] ?? '')),
            'email' => Masking::email((string) ($row['customer_email'] ?? '')),
        ];
    }

    private static function date(mixed $value): ?string
    {
        return $value === null ? null : \substr((string) $value, 0, 10);
    }
}
