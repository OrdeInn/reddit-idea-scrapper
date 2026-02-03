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

    protected function passedValidation(): void
    {
        // Normalize per_page to integer
        if ($this->has('per_page')) {
            $this->merge(['per_page' => (int) $this->input('per_page')]);
        }

        // Normalize sort_dir to lowercase
        if ($this->has('sort_dir')) {
            $this->merge(['sort_dir' => strtolower($this->input('sort_dir'))]);
        }
    }
}
