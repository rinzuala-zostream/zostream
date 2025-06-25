<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionHistoryModel;
use App\Models\SubscriptionModel;
use App\Models\TVSubscriptionModel;
use App\Models\BrowserSubscriptionModel;
use App\Models\UserModel;
use App\Models\ZonetSubscriptionModel;
use App\Models\ZonetUserModel;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use DateInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DateTime;

class SubscriptionController extends Controller
{
    private $validApiKey;
    protected $deviceManagementController;
    protected $streamController;

    public function __construct(DeviceManagementController $deviceManagementController, StreamController $streamController)
    {
        $this->validApiKey = config('app.api_key');
        $this->deviceManagementController = $deviceManagementController;
        $this->streamController = $streamController;
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
                'ip' => 'nullable|string',
            ]);

            $uid = $request->query('id');
            $device = $request->query('device_type');
            $ip = $request->query('ip');

            $subscriptions = [];

            if (!$device) {
                $subscriptions[] = [
                    'device_type' => 'Mobile',
                    'data' => SubscriptionModel::where('id', $uid)->first()
                ];
                $subscriptions[] = [
                    'device_type' => 'TV',
                    'data' => TVSubscriptionModel::where('id', $uid)->first()
                ];
                $subscriptions[] = [
                    'device_type' => 'Browser',
                    'data' => BrowserSubscriptionModel::where('id', $uid)->first()
                ];
                $subscriptions[] = [
                    'device_type' => 'TV',
                    'data' => ZonetUserModel::where('id', $uid)->first()
                ];
            }
            if ($ip) {
                // Call route to check if IP is from ISP
                $ispRequest = new Request(['ip' => $ip]);
                $ispResponse = $this->streamController->stream($ispRequest);
                $responseData = $ispResponse->getData(true);

                $isFromISP = $responseData['is_from_isp'] ?? false;

                if ($isFromISP) {
                    $zonetUser = ZonetUserModel::where('id', $uid)->first();

                    if ($zonetUser) {
                        $subscription = ZonetSubscriptionModel::where('user_num', $zonetUser->num)
                            ->orderByDesc('id')
                            ->first();

                        $subscriptions[] = [
                            'device_type' => $device,
                            'data' => $subscription
                        ];
                    } else {
                        $subscriptions[] = [
                            'device_type' => $device,
                            'data' => null
                        ];
                    }
                } else {
                    $model = match ($device) {
                        'TV' => TVSubscriptionModel::class,
                        'Mobile' => SubscriptionModel::class,
                        default => BrowserSubscriptionModel::class,
                    };

                    $subscriptions[] = [
                        'device_type' => $device,
                        'data' => $model::where('id', $uid)->first()
                    ];
                }
            } else {
                // If IP is null, skip the ISP check and fetch from regular model
                $model = match ($device) {
                    'TV' => TVSubscriptionModel::class,
                    'Mobile' => SubscriptionModel::class,
                    default => BrowserSubscriptionModel::class,
                };

                $subscriptions[] = [
                    'device_type' => $device,
                    'data' => $model::where('id', $uid)->first()
                ];
            }

            $results = [];

            foreach ($subscriptions as $entry) {
                $deviceType = $entry['device_type'];
                $subscription = $entry['data'];

                if ($subscription) {
                    $createDate = new DateTime($subscription->create_date);
                    $daysToAdd = $subscription->period;

                    $expiryDate = clone $createDate;
                    $expiryDate->modify("+{$daysToAdd} days")->setTime(23, 59, 59);
                    $currentDate = new DateTime();

                    $isActive = $currentDate >= $createDate && $currentDate <= $expiryDate;

                    $startDateObj = clone $createDate;
                    $endDateObj = clone $createDate;
                    $endDateObj->modify("+{$daysToAdd} days");
                    $diff = $startDateObj->diff($endDateObj);
                    $months = $diff->m + ($diff->y * 12);

                    $deviceSupport = match (true) {
                        $months < 1 => 1,
                        $months <= 4 => 2,
                        $months <= 6 => 3,
                        default => 4,
                    };

                    $isAdsFree = match (true) {
                        $months < 1 => false,
                        $months < 4 => rand(1, 100) > 40,
                        $months < 6 => rand(1, 100) > 20,
                        default => true,
                    };

                    $subscriptionResult = [
                        'status' => 'success',
                        'device_type' => $deviceType,
                        'id' => $subscription->id,
                        'create_date' => $createDate->format('F j, Y'),
                        'current_date' => $currentDate->format('F j, Y'),
                        'period' => $subscription->period,
                        'sub_plan' => $subscription->sub_plan,
                        'sub' => $isActive,
                        'expiry_date' => $expiryDate->format('F j, Y'),
                        'device_support' => $deviceSupport,
                        'isAdsFree' => $isAdsFree,
                    ];

                    if ($device) {
                        // Return single object directly if device_type is provided
                        return response()->json($subscriptionResult);
                    }

                    $results[] = $subscriptionResult;
                }
            }

            if (empty($results)) {
                return response()->json(['status' => 'error', 'message' => 'No data found for the given id']);
            }

            return response()->json(
                $results
            );

        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            return response()->json(['status' => 'error', 'message' => 'Invalid encrypted API key'], 403);
        } catch (\Exception $e) {
            Log::error('Error fetching subscription data: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Internal Server Error: ' . $e->getMessage()], 500);
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

    private function calculateMonthsFromInterval($createDate, $daysToAdd)
    {
        // If $createDate is a DateTime object, use it directly, otherwise convert it to a DateTime object from string
        if (!$createDate instanceof DateTime) {
            $createDate = new DateTime($createDate);  // Convert string to DateTime
        }

        // Add the given number of days as a DateInterval to the start date
        $interval = new DateInterval('P' . $daysToAdd . 'D');
        $createDate->add($interval);

        // Get the current date for comparison
        $currentDate = new DateTime(); // Or use any other reference date here

        // Calculate the difference in years, months, and days
        $diff = $createDate->diff($currentDate);

        // Calculate total months: years converted to months + the months difference
        $totalMonths = ($diff->y * 12) + $diff->m;

        // Return the total number of months
        return $totalMonths;
    }

    private function fetchHistory($uid)
    {
        return SubscriptionHistoryModel::where('uid', $uid)
            ->orderByDesc('num')
            ->get();
    }

    // GET request: /subscription-history?uid=123
    public function getHistory(Request $request)
    {

        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        $validated = $request->validate([
            'uid' => 'required|string',
        ]);

        $history = $this->fetchHistory($validated['uid']);

        if ($history->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No subscription history found for this UID.'
            ], 404);
        }

        return response()->json(
            $history
        );
    }

    // POST request: JSON body { all fields except `num` }
    public function addHistory(Request $request)
    {
        $validated = $request->validate([
            'uid' => 'required|string',
            'pid' => 'required|string',
            'plan' => 'required|string',
            'platform' => 'required|string',
            'pg' => 'required|string',
            'total_pay' => 'required|int',
            'amount' => 'required|int',
            'plan_start' => 'required|string',
            'plan_end' => 'nullable|string',
            'mail' => 'nullable|string',
            'phone' => 'nullable|string',
            'hming' => 'nullable|string',
        ]);

        $entry = SubscriptionHistoryModel::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Subscription history added successfully.',
            'entry' => $entry
        ]);
    }

    public function generateInvoice($num)
    {
        $subscription = SubscriptionHistoryModel::where('num', $num)->firstOrFail();
        $user = UserModel::where('mail', $subscription->mail)->first();

        $data = [
            'hming' => $user->name ?? 'N/A',
            'mail' => $user->mail ?? 'N/A',
            'phone' => $user->call ?? 'N/A',
            'uid' => $subscription->uid,
            'plan' => $subscription->plan,
            'pid' => $subscription->pid,
            'amount' => $subscription->amount,
            'method' => $subscription->method,
            'plan_start' => $subscription->plan_start,
            'plan_end' => $subscription->plan_end,
            'invoice_no' => 'INV-' . str_pad($subscription->id, 10, '0', STR_PAD_LEFT),
            'created_at' => $subscription->created_at,
            'address' => $user->address ?? 'Aizawl',
            'total_pay' => $subscription->total_pay,
            'pg' => $subscription->pg ?? 'N/A',
        ];

        $pdf = Pdf::loadView('pdf.invoice', ['data' => (object) $data]);

        return $pdf->download("invoice_{$data['uid']}_{$data['pid']}.pdf");
    }
}
