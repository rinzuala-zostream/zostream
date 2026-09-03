<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\AdSubmission;
use App\Services\AdApprovalNotificationService;
use App\Services\AdApprovalService;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminAdSubmissionController extends Controller
{
    public function __construct(
        private readonly AdApprovalService $approvalService,
        private readonly AdApprovalNotificationService $notifications,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([
                AdSubmission::STATUS_PENDING,
                AdSubmission::STATUS_CHANGES_REQUESTED,
                AdSubmission::STATUS_APPROVED,
                AdSubmission::STATUS_REJECTED,
            ])],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AdSubmission::query()->with([
            'user:num,uid,name,mail,country_code,auth_phone',
            'assets',
            'campaign.invoices',
        ])->latest();
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if ($search = trim((string) ($data['search'] ?? ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('reference_no', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('contact_phone', 'like', "%{$search}%")
                    ->orWhere('ads_name', 'like', "%{$search}%");
            });
        }

        $page = $query->paginate($data['per_page'] ?? 20);
        $counts = AdSubmission::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return V4Response::success([
            'items' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => $counts,
        ]);
    }

    public function show(AdSubmission $adSubmission)
    {
        return V4Response::success($adSubmission->load([
            'user:num,uid,name,mail,country_code,auth_phone',
            'assets', 'events', 'campaign.creatives', 'campaign.invoices.items', 'campaign.invoices.payments',
        ]));
    }

    public function approve(Request $request, AdSubmission $adSubmission)
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'period_days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'media_url' => ['nullable', 'url:https', 'max:2048'],
            'review_note' => ['nullable', 'string', 'max:3000'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $effectiveMedia = $data['media_url'] ?? $adSubmission->media_url;
        if ($adSubmission->type === 'video' && ! $effectiveMedia) {
            return V4Response::error('AD_MEDIA_REQUIRED', 'A video URL or uploaded video is required.', 422);
        }

        $submission = $this->approvalService->approve(
            $adSubmission,
            $data,
            (string) $request->input('auth_user_id')
        );
        $this->notifications->sendPaymentLink($submission);

        return V4Response::success(
            $submission->fresh([
                'user:num,uid,name,mail,country_code,auth_phone',
                'assets', 'events', 'campaign.creatives', 'campaign.invoices.items',
            ]),
            $submission->approval_whatsapp_sent_at
                ? 'Ad approved. The payment link was sent by WhatsApp.'
                : 'Ad approved, but the WhatsApp payment link could not be sent.',
        );
    }

    public function reject(Request $request, AdSubmission $adSubmission)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:3000'],
        ]);

        return $this->transition(
            $adSubmission,
            AdSubmission::STATUS_REJECTED,
            'rejected',
            $data['reason'],
            (string) $request->input('auth_user_id')
        );
    }

    public function requestChanges(Request $request, AdSubmission $adSubmission)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:3000'],
        ]);

        return $this->transition(
            $adSubmission,
            AdSubmission::STATUS_CHANGES_REQUESTED,
            'changes_requested',
            $data['reason'],
            (string) $request->input('auth_user_id')
        );
    }

    public function resendPaymentLink(AdSubmission $adSubmission)
    {
        $adSubmission->load('campaign.invoices');
        $invoice = $adSubmission->campaign?->invoices->sortByDesc('id')->first();
        if ($adSubmission->status !== AdSubmission::STATUS_APPROVED || ! $invoice || $invoice->status === 'paid') {
            return V4Response::error(
                'AD_PAYMENT_LINK_NOT_AVAILABLE',
                'A payment link can be sent only for an approved unpaid campaign.',
                409,
            );
        }

        $sent = $this->notifications->sendPaymentLink($adSubmission);

        return V4Response::success(
            $adSubmission->fresh([
                'user:num,uid,name,mail,country_code,auth_phone',
                'assets', 'events', 'campaign.creatives', 'campaign.invoices.items',
            ]),
            $sent ? 'The WhatsApp payment link was sent.' : 'The WhatsApp payment link could not be sent.',
        );
    }

    private function transition(
        AdSubmission $submission,
        string $toStatus,
        string $action,
        string $reason,
        string $adminId
    ) {
        $updated = DB::transaction(function () use ($submission, $toStatus, $action, $reason, $adminId) {
            $locked = AdSubmission::query()->lockForUpdate()->findOrFail($submission->getKey());
            if ($locked->status !== AdSubmission::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['Only a pending submission can be reviewed.'],
                ]);
            }

            $fromStatus = $locked->status;
            $locked->update([
                'status' => $toStatus,
                'review_note' => $toStatus === AdSubmission::STATUS_CHANGES_REQUESTED ? $reason : null,
                'rejection_reason' => $toStatus === AdSubmission::STATUS_REJECTED ? $reason : null,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);
            $locked->events()->create([
                'action' => $action,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'note' => $reason,
                'actor_type' => 'admin',
                'actor_id' => $adminId,
            ]);

            return $locked->fresh(['assets', 'events']);
        });

        return V4Response::success($updated, 'Submission status updated.');
    }
}
