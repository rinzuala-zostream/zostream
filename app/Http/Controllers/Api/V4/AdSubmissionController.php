<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\AdSubmission;
use App\Models\UserModel;
use App\Services\AdPricingService;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdSubmissionController extends Controller
{
    public function __construct(private readonly AdPricingService $pricing) {}

    public function store(Request $request)
    {
        $userId = (string) $request->input('auth_user_id');
        $user = UserModel::where('uid', $userId)->firstOrFail();
        if (blank($user->auth_phone)) {
            throw ValidationException::withMessages([
                'account' => ['Add and verify your WhatsApp phone number before submitting an ad.'],
            ]);
        }

        $data = $request->validate($this->submissionRules());
        $this->validateMedia($request, $data);
        $quote = $this->pricing->quote($data);

        $token = Str::random(48);

        $submission = DB::transaction(function () use ($request, $data, $quote, $token, $userId) {
            $submission = AdSubmission::create([
                'user_id' => $userId,
                'reference_no' => $this->newReferenceNumber(),
                'public_token_hash' => hash('sha256', $token),
                'public_token_encrypted' => Crypt::encryptString($token),
                'status' => AdSubmission::STATUS_PENDING,
                'business_name' => $data['business_name'],
                'contact_name' => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'contact_email' => $data['contact_email'] ?? null,
                'ads_name' => $data['ads_name'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'placement_code' => $quote['placement_code'],
                'billing_model' => $quote['billing_model'],
                'target_quantity' => $quote['target_quantity'],
                'quoted_rate' => $quote['rate'],
                'quoted_amount' => $quote['amount'],
                'currency' => $quote['currency'],
                'daily_budget' => $data['daily_budget'] ?? null,
                'media_url' => $data['media_url'] ?? null,
                'destination_url' => $data['destination_url'] ?? null,
                'requested_start_date' => $data['requested_start_date'] ?? null,
                'requested_period_days' => $data['requested_period_days'],
                'submitted_ip_hash' => $request->ip()
                    ? hash_hmac('sha256', $request->ip(), (string) config('app.key'))
                    : null,
            ]);

            if ($request->hasFile('media_file')) {
                $asset = $this->storeAsset($request->file('media_file'), $submission, 'media', 0);
                $submission->media_url = $asset->file_url;
                $submission->save();
            }

            if ($request->hasFile('feature_image')) {
                $this->storeAsset($request->file('feature_image'), $submission, 'feature', 0);
            }

            foreach ($request->file('gallery_images', []) as $index => $file) {
                $this->storeAsset($file, $submission, 'gallery', $index);
            }

            $submission->events()->create([
                'action' => 'submitted',
                'to_status' => AdSubmission::STATUS_PENDING,
                'actor_type' => 'advertiser',
                'actor_id' => $userId,
            ]);

            return $submission->fresh('assets');
        });

        return V4Response::success([
            'submission' => $this->publicSubmission($submission),
            'status_url' => url('/advertise/status/'.$token),
        ], 'Your ad was submitted for review.', status: 201);
    }

    public function status(Request $request, string $token)
    {
        $submission = $this->findByToken($token, (string) $request->input('auth_user_id'))->load([
            'assets', 'events', 'campaign.creatives', 'campaign.invoices.items', 'campaign.invoices.payments',
        ]);

        return V4Response::success($this->publicSubmission($submission));
    }

    public function resubmit(Request $request, string $token)
    {
        $userId = (string) $request->input('auth_user_id');
        $submission = $this->findByToken($token, $userId);

        if ($submission->status !== AdSubmission::STATUS_CHANGES_REQUESTED) {
            return V4Response::error(
                'AD_RESUBMISSION_NOT_ALLOWED',
                'This submission is not awaiting changes.',
                409
            );
        }

        $data = $request->validate([
            'business_name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'required', 'string', 'max:40'],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'ads_name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'required', 'in:website,video,image'],
            'placement_code' => ['sometimes', 'required', 'string', 'max:60'],
            'billing_model' => ['sometimes', 'required', 'in:FLAT,CPM,CPC,CPV'],
            'target_quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000'],
            'daily_budget' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:100000000'],
            'media_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'destination_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'requested_start_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'requested_period_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:366'],
            'response_note' => ['nullable', 'string', 'max:3000'],
        ]);

        $quoteInput = array_merge($submission->only([
            'type', 'placement_code', 'billing_model', 'target_quantity', 'requested_period_days',
        ]), collect($data)->except('response_note')->all());
        $quote = $this->pricing->quote($quoteInput);
        $fromStatus = $submission->status;
        $submission->fill(collect($data)->except('response_note')->all());
        $submission->fill([
            'placement_code' => $quote['placement_code'],
            'billing_model' => $quote['billing_model'],
            'target_quantity' => $quote['target_quantity'],
            'quoted_rate' => $quote['rate'],
            'quoted_amount' => $quote['amount'],
            'currency' => $quote['currency'],
        ]);
        $submission->status = AdSubmission::STATUS_PENDING;
        $submission->reviewed_by = null;
        $submission->reviewed_at = null;
        $submission->rejection_reason = null;
        $submission->save();
        $submission->events()->create([
            'action' => 'resubmitted',
            'from_status' => $fromStatus,
            'to_status' => AdSubmission::STATUS_PENDING,
            'note' => $data['response_note'] ?? null,
            'actor_type' => 'advertiser',
            'actor_id' => $userId,
        ]);

        return V4Response::success(
            $this->publicSubmission($submission->fresh(['assets', 'events'])),
            'Your revised ad was submitted for review.'
        );
    }

    private function submissionRules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'ads_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', 'in:website,video,image'],
            'placement_code' => ['required', 'string', 'max:60'],
            'billing_model' => ['required', 'in:FLAT,CPM,CPC,CPV'],
            'target_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'daily_budget' => ['nullable', 'numeric', 'min:1', 'max:100000000'],
            'media_url' => ['nullable', 'url:https', 'max:2048'],
            'media_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,mp4,mov,webm', 'max:51200'],
            'feature_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'gallery_images' => ['nullable', 'array', 'max:4'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'destination_url' => ['nullable', 'url:https', 'max:2048'],
            'requested_start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'requested_period_days' => ['required', 'integer', 'min:1', 'max:366'],
            'terms_accepted' => ['accepted'],
            'website' => ['nullable', 'max:0'],
        ];
    }

    private function validateMedia(Request $request, array $data): void
    {
        if (! empty($data['media_url'])) {
            return;
        }

        $mediaFile = $request->file('media_file');
        $mediaMime = $mediaFile?->getMimeType() ?? '';

        if ($data['type'] === 'video' && $mediaFile && str_starts_with($mediaMime, 'video/')) {
            return;
        }

        if (
            $data['type'] !== 'video'
            && (($mediaFile && str_starts_with($mediaMime, 'image/')) || $request->hasFile('feature_image'))
        ) {
            return;
        }

        throw ValidationException::withMessages([
            'media' => [$data['type'] === 'video'
                ? 'Provide a video URL or upload a video file.'
                : 'Provide an image URL or upload an image file.'],
        ]);
    }

    private function storeAsset(UploadedFile $file, AdSubmission $submission, string $kind, int $order)
    {
        $disk = (string) config('ads.upload_disk', 'public');
        $directory = trim((string) config('ads.upload_directory', 'ads/submissions'), '/').'/'.$submission->reference_no;
        $path = $file->store($directory, $disk);

        return $submission->assets()->create([
            'kind' => $kind,
            'file_url' => Storage::disk($disk)->url($path),
            'storage_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'sort_order' => $order,
        ]);
    }

    private function newReferenceNumber(): string
    {
        do {
            $reference = 'ADS-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (AdSubmission::where('reference_no', $reference)->exists());

        return $reference;
    }

    private function findByToken(string $token, string $userId): AdSubmission
    {
        $lookupToken = strlen($token) === 48 ? $token : Str::random(48);

        return AdSubmission::where('public_token_hash', hash('sha256', $lookupToken))
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    private function publicSubmission(AdSubmission $submission): array
    {
        return [
            'reference_no' => $submission->reference_no,
            'user_id' => $submission->user_id,
            'status' => $submission->status,
            'business_name' => $submission->business_name,
            'contact_name' => $submission->contact_name,
            'contact_email' => $submission->contact_email,
            'ads_name' => $submission->ads_name,
            'description' => $submission->description,
            'type' => $submission->type,
            'placement_code' => $submission->placement_code,
            'billing_model' => $submission->billing_model,
            'target_quantity' => $submission->target_quantity,
            'quoted_rate' => (float) $submission->quoted_rate,
            'quoted_amount' => (float) $submission->quoted_amount,
            'currency' => $submission->currency,
            'daily_budget' => $submission->daily_budget ? (float) $submission->daily_budget : null,
            'media_url' => $submission->media_url,
            'destination_url' => $submission->destination_url,
            'requested_start_date' => $submission->requested_start_date?->format('Y-m-d'),
            'requested_period_days' => $submission->requested_period_days,
            'review_note' => $submission->review_note,
            'rejection_reason' => $submission->rejection_reason,
            'approved_ad_num' => $submission->approved_ad_num,
            'ad_url' => $submission->approved_ad_num
                ? route('ads.show', ['ad' => $submission->approved_ad_num.'-'.Str::slug($submission->ads_name)])
                : null,
            'assets' => $submission->relationLoaded('assets')
                ? $submission->assets->map(fn ($asset) => [
                    'id' => $asset->id,
                    'kind' => $asset->kind,
                    'file_url' => $asset->file_url,
                    'mime_type' => $asset->mime_type,
                    'file_size' => $asset->file_size,
                    'sort_order' => $asset->sort_order,
                ])->values()
                : [],
            'events' => $submission->relationLoaded('events')
                ? $submission->events->map(fn ($event) => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'to_status' => $event->to_status,
                    'note' => $event->note,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values()
                : [],
            'submitted_at' => $submission->created_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'approval_whatsapp_sent_at' => $submission->approval_whatsapp_sent_at?->toIso8601String(),
            'campaign' => $submission->relationLoaded('campaign') && $submission->campaign
                ? $this->publicCampaign($submission->campaign)
                : null,
        ];
    }

    private function publicCampaign($campaign): array
    {
        $invoice = $campaign->relationLoaded('invoices') ? $campaign->invoices->sortByDesc('id')->first() : null;

        return [
            'id' => $campaign->id,
            'status' => $campaign->status,
            'billing_model' => $campaign->billing_model,
            'requires_prepayment' => $campaign->requires_prepayment,
            'target_quantity' => $campaign->target_quantity,
            'consumed_quantity' => $campaign->consumed_quantity,
            'estimated_amount' => (float) $campaign->estimated_amount,
            'accrued_amount' => (float) $campaign->accrued_amount,
            'currency' => $campaign->currency,
            'start_at' => $campaign->start_at?->toIso8601String(),
            'end_at' => $campaign->end_at?->toIso8601String(),
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'subtotal' => (float) $invoice->subtotal,
                'tax' => (float) $invoice->tax,
                'total' => (float) $invoice->total,
                'paid_amount' => (float) $invoice->paid_amount,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
                'due_at' => $invoice->due_at?->toIso8601String(),
                'paid_at' => $invoice->paid_at?->toIso8601String(),
            ] : null,
        ];
    }
}
