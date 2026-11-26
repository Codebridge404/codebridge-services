<?php

namespace App\Http\Requests\Security;

use App\Services\IpRuleService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreIpRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ip_or_cidr' => [
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! is_string($value) || ! IpRuleService::isValidIpOrCidr($value)) {
                        $fail('The '.$attribute.' must be a valid IPv4, IPv6, or CIDR range.');
                    }
                },
            ],
            'rule_type' => ['required', 'string', 'in:allow,block'],
            'reason' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
