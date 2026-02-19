<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => [
                'nullable',
                'date_format:Y-m-d\TH:i:s.v\Z',
                'required_with:date_to',
                'before:date_to',
                'before_or_equal:now',
            ],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d\TH:i:s.v\Z',
                'required_with:date_from',
                'after:date_from',
                'before_or_equal:now',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'date_from.date_format' => 'The start date must be a valid ISO8601 UTC datetime.',
            'date_from.required_with' => 'The start date is required when an end date is provided.',
            'date_from.before' => 'The start date must be before the end date.',
            'date_from.before_or_equal' => 'The start date cannot be in the future.',
            'date_to.date_format' => 'The end date must be a valid ISO8601 UTC datetime.',
            'date_to.required_with' => 'The end date is required when a start date is provided.',
            'date_to.after' => 'The end date must be after the start date.',
            'date_to.before_or_equal' => 'The end date cannot be in the future.',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $dateFrom = $this->input('date_from');
            $dateTo = $this->input('date_to');

            if ($dateFrom && $dateTo && !$validator->errors()->hasAny(['date_from', 'date_to'])) {
                $from = \Carbon\Carbon::parse($dateFrom)->utc();
                $to = \Carbon\Carbon::parse($dateTo)->utc();

                // Use seconds for precision — avoids integer truncation with diffInDays
                $maxSeconds = 84 * 24 * 60 * 60; // 12 weeks in seconds
                if ($to->diffInRealSeconds($from) > $maxSeconds) {
                    $validator->errors()->add('date_from', 'The date range cannot exceed 12 weeks (84 days).');
                }
            }
        });
    }
}
