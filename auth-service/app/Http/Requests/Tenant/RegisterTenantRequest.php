<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterTenantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = auth('sanctum')->user() ?? $this->user();
        if (!$user) {
            throw new HttpResponseException(response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401));
        }

        if (!$user->is_active) {
            throw new HttpResponseException(response()->json([
                'status' => 'error',
                'code' => 'ACCOUNT_DEACTIVATED',
                'message' => 'User account is deactivated.',
            ], 401));
        }

        if (method_exists($user, 'isLockedOut') && $user->isLockedOut()) {
            throw new HttpResponseException(response()->json([
                'status' => 'error',
                'code' => 'ACCOUNT_LOCKED_OUT',
                'message' => 'User account is temporarily locked out.',
            ], 401));
        }

        // Defense in depth: POS-scoped sessions cannot provision workspaces
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $abilities = is_array($token?->abilities ?? null) ? $token->abilities : [];
        $isPos = $this->attributes->get('is_pos_session', false)
            || (in_array('pos:access', $abilities, true) && !in_array('*', $abilities, true))
            || ($token && method_exists($token, 'can') && $token->can('pos:access') && !$token->can('*'));
        if ($isPos) {
            return false;
        }

        // Prevent authenticated users from provisioning a workspace on behalf of someone else
        foreach (['owner_user_id', 'owner_id', 'user_id'] as $ownerField) {
            if ($this->filled($ownerField) && (string) $this->input($ownerField) !== (string) $user->id) {
                return false;
            }
        }

        if ($this->filled('owner_email') && strtolower(trim((string) $this->input('owner_email'))) !== strtolower(trim((string) $user->email))) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'client_tier' => ['nullable', 'in:free_personal,business_runner,enterprise_org'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'max:10'],
        ];
    }
}
