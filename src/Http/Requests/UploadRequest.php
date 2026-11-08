<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Imports\Enums\DuplicateMode;

/**
 * An upload: the file and how to read it (size and format are checked by the package itself).
 *
 * @internal
 */
final class UploadRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'sheet' => ['nullable', 'string', 'max:31'],
            'sheet_index' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'delimiter' => ['nullable', 'string', Rule::in([',', ';', '|', 'tab'])],
            'header_row' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'locale' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z_-]+$/'],
            'mode' => ['nullable', Rule::enum(DuplicateMode::class)],
        ];
    }
}
