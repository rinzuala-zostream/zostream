<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FCMNotificationController;
use App\Models\New\AdminUser;
use App\Models\New\CustomerSupport;
use App\Models\New\CustomerSupportReply;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CustomerSupportController extends Controller
{
    public function __construct(
        private FCMNotificationController $fcmNotificationController
    ) {
    }

    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|string|exists:user,uid',
                'status' => 'nullable|in:pending,seen,closed',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            $query = CustomerSupport::with([
                'user:num,uid,name,mail,call,auth_phone,token',
                'latestReply',
            ])->withCount('replies')->orderByDesc('created_at');

            if (!empty($validated['user_id'])) {
                $query->where('user_id', $validated['user_id']);
            }

            if (!empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            $supports = $query->paginate((int) ($validated['per_page'] ?? 15));

            return response()->json([
                'status' => 'success',
                'data' => $supports,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Customer support index error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch support tickets',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|string|exists:user,uid',
                'complaint' => 'required|string|max:5000',
            ]);

            $support = DB::transaction(function () use ($validated) {
                return CustomerSupport::create([
                    'user_id' => $validated['user_id'],
                    'complaint' => trim($validated['complaint']),
                    'status' => 'pending',
                ]);
            });

            $support->load('user');
            $adminNotification = $this->notifyAdminsAboutComplaint($support);

            return response()->json([
                'status' => 'success',
                'message' => 'Complaint submitted successfully',
                'data' => $support,
                'notification' => $adminNotification,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Customer support create error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit complaint',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $support = CustomerSupport::with([
                'user:num,uid,name,mail,call,auth_phone,token',
                'replies.admin.user:num,uid,name,mail,call,auth_phone',
            ])->find($id);

            if (!$support) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Support ticket not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $support,
            ]);
        } catch (\Exception $e) {
            Log::error('Customer support show error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch support ticket',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function reply(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'admin_id' => 'required|integer|exists:admin_users,id',
                'reply' => 'required|string|max:5000',
            ]);

            $support = CustomerSupport::with('user')->find($id);

            if (!$support) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Support ticket not found',
                ], 404);
            }

            $reply = DB::transaction(function () use ($validated, $support) {
                $reply = CustomerSupportReply::create([
                    'support_id' => $support->id,
                    'admin_id' => $validated['admin_id'],
                    'reply' => trim($validated['reply']),
                ]);

                $support->update([
                    'status' => 'seen',
                ]);

                return $reply;
            });

            $reply->load('admin.user');
            $userNotification = $this->notifyUserAboutReply($support, $reply);

            return response()->json([
                'status' => 'success',
                'message' => 'Reply sent successfully',
                'data' => $reply,
                'notification' => $userNotification,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Customer support reply error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send reply',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:pending,seen,closed',
            ]);

            $support = CustomerSupport::find($id);

            if (!$support) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Support ticket not found',
                ], 404);
            }

            $support->update([
                'status' => $validated['status'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Support status updated successfully',
                'data' => $support->fresh(),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Customer support status update error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update support status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function registerAdminDevice(Request $request)
    {
        try {
            $validated = $request->validate([
                'admin_uid' => 'required|string|exists:user,uid',
                'fcm_token' => 'required|string|max:5000',
                'device_name' => 'nullable|string|max:100',
            ]);

            $adminUser = AdminUser::updateOrCreate(
                [
                    'admin_uid' => $validated['admin_uid'],
                    'fcm_token' => $validated['fcm_token'],
                ],
                [
                    'device_name' => $validated['device_name'] ?? null,
                ]
            );

            $adminUser->load('user');

            return response()->json([
                'status' => 'success',
                'message' => 'Admin device registered successfully',
                'data' => $adminUser,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Admin support device register error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to register admin device',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteAdminDevice(Request $request)
    {
        try {
            $validated = $request->validate([
                'admin_uid' => 'required|string',
                'fcm_token' => 'required|string',
            ]);

            $deleted = AdminUser::where('admin_uid', $validated['admin_uid'])
                ->where('fcm_token', $validated['fcm_token'])
                ->delete();

            if (!$deleted) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Admin device not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Admin device removed successfully',
                'deleted_count' => $deleted,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Admin support device delete error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove admin device',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function notifyAdminsAboutComplaint(CustomerSupport $support): array
    {
        $adminUsers = AdminUser::query()
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->get();

        if ($adminUsers->isEmpty()) {
            return [
                'attempted' => 0,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $title = 'New complaint received';
        $body = 'A user has submitted a new support complaint.';
        $data = [
            'type' => 'customer_support_new',
            'support_id' => $support->id,
            'user_id' => $support->user_id,
            'status' => $support->status,
        ];

        return $this->sendNotificationsToTokens($adminUsers->pluck('fcm_token')->all(), $title, $body, $data);
    }

    private function notifyUserAboutReply(CustomerSupport $support, CustomerSupportReply $reply): array
    {
        $user = $support->user ?? UserModel::where('uid', $support->user_id)->first();
        $token = trim((string) ($user?->token ?? ''));

        if ($token === '') {
            return [
                'attempted' => 0,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $title = 'Support reply received';
        $body = $this->truncateForNotification($reply->reply);
        $data = [
            'type' => 'customer_support_reply',
            'support_id' => $support->id,
            'status' => 'seen',
        ];

        return $this->sendNotificationsToTokens([$token], $title, $body, $data);
    }

    private function sendNotificationsToTokens(array $tokens, string $title, string $body, array $data): array
    {
        $tokens = array_values(array_unique(array_filter(array_map(
            static fn ($token) => trim((string) $token),
            $tokens
        ))));

        $sent = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            $result = $this->fcmNotificationController->sendToToken(
                $token,
                $title,
                $body,
                '',
                'customer_support',
                $data
            );

            if (($result['success'] ?? false) === true) {
                $sent++;
            } else {
                $failed++;
                Log::warning('Customer support notification failed', [
                    'token' => $token,
                    'result' => $result,
                ]);
            }
        }

        return [
            'attempted' => count($tokens),
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    private function truncateForNotification(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message));

        if (mb_strlen($message) <= 120) {
            return $message;
        }

        return mb_substr($message, 0, 117) . '...';
    }
}
