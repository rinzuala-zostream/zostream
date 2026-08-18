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

        RateLimiter::for('playback-start', function (Request $request) {
            return $this->playbackDeviceLimit(
                $request,
                'start',
                (int) config('playback.rate_limits.start_per_minute', 60)
            );
        });

        RateLimiter::for('playback-stop', function (Request $request) {
            return $this->playbackDeviceLimit(
                $request,
                'stop',
                (int) config('playback.rate_limits.stop_per_minute', 120)
            );
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

    private function playbackDeviceLimit(Request $request, string $scope, int $maxAttempts): Limit
    {
        // Prefer identities supplied by AuthTokenMiddleware. Device-Token keeps
        // legacy authenticated sessions isolated before falling back to an IP,
        // which may be shared by many viewers behind Cloudflare or carrier NAT.
        $deviceId = trim((string) $request->input('auth_device_id', ''));
        $sessionId = trim((string) $request->attributes->get('auth_session_token_id', ''));
        $headerDeviceId = trim((string) $request->header('Device-Token', ''));
        $bodyDeviceId = trim((string) (
            $request->input('device_token')
            ?: $request->input('device_id')
            ?: ''
        ));

        $identifier = match (true) {
            $deviceId !== '' => 'device:'.$deviceId,
            $sessionId !== '' => 'session:'.$sessionId,
            $headerDeviceId !== '' => 'header-device:'.$headerDeviceId,
            $bodyDeviceId !== '' => 'body-device:'.$bodyDeviceId,
            default => 'ip:'.$request->ip(),
        };

        return Limit::perMinute(max(1, $maxAttempts))
            ->by('playback-'.$scope.':'.hash('sha256', $identifier));
    }
}
