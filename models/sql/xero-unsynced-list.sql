-- The oldest orders waiting for an invoice: only what is needed to find the order and tell who it is for (masked later).
SELECT O.order_ref, O.status, O.total_inc_tax, O.currency, O.customer_name, O.customer_email,
       COALESCE(O.paid_at, O.created_at) AS waiting_since
FROM {PREFIX}shop_orders AS O
LEFT JOIN {PREFIX}xero_invoices AS X ON X.order_id = O.id
WHERE O.status IN ('paid', 'dispatched', 'refunded') AND O.is_test_payment = 0 AND X.id IS NULL
ORDER BY waiting_since ASC, O.id ASC
LIMIT :limit
