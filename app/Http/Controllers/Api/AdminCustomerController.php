<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminCustomerController extends Controller
{
    /**
     * List customers.
     */
    public function index(Request $request): JsonResponse
    {
        $customers = User::query()
            ->where('role', 'CUSTOMER')
            ->when(
                $request->filled('search'),
                function ($query) use ($request) {
                    $query->where(function ($q) use ($request) {
                        $q->where(
                            'name',
                            'like',
                            '%' . $request->search . '%'
                        )
                            ->orWhere(
                                'email',
                                'like',
                                '%' . $request->search . '%'
                            );
                    });
                }
            )
            ->withCount('orders')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Customers retrieved successfully.',
            'data' => $customers,
        ]);
    }

    /**
     * Show one customer.
     */
    public function show(User $user): JsonResponse
    {
        if ($user->role !== 'CUSTOMER') {
            return response()->json([
                'success' => false,
                'message' => 'The selected user is not a customer.',
            ], 422);
        }

        $user->load([
            'orders' => fn ($query) => $query->latest(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Customer retrieved successfully.',
            'data' => [
                'customer' => $user,
            ],
        ]);
    }

    /**
     * Update customer.
     */
    public function update(
        Request $request,
        User $user
    ): JsonResponse {
        if ($user->role !== 'CUSTOMER') {
            return response()->json([
                'success' => false,
                'message' => 'The selected user is not a customer.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => [
                'sometimes',
                'nullable',
                'string',
                'min:8',
            ],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make(
                $validated['password']
            );
        }

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully.',
            'data' => [
                'customer' => $user->fresh(),
            ],
        ]);
    }

    /**
     * Delete customer.
     */
    public function destroy(User $user): JsonResponse
    {
        if ($user->role !== 'CUSTOMER') {
            return response()->json([
                'success' => false,
                'message' => 'Only customer accounts can be deleted here.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted successfully.',
        ]);
    }
}