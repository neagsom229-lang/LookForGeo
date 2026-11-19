<?php

namespace App\Http\Controllers;

use App\Services\Gemini\SecretSanitizer;
use App\Services\GeminiService;
use Illuminate\Http\Request;

class GeminiController extends Controller
{
    protected $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Plain text chat with Gemini (rate-limited at the route level).
     */
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
        ]);

        try {
            $response = $this->gemini->chat($validated['message']);

            return response()->json([
                'success' => true,
                'response' => $response,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => SecretSanitizer::redact($e->getMessage()),
            ], 500);
        }
    }
}
