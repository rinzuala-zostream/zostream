<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\MovieModel;
use App\Models\New\Plan;
use App\Models\RedemptionCode;
use App\Services\RedemptionService;
use App\Support\Api\V4Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminRedemptionController extends Controller
{
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
            'movies' => MovieModel::where('status', 'Published')->where('isEnable', true)->where('isPayPerView', true)
                ->orderBy('title')->get(['id', 'title']),
            'plans' => Plan::where('is_active', true)->orderBy('name')->get(['id', 'name', 'device_type', 'duration_days']),
        ]);
    }

    public function store(Request $request, RedemptionService $service): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['ppv', 'subscription'])],
            'movie_id' => ['required_if:type,ppv', 'prohibited_unless:type,ppv', 'nullable', 'string', 'max:255'],
            'plan_id' => ['required_if:type,subscription', 'prohibited_unless:type,subscription', 'nullable', 'integer'],
            'rental_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $code = new RedemptionCode([
            ...$data, 'token' => bin2hex(random_bytes(32)),
            'created_by' => (string) $request->input('auth_user_id'), 'status' => 'available',
        ]);
        $service->target($code);
        $code->save();

        return V4Response::success($this->serialize($code->fresh()), 'QR created.', status: 201);
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
