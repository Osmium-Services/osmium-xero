-- Only whether a Xero organisation is connected: never the tokens or the organisation's details.
SELECT COUNT(*) AS connected FROM {PREFIX}xero_connection
