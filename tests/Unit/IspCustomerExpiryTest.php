<?php

namespace Tests\Unit;

use App\Isp\Models\Customer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IspCustomerExpiryTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_new_customer_expiry_is_thirty_days_from_today(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');

        $this->assertSame('2026-10-31', Customer::initialExpiryDate()->toDateString());
    }

    public function test_active_plan_is_extended_from_its_current_expiry_date(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $customer = new Customer(['expires_at' => '2026-10-15']);

        $this->assertSame('2026-11-14', $customer->nextExpiryDate()->toDateString());
    }

    public function test_plan_expiring_today_is_still_extended_from_its_expiry_date(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $customer = new Customer(['expires_at' => '2026-10-01']);

        $this->assertSame('2026-10-31', $customer->nextExpiryDate()->toDateString());
    }

    public function test_expired_plan_restarts_for_thirty_days_from_today(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $customer = new Customer(['expires_at' => '2026-09-30']);

        $this->assertSame('2026-10-31', $customer->nextExpiryDate()->toDateString());
    }
}
