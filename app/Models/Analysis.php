<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Analysis extends Model
{
    protected $fillable = [
        'landmark_name',
        'local_name',
        'latitude',
        'longitude',
        'country',
        'city',
        'confidence',
        'description',
        'type',
        'image_path',
        'share_token',
        'user_id',
        'metadata',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'confidence' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get formatted coordinates
     */
    public function getFormattedCoordinates(): string
    {
        if (!$this->latitude || !$this->longitude) {
            return 'No GPS data available';
        }

        $lat = abs($this->latitude);
        $lng = abs($this->longitude);
        $latDir = $this->latitude >= 0 ? 'N' : 'S';
        $lngDir = $this->longitude >= 0 ? 'E' : 'W';

        return sprintf('%s° %s, %s° %s', 
            number_format($lat, 4), $latDir,
            number_format($lng, 4), $lngDir
        );
    }

    /**
     * Get metadata value
     */
    public function getMetadataValue($key, $default = null)
    {
        $metadata = $this->metadata ?? [];
        return $metadata[$key] ?? $default;
    }

    /**
     * Get reasoning from metadata
     */
    public function getReasoning(): ?string
    {
        return $this->getMetadataValue('reasoning');
    }

    /**
     * Get tags from metadata
     */
    public function getTags(): array
    {
        return $this->getMetadataValue('tags', []);
    }
}