-- Orders Xero can be sent (the same statuses as XeroOrderSync::PUSHABLE_STATUSES) that have no invoice yet. Test payments are left out.
SELECT COUNT(*) AS orders,
       COALESCE(SUM(O.total_inc_tax), 0) AS value,
       MIN(COALESCE(O.paid_at, O.created_at)) AS oldest,
       COALESCE(SUM(COALESCE(O.paid_at, O.created_at) < :cutoff), 0) AS older_than_a_week
FROM {PREFIX}shop_orders AS O
LEFT JOIN {PREFIX}xero_invoices AS X ON X.order_id = O.id
WHERE O.status IN ('paid', 'dispatched', 'refunded') AND O.is_test_payment = 0 AND X.id IS NULL
