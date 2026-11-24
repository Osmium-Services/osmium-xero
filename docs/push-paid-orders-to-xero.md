---
title: Push paid orders to Xero
tags: xero, accounting, invoice, orders, bookkeeping, oauth, push
page: admin/settings/xero
---

**Xero** connects your shop to your Xero account. A **Push to Xero** button on each paid order creates a sales invoice there. It creates an authorised invoice.

## Set it up

1. In Xero, create an app for your shop and note its ****Client ID** and **Client Secret****.
2. Open **Xero Settings** and enter them. The **Redirect URI** shown must match the one on the Xero app exactly, including the protocol and trailing slash.
3. Enter the **Sales account code** the income is booked to.
4. The secret is stored on the server and never shown again.
5. Use a test organisation first if you can.
6. Enter the **Allowed organisation**: the exact name of the Xero organisation that invoices may go to.
7. Press **Save Settings**, then connect. You are sent to Xero to approve the connection.

Pushes are refused until the allowed organisation matches the connected one exactly, so an order can never go to the wrong account.

## Push an order

Open a paid order and press **Push to Xero**. The invoice appears in Xero as authorised. Only a paid order can be pushed, and it cannot be undone from here.
