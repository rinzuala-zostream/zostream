<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('otp-request', function (Request $request) {
            return $this->otpRecipientLimit(
                $request,
                'customer-otp',
                (int) config('otp.request_max_attempts', 10)
            );
        });

        RateLimiter::for('admin-otp-request', function (Request $request) {
            return $this->otpRecipientLimit(
                $request,
                'admin-otp',
                (int) config('otp.admin_request_max_attempts', 6)
            );
        });

        RateLimiter::for('account-deletion-otp', function (Request $request) {
            return $this->otpRecipientLimit(
                $request,
                'account-deletion-otp',
                (int) config('otp.account_deletion_max_attempts', 6)
            );
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            $userId = trim((string) $request->input('user_id', ''));
            $identifier = $userId !== '' ? strtolower($userId) : $request->ip();

            return Limit::perMinute(max(1, (int) config('otp.verify_max_attempts', 10)))
                ->by('otp-verify:'.hash('sha256', $identifier));
        });
    }

    private function otpRecipientLimit(Request $request, string $scope, int $maxAttempts): Limit
    {
        $countryCode = preg_replace('/\D+/', '', (string) $request->input('country_code', ''));
        $phoneNumber = preg_replace('/\D+/', '', (string) $request->input('phone_number', ''));

        if ($countryCode !== '' && ! str_starts_with($phoneNumber, $countryCode)) {
            $phoneNumber = $countryCode.$phoneNumber;
        }

        $identifier = $phoneNumber !== '' ? $phoneNumber : $request->ip();

        return Limit::perMinute(max(1, $maxAttempts))
            ->by($scope.':'.hash('sha256', $identifier));
    }
}
