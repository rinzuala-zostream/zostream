<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;

class PlanPriceController extends Controller
{
    public function getPlanPrice(Request $request)
    {
        $plan = $request->query('plan');
        $device = $request->query('device');

        $devices = $device ? array_map('trim', explode(',', $device)) : [];

        $planAmounts = [
            'Kar 1' => 288,
            'Thla 1' => 638,
            'Thla 4' => 2407,
            'Thla 6' => 3577,
            'Kum 1' => 6991
        ];

        $planDevices = [
            'Kar 1' => ['Mobile' => 84, 'Browser' => 99, 'TV' => 105],
            'Thla 1' => ['Mobile' => 194, 'Browser' => 219, 'TV' => 225],
            'Thla 4' => ['Mobile' => 719, 'Browser' => 829, 'TV' => 859],
            'Thla 6' => ['Mobile' => 1089, 'Browser' => 1199, 'TV' => 1289],
            'Kum 1' => ['Mobile' => 2133, 'Browser' => 2348, 'TV' => 2510]
        ];

        $planDiscounts = [
            'Kar 1' => 18.58,
            'Thla 1' => 21.78,
            'Thla 4' => 16.95,
            'Thla 6' => 16.14,
            'Kum 1' => 14.17
        ];

        if (!$plan || !isset($planAmounts[$plan])) {
            return response()->json(['error' => 'Invalid plan'], 400);
        }

        $originalPrice = $planAmounts[$plan];
        $discountData = $this->calculateDiscountSplit($planDiscounts[$plan]);

        $totalDiscountPercent = 0;
        $discountDetails = [];
        $discountedPrice = $originalPrice;

        if (count($devices) === 1) {
            $selectedDevice = $devices[0];

            if (isset($planDevices[$plan][$selectedDevice])) {
                $discountedPrice = $planDevices[$plan][$selectedDevice];
                $discountDetails[$selectedDevice] = '0%';
            } else {
                return response()->json(['error' => 'Invalid device selected'], 400);
            }
        } else {
            foreach ($devices as $deviceName) {
                if (isset($discountData[$deviceName])) {
                    $totalDiscountPercent += $discountData[$deviceName];
                    $discountDetails[$deviceName] = $discountData[$deviceName] . '%';
                } else {
                    $discountDetails[$deviceName] = 'Invalid device';
                }
            }

            $discountedPrice = $originalPrice - ($originalPrice * ($totalDiscountPercent / 100));
        }

        $startDate = Carbon::now();
        $endDate = match ($plan) {
            'Kar 1' => $startDate->copy()->addDays(7),
            'Thla 1' => $startDate->copy()->addMonth(),
            'Thla 4' => $startDate->copy()->addMonths(4),
            'Thla 6' => $startDate->copy()->addMonths(6),
            'Kum 1' => $startDate->copy()->addYear(),
            default => $startDate
        };

        $period = $startDate->diffInDays($endDate);

        return response()->json([
            'status' => 'success',
            'data' => [
                'original_price' => round($originalPrice, 2),
                'discounted_price' => round($discountedPrice, 2),
                'total_discount_percent' => round($totalDiscountPercent, 2) . '%',
                'start_date' => $startDate->toDateString(),
                'expiry_date' => $endDate->toDateString(),
                'period' => $period,
                'plan' => $plan,
                'devices' => $discountDetails
            ]
        ]);        
    }

    private function calculateDiscountSplit($discountPercent)
    {
        $totalParts = 6; // TV:Browser:Mobile = 3:2:1
        $partValue = $discountPercent / $totalParts;

        return [
            'TV' => round($partValue * 3, 2),
            'Browser' => round($partValue * 2, 2),
            'Mobile' => round($partValue * 1, 2)
        ];
    }
}
