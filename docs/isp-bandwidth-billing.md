# ISP Bandwidth Only billing

Existing branches default to `standard`; their billing and customer expiry permissions stay unchanged.

In **Branches** or **Admin users → Add/Edit → Role**, select **Bandwidth Only** for the assigned branch and enter bandwidth in Mbps, monthly price per Mbps, GST percentage, and monthly payment due day. The user form uses the Role selection directly, without a separate Operator type selector. Bandwidth Only retains branch-operator permissions. Settings are shared by every operator and customer in that branch. Operator share and OTT deduction do not apply to this type.

Monthly subtotal is `Mbps × rate`, rounded to two decimal places. GST is rounded to two decimal places and added to the subtotal. For 1200 Mbps at ₹70 with 18% GST: ₹84,000 + ₹15,120 = ₹99,120. Each invoice preserves its issued quantity, rate, tax, amount and branch name.

Billing starts in the month the branch becomes Bandwidth Only. The first invoice covers that month at the full monthly rate and is issued on the following month's first day. There is no prorating. A due day of 31 is capped at the last day of a shorter month. Administrators can also change an individual unpaid invoice's due date.

## Deployment

Run the new migration before serving the updated application:

```sh
php artisan migrate --force
```

The existing Laravel scheduler must be running every minute, in the normal application environment:

```cron
* * * * * cd /path/to/zostream && php artisan schedule:run >> /dev/null 2>&1
```

`isp:bandwidth-billing` runs every five minutes (Asia/Kolkata), including midnight on the first. It generates missing completed-month invoices once per branch/month, and retries access synchronization and router disconnections after failures. To process manually:

```sh
php artisan isp:bandwidth-billing
```

Due dates include the entire selected day. After that day, any unpaid overdue invoice rejects RADIUS access for every customer in the branch and disconnects their active PPP sessions through the existing MikroTik REST integration. Scheduled enforcement normally happens within five minutes. Router connectivity and the existing RADIUS PPP configuration are required for live enforcement. This implementation controls panel-managed customers; it does not disable an upstream interface or unrelated router accounts.

Only an ISP administrator (the existing `admin` role) can mark an invoice paid or change its due date. **Bandwidth invoices** shows branch-scoped invoice history and printable details. **Mark as Paid** stores the administrator, timestamp and optional payment reference; repeating it preserves the original payment record. Paying all overdue invoices restores only customers whose own status and expiry allow access. Suspended and expired customers stay blocked.

Bandwidth Only operators can edit customer expiry dates and activate customers without recording customer payments. These actions, package renewals and manual Sync cannot bypass an overdue branch invoice. Bandwidth Only operators use Bandwidth invoices instead of Payments. Their customer cards offer Activate / Set expiry without collecting a payment. New customer collections and checkouts for these branches are rejected, including admin requests. Admin Payments lists, collection totals and dashboard customer revenue exclude these customers. Existing payment records remain stored for history and invoice retrieval; previously started paid gateway checkouts can still be settled by the existing callback. A branch with unpaid invoices cannot switch to standard, and a branch with invoice history cannot be deleted.

Invoices are created using settings available at issuance. Catch-up after downtime uses the current settings for any missing invoices. Generated invoices are never recalculated when settings change.

## Verification

```sh
vendor/bin/phpunit --filter Isp
```

Tests use an isolated in-memory database and mocked router connections. Live router blocking/unblocking still needs verification in the deployment environment.
