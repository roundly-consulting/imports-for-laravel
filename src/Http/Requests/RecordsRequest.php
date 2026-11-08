<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Imports\Enums\RecordAction;

/**
 * A page of a batch's imported records.
 *
 * @internal
 */
final class RecordsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', Rule::enum(RecordAction::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
