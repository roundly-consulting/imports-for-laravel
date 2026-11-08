<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A file-batch upload: files and/or ZIP archives.
 *
 * @internal
 */
final class UploadFilesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file'],
            'locale' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z_-]+$/'],
        ];
    }
}
