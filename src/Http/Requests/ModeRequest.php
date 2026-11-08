<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Imports\Enums\DuplicateMode;

/**
 * The duplicate mode.
 *
 * @internal
 */
final class ModeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(DuplicateMode::class)],
        ];
    }
}
