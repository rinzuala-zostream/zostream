<?php

namespace App\Http\Controllers;

use App\Models\ZonetOperator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Str;

class ZonetOperatorController extends Controller
{
    public function add(Request $request)
    {
        try {
            $validated = $request->validate([
        
                'password' => 'required|string|min:6',
                'name' => 'required|string|max:30',
                'phone' => 'required|string|max:15|unique:zonet_operators,phone',
                'address' => 'required|string|max:225',
            ]);

            // Generate a unique random ID
            do {
                $randomId = Str::random(10); // You can change length if needed
            } while (ZonetOperator::where('id', $randomId)->exists());

            ZonetOperator::create([
                'id' => $randomId,
                'password' => Hash::make($validated['password']),
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'address' => $validated['address'],
                'created_at' => now(),
            ]);

            return $this->respond('true', 'Operator added successfully', 201);
        } catch (\Exception $e) {
            return $this->respond('false', 'Failed to add operator', 500);
        }
    }

    public function update(Request $request, $num)
    {
        $operator = ZonetOperator::find($num);

        if (!$operator) {
            return $this->respond('false', 'Operator not found', 404);
        }

        try {
            $validated = $request->validate([
                'password' => 'nullable|string|min:6',
                'name' => 'nullable|string|max:30',
                'phone' => 'nullable|string|max:15',
                'address' => 'nullable|string|max:225',
            ]);

            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }

            $operator->update($validated);

            return $this->respond('true', 'Operator updated successfully');
        } catch (\Exception $e) {
            return $this->respond('false', 'Failed to update operator', 500);
        }
    }

    public function login(Request $request)
    {
        try {
            $validated = $request->validate([
                'phone' => 'required|string',
                'password' => 'required|string',
            ]);

            $operator = ZonetOperator::where('phone', $validated['phone'])->first();

            if (!$operator || !Hash::check($validated['password'], $operator->password)) {
                return $this->respond('false', 'Invalid phone or password', 401);
            }

            return $this->respond('true', 'Login successful');
        } catch (\Exception $e) {
            return $this->respond('false', 'Login failed', 500);
        }
    }

    public function delete($num)
    {
        $operator = ZonetOperator::find($num);

        if (!$operator) {
            return $this->respond('false', 'Operator not found', 404);
        }

        try {
            $operator->delete();
            return $this->respond('true', 'Operator deleted successfully');
        } catch (\Exception $e) {
            return $this->respond('false', 'Failed to delete operator', 500);
        }
    }

    /**
     * Response helper
     * status: "true" | "false"
     */
    private function respond(string $status, string $message, int $code = 200)
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
        ], $code);
    }
}
