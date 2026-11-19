<?php

namespace App\Console\Commands;

use App\Isp\Models\Branch;
use App\Isp\Services\BandwidthBillingService;
use App\Isp\Services\BandwidthInvoiceWhatsAppService;
use Illuminate\Console\Command;
use Throwable;

class ProcessIspBandwidthBilling extends Command
{
    protected $signature = 'isp:bandwidth-billing';

    protected $description = 'Generate previous-month bandwidth invoices and enforce unpaid invoice access blocks.';

    public function handle(BandwidthBillingService $billing, BandwidthInvoiceWhatsAppService $whatsApp): int
    {
        $created = 0;
        $failed = 0;
        Branch::where('billing_type', 'bandwidth_only')->eachById(function (Branch $branch) use ($billing, &$created, &$failed): void {
            try {
                $created += $billing->generate($branch);
                $failed += $billing->reconcile($branch);
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("Branch #{$branch->id}: {$e->getMessage()}");
            }
        });
        // Finish access enforcement for every branch before contacting WhatsApp.
        Branch::where('billing_type', 'bandwidth_only')->eachById(function (Branch $branch) use ($whatsApp, &$failed): void {
            try {
                $failed += $whatsApp->sendPending($branch);
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("WhatsApp for branch #{$branch->id}: {$e->getMessage()}");
            }
        });
        $this->info("Bandwidth billing: {$created} invoice(s) created, {$failed} failure(s).");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
