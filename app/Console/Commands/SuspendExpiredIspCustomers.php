<?php

namespace App\Console\Commands;

use App\Isp\Models\Customer;
use App\Isp\Services\RadiusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SuspendExpiredIspCustomers extends Command
{
    protected $signature = 'isp:suspend-expired';

    protected $description = 'Suspend expired ISP customers, reject RADIUS access, and disconnect active PPP sessions.';

    public function __construct(
        private readonly RadiusService $radius,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $processed = 0;
        $failed = 0;
        $skipped = 0;

        Customer::query()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', today())
            ->where(function ($query): void {
                $query->whereNull('expiry_suspended_for')
                    ->orWhereColumn('expiry_suspended_for', '!=', 'expires_at');
            })
            ->orderBy('id')
            ->eachById(function (Customer $customer) use (&$processed, &$failed, &$skipped): void {
                $customer->refresh();

                if (! $customer->expires_at?->lt(today())
                    || $customer->expiry_suspended_for?->isSameDay($customer->expires_at)) {
                    $skipped++;

                    return;
                }

                $expiryDate = $customer->expires_at->toDateString();

                try {
                    $result = $this->radius->syncCustomer($customer);
                    Customer::query()->whereKey($customer->id)->update([
                        'expiry_suspended_for' => $expiryDate,
                    ]);
                    $processed++;
                    $this->info("Suspended customer #{$customer->id}; disconnected {$result['disconnected']} session(s).");
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("Failed to suspend customer #{$customer->id}: {$e->getMessage()}");
                    Log::error('Expired ISP customer suspension failed', [
                        'customer_id' => $customer->id,
                        'expiry_date' => $expiryDate,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        $this->info("ISP expiry suspension complete: {$processed} processed, {$failed} failed, {$skipped} skipped.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
