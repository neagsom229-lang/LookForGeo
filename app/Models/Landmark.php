<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Landmark extends Model
{
    protected $fillable = [
        'name', 'city', 'country', 'region',
        'latitude', 'longitude', 'description',
        'historical_context', 'tags', 'type', 'image_url'
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'tags' => 'array',
    ];

    public static function searchByName($query)
    {
        return self::where('name', 'LIKE', "%{$query}%")
            ->orWhere('city', 'LIKE', "%{$query}%")
            ->orWhere('country', 'LIKE', "%{$query}%");
    }

    public static function getNearby($lat, $lng, $radius = 50)
    {
        return self::select('*')
            ->selectRaw(
                '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance',
                [$lat, $lng, $lat]
            )
            ->having('distance', '<', $radius)
            ->orderBy('distance')
            ->get();
    }

    public static function getByCountry($country)
    {
        return self::where('country', 'LIKE', "%{$country}%")->get();
    }

    public static function searchAll($query)
    {
        return self::where('name', 'LIKE', "%{$query}%")
            ->orWhere('city', 'LIKE', "%{$query}%")
            ->orWhere('country', 'LIKE', "%{$query}%")
            ->orWhere('region', 'LIKE', "%{$query}%")
            ->get();
    }
}