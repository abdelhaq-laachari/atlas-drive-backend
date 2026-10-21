<?php

namespace App\Http\Requests;

use App\Enums\IdType;
use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add a customer (POST /api/customers) or edit one (PUT /api/customers/{customer}).
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer
            ? $this->user()->can('update', $customer)
            : $this->user()->can('create', Customer::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            // Digits, spaces, dashes, brackets, optional leading +; 8 to 15 digits once cleaned
            'phone' => ['required', 'string', 'max:25', 'regex:/^\+?[\d\s().-]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:50'],
            'id_type' => ['required', Rule::enum(IdType::class)],
            'id_number' => ['required', 'string', 'max:20', 'regex:/^[\p{L}\p{N}\s-]+$/u'],
            'nationality' => ['required', 'string', 'max:50'],
            'birth_date' => ['required', 'date', 'after:1900-01-01', 'before:today'],
            'licence_number' => ['nullable', 'string', 'max:30', 'required_with:licence_issue_date'],
            'licence_issue_date' => ['nullable', 'date', 'before_or_equal:today', 'after:birth_date', 'required_with:licence_number'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Checks that need the cleaned values or the database.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $digits = strlen(preg_replace('/\D/', '', (string) $this->input('phone')));
                if (! $validator->errors()->has('phone') && ($digits < 8 || $digits > 15)) {
                    $validator->errors()->add('phone', 'Enter a valid phone number.');
                }

                if ($validator->errors()->has('id_number')) {
                    return;
                }

                // Same person twice? (Customer queries are limited to the user's agency.)
                $taken = Customer::query()
                    ->where('id_key', Customer::idKey($this->input('id_number')))
                    ->when($this->route('customer'), fn ($query, $customer) => $query->whereKeyNot($customer->getKey()))
                    ->first();

                if ($taken) {
                    $validator->errors()->add('id_number', "This ID number already belongs to customer {$taken->customer_code} ({$taken->full_name}).");
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number.',
            'id_number.regex' => 'The ID number may only contain letters, digits, spaces and dashes.',
            'birth_date.before' => 'The birth date must be in the past.',
            'licence_issue_date.after' => 'The licence date must be after the birth date.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'id_type' => 'ID type',
            'id_number' => 'ID number',
            'licence_issue_date' => 'licence date',
        ];
    }
}
