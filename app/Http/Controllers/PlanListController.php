<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PlanListController extends Controller
{
    public function getPriceList()
    {
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

        $durations = [
            'Kar 1' => 7,
            'Thla 1' => 30,
            'Thla 4' => 120,
            'Thla 6' => 180,
            'Kum 1' => 365
        ];

        $features = [
            'Kar 1' => [
                'Watch on 1 device',
                'Ads',
                'PPV 2% discount',
                'Unlock all premium content'
            ],
            'Thla 1' => [
                'Watch on 2 devices',
                'Ad 40%',
                'PPV 5% discount',
                'Unlock all premium content'
            ],
            'Thla 4' => [
                'Watch on 2 devices',
                'Ad 20%',
                'PPV 5% discount',
                'Unlock all premium content'
            ],
            'Thla 6' => [
                'Watch on 3 devices',
                'Ad free',
                'PPV 7% discount',
                'Unlock all premium content'
            ],
            'Kum 1' => [
                'Watch on 4 devices',
                'Ad free',
                'PPV 10% discount',
                'Unlock all premium content'
            ]
        ];

        $priceList = [];

        foreach ($planAmounts as $plan => $originalPrice) {
            $priceList[] = [
                'plan' => $plan,
                'original_price' => round($originalPrice, 2),
                'per_device_price' => $planDevices[$plan],
                'duration_days' => $durations[$plan],
                'features' => $features[$plan]
            ];
        }

        return response()->json($priceList
        );
    }
}
