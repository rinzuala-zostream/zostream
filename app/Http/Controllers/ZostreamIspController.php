<?php

namespace App\Http\Controllers;

use App\Models\New\Plan;
use App\Models\New\Subscription;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ZostreamIspController extends Controller
{
    private const THLA_ONE_MOBILE_AND_TV_PLAN_IDS = [22, 24];

    public function subscribeThlaOne(Request $request)
    {
        try {
            $validated = $request->validate([
                'phone_no' => 'nullable|string|required_without_all:phone,phone_number',
                'phone' => 'nullable|string|required_without_all:phone_no,phone_number',
                'phone_number' => 'nullable|string|required_without_all:phone_no,phone',
            ]);

            $phone = $this->normalizePhone(
                $validated['phone_no'] ?? $validated['phone'] ?? $validated['phone_number'] ?? ''
            );

            if ($phone === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Valid phone number is required',
                ], 422);
            }

            $result = DB::transaction(function () use ($phone) {
                $user = UserModel::where('auth_phone', $phone)->lockForUpdate()->first();
                $userCreated = false;

                if (!$user) {
                    $user = UserModel::create([
                        'uid' => (string) Str::uuid(),
                        'auth_phone' => $phone,
                        'created_date' => Carbon::now()->format('M d, Y h:i:s a'),
                        'device_name' => 'Zostream ISP',
                        'isACActive' => true,
                        'isAccountComplete' => false,
                        'is_auth_phone_active' => true,
                    ]);

                    $userCreated = true;
                }

                $plans = $this->thlaOnePlans();

                if ($plans->isEmpty()) {
                    return [
                        'response' => response()->json([
                            'status' => 'error',
                            'message' => 'Active Thla 1 plan not found',
                        ], 404),
                    ];
                }

                $subscriptions = $plans->map(function (Plan $plan) use ($user) {
                    $startAt = now();
                    $endAt = $startAt->copy()->addDays((int) $plan->duration_days);

                    return Subscription::create([
                        'user_id' => $user->uid,
                        'plan_id' => $plan->id,
                        'start_at' => $startAt,
                        'end_at' => $endAt,
                        'is_active' => true,
                        'renewed_by' => null,
                    ])->fresh('plan');
                })->values();

                return [
                    'response' => response()->json([
                        'status' => 'success',
                        'message' => 'Zostream ISP Thla 1 subscription added successfully',
                        'data' => [
                            'user_created' => $userCreated,
                            'user' => $user->fresh(),
                            'subscriptions' => $subscriptions,
                        ],
                    ], 201),
                ];
            });

            return $result['response'];
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add Zostream ISP subscription',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function thlaOnePlans()
    {
        return Plan::whereIn('id', self::THLA_ONE_MOBILE_AND_TV_PLAN_IDS)
            ->where('is_active', true)
            ->orderByRaw('FIELD(id, ' . implode(',', self::THLA_ONE_MOBILE_AND_TV_PLAN_IDS) . ')')
            ->get();
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone) ?? '';

        return substr($phone, -10);
    }
}
