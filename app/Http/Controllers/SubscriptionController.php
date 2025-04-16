<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionModel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use DateTime;

class SubscriptionController extends Controller
{
    private $validApiKey;
    protected $deviceManagementController; 

    public function __construct(DeviceManagementController $deviceManagementController)
    {
        $this->validApiKey = config('app.api_key');
        $this->deviceManagementController = $deviceManagementController;
    }

    public function getSubscription(Request $request)
    {
        try {
            // Decrypt the API key from header
            
            $apiKey = $request->header('X-Api-Key');
            
            if ($apiKey !== $this->validApiKey) {
                return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
            }

            // Validate the subscription ID input
            $validatedData = $request->validate([
                'id' => 'required|string',
            ]);

            // Fetch subscription by id
            $subscription = SubscriptionModel::where('id', $validatedData['id'])->first();

            if ($subscription) {
                $createDate = new DateTime($subscription->create_date);
                $daysToAdd = $subscription->period;
                $endDate = clone $createDate;
                $endDate->modify("+{$daysToAdd} days");

                $currentDate = new DateTime();
                $isActive = $currentDate >= $createDate && $currentDate <= $endDate;

                $interval = $createDate->diff($endDate);
                $months = ($interval->y * 12) + $interval->m;

                $deviceSupport = 0;
                if ($months < 1) {
                    $deviceSupport = 1;
                } elseif ($months < 12) {
                    $deviceSupport = 2;
                } elseif ($months >= 12) {
                    $deviceSupport = 4;
                }

                $isAdsFree = false;
                if ($subscription->sub_plan >= 'Thla 1' && $subscription->sub_plan < 'Thla 6') {
                    $isAdsFree = rand(1, 100) > 40;
                } elseif ($subscription->sub_plan >= 'Thla 6') {
                    $isAdsFree = true;
                }

                return response()->json([
                    'status' => 'success',
                    'id' => $subscription->id,
                    'create_date' => $subscription->create_date,
                    'period' => $subscription->period,
                    'sub_plan' => $subscription->sub_plan,
                    'sub' => $isActive,
                    'expiry_date' => $endDate->format('F j, Y'),
                    'device_support' => $deviceSupport,
                    'isAdsFree' => $isAdsFree,
                ]);
            } else {
                return response()->json(['status' => 'error', 'message' => 'No data found for the given id']);
            }

        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            return response()->json(['status' => 'error', 'message' => 'Invalid encrypted API key'], 403);
        } catch (\Exception $e) {
            Log::error('Error fetching subscription data: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Internal Server Error'], 500);
        }
    }

    public function addSubscription(Request $request)
{
    $apiKey = $request->header('X-Api-Key');
    if ($apiKey !== $this->validApiKey) {
        return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
    }

    // Validate query parameters
    $validated = $request->validate([
        'id' => 'required|string',
        'period' => 'required|integer|min:1',
        'currentDate' => 'nullable|string'
    ]);

    $id = $request->query('id');
    $period = (int) $request->query('period');
    $currentDateString = $request->query('currentDate');

    try {
        $currentDate = $currentDateString
            ? Carbon::parse($currentDateString)
            : Carbon::now();
    } catch (\Exception $e) {
        Log::error('Invalid currentDate format: ' . $e->getMessage());
        return response()->json(['status' => 'error', 'message' => 'Invalid currentDate format']);
    }

    try {
        $endDate = (clone $currentDate)->addDays($period);
        $interval = $currentDate->diff($endDate);
        $sub_plan = $this->generateSubPlan($interval);
        $createDate = $currentDate->format('F j, Y');

        SubscriptionModel::updateOrInsert(
            ['id' => $id],
            [
                'sub_plan' => $sub_plan,
                'period' => $period,
                'create_date' => $createDate,
            ]
        );

        $this->deleteSharedUser($id, $apiKey);

        return response()->json(['status' => 'success', 'message' => 'Record saved successfully']);
    } catch (\Exception $e) {
        Log::error('Error in addSubscription: ' . $e->getMessage());
        return response()->json(['status' => 'error', 'message' => 'Internal Server Error'], 500);
    }
}

private function generateSubPlan(\DateInterval $interval)
{
    if ($interval->days >= 365) {
        if ($interval->y == 1 && $interval->m == 0 && $interval->d == 0) {
            return "Kum 1";
        } else {
            $output = '';
            if ($interval->y > 0) $output .= "Kum {$interval->y} ";
            if ($interval->m > 0) $output .= "leh thla {$interval->m} ";
            if ($interval->d > 0) $output .= "leh ni {$interval->d}";
            return trim($output);
        }
    } else {
        $months = $interval->m;
        $days = $interval->d;
        if ($months > 0 && $days > 0) {
            return "Thla $months leh ni $days";
        } elseif ($months > 0) {
            return "Thla $months";
        } elseif ($days > 0) {
            return "Ni $days";
        } else {
            return "Dates are the same";
        }
    }
}

private function deleteSharedUser($id, $apiKey)
{
    try {
        $deviceRequest = new Request([
            'user_id' => $id,
        ]);

        $deviceRequest->headers->set('X-Api-Key', $apiKey);

        // Call the controller method safely
        app(\App\Http\Controllers\DeviceManagementController::class)->delete($deviceRequest);
    } catch (\Exception $e) {
        Log::error('Failed to delete shared user: ' . $e->getMessage());
        // Don’t return a response here
    }
}


}
