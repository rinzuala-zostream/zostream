<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Services\AdPricingService;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;

class AdPricingController extends Controller
{
    public function __construct(private readonly AdPricingService $pricing) {}

    public function index()
    {
        return V4Response::success(['placements' => $this->pricing->catalogue()]);
    }

    public function quote(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:image,video,website'],
            'placement_code' => ['required', 'string', 'max:60'],
            'billing_model' => ['required', 'in:FLAT,CPM,CPC,CPV'],
            'target_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'requested_period_days' => ['required', 'integer', 'min:1', 'max:366'],
        ]);

        return V4Response::success($this->pricing->quote($data));
    }
}
