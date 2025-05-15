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
            'Kar 1' => 317,
            'Thla 1' => 737,
            'Thla 4' => 2797,
            'Thla 6' => 4097,
            'Kum 1' => 7897
        ];

        $planDevices = [
            'Kar 1' => ['Mobile' => 99, 'Browser' => 109, 'TV' => 109],
            'Thla 1' => ['Mobile' => 199, 'Browser' => 269, 'TV' => 269],
            'Thla 4' => ['Mobile' => 799, 'Browser' => 999, 'TV' => 999],
            'Thla 6' => ['Mobile' => 1299, 'Browser' => 1399, 'TV' => 1399],
            'Kum 1' => ['Mobile' => 2499, 'Browser' => 2699, 'TV' => 2699]
        ];

        $planDiscounts = [
            'Kar 1' => 3.6,
            'Thla 1' => 4.1,
            'Thla 4' => 5.6,
            'Thla 6' => 7.2,
            'Kum 1' => 9.6
        ];

        if (!$plan || !isset($planAmounts[$plan])) {
            return response()->json(['error' => 'Invalid plan'], 400);
        }

        // Modified original_price: sum of selected devices
        $originalPrice = 0;
        foreach ($devices as $deviceName) {
            if (isset($planDevices[$plan][$deviceName])) {
                $originalPrice += $planDevices[$plan][$deviceName];
            }
        }
        if ($originalPrice === 0 && isset($planAmounts[$plan])) {
            $originalPrice = $planAmounts[$plan];
        }

        $discountPercent = $planDiscounts[$plan];
        $discountedPrice = 0;
        $discountDetails = [];
        $totalDiscountPercent = 0;

        if (count($devices) === 1) {
            $selectedDevice = $devices[0];
            if (isset($planDevices[$plan][$selectedDevice])) {
                $discountedPrice = $planDevices[$plan][$selectedDevice];
                $discountDetails[$selectedDevice] = '0%';
            } else {
                return response()->json(['error' => 'Invalid device selected'], 400);
            }
        } elseif (count($devices) > 1) {
            $discountSplit = $this->calculateDiscountSplit($discountPercent);

            foreach ($devices as $deviceName) {
                if (isset($planDevices[$plan][$deviceName])) {
                    $devicePrice = $planDevices[$plan][$deviceName];
                    $deviceDiscountPercent = $discountSplit[$deviceName] ?? 0;
                    $deviceDiscountAmount = $devicePrice * ($deviceDiscountPercent / 100);
                    $discountedPrice += ($devicePrice - $deviceDiscountAmount);
                    $discountDetails[$deviceName] = round($deviceDiscountPercent, 2) . '%';
                    $totalDiscountPercent += $deviceDiscountPercent;
                } else {
                    return response()->json(['error' => "Invalid device: $deviceName"], 400);
                }
            }
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
