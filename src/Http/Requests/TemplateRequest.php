<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Imports\Enums\TemplateFormat;

/**
 * A template or error-report download.
 *
 * @internal
 */
final class TemplateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['nullable', Rule::enum(TemplateFormat::class)],
            'locale' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z_-]+$/'],
        ];
    }
}
