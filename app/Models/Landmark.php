<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Landmark extends Model
{
    protected $fillable = [
        'name', 'city', 'country', 'region',
        'latitude', 'longitude', 'description',
        'historical_context', 'tags', 'type', 'image_url',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'tags' => 'array',
    ];

    // Kept: GeminiResultEnhancer uses Landmark::query()/where() for the
    // cross-reference step (app/Services/Gemini/GeminiResultEnhancer.php:131,187).
    // Four unused static search helpers were removed in Phase C (no callers).
}
