<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListIdeasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'min_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'min_complexity' => ['nullable', 'integer', 'min:1', 'max:5'],
            'starred_only' => ['nullable', 'boolean'],
            'include_borderline' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort_by' => ['nullable', 'string', Rule::in([
                'score_overall',
                'score_complexity',
                'score_monetization',
                'score_saturation',
                'score_demand',
                'created_at',
                'starred_at',
            ])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // URL query params come in as strings, but the validator's `boolean`
        // rule does not accept "true"/"false". Normalize to actual booleans
        // before validation.
        foreach (['starred_only', 'include_borderline'] as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $raw = $this->input($key);
            if (is_bool($raw)) {
                continue;
            }

            $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                $this->merge([$key => $parsed]);
            }
        }

        // Normalize sort_dir to lowercase
        if ($this->has('sort_dir')) {
            $this->merge(['sort_dir' => strtolower($this->input('sort_dir'))]);
        }
    }
}
