<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionModel;
use App\Models\TVSubscriptionModel;
use App\Models\BrowserSubscriptionModel;
use Carbon\Carbon;
use DateInterval;
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
            $apiKey = $request->header('X-Api-Key');
            if ($apiKey !== $this->validApiKey) {
                return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
            }

            $request->validate([
                'id' => 'required|string',
                'device_type' => 'required|string',
            ]);

            $uid = $request->query('id');
            $device = $request->query('device_type');

            if ($device === 'Mobile') {
                $subscription = SubscriptionModel::where('id', $uid)->first();
            } elseif ($device === 'TV') {
                $subscription = TVSubscriptionModel::where('id', $uid)->first();
            } else {
                $subscription = BrowserSubscriptionModel::where('id', $uid)->first();
            }


            if ($subscription) {
                $createDate = new DateTime($subscription->create_date);
                $daysToAdd = $subscription->period;
                $endDate = clone $createDate;
                $endDate->modify("+{$daysToAdd} days")->setTime(23, 59, 59);

                $currentDate = new DateTime();
                $isActive = $currentDate >= $createDate && $currentDate <= $endDate;
            
                $months = $this->calculateMonthsFromInterval($createDate, $daysToAdd);

                $deviceSupport = 0;
                $isAdsFree = false;

                if ($months < 1 ) {
                    $deviceSupport = 2;
                    $isAdsFree = false;
                } elseif ($months >= 1 && $months <= 4) {
                    $deviceSupport = 2;
                    $isAdsFree = rand(1, 100) > 40;
                } elseif ($months > 4 && $months <= 6) {
                    $deviceSupport = 3;
                    $isAdsFree = rand(1, 100) > 20;
                } else {
                    $isAdsFree = true;
                    $deviceSupport = 4;
                }

                return response()->json([
                    'status' => 'success',
                    'id' => $subscription->id,
                    'create_date' => $subscription->create_date,
                    'current_date' => $currentDate->format('F j, Y'),
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
            return response()->json(['status' => 'error', 'message' => 'Internal Server Error' . $e->getMessage()], 500);
        }
    }

    public function addSubscription(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(['status' => 'error', 'message' => 'Invalid API key'], 401);
        }

        $request->validate([
            'id' => 'required|string',
            'period' => 'required|integer|min:1',
            'currentDate' => 'nullable|string',
            'plan' => 'required|string',
            'device_type' => 'required|string'
        ]);

        $id = $request->query('id');
        $period = (int) $request->query('period');
        $currentDateString = $request->query('currentDate');
        $sub_plan = $request->query('plan');
        $device_type = $request->query('device_type');

        try {
            $currentDate = $currentDateString
                ? Carbon::parse($currentDateString)
                : Carbon::now();

            $createDate = $currentDate->format('F j, Y');

            $saved = false;

            if (str_contains($device_type, 'Mobile')) {
                $saved = SubscriptionModel::updateOrInsert(
                    ['id' => $id],
                    [
                        'sub_plan' => $sub_plan,
                        'period' => $period,
                        'create_date' => $createDate,
                    ]
                );
            } 
            
            if (str_contains($device_type, 'Browser')) {
                $saved = BrowserSubscriptionModel::updateOrInsert(
                    ['id' => $id],
                    [
                        'sub_plan' => $sub_plan,
                        'period' => $period,
                        'create_date' => $createDate,
                    ]
                );
            } 
            
            if (str_contains($device_type, 'TV')) {
                $saved = TVSubscriptionModel::updateOrInsert(
                    ['id' => $id],
                    [
                        'sub_plan' => $sub_plan,
                        'period' => $period,
                        'create_date' => $createDate,
                    ]
                );
            }

            if ($saved) {
                $deleteResponse = $this->deleteSharedUser($id, $apiKey);

                if ($deleteResponse instanceof \Illuminate\Http\JsonResponse && $deleteResponse->getStatusCode() !== 200) {
                    return response()->json([
                        'status' => 'success',
                        'message' => json_decode($deleteResponse->getContent(), true)
                    ]);
                }

                return response()->json(['status' => 'success', 'message' => 'Record saved successfully']);
            }

            return response()->json(['status' => 'error', 'message' => 'Failed to save subscription']);

        } catch (\Exception $e) {
            Log::error('Error in addSubscription: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
        
    }

    private function deleteSharedUser($id, $apiKey)
    {
        try {
            $deviceRequest = new Request([
                'user_id' => $id,
            ]);

            $deviceRequest->headers->set('X-Api-Key', $apiKey);
            return $this->deviceManagementController->delete($deviceRequest);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Share delete failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function calculateMonthsFromInterval($startDate, $days) {
        // Create a DateTime object for the given start date
        $startDateObj = new DateTime($startDate);
        
        // Add the given number of days as a DateInterval
        $interval = new DateInterval('P' . $days . 'D');
        $startDateObj->add($interval);
        
        // Get the difference in months between the start date and the end date
        $endDate = new DateTime($startDate); // Another DateTime object for the original start date
        $diff = $startDateObj->diff($endDate);
        
        // Return the total number of months (years converted to months + the months)
        return $diff->m + ($diff->y * 12); 
    }
}
