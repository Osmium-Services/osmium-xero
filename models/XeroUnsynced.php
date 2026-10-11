<?php

declare(strict_types=1);

namespace Osmium\Services\Xero\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * Orders that are ready for Xero but have no invoice there yet, for the /mcp tool.
 */
class XeroUnsynced extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'shop_orders');
    }

    /** @return array<string, mixed> orders, value, oldest and older_than_a_week */
    public function totals(string $cutoff): array
    {
        return $this->database->prepare(sql: $this->loadSqlFile('xero-unsynced-totals.sql'), bindings: [':cutoff' => $cutoff])->single() ?: [];
    }

    /** @return array<int, array<string, mixed>> The oldest waiting orders first */
    public function oldest(int $limit): array
    {
        return $this->database->prepare(sql: $this->loadSqlFile('xero-unsynced-list.sql'), bindings: [':limit' => $limit])->resultset();
    }

    public function isConnected(): bool
    {
        $row = $this->database->prepare(sql: $this->loadSqlFile('xero-connected.sql'))->single();

        return (int) ($row['connected'] ?? 0) > 0;
    }
}
