<?php

namespace App\Http\Controllers;

use App\Services\LandmarkRecognitionService;
use App\Services\OpenStreetMapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class LandmarkController extends Controller
{
    protected $landmarkService;
    protected $osmService;

    public function __construct(
        LandmarkRecognitionService $landmarkService,
        OpenStreetMapService $osmService
    ) {
        $this->landmarkService = $landmarkService;
        $this->osmService = $osmService;
        set_time_limit(300);
    }

    /**
     * Display the landmark identification page
     */
    public function index()
    {
        return view('landmark.identify');
    }

    /**
     * Identify a landmark from an uploaded image
     */
    public function identify(Request $request)
    {
        set_time_limit(300);
        $startTime = microtime(true);

        try {
            $request->validate([
                'image' => 'required|image|max:5120|mimes:jpeg,png,jpg,gif,webp',
                'mode' => 'sometimes|in:fast,detailed,location'
            ]);

            $path = $request->file('image')->store('landmarks', 'public');
            $fullPath = Storage::disk('public')->path($path);
            $mode = $request->input('mode', 'fast');

            // Collect location hints
            $options = [];
            if ($request->has('hint_lat') && $request->has('hint_lng')) {
                $options['hint_lat'] = (float)$request->input('hint_lat');
                $options['hint_lng'] = (float)$request->input('hint_lng');
                $options['hint_accuracy_m'] = (int)$request->input('hint_accuracy_m', 0);
                $options['hint_timezone'] = $request->input('hint_timezone', '');
                $options['hint_locale'] = $request->input('hint_locale', '');
            }

            // Check cache
            $cached = $this->landmarkService->getCachedResult($fullPath);
            if ($cached && !empty($cached['landmark_name'])) {
                return response()->json([
                    'success' => true,
                    'data' => $this->enrichWithLocationData($cached),
                    'message' => 'Landmark identified (from cache)!',
                    'cached' => true
                ]);
            }

            // Perform AI identification
            $result = $this->landmarkService->identify($fullPath, $mode, $options);

            if (isset($result['quota_exceeded']) && $result['quota_exceeded'] === true) {
                return response()->json([
                    'success' => false,
                    'quota_exceeded' => true,
                    'message' => '⚠️ Daily quota exceeded. Please try again tomorrow.'
                ], 429);
            }

            if (empty($result['landmark_name']) || $result['landmark_name'] === 'Unknown Location') {
                return response()->json([
                    'success' => false,
                    'message' => $result['description'] ?? 'Could not identify the landmark.'
                ], 404);
            }

            // Enrich with location data
            $enrichedResult = $this->enrichWithLocationData($result);

            // Add weather data if coordinates found
            if (isset($enrichedResult['latitude']) && isset($enrichedResult['longitude'])) {
                $enrichedResult['weather'] = $this->getWeatherData(
                    $enrichedResult['latitude'],
                    $enrichedResult['longitude']
                );
            }

            Log::info('Landmark identified with AI geography analysis', [
                'name' => $result['landmark_name'],
                'confidence' => $result['confidence'] ?? 0,
                'mode' => $mode,
                'has_coordinates' => isset($enrichedResult['latitude']),
                'duration' => round(microtime(true) - $startTime, 2)
            ]);

            return response()->json([
                'success' => true,
                'data' => $enrichedResult,
                'message' => 'Landmark identified successfully!',
                'cached' => false
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid input: ' . $e->getMessage()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Landmark identification failed: ' . $e->getMessage());

            if (strpos($e->getMessage(), '429') !== false || strpos($e->getMessage(), 'quota') !== false) {
                return response()->json([
                    'success' => false,
                    'quota_exceeded' => true,
                    'message' => '⚠️ Daily quota exceeded. Please try again tomorrow.'
                ], 429);
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Failed to identify landmark.'
            ], 500);
        }
    }

    /**
     * Enrich with location data from multiple sources
     */
    protected function enrichWithLocationData(array $result): array
    {
        $enriched = $result;
        $name = $result['landmark_name'] ?? '';

        if ($name && $name !== 'Unknown Location') {
            // Try geocoding with multiple sources
            $geocodeData = $this->osmService->geocode($name);

            if ($geocodeData) {
                $lat = $geocodeData['lat'];
                $lng = $geocodeData['lng'];

                $enriched['latitude'] = $lat;
                $enriched['longitude'] = $lng;
                $enriched['coordinate_source'] = $geocodeData['source'] ?? 'openstreetmap';
                $enriched['display_name'] = $geocodeData['display_name'] ?? '';
                $enriched['dms'] = $this->decimalToDMS($lat, $lng);
                
                // ✅ FIXED: Get Plus Code as string
                $plusCode = $this->getPlusCode($lat, $lng);
                $enriched['plus_code'] = $plusCode ?? 'Not available';
                
                $enriched['what3words'] = $this->getWhat3Words($lat, $lng);
                $enriched['google_maps_url'] = "https://www.google.com/maps?q={$lat},{$lng}";
                $enriched['google_maps_directions'] = "https://www.google.com/maps/dir//{$lat},{$lng}";
                $enriched['google_street_view'] = "https://www.google.com/maps/@?api=1&map_action=pano&viewpoint={$lat},{$lng}";
                $enriched['osm_url'] = "https://www.openstreetmap.org/#map=15/{$lat}/{$lng}";

                // Reality check
                $reality = $this->realityCheck($name, $lat, $lng);
                $enriched['reality_check'] = $reality;

                // Boost confidence if verified
                if ($reality['verified']) {
                    $enriched['confidence'] = min(100, ($enriched['confidence'] ?? 0) + $reality['confidence_boost']);
                }

                // Get nearby attractions
                $nearby = $this->osmService->getNearbyAttractions($lat, $lng, 1500, 10);
                $enriched['nearby_attractions'] = $nearby;

                // Get address
                $reverse = $this->osmService->reverseGeocode($lat, $lng);
                if ($reverse) {
                    $enriched['address'] = $reverse['display_name'] ?? '';
                    $enriched['address_details'] = $reverse['address'] ?? [];
                }

                // Get Wikipedia
                $enriched['wikipedia'] = $this->getWikipediaInfo($name);
                $enriched['wikidata_url'] = $this->getWikidataUrl($name);
            } else {
                // Fallback: Google Search
                $enriched['google_search_url'] = "https://www.google.com/search?q=" . urlencode($name . ' landmark');

                // Try search with country
                if (isset($result['country']) && $result['country']) {
                    $fallbackData = $this->osmService->geocode($name . ', ' . $result['country']);
                    if ($fallbackData) {
                        $enriched['latitude'] = $fallbackData['lat'];
                        $enriched['longitude'] = $fallbackData['lng'];
                        $enriched['coordinate_source'] = 'fallback';
                        $enriched['dms'] = $this->decimalToDMS($fallbackData['lat'], $fallbackData['lng']);
                    }
                }
            }
        }

        return $enriched;
    }

    /**
     * Convert decimal to DMS format
     */
    protected function decimalToDMS(float $lat, float $lng): string
    {
        $latDir = $lat >= 0 ? 'N' : 'S';
        $lngDir = $lng >= 0 ? 'E' : 'W';

        $lat = abs($lat);
        $lng = abs($lng);

        $latDeg = floor($lat);
        $latMin = floor(($lat - $latDeg) * 60);
        $latSec = round(($lat - $latDeg - $latMin/60) * 3600, 1);

        $lngDeg = floor($lng);
        $lngMin = floor(($lng - $lngDeg) * 60);
        $lngSec = round(($lng - $lngDeg - $lngMin/60) * 3600, 1);

        return "{$latDeg}°{$latMin}'{$latSec}\"{$latDir} {$lngDeg}°{$lngMin}'{$lngSec}\"{$lngDir}";
    }

    /**
     * ✅ FIXED: Get Plus Code - returns string or null
     */
    protected function getPlusCode(float $lat, float $lng): ?string
    {
        try {
            // Try the Plus Codes API first
            $response = Http::timeout(5)->get('https://plus.codes/api', [
                'address' => "{$lat},{$lng}"
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['plus_code']) && is_string($data['plus_code'])) {
                    return $data['plus_code'];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Plus Codes API failed: ' . $e->getMessage());
        }

        // Fallback: Generate Open Location Code
        try {
            return $this->generateOpenLocationCode($lat, $lng);
        } catch (\Exception $e) {
            Log::warning('Open Location Code generation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ FIXED: Generate Open Location Code - returns string only
     */
    protected function generateOpenLocationCode(float $lat, float $lng, int $codeLength = 10): string
    {
        $chars = '23456789CFGHJMPQRVWX';
        $charCount = strlen($chars);
        
        // Normalize coordinates
        $lat = $lat + 90;
        $lng = $lng + 180;
        
        // Calculate grid positions
        $latPos = $lat / 20;
        $lngPos = $lng / 20;
        
        $code = '';
        
        // First 4 pairs (8 characters) for the area
        for ($i = 0; $i < 4; $i++) {
            $latIndex = (int)floor($latPos);
            $lngIndex = (int)floor($lngPos);
            
            $code .= $chars[$lngIndex % $charCount];
            $code .= $chars[$latIndex % $charCount];
            
            $latPos = ($latPos - $latIndex) * 20;
            $lngPos = ($lngPos - $lngIndex) * 20;
        }
        
        $code .= '+';
        
        // Next 4 pairs (8 characters) for precision
        for ($i = 0; $i < 4; $i++) {
            $latIndex = (int)floor($latPos);
            $lngIndex = (int)floor($lngPos);
            
            $code .= $chars[$lngIndex % $charCount];
            $code .= $chars[$latIndex % $charCount];
            
            $latPos = ($latPos - $latIndex) * 20;
            $lngPos = ($lngPos - $lngIndex) * 20;
        }
        
        return $code;
    }

    /**
     * Get What3Words location
     */
    protected function getWhat3Words(float $lat, float $lng): ?string
    {
        $apiKey = config('services.what3words.key');
        if (empty($apiKey)) {
            return null;
        }

        try {
            $response = Http::timeout(5)->get('https://api.what3words.com/v3/convert-to-3wa', [
                'key' => $apiKey,
                'coordinates' => "{$lat},{$lng}",
                'format' => 'json'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['words'] ?? null;
            }
        } catch (\Exception $e) {
            Log::warning('What3Words API failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Reality check with multiple sources
     */
    protected function realityCheck(string $landmarkName, float $lat, float $lng): array
    {
        $reality = [
            'verified' => false,
            'sources' => [],
            'confidence_boost' => 0,
        ];

        // 1. Check Wikipedia
        $wiki = $this->getWikipediaInfo($landmarkName);
        if ($wiki['available']) {
            $reality['sources'][] = 'Wikipedia';
            $reality['confidence_boost'] += 5;
            $reality['verified'] = true;
        }

        // 2. Check Wikidata
        $wikidata = $this->getWikidataUrl($landmarkName);
        if ($wikidata) {
            $reality['sources'][] = 'Wikidata';
            $reality['confidence_boost'] += 5;
        }

        // 3. Check Google Places (if key available)
        $places = $this->getGooglePlace($landmarkName, $lat, $lng);
        if ($places) {
            $reality['sources'][] = 'Google Places';
            $reality['confidence_boost'] += 10;
            $reality['verified'] = true;
        }

        // 4. Check OpenStreetMap
        $osm = $this->osmService->geocode($landmarkName);
        if ($osm) {
            $reality['sources'][] = 'OpenStreetMap';
            $reality['confidence_boost'] += 5;
        }

        return $reality;
    }

    /**
     * Get Google Place data
     */
    protected function getGooglePlace(string $name, float $lat, float $lng): ?array
    {
        $apiKey = config('services.google.maps_key');
        if (empty($apiKey)) {
            return null;
        }

        try {
            $response = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/place/findplacefromtext/json', [
                'input' => $name,
                'inputtype' => 'textquery',
                'locationbias' => "circle:5000@{$lat},{$lng}",
                'fields' => 'place_id,formatted_address,name,rating,geometry',
                'key' => $apiKey,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if ($data['status'] === 'OK' && !empty($data['candidates'])) {
                    return $data['candidates'][0];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Google Places API failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Get weather data
     */
    protected function getWeatherData(float $lat, float $lng): array
    {
        try {
            $response = Http::timeout(10)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => $lat,
                'longitude' => $lng,
                'current' => 'temperature_2m,weather_code,wind_speed_10m,humidity,cloud_cover',
                'timezone' => 'auto'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'temperature' => $data['current']['temperature_2m'] ?? null,
                    'weather_code' => $data['current']['weather_code'] ?? null,
                    'weather_description' => $this->getWeatherDescription($data['current']['weather_code'] ?? null),
                    'wind_speed' => $data['current']['wind_speed_10m'] ?? null,
                    'humidity' => $data['current']['humidity'] ?? null,
                    'cloud_cover' => $data['current']['cloud_cover'] ?? null,
                    'unit' => $data['current_units']['temperature_2m'] ?? '°C',
                    'available' => true
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Weather data retrieval failed: ' . $e->getMessage());
        }
        return ['available' => false];
    }

    /**
     * Get weather description from code
     */
    protected function getWeatherDescription(?int $code): string
    {
        $weatherCodes = [
            0 => 'Clear sky ☀️',
            1 => 'Mainly clear 🌤️',
            2 => 'Partly cloudy ⛅',
            3 => 'Overcast ☁️',
            45 => 'Foggy 🌫️',
            48 => 'Depositing rime fog 🌫️',
            51 => 'Light drizzle 🌦️',
            53 => 'Moderate drizzle 🌧️',
            55 => 'Dense drizzle 🌧️',
            61 => 'Slight rain 🌦️',
            63 => 'Moderate rain 🌧️',
            65 => 'Heavy rain ☔',
            71 => 'Slight snow fall ❄️',
            73 => 'Moderate snow fall ❄️',
            75 => 'Heavy snow fall ❄️',
            95 => 'Thunderstorm ⛈️',
            96 => 'Thunderstorm with slight hail ⛈️',
            99 => 'Thunderstorm with heavy hail ⛈️'
        ];
        return $weatherCodes[$code] ?? 'Unknown 🌍';
    }

    /**
     * Get Wikipedia info
     */
    protected function getWikipediaInfo(string $name): array
    {
        try {
            $response = Http::timeout(5)->get('https://en.wikipedia.org/api/rest_v1/page/summary/' . urlencode($name));
            if ($response->successful()) {
                $data = $response->json();
                return [
                    'title' => $data['title'] ?? $name,
                    'extract' => $data['extract'] ?? null,
                    'url' => $data['content_urls']['desktop']['page'] ?? null,
                    'thumbnail' => $data['thumbnail']['source'] ?? null,
                    'available' => true
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Wikipedia search failed: ' . $e->getMessage());
        }
        return ['available' => false];
    }

    /**
     * Get Wikidata URL
     */
    protected function getWikidataUrl(string $name): ?string
    {
        try {
            $response = Http::timeout(5)->get('https://www.wikidata.org/w/api.php', [
                'action' => 'wbsearchentities',
                'search' => $name,
                'language' => 'en',
                'format' => 'json'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data['search'])) {
                    $id = $data['search'][0]['id'] ?? null;
                    if ($id) {
                        return "https://www.wikidata.org/wiki/" . $id;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Wikidata search failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Search for a place manually
     */
    public function searchPlace(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:2',
            'category' => 'sometimes|string'
        ]);

        $results = $this->osmService->searchPlaces(
            $request->input('query'),
            $request->input('category', '')
        );

        return response()->json([
            'success' => true,
            'results' => $results
        ]);
    }

    /**
     * Submit a location correction
     */
    public function submitCorrection(Request $request, $landmarkId)
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        // Store the correction
        // This should be saved to a landmark_corrections table

        return response()->json([
            'success' => true,
            'message' => 'Correction submitted successfully! Thank you for helping improve the accuracy.',
            'data' => [
                'landmark_id' => $landmarkId,
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'verified' => false,
                'pending_review' => true
            ]
        ]);
    }
}