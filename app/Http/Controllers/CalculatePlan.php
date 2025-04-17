<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;

class CalculatePlan extends Controller
{

    private $validApiKey;

    public function __construct()
    
    {
        $this->validApiKey = config('app.api_key');
     
    }
    public function calculate(Request $request)
    {
        // Check API key
        $apiKey = $request->header('X-Api-Key');

    if ($apiKey !== $this->validApiKey) {
        return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
    }

        // Check 'period' parameter
        $period = (int) $request->query('period');
        if (!$period || $period < 7) {
            return response()->json([
                'status' => 'error', 'message' => 'Invalid period parameter provided or period is not less than 7'
            ]);
        }

        // Use provided current date or default to today
        $currentDate = $request->query('current_date') 
            ? Carbon::parse($request->query('current_date')) 
            : Carbon::now();

        $modifiedDate = $currentDate->copy()->addDays($period);
        $interval = $currentDate->diff($modifiedDate);

        // Generate sub_plan
        if ($interval->days >= 365) {
            if ($interval->y == 1 && $interval->m == 0 && $interval->d == 0) {
                $sub_plan = "Kum 1";
            } else {
                $output = '';
                if ($interval->y > 0) $output .= "Kum {$interval->y} ";
                if ($interval->m > 0) $output .= "leh thla {$interval->m} ";
                if ($interval->d > 0) $output .= "leh ni {$interval->d}";
                $sub_plan = trim($output);
            }
        } else {
            if ($interval->m > 0 && $interval->d > 0) {
                $sub_plan = "Thla {$interval->m} leh ni {$interval->d}";
            } elseif ($interval->m > 0) {
                $sub_plan = "Thla {$interval->m}";
            } elseif ($interval->d > 0) {
                $sub_plan = "Ni {$interval->d}";
            } else {
                $sub_plan = "Dates are the same";
            }
        }

        $actual_amount = $period * 10;

        // Discount logic
        if ($interval->m >= 1 || $interval->y >= 1) {
            $amount = $interval->y * 12 * 169 + $interval->m * 169;
            if ($interval->d > 0) {
                $amount += ($interval->d / 30) * 169;
            }
        } else {
            $amount = $actual_amount;
        }

        $discount_amount = $actual_amount - $amount;
        $discount_percentage = $actual_amount > 0 ? (($discount_amount / $actual_amount) * 100) : 0;

        return response()->json([
            'status' => 'success',
            'data' => [
                'current_date' => $currentDate->format('F j, Y'),
                'expiry_date' => $modifiedDate->format('F j, Y'),
                'amount' => intval($actual_amount),
                'discount' => intval($discount_amount),
                'discount_percentage' => intval($discount_percentage),
                'total_pay' => intval($amount),
                'sub_plan' => $sub_plan,
            ]
        ]);
    }
}
