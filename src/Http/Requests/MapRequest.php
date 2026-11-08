<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A mapping: `mapping` (header text or 0-based column index => field, numeric keys resolved as
 * `map()` does) or `columns` (a list of `{column, field}` pairs — always by column index).
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
            'mapping' => ['required_without:columns', 'prohibits:columns', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:64'],
            'columns' => ['required_without:mapping', 'array', 'list'],
            'columns.*' => ['array:column,field'],
            'columns.*.column' => ['required', 'integer', 'min:0', 'max:65535'],
            'columns.*.field' => ['nullable', 'string', 'max:64'],
        ];
    }
}
