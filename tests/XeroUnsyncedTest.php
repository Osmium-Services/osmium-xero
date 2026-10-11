<?php

declare(strict_types=1);

namespace Tests\Services\Xero;

use Osmium\Services\Xero\Models\XeroUnsynced;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/XeroUnsynced.php';

/**
 * The SQL behind xero_unsynced_orders, run against real rows: only orders Xero can be sent, with no invoice
 * and not a test payment, oldest first.
 */
class XeroUnsyncedTest extends DatabaseTestCase
{
    private string $runTag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runTag = 'XT' . \substr(\uniqid(), -6);

        $prefix = $this->db->tablePrefix();
        $tablesExist = $this->db->prepare("SHOW TABLES LIKE '{$prefix}xero_invoices'")->single() !== false;
        if (!$tablesExist) $this->markTestSkipped('The Xero tables are not installed');
    }

    private function order(string $suffix, string $status, string $paidAt, array $overrides = []): int
    {
        $prefix = $this->db->tablePrefix();
        $fields = $overrides + [
            'order_ref' => "{$this->runTag}-{$suffix}", 'status' => $status, 'customer_name' => "Name {$suffix}", 'customer_email' => "{$suffix}@example.com",
            'total_inc_tax' => 100.00, 'is_test_payment' => 0, 'paid_at' => $paidAt,
        ];
        $columns = \implode(', ', \array_keys($fields));
        $marks = \implode(', ', \array_map(static fn(string $name): string => ":{$name}", \array_keys($fields)));
        $bindings = [];
        foreach ($fields as $name => $value) {
            $bindings[":{$name}"] = $value;
        }
        $this->db->prepare("INSERT INTO {$prefix}shop_orders ({$columns}) VALUES ({$marks})", $bindings)->execute();

        return (int) $this->db->lastInsertId();
    }

    private function invoice(int $orderId): void
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            "INSERT INTO {$prefix}xero_invoices (order_id, invoice_id, invoice_number) VALUES (:order_id, 'test-invoice', 'INV-TEST')",
            [':order_id' => $orderId],
        )->execute();
    }

    /** @return array<int, string> order refs of this run's orders in the list, oldest first */
    private function listed(): array
    {
        $refs = \array_column((new XeroUnsynced($this->db))->oldest(1000), 'order_ref');

        return \array_values(\array_filter($refs, fn(string $ref): bool => \str_starts_with($ref, $this->runTag)));
    }

    public function test_only_orders_xero_can_be_sent_with_no_invoice_and_not_a_test_payment_are_listed_oldest_first(): void
    {
        $this->order('paid-new', 'paid', '2026-10-09 10:00:00');
        $this->order('paid-old', 'paid', '2026-10-01 10:00:00');
        $this->order('dispatched', 'dispatched', '2026-10-05 10:00:00');
        $this->order('refunded', 'refunded', '2026-10-07 10:00:00');
        $this->order('pending', 'pending', '2026-10-02 10:00:00');
        $this->order('failed', 'failed', '2026-10-02 10:00:00');
        $this->order('cancelled', 'cancelled', '2026-10-02 10:00:00');
        $this->order('test', 'paid', '2026-10-02 10:00:00', ['is_test_payment' => 1]);
        $this->invoice($this->order('has-invoice', 'paid', '2026-10-02 10:00:00'));

        $this->assertSame(
            ["{$this->runTag}-paid-old", "{$this->runTag}-dispatched", "{$this->runTag}-refunded", "{$this->runTag}-paid-new"],
            $this->listed(),
        );
    }

    public function test_the_totals_count_the_same_orders_and_the_week_cut_off(): void
    {
        $orders = new XeroUnsynced($this->db);
        $before = $orders->totals('2026-10-08 00:00:00');

        $this->order('a', 'paid', '2026-10-01 10:00:00', ['total_inc_tax' => 40.50]);
        $this->order('b', 'dispatched', '2026-10-09 10:00:00', ['total_inc_tax' => 10.25]);
        $this->order('c', 'pending', '2026-10-01 10:00:00', ['total_inc_tax' => 999.00]);
        $this->invoice($this->order('d', 'paid', '2026-10-01 10:00:00', ['total_inc_tax' => 888.00]));

        $after = $orders->totals('2026-10-08 00:00:00');

        $this->assertSame(2, (int) $after['orders'] - (int) $before['orders']);
        $this->assertEqualsWithDelta(50.75, (float) $after['value'] - (float) $before['value'], 0.001);
        $this->assertSame(1, (int) $after['older_than_a_week'] - (int) $before['older_than_a_week']);
    }

    public function test_an_order_with_no_paid_date_waits_from_when_it_was_created(): void
    {
        $this->order('nodate', 'paid', '2026-10-03 10:00:00', ['paid_at' => null, 'created_at' => '2026-10-03 09:00:00']);

        $rows = \array_values(\array_filter((new XeroUnsynced($this->db))->oldest(1000), fn(array $row): bool => $row['order_ref'] === "{$this->runTag}-nodate"));

        $this->assertSame('2026-10-03 09:00:00', $rows[0]['waiting_since']);
    }

    public function test_connected_is_true_only_when_an_organisation_row_exists(): void
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare("DELETE FROM {$prefix}xero_connection")->execute();
        $orders = new XeroUnsynced($this->db);

        $this->assertFalse($orders->isConnected());

        $this->db->prepare(
            "INSERT INTO {$prefix}xero_connection (tenant_id, tenant_name, access_token, refresh_token, access_token_expires_at) VALUES ('t', 'Test org', 'a', 'r', NOW())",
        )->execute();

        $this->assertTrue($orders->isConnected());
    }
}
