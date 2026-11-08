<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * How the stored file is read: sheet, delimiter, header row.
 *
 * @internal
 */
final class SourceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sheet' => ['nullable', 'string', 'max:31'],
            'sheet_index' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'delimiter' => ['nullable', 'string', Rule::in([',', ';', '|', 'tab'])],
            'header_row' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
