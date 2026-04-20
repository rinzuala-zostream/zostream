<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\New\Plan;
use App\Models\New\PlanFeature;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlanController extends Controller
{
    /**
     * List plans with optional filters.
     */
    public function index(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 15);
            $perPage = min(max($perPage, 1), 100);

            $query = Plan::with('features')->orderByDesc('created_at');

            if ($request->filled('search')) {
                $search = trim((string) $request->get('search'));
                $query->where('name', 'like', "%{$search}%");
            }

            if ($request->filled('device_type')) {
                $query->where('device_type', strtolower(trim((string) $request->get('device_type'))));
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
            }

            return response()->json([
                'status' => 'success',
                'data' => $query->paginate($perPage),
            ]);
        } catch (Exception $e) {
            Log::error('Plan index error', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to fetch plans', $e);
        }
    }

    /**
     * Create a plan.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->rules());

            $validated['device_type'] = strtolower($validated['device_type']);
            $validated['is_active'] = $validated['is_active'] ?? true;

            $plan = Plan::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Plan created successfully',
                'data' => $plan->load('features'),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Plan store error', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to create plan', $e);
        }
    }

    /**
     * Show a single plan.
     */
    public function show($id)
    {
        try {
            $plan = Plan::with('features')->find($id);

            if (!$plan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Plan not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $plan,
            ]);
        } catch (Exception $e) {
            Log::error('Plan show error', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to fetch plan', $e);
        }
    }

    /**
     * Update a plan.
     */
    public function update(Request $request, $id)
    {
        try {
            $plan = Plan::find($id);

            if (!$plan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Plan not found',
                ], 404);
            }

            $validated = $request->validate($this->rules(true));

            if (isset($validated['device_type'])) {
                $validated['device_type'] = strtolower($validated['device_type']);
            }

            $plan->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Plan updated successfully',
                'data' => $plan->fresh()->load('features'),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Plan update error', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to update plan', $e);
        }
    }

    /**
     * Delete a plan.
     */
    public function destroy($id)
    {
        try {
            $plan = Plan::find($id);

            if (!$plan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Plan not found',
                ], 404);
            }

            if ($plan->subscriptions()->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Plan has subscriptions and cannot be deleted. Deactivate it instead.',
                ], 409);
            }

            $plan->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Plan deleted successfully',
            ]);
        } catch (Exception $e) {
            Log::error('Plan destroy error', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to delete plan', $e);
        }
    }

    /**
     * List all plan features with optional filters.
     */
    public function featureIndex(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 15);
            $perPage = min(max($perPage, 1), 100);

            $query = PlanFeature::query()
                ->orderBy('sort_order')
                ->orderByDesc('created_at');

            if ($request->filled('plan_id')) {
                $query->where('plan_id', $request->get('plan_id'));
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->get('search'));
                $query->where('feature', 'like', "%{$search}%");
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
            }

            return response()->json([
                'status' => 'success',
                'data' => $query->paginate($perPage),
            ]);
        } catch (Exception $e) {
            Log::error('Plan feature index error', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to fetch plan features', $e);
        }
    }

    /**
     * List features for one plan.
     */
    public function planFeatures($planId)
    {
        try {
            $plan = Plan::find($planId);

            if (!$plan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Plan not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'plan' => $plan,
                    'features' => $plan->features()
                        ->orderBy('sort_order')
                        ->orderByDesc('created_at')
                        ->get(),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Plan features error', ['plan_id' => $planId, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to fetch features for plan', $e);
        }
    }

    /**
     * Create a feature.
     */
    public function storeFeature(Request $request)
    {
        try {
            $validated = $request->validate($this->featureRules());
            $validated = $this->normalizeFeaturePayload($validated);

            $feature = PlanFeature::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Plan feature created successfully',
                'data' => $feature,
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Plan feature store error', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to create plan feature', $e);
        }
    }

    /**
     * Create a feature for a specific plan.
     */
    public function storePlanFeature(Request $request, $planId)
    {
        try {
            $plan = Plan::find($planId);

            if (!$plan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Plan not found',
                ], 404);
            }

            $validated = $request->validate($this->featureRules(true));
            $validated['plan_id'] = $plan->id;
            $validated = $this->normalizeFeaturePayload($validated);

            $feature = PlanFeature::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Plan feature created successfully',
                'data' => $feature,
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Plan feature store for plan error', ['plan_id' => $planId, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to create plan feature', $e);
        }
    }

    /**
     * Show a single feature.
     */
    public function showFeature($featureId)
    {
        try {
            $feature = PlanFeature::find($featureId);

            if (!$feature) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Plan feature not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $feature,
            ]);
        } catch (Exception $e) {
            Log::error('Plan feature show error', ['feature_id' => $featureId, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to fetch plan feature', $e);
        }
    }

    /**
     * Update a feature.
     */
    public function updateFeature(Request $request, $featureId)
    {
        try {
            $feature = PlanFeature::find($featureId);

            if (!$feature) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Plan feature not found',
                ], 404);
            }

            $validated = $request->validate($this->featureRules(false, true));
            $validated = $this->normalizeFeaturePayload($validated, true);

            $feature->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Plan feature updated successfully',
                'data' => $feature->fresh(),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Plan feature update error', ['feature_id' => $featureId, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to update plan feature', $e);
        }
    }

    /**
     * Delete a feature.
     */
    public function destroyFeature($featureId)
    {
        try {
            $feature = PlanFeature::find($featureId);

            if (!$feature) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Plan feature not found',
                ], 404);
            }

            $feature->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Plan feature deleted successfully',
            ]);
        } catch (Exception $e) {
            Log::error('Plan feature destroy error', ['feature_id' => $featureId, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to delete plan feature', $e);
        }
    }

    private function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:100'],
            'device_type' => [$required, 'string', Rule::in(['mobile', 'tv', 'browser'])],
            'device_limit' => [$required, 'integer', 'min:1'],
            'price' => [$required, 'numeric', 'min:0'],
            'duration_days' => [$required, 'integer', 'min:1'],
            'quality' => [$required, 'string', Rule::in(['SD', 'HD', 'FULL_HD', '4K'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function featureRules(bool $planProvided = false, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';
        $planRule = $planProvided || $isUpdate ? 'sometimes' : 'required';

        return [
            'plan_id' => [$planRule, 'integer', 'exists:n_plans,id'],
            'feature' => [$required, 'string', 'max:255'],
            'ppv_discount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function normalizeFeaturePayload(array $payload, bool $isUpdate = false): array
    {
        if (!$isUpdate) {
            $payload['ppv_discount'] = $payload['ppv_discount'] ?? 0;
            $payload['sort_order'] = $payload['sort_order'] ?? 0;
            $payload['is_active'] = $payload['is_active'] ?? true;
        }

        return $payload;
    }

    private function errorResponse(string $message, Exception $e, int $code = 500)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'error' => $e->getMessage(),
        ], $code);
    }
}
