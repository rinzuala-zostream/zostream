<?php

namespace App\Isp\Services;

use App\Isp\Models\BandwidthInvoice;
use App\Isp\Models\Branch;
use Illuminate\Support\Facades\DB;
use Throwable;

class BandwidthBillingService
{
    public function __construct(private readonly RadiusService $radius) {}

    public function generate(Branch $branch): int
    {
        if (! $branch->isBandwidthOnly() || ! $branch->bandwidth_started_at) {
            return 0;
        }
        $created = 0;
        $month = $branch->bandwidth_started_at->copy()->startOfMonth();
        while ($month->lt(today()->startOfMonth())) {
            $issued = $month->copy()->addMonth();
            $subtotal = round((float) $branch->bandwidth_mbps * (float) $branch->bandwidth_rate, 2);
            $gst = round($subtotal * (float) $branch->bandwidth_gst / 100, 2);
            $invoice = BandwidthInvoice::whereDate('billing_month', $month->toDateString())->firstOrCreate([
                'branch_id' => $branch->id,
            ], [
                'billing_month' => $month->toDateString(),
                'branch_name' => $branch->name,
                'issued_on' => $issued->toDateString(),
                'due_date' => $issued->copy()->day(min((int) $branch->bandwidth_due_day, $issued->daysInMonth))->toDateString(),
                'bandwidth_mbps' => $branch->bandwidth_mbps,
                'rate' => $branch->bandwidth_rate,
                'gst_percentage' => $branch->bandwidth_gst,
                'subtotal' => $subtotal,
                'gst_amount' => $gst,
                'total' => round($subtotal + $gst, 2),
            ]);
            $created += (int) $invoice->wasRecentlyCreated;
            $month->addMonth();
        }

        return $created;
    }

    // Keep manual status and expiry intact. Retry failed router operations on the next run.
    public function reconcile(Branch $branch, bool $force = false): int
    {
        if ($force) {
            $branch->customers()->update(['bandwidth_access_blocked' => null]);
        }
        $blocked = $branch->hasOverdueBandwidthInvoice();
        $failed = 0;
        $disconnects = [];
        $branch->customers()->with(['router', 'package'])->where(function ($query) use ($blocked): void {
            $query->whereNull('bandwidth_access_blocked')->orWhere('bandwidth_access_blocked', '!=', $blocked);
        })->eachById(function ($customer) use ($branch, $blocked, &$failed, &$disconnects): void {
            try {
                $customer->setRelation('branch', $branch);
                // Reject all affected logins before making any potentially slow router requests.
                $result = $this->radius->syncCustomer($customer, disconnectSuspended: false);
                if (! $result['active'] && $customer->router) {
                    $routerId = $customer->router_id;
                    $disconnects[$routerId]['router'] = $customer->router;
                    $disconnects[$routerId]['customers'][$customer->id] = $customer->username;
                } else {
                    DB::table('customers')->where('id', $customer->id)->update(['bandwidth_access_blocked' => $blocked]);
                }
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        });
        foreach ($disconnects as $group) {
            try {
                // One active-session lookup per router, even for a large branch.
                $this->radius->disconnectUsernames($group['router'], array_values($group['customers']));
                DB::table('customers')->whereIn('id', array_keys($group['customers']))
                    ->update(['bandwidth_access_blocked' => $blocked]);
            } catch (Throwable $e) {
                $failed += count($group['customers']);
                report($e);
            }
        }

        return $failed;
    }
}
