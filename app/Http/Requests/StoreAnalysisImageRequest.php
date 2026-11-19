<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAnalysisImageRequest extends FormRequest
{
    /**
     * Auth is enforced by middleware (web: auth:web, api: auth:sanctum),
     * so validation only decides whether the payload is acceptable.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => 'required|file|mimes:jpeg,png,jpg,gif,webp|max:20480',
        ];
    }
}
