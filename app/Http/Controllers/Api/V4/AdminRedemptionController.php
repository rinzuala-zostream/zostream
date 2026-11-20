<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\New\Plan;
use App\Models\RedemptionCode;
use App\Services\RedemptionPpvCatalog;
use App\Services\RedemptionService;
use App\Support\Api\V4Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminRedemptionController extends Controller
{
    public const CODES_PER_PAGE = 12;

    public static function codesPerPage(string $type): int
    {
        return $type === 'ppv' ? 8 : self::CODES_PER_PAGE;
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['available', 'redeemed', 'cancelled', 'expired'])]]);
        $query = RedemptionCode::query()->latest('id');
        if (($data['status'] ?? '') === 'expired') {
            $query->where('status', 'available')->where('expires_at', '<', now());
        } elseif ($data['status'] ?? null) {
            $query->where('status', $data['status']);
            if ($data['status'] === 'available') {
                $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
            }
        }
        $page = $query->paginate(25);
        $page->getCollection()->transform(fn ($code) => $this->serialize($code));

        return V4Response::success($page);
    }

    public function options(): JsonResponse
    {
        return V4Response::success([
            ...app(RedemptionPpvCatalog::class)->options(),
            'plans' => Plan::where('is_active', true)->orderBy('name')->get(['id', 'name', 'device_type', 'duration_days']),
        ]);
    }

    public function store(Request $request, RedemptionService $service): JsonResponse
    {
        $data = $this->validateCode($request);
        $code = new RedemptionCode([
            ...$data, 'token' => bin2hex(random_bytes(32)),
            'created_by' => (string) $request->input('auth_user_id'), 'status' => 'available',
        ]);
        $service->target($code);
        $code->save();

        return V4Response::success($this->serialize($code->fresh()), 'QR created.', status: 201);
    }

    public function storeBatch(Request $request, RedemptionService $service): JsonResponse
    {
        $data = $this->validateCode($request, futureExpiry: false);
        $batch = $request->validate([
            'batch_id' => ['required', 'uuid'],
            'page_count' => ['required', 'integer', 'min:1', 'max:20'],
            'plan_id' => ['prohibited'],
        ]);
        $userId = (string) $request->input('auth_user_id');
        $fingerprint = hash('sha256', json_encode([$data, (int) $batch['page_count']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $batch, $userId, $fingerprint, $service) {
            DB::table('redemption_print_batches')->insertOrIgnore([
                'id' => $batch['batch_id'], 'created_by' => $userId,
                'request_fingerprint' => $fingerprint, 'page_count' => $batch['page_count'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $record = DB::table('redemption_print_batches')->where('id', $batch['batch_id'])->lockForUpdate()->first();
            abort_if($record->created_by !== $userId || $record->request_fingerprint !== $fingerprint, 409, 'This batch reference belongs to a different request.');
            if (! RedemptionCode::where('batch_id', $batch['batch_id'])->exists()) {
                if (! empty($data['expires_at']) && \Carbon\Carbon::parse($data['expires_at'])->lessThanOrEqualTo(now())) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['expires_at' => 'The QR expiry must be in the future.']);
                }
                $prototype = new RedemptionCode($data);
                $service->target($prototype);
                for ($index = 0; $index < (int) $batch['page_count'] * self::codesPerPage($data['type']); $index++) {
                    RedemptionCode::create([
                        ...$data, 'token' => bin2hex(random_bytes(32)), 'created_by' => $userId,
                        'status' => 'available', 'batch_id' => $batch['batch_id'], 'batch_page_count' => $batch['page_count'],
                    ]);
                }
            }

            return V4Response::success($this->batchData($batch['batch_id']), 'A4 QR sheets ready.', status: 201);
        }, 3);
    }

    public function batch(string $batchId): JsonResponse
    {
        abort_unless(DB::table('redemption_print_batches')->where('id', $batchId)->exists(), 404);

        return V4Response::success($this->batchData($batchId));
    }

    private function batchData(string $batchId): array
    {
        $codes = RedemptionCode::where('batch_id', $batchId)->orderBy('id')->get();
        $first = $codes->firstOrFail();
        $perPage = self::codesPerPage($first->type);

        return [
            'batch_id' => $batchId, 'page_count' => (int) ceil($codes->count() / $perPage),
            'codes_per_page' => $perPage, 'label' => $first->label,
            'type' => $first->type,
            ...($first->type === 'ppv' ? app(RedemptionPpvCatalog::class)->presentation($first) : ['target_name' => '30-day subscription']),
            'codes' => $codes->map(fn ($code) => $this->serialize($code))->all(),
        ];
    }

    private function validateCode(Request $request, bool $futureExpiry = true): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['ppv', 'subscription'])],
            'movie_id' => ['required_if:type,ppv', 'prohibited_unless:type,ppv', 'nullable', 'string', 'max:255'],
            'content_type' => ['sometimes', 'prohibited_unless:type,ppv', Rule::in(['movie', 'season', 'episode'])],
            'plan_id' => ['prohibited_unless:type,subscription', 'nullable', 'integer'],
            'rental_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'expires_at' => $futureExpiry ? ['nullable', 'date', 'after:now'] : ['nullable', 'date'],
        ]);
    }

    public function cancel(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $code = RedemptionCode::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($code->status === 'redeemed', 409, 'An activated QR cannot be cancelled.');
            $code->update(['status' => 'cancelled']);

            return V4Response::success($this->serialize($code), 'QR cancelled.');
        });
    }

    private function serialize(RedemptionCode $code): array
    {
        return [
            ...$code->toArray(),
            'status' => $code->status === 'available' && $code->expires_at?->isPast() ? 'expired' : $code->status,
            'qr_payload' => 'zostream://redeem/'.$code->token,
        ];
    }
}
