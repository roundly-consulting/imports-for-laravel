<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A mapping: column header or index => field.
 *
 * @internal
 */
final class MapRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:64'],
        ];
    }
}
