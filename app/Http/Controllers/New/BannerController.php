<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\New\Banner;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BannerController extends Controller
{
    // =========================
    // INDEX (Already yours)
    // =========================
    public function index(Request $request)
    {
        try {
            $userAge = $request->input('age');
            $hasParentalPin = $request->input('parental_pin_verified', false);

            $now = Carbon::now();

            $query = Banner::where('is_active', true)
                ->where(function ($q) use ($now) {
                    $q->whereNull('start_date')
                      ->orWhere('start_date', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('end_date')
                      ->orWhere('end_date', '>=', $now);
                });

            $banners = $query->orderBy('priority', 'desc')->get();

            $filtered = $banners->filter(function ($banner) use ($userAge, $hasParentalPin) {

                if (!$banner->age_restriction_enabled) {
                    return true;
                }

                if ($banner->requires_parental_pin && !$hasParentalPin) {
                    return false;
                }

                if ($banner->age_rating) {
                    return $this->checkAgeRating($banner->age_rating, $userAge);
                }

                if ($banner->min_age && $userAge < $banner->min_age) {
                    return false;
                }

                return true;
            });

            $responseData = $filtered->values()->map(function ($banner) {
                $item = $banner->toArray();

                if (($item['type'] ?? null) === 'ad') {
                    $item['batch'] = 'Sponsored';
                }

                return $item;
            });

            return response()->json([
                'status' => true,
                'data' => $responseData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch banners',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =========================
    // SHOW
    // =========================
    public function show($id)
    {
        try {
            $banner = Banner::find($id);

            if (!$banner) {
                return response()->json([
                    'status' => false,
                    'message' => 'Banner not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $banner
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch banner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =========================
    // STORE
    // =========================
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string',
                'description' => 'nullable|string',
                'type' => 'required|in:movie,ad,external,category,custom',
                'media_type' => 'required|in:image,video',
                'media_url' => 'required|string',
                'thumbnail_url' => 'nullable|string',
                'target_type' => 'required|in:movie,series,episode,url,category,none',
                'target_id' => 'nullable|string',
                'target_url' => 'nullable|string',
                'priority' => 'nullable|integer',
                'is_active' => 'boolean',

                // Age restriction
                'age_restriction_enabled' => 'boolean',
                'min_age' => 'nullable|integer',
                'max_age' => 'nullable|integer',
                'age_rating' => 'nullable|in:G,PG,PG13,R,18+,21+',
                'requires_parental_pin' => 'boolean',

                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'button_text' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $banner = Banner::create($validator->validated());

            return response()->json([
                'status' => true,
                'message' => 'Banner created successfully',
                'data' => $banner
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create banner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =========================
    // UPDATE
    // =========================
    public function update(Request $request, $id)
    {
        try {
            $banner = Banner::find($id);

            if (!$banner) {
                return response()->json([
                    'status' => false,
                    'message' => 'Banner not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string',
                'description' => 'nullable|string',
                'type' => 'sometimes|in:movie,ad,external,category,custom',
                'media_type' => 'sometimes|in:image,video',
                'media_url' => 'sometimes|string',
                'thumbnail_url' => 'nullable|string',
                'target_type' => 'sometimes|in:movie,series,episode,url,category,none',
                'target_id' => 'nullable|string',
                'target_url' => 'nullable|string',
                'priority' => 'nullable|integer',
                'is_active' => 'boolean',

                'age_restriction_enabled' => 'boolean',
                'min_age' => 'nullable|integer',
                'max_age' => 'nullable|integer',
                'age_rating' => 'nullable|in:G,PG,PG13,R,18+,21+',
                'requires_parental_pin' => 'boolean',

                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'button_text' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $banner->update($validator->validated());

            return response()->json([
                'status' => true,
                'message' => 'Banner updated successfully',
                'data' => $banner
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update banner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =========================
    // DESTROY
    // =========================
    public function destroy($id)
    {
        try {
            $banner = Banner::find($id);

            if (!$banner) {
                return response()->json([
                    'status' => false,
                    'message' => 'Banner not found'
                ], 404);
            }

            $banner->delete();

            return response()->json([
                'status' => true,
                'message' => 'Banner deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete banner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =========================
    // AGE CHECK HELPER
    // =========================
    private function checkAgeRating($rating, $age)
    {
        $map = [
            'G' => 0,
            'PG' => 10,
            'PG13' => 13,
            'R' => 17,
            '18+' => 18,
            '21+' => 21
        ];

        if (!isset($map[$rating]) || !$age) return true;

        return $age >= $map[$rating];
    }
}
