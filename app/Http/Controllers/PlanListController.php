<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PlanListController extends Controller
{
    public function getPriceList()
    {
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
                'Unlock all content'
            ],
            'Thla 1' => [
                'Watch on 2 devices',
                'Ad 40%',
                'PPV 5% discount',
                'Unlock all content'
            ],
            'Thla 4' => [
                'Watch on 2 devices',
                'Ad 20%',
                'PPV 5% discount',
                'Unlock all content'
            ],
            'Thla 6' => [
                'Watch on 3 devices',
                'Ad free',
                'PPV 7% discount',
                'Unlock all content'
            ],
            'Kum 1' => [
                'Watch on 4 devices',
                'Ad free',
                'PPV 10% discount',
                'Unlock all content'
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
