<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubredditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                'regex:/^(r\/)?[a-zA-Z0-9_]+$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter a subreddit name.',
            'name.regex' => 'Subreddit name can only contain letters, numbers, and underscores.',
            'name.min' => 'Subreddit name must be at least 2 characters.',
            'name.max' => 'Subreddit name cannot exceed 100 characters.',
        ];
    }
}
