<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserModel;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{

    // Get all users
    public function index(Request $request)
    {
        try {

            $limit = $request->get('limit', 20);

            $users = UserModel::orderBy('num', 'desc')
                ->paginate($limit);

            return response()->json([
                'status' => 'success',
                'data' => $users
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Get single user
    public function show($id)
    {
        try {

            $user = UserModel::where('uid', $id)->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $user
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch user',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Find user by UID or email
    public function find(Request $request)
    {
        try {

            $request->validate([
                'uid' => 'nullable|string',
                'mail' => 'nullable|string'
            ]);

            $query = UserModel::query();

            if ($request->uid) {
                $query->where('uid', $request->uid);
            }

            if ($request->mail) {
                $query->where('mail', $request->mail);
            }

            $user = $query->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $user
            ]);

        } catch (ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to search user',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Create user
    public function store(Request $request)
    {
        try {

            $request->validate([
                'uid' => 'required|string',
                'mail' => 'nullable|string',
                'name' => 'nullable|string'
            ]);

            $user = UserModel::create($request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'User created successfully',
                'data' => $user
            ], 201);

        } catch (ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'User creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Update user
    public function update(Request $request, $id)
    {
        try {

            $user = UserModel::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $user->update($request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'User updated successfully',
                'data' => $user
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Delete user
    public function destroy($id)
    {
        try {

            $user = UserModel::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $user->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'User deleted successfully'
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}