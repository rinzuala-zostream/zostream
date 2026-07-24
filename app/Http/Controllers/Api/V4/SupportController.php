<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\New\CustomerSupportController;
use App\Models\New\CustomerSupport;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function __construct(private readonly CustomerSupportController $support) {}

    public function index(Request $request)
    {
        $userId = $this->userId($request);
        $request->merge(['user_id' => $userId]);
        $request->query->set('user_id', $userId);

        return $this->support->index($request);
    }

    public function store(Request $request)
    {
        $request->merge(['user_id' => $this->userId($request)]);

        return $this->support->store($request);
    }

    public function show(Request $request, string $id)
    {
        $exists = CustomerSupport::whereKey($id)
            ->where('user_id', $this->userId($request))
            ->exists();

        if (! $exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Support ticket not found.',
            ], 404);
        }

        return $this->support->show($id);
    }

    public function reply(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Customer replies are not supported by this endpoint.',
        ], 405);
    }

    private function userId(Request $request): string
    {
        return (string) $request->input('auth_user_id');
    }
}
