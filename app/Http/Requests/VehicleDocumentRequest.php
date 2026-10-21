<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use App\Models\VehicleDocument;
use App\Rules\WholeDirhams;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or renew a document (POST /api/vehicles/{vehicle}/documents)
 * or correct one (PUT /api/vehicle-documents/{document}).
 */
class VehicleDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document
            ? $this->user()->can('update', $document)
            : $this->user()->can('create', VehicleDocument::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = DocumentType::tryFrom((string) $this->input('type'));

        return [
            'type' => ['required', Rule::enum(DocumentType::class)],
            'reference' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['required', 'date'],
            // Insurance, inspection and vignette expire; the registration card does not.
            'expiry_date' => $type === DocumentType::Registration
                ? ['prohibited']
                : ['required', 'date', 'after:issue_date'],
            'cost' => ['nullable', new WholeDirhams],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expiry_date.prohibited' => 'A registration card has no expiry date.',
            'expiry_date.after' => 'The expiry date must be after the start date.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'issue_date' => 'start date',
        ];
    }
}
