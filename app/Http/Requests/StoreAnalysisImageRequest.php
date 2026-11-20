<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

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
            'image' => [
                'required',
                'file',
                'mimes:jpeg,png,jpg,gif,webp',
                'max:20480',
                $this->validateImageBytes(...),
            ],
        ];
    }

    /**
     * MIME labels can be spoofed; getimagesize() actually parses the image
     * header. Also cap dimensions so a tiny compressed file cannot expand
     * into a memory-exhausting bitmap (decompression bomb).
     */
    private function validateImageBytes(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return; // the required/file/mimes rules already report this
        }

        $info = @getimagesize($value->getRealPath());
        if (! is_array($info)) {
            $fail('The image must be a valid image file.');

            return;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000 || ($width * $height) > 40_000_000) {
            $fail('The image dimensions are too large (max 8000x8000, 40 megapixels).');
        }
    }
}
