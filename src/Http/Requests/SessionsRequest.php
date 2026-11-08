<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Imports\Enums\SessionStatus;

/**
 * A page of the owner's sessions, optionally of one importer and some statuses.
 *
 * @internal
 */
final class SessionsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'importer' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'array', 'max:20'],
            'status.*' => [Rule::enum(SessionStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
