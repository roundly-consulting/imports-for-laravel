<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A name to save the session's mapping under.
 *
 * @internal
 */
final class SaveMappingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:60'],
        ];
    }
}
