<?php

namespace App\Http\Controllers;

use App\Services\Gemini\SecretSanitizer;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GeminiController extends Controller
{
    /**
     * Plain text chat with Gemini (rate-limited at the route level).
     *
     * GeminiService is resolved lazily here rather than in the constructor:
     * the container would otherwise build the whole client (and read the
     * API key) on every route that happens to use this controller.
     */
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
        ]);

        try {
            $response = app(GeminiService::class)->chat($validated['message']);

            return response()->json([
                'success' => true,
                'response' => $response,
            ]);
        } catch (\Throwable $e) {
            // Details go to the (centrally sanitized) log; the client only
            // ever sees a generic message.
            Log::error('Gemini chat failed: '.SecretSanitizer::redact($e->getMessage()));

            return response()->json([
                'success' => false,
                'error' => 'The AI service did not respond. Please retry.',
            ], 500);
        }
    }
}
