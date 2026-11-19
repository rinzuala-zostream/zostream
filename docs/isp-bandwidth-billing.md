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

## WhatsApp invoices and operator phone numbers

Run the additional migration before deploying the phone and WhatsApp UI:

```sh
php artisan migrate --path=database/migrations/2026_11_19_000002_add_bandwidth_invoice_whatsapp_delivery.php --force
php artisan view:clear
```

Admin users have a WhatsApp phone field on Add/Edit. It is required when saving a Bandwidth Only operator and optional for the existing roles. Indian ten-digit numbers become `91` plus the number; international numbers need their country code. Existing operators should edit their account to add a phone number.

The existing `isp:bandwidth-billing` schedule first generates invoices and finishes access enforcement for all branches, then sends unpaid invoices to active operators assigned to each Bandwidth Only branch. No additional queue worker is required. Recipients with the same normalized phone share one delivery record per invoice; accepted sends are skipped on subsequent runs. Failed sends retry after 15 minutes; a processing claim older than 15 minutes can be retried. Standard branches and administrator accounts are not invoice recipients.

Delivery reuses the WiFi document template flow: a signed PDF attachment, five body parameters (recipient name, bandwidth invoice number, service dates, GST-inclusive total, due date), and an optional signed invoice-view button. The default template is the existing `zostream_wifi_invoice_v3`. To use a separately approved bandwidth template with the same parameter structure:

```dotenv
WHATSAPP_BANDWIDTH_INVOICE_TEMPLATE=zostream_wifi_invoice_v3
WHATSAPP_BANDWIDTH_INVOICE_LANGUAGE=en
```

The existing WhatsApp phone ID/token and `WHATSAPP_INVOICE_BUTTON_PARAMETER` configuration are reused. A bandwidth template with different parameters is not interchangeable. Public link and PDF use a separate Business Bandwidth design and contain bandwidth, monthly Mbps rate, subtotal, GST, total, service month, due date and current payment state. They require valid URL signatures and expose no admin controls.

The admin invoice page shows per-recipient acceptance/error/retry status and offers **Send / Retry pending WhatsApp invoices**. Successful recipients are not resent by this button. Provider acceptance is not proof of handset delivery or reading. Messages are sent by the deployed scheduler or an explicit admin action; development tests fake WhatsApp HTTP requests and do not message real users.
