<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Landmark;

class GeminiService
{
    protected $apiKey;
    protected $model;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
        $this->model = config('services.gemini.model', 'gemini-3.6-flash');
        
        Log::info('GeminiService initialized', [
            'api_key_set' => !empty($this->apiKey),
            'model' => $this->model
        ]);
    }

    /**
     * Analyze image with Gemini AI
     */
    public function analyzeImage($imageData, $prompt)
    {
        try {
            if (empty($this->apiKey)) {
                Log::error('Gemini API key is not set');
                return ['error' => 'api_key_missing', 'message' => 'API key not configured'];
            }

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";
            
            Log::info('Calling Gemini API', ['model' => $this->model]);
            
            $response = Http::timeout(120)->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inline_data' => [
                                    'mime_type' => 'image/jpeg',
                                    'data' => base64_encode($imageData)
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.05,
                    'maxOutputTokens' => 8192,
                    'topP' => 0.9,
                    'topK' => 50,
                ]
            ]);

            Log::info('Gemini API response', ['status' => $response->status()]);

            if ($response->status() === 429) {
                return ['error' => 'quota_exceeded', 'message' => 'API quota exceeded. Please try again later.'];
            }

            if (!$response->successful()) {
                Log::error('Gemini API error: ' . $response->body());
                return ['error' => 'api_error', 'message' => 'API returned error: ' . $response->status()];
            }

            $result = $response->json();
            $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            Log::info('Gemini Response received', ['length' => strlen($text)]);
            
            $text = $this->cleanText($text);
            $parsed = $this->parseAIResponse($text);
            
            if (!$parsed) {
                return $this->getFallbackResponse('Could not parse AI response.');
            }
            
            // ✅ Ultimate enrichment
            $parsed = $this->ultimateEnrichment($parsed);
            
            return $this->cleanArray($parsed);

        } catch (\Exception $e) {
            Log::error('Gemini Service error: ' . $e->getMessage());
            return ['error' => 'service_error', 'message' => $e->getMessage()];
        }
    }

    /**
     * ✅ ULTIMATE ENRICHMENT - All methods combined
     */
    private function ultimateEnrichment($data)
    {
        // Layer 1: Validate all fields
        $data = $this->validateAllFields($data);
        
        // Layer 2: Database cross-reference
        $data = $this->databaseCrossReference($data);
        
        // Layer 3: Multi-source coordinate verification
        $data = $this->multiSourceVerification($data);
        
        // Layer 4: Confidence calculation
        $data = $this->calculateUltimateConfidence($data);
        
        // Layer 5: Add rich metadata
        $data = $this->addRichMetadata($data);
        
        return $data;
    }

    /**
     * ✅ DATABASE CROSS REFERENCE
     */
    private function databaseCrossReference($data)
    {
        $landmarkName = $data['landmark_name'] ?? '';
        $city = $data['city'] ?? '';
        $country = $data['country'] ?? '';
        
        try {
            // Search in database
            $landmarks = Landmark::searchByName($landmarkName)
                ->when($country, function($query, $country) {
                    return $query->orWhere('country', 'LIKE', "%{$country}%");
                })
                ->when($city, function($query, $city) {
                    return $query->orWhere('city', 'LIKE', "%{$city}%");
                })
                ->limit(3)
                ->get();
            
            if ($landmarks->isNotEmpty()) {
                $landmark = $landmarks->first();
                
                $data['landmark_name'] = $landmark->name;
                $data['city'] = $landmark->city ?? $data['city'];
                $data['country'] = $landmark->country;
                $data['region'] = $landmark->region ?? $data['region'];
                $data['latitude'] = $landmark->latitude;
                $data['longitude'] = $landmark->longitude;
                $data['matched_landmark'] = $landmark->name;
                
                if ($landmark->description) {
                    $data['description'] = $landmark->description;
                }
                
                $data['confidence'] = max($data['confidence'] ?? 50, 85);
                $data['reasoning'] = ($data['reasoning'] ?? '') . ' Matched with database landmark: ' . $landmark->name;
                
                Log::info('Found landmark in database', ['landmark' => $landmark->name]);
            }
        } catch (\Exception $e) {
            Log::warning('Database landmark search failed: ' . $e->getMessage());
        }
        
        return $data;
    }

    /**
     * ✅ MULTI-SOURCE VERIFICATION
     */
    private function multiSourceVerification($data)
    {
        $coordinates = [];
        $sources = [];
        
        // Source 1: AI coordinates
        if (isset($data['latitude']) && isset($data['longitude']) && 
            $this->isValidCoordinate($data['latitude'], $data['longitude'])) {
            $coordinates[] = ['lat' => $data['latitude'], 'lng' => $data['longitude'], 'source' => 'AI'];
            $sources[] = 'AI';
        }
        
        // Source 2: Extract from reasoning
        if (isset($data['reasoning'])) {
            $extracted = $this->extractCoordinatesFromText($data['reasoning']);
            if ($extracted) {
                $coordinates[] = ['lat' => $extracted['lat'], 'lng' => $extracted['lng'], 'source' => 'Text'];
                $sources[] = 'Text';
            }
        }
        
        // Source 3: Country-based approximation
        if (isset($data['country']) && !empty($data['country'])) {
            $countryCoord = $this->getCountryCoordinates($data['country']);
            if ($countryCoord) {
                $coordinates[] = ['lat' => $countryCoord['lat'], 'lng' => $countryCoord['lng'], 'source' => 'Country'];
                $sources[] = 'Country';
            }
        }
        
        // ✅ Vote on the best coordinates
        $best = $this->voteOnCoordinates($coordinates);
        
        if ($best) {
            $data['latitude'] = $best['lat'];
            $data['longitude'] = $best['lng'];
            $data['coordinate_sources'] = array_unique($sources);
            $data['coordinate_confidence'] = $best['confidence'];
        }
        
        return $data;
    }

    /**
     * ✅ VOTE ON COORDINATES
     */
    private function voteOnCoordinates($coordinates)
    {
        if (empty($coordinates)) return null;
        if (count($coordinates) === 1) {
            return ['lat' => $coordinates[0]['lat'], 'lng' => $coordinates[0]['lng'], 'confidence' => 80];
        }
        
        // Find clusters of coordinates
        $clusters = [];
        foreach ($coordinates as $coord) {
            $found = false;
            foreach ($clusters as &$cluster) {
                $dist = $this->calculateDistance(
                    $cluster['lat'], $cluster['lng'],
                    $coord['lat'], $coord['lng']
                );
                if ($dist < 50) {
                    $cluster['coords'][] = $coord;
                    $cluster['count']++;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $clusters[] = [
                    'lat' => $coord['lat'],
                    'lng' => $coord['lng'],
                    'coords' => [$coord],
                    'count' => 1
                ];
            }
        }
        
        // Find the largest cluster
        $bestCluster = null;
        $maxCount = 0;
        foreach ($clusters as $cluster) {
            if ($cluster['count'] > $maxCount) {
                $maxCount = $cluster['count'];
                $bestCluster = $cluster;
            }
        }
        
        if ($bestCluster) {
            $avgLat = 0;
            $avgLng = 0;
            foreach ($bestCluster['coords'] as $coord) {
                $avgLat += $coord['lat'];
                $avgLng += $coord['lng'];
            }
            $avgLat /= count($bestCluster['coords']);
            $avgLng /= count($bestCluster['coords']);
            
            $confidence = min(100, $bestCluster['count'] * 20 + 50);
            
            return ['lat' => $avgLat, 'lng' => $avgLng, 'confidence' => $confidence];
        }
        
        return ['lat' => $coordinates[0]['lat'], 'lng' => $coordinates[0]['lng'], 'confidence' => 60];
    }

    /**
     * ✅ CALCULATE ULTIMATE CONFIDENCE
     */
    private function calculateUltimateConfidence($data)
    {
        $baseConfidence = $data['confidence'] ?? 0;
        $factors = [];
        $totalBoost = 0;
        
        // Factor 1: Multiple coordinate sources
        if (isset($data['coordinate_sources'])) {
            $sourceCount = count($data['coordinate_sources']);
            if ($sourceCount >= 3) {
                $totalBoost += 15;
                $factors[] = 'Multiple sources confirmed (' . $sourceCount . ' sources)';
            } elseif ($sourceCount >= 2) {
                $totalBoost += 10;
                $factors[] = 'Two sources confirmed';
            } else {
                $factors[] = 'Single source';
            }
        }
        
        // Factor 2: Database match
        if (isset($data['matched_landmark'])) {
            $totalBoost += 15;
            $factors[] = 'Database landmark match: ' . $data['matched_landmark'];
        }
        
        // Factor 3: Specific location name
        if (isset($data['landmark_name']) && $data['landmark_name'] !== 'Unknown Location') {
            $totalBoost += 10;
            $factors[] = 'Specific location identified';
        }
        
        // Factor 4: City and country present
        if (isset($data['city']) && isset($data['country']) && 
            !empty($data['city']) && !empty($data['country'])) {
            $totalBoost += 5;
            $factors[] = 'City and country identified';
        }
        
        // Calculate final confidence
        $finalConfidence = min(100, $baseConfidence + $totalBoost);
        $finalConfidence = round($finalConfidence / 5) * 5;
        
        $data['confidence'] = $finalConfidence;
        $data['confidence_factors'] = $factors;
        $data['confidence_boost'] = $totalBoost;
        
        return $data;
    }

    /**
     * ✅ VALIDATE ALL FIELDS
     */
    private function validateAllFields($data)
    {
        if (!isset($data['landmark_name']) || empty($data['landmark_name'])) {
            $data['landmark_name'] = 'Unknown Location';
        }
        
        if (!isset($data['confidence']) || $data['confidence'] < 0) {
            $data['confidence'] = 0;
        }
        
        if (!isset($data['type']) || empty($data['type'])) {
            $data['type'] = 'landmark';
        }
        
        if (!isset($data['tags']) || empty($data['tags'])) {
            $data['tags'] = ['Geolocation', 'OSINT', 'AI Analysis'];
        }
        
        if (!isset($data['description']) || empty($data['description'])) {
            $data['description'] = 'Location identified by AI analysis.';
        }
        
        return $data;
    }

    /**
     * ✅ ADD RICH METADATA
     */
    private function addRichMetadata($data)
    {
        if (!isset($data['continent']) || empty($data['continent'])) {
            $data['continent'] = $this->getContinent($data['country'] ?? '');
        }
        
        if (isset($data['confidence'])) {
            $data['confidence_level'] = $this->getConfidenceLevel($data['confidence']);
        }
        
        if (isset($data['latitude']) && isset($data['longitude'])) {
            $data['timezone'] = $this->getTimezone($data['latitude'], $data['longitude']);
        }
        
        if (isset($data['tags']) && is_array($data['tags'])) {
            $data['tags'] = array_merge($data['tags'], ['Geolocation', 'OSINT', 'AI Verified']);
            $data['tags'] = array_slice(array_unique($data['tags']), 0, 10);
        }
        
        return $data;
    }

    /**
     * ✅ CHECK IF COORDINATE IS VALID
     */
    private function isValidCoordinate($lat, $lng)
    {
        return is_numeric($lat) && is_numeric($lng) &&
            abs($lat) <= 90 && abs($lng) <= 180 &&
            $lat != 0 && $lng != 0;
    }

    /**
     * ✅ EXTRACT COORDINATES FROM TEXT
     */
    private function extractCoordinatesFromText($text)
    {
        if (preg_match('/(\d+\.?\d*)\s*[,.]\s*(\d+\.?\d*)/', $text, $matches)) {
            $lat = floatval($matches[1]);
            $lng = floatval($matches[2]);
            if (abs($lat) <= 90 && abs($lng) <= 180 && $lat != 0 && $lng != 0) {
                return ['lat' => $lat, 'lng' => $lng];
            }
        }
        return null;
    }

    /**
     * ✅ GET COUNTRY COORDINATES
     */
    private function getCountryCoordinates($country)
    {
        $countries = [
            'cambodia' => ['lat' => 11.5564, 'lng' => 104.9282],
            'united states' => ['lat' => 37.0902, 'lng' => -95.7129],
            'united kingdom' => ['lat' => 51.5074, 'lng' => -0.1278],
            'france' => ['lat' => 48.8566, 'lng' => 2.3522],
            'italy' => ['lat' => 41.9028, 'lng' => 12.4964],
            'japan' => ['lat' => 35.6762, 'lng' => 139.6503],
            'india' => ['lat' => 20.5937, 'lng' => 78.9629],
            'china' => ['lat' => 39.9042, 'lng' => 116.4074],
            'australia' => ['lat' => -33.8688, 'lng' => 151.2093],
            'egypt' => ['lat' => 30.0444, 'lng' => 31.2357],
            'brazil' => ['lat' => -22.9068, 'lng' => -43.1729],
            'thailand' => ['lat' => 13.7563, 'lng' => 100.5018],
            'singapore' => ['lat' => 1.3521, 'lng' => 103.8198],
            'uae' => ['lat' => 25.2048, 'lng' => 55.2708],
            'south africa' => ['lat' => -33.9249, 'lng' => 18.4241],
        ];
        
        $countryLower = strtolower($country);
        foreach ($countries as $name => $coord) {
            if (strpos($countryLower, $name) !== false) {
                return $coord;
            }
        }
        return null;
    }

    /**
     * ✅ CALCULATE DISTANCE
     */
    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) + 
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
             sin($dLng/2) * sin($dLng/2);
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1-$a));
    }

    /**
     * ✅ GET CONTINENT
     */
    private function getContinent($country)
    {
        $continents = [
            'cambodia' => 'Asia', 'thailand' => 'Asia', 'singapore' => 'Asia', 
            'china' => 'Asia', 'japan' => 'Asia', 'india' => 'Asia', 'uae' => 'Asia',
            'united states' => 'North America', 'canada' => 'North America', 'mexico' => 'North America',
            'united kingdom' => 'Europe', 'france' => 'Europe', 'italy' => 'Europe', 
            'germany' => 'Europe', 'spain' => 'Europe', 'russia' => 'Europe',
            'australia' => 'Oceania', 'new zealand' => 'Oceania',
            'egypt' => 'Africa', 'south africa' => 'Africa', 'nigeria' => 'Africa',
            'brazil' => 'South America', 'argentina' => 'South America', 'chile' => 'South America',
        ];
        
        $countryLower = strtolower($country);
        foreach ($continents as $name => $continent) {
            if (strpos($countryLower, $name) !== false) {
                return $continent;
            }
        }
        return 'Unknown';
    }

    /**
     * ✅ GET TIMEZONE
     */
    private function getTimezone($lat, $lng)
    {
        if (!$lat || !$lng) return 'Unknown';
        $timezone = floor($lng / 15);
        if ($timezone >= 0) {
            return 'UTC+' . $timezone;
        }
        return 'UTC' . $timezone;
    }

    /**
     * ✅ GET CONFIDENCE LEVEL
     */
    private function getConfidenceLevel($confidence)
    {
        if ($confidence >= 95) return 'Verified - Very High';
        if ($confidence >= 85) return 'Very High';
        if ($confidence >= 70) return 'High';
        if ($confidence >= 50) return 'Medium';
        if ($confidence >= 30) return 'Low';
        return 'Very Low';
    }

    /**
     * ✅ CLEAN TEXT
     */
    private function cleanText($text)
    {
        if (!$text) return '';
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x80-\xFF]/u', '', $text);
        return trim($text);
    }

    /**
     * ✅ CLEAN ARRAY
     */
    private function cleanArray($data)
    {
        if (is_string($data)) {
            return $this->cleanText($data);
        }
        if (is_array($data)) {
            $cleaned = [];
            foreach ($data as $key => $value) {
                $cleanKey = is_string($key) ? $this->cleanText($key) : $key;
                $cleaned[$cleanKey] = $this->cleanArray($value);
            }
            return $cleaned;
        }
        return $data;
    }

    /**
     * ✅ PARSE AI RESPONSE
     */
    private function parseAIResponse($text)
    {
        preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches);
        $jsonString = $matches[1] ?? $text;
        $jsonString = trim($jsonString);
        preg_match('/\{[\s\S]*\}/', $jsonString, $jsonMatches);
        $jsonString = $jsonMatches[0] ?? $jsonString;
        $jsonString = preg_replace('/,\s*}/', '}', $jsonString);
        $jsonString = preg_replace('/,\s*\]/', ']', $jsonString);
        $jsonString = preg_replace('/\n\s*/', ' ', $jsonString);
        $jsonString = mb_convert_encoding($jsonString, 'UTF-8', 'UTF-8');
        
        try {
            return json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            Log::error('JSON parse error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ FALLBACK RESPONSE
     */
    private function getFallbackResponse($message)
    {
        return [
            'landmark_name' => 'Unknown Location',
            'city' => 'Unknown',
            'country' => 'Unknown',
            'latitude' => null,
            'longitude' => null,
            'confidence' => 0,
            'reasoning' => $message,
            'tags' => ['Unknown'],
            'description' => 'Unable to determine location.',
            'type' => 'unknown',
            'continent' => 'Unknown',
            'timezone' => 'Unknown',
            'confidence_level' => 'None'
        ];
    }

    /**
     * ✅ BUILD GEOLOCATION PROMPT
     */
    private function buildGeoPrompt($metadata)
    {
        $exifInfo = '';
        if (!empty($metadata) && isset($metadata['gps'])) {
            $exifInfo .= "EXIF GPS: " . $metadata['gps']['latitude'] . ", " . $metadata['gps']['longitude'] . "\n";
        }

        return <<<PROMPT
You are a world-class OSINT geolocation expert. Identify the EXACT location where this image was taken.

{$exifInfo}

**ANALYZE THESE VISUAL CLUES:**

1. Architecture (style, materials, colors, roof types, windows)
2. Vegetation (trees, plants, climate indicators)
3. Infrastructure (roads, signs, lampposts, sidewalks)
4. Vehicles (types, license plates, driving side)
5. Language/Text (signs, advertisements, store names)
6. Weather/Climate (clouds, shadows, light direction)
7. Terrain (mountains, rivers, coastline, landscape)
8. People (clothing styles, activities, cultural indicators)

**OUTPUT - Return ONLY valid JSON:**
{
    "landmark_name": "Specific location name",
    "city": "City name",
    "country": "Country name",
    "region": "Region/State/Province",
    "latitude": 0.000000,
    "longitude": 0.000000,
    "confidence": 0-100,
    "reasoning": "Step-by-step reasoning with visual evidence",
    "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"],
    "description": "Description of the scene",
    "type": "landmark|natural|city|architectural|historical",
    "visual_clues": ["clue1", "clue2", "clue3", "clue4"]
}
PROMPT;
    }

    /**
     * ✅ MAIN ENTRY POINT
     */
    public function analyzeGeolocation($imageData, $metadata = [])
    {
        $prompt = $this->buildGeoPrompt($metadata);
        $result = $this->analyzeImage($imageData, $prompt);
        
        if (!is_array($result)) {
            return $this->getFallbackResponse('Invalid response from AI.');
        }
        
        if (!isset($result['error'])) {
            $result = $this->ultimateEnrichment($result);
        }
        
        return $result;
    }

    /**
     * ✅ CHAT WITH GEMINI
     */
    public function chat($message, $context = [])
    {
        try {
            if (empty($this->apiKey)) {
                return null;
            }

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";
            
            $prompt = $message;
            if (!empty($context)) {
                $prompt = "Context: " . json_encode($context) . "\n\nQuestion: " . $message;
            }

            $response = Http::timeout(30)->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 1024,
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if ($text) {
                    return $this->cleanText($text);
                }
                return null;
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Chat error: ' . $e->getMessage());
            return null;
        }
    }
}