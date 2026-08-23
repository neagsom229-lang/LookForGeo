<?php

namespace App\Http\Controllers;


use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use App\Models\Analysis;
use App\Services\GeminiService;

class AnalysisController extends Controller
{
    protected $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    public function index()
    {
        return view('analysis');
    }
    
    /**
     * Analyze uploaded image with Ultimate AI Intelligence
     */
    public function analyze(Request $request)
    {
        // ✅ Check if user is authenticated
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to upload images.'
            ], 401);
        }

        $request->validate([
            'image' => 'required|file|mimes:jpeg,png,jpg,gif,webp|max:20480',
        ]);

        try {
            $file = $request->file('image');
$filename = time() . '_' . $file->getClientOriginalName();

$uploadedFile = cloudinary()->upload($file->getRealPath(), [
    'folder' => 'tracegeo/analyses',
    'public_id' => pathinfo($filename, PATHINFO_FILENAME),
]);

$path = $uploadedFile->getSecurePath(); // full Cloudinary HTTPS URL
            
            // ✅ Extract REAL metadata from image
            $metadata = $this->extractFullMetadata($file);
            
            // ✅ Get image data (with compression if available)
            $imageData = $this->getImageData($file);
            
            \Log::info('Starting Ultimate AI Analysis', [
                'file' => $filename,
                'size' => strlen($imageData),
                'has_gps' => isset($metadata['gps'])
            ]);
            
            // ✅ CALL ULTIMATE GEMINI AI ANALYSIS
            $aiResult = $this->geminiService->analyzeGeolocation($imageData, $metadata);
            
            // ✅ CHECK FOR AI ERRORS
            if (isset($aiResult['error'])) {
                \Log::error('AI error', ['error' => $aiResult]);
                
                // ✅ Use GPS if available
                if (isset($metadata['gps']) && isset($metadata['gps']['latitude'])) {
                    $finalResult = $this->createGPSResult($metadata['gps']);
                    $source = 'GPS Metadata (Fallback)';
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'AI analysis failed: ' . ($aiResult['message'] ?? 'Unknown error')
                    ], 500);
                }
            } else {
                // ✅ AI SUCCESS - USE REAL AI DATA
                $finalResult = $aiResult;
                $source = 'Gemini AI (Ultimate Analysis)';
                
                // ✅ Enrich with REAL GPS if available
                if (isset($metadata['gps']) && isset($metadata['gps']['latitude'])) {
                    $finalResult = $this->enrichWithGPS($finalResult, $metadata);
                }
                
                \Log::info('AI identified location', [
                    'landmark' => $finalResult['landmark_name'] ?? 'Unknown',
                    'coordinates' => ($finalResult['latitude'] ?? 'null') . ', ' . ($finalResult['longitude'] ?? 'null'),
                    'confidence' => $finalResult['confidence'] ?? 0,
                    'confidence_level' => $finalResult['confidence_level'] ?? 'Unknown'
                ]);
            }
            
            // ✅ Build comprehensive metadata
            $metadataArray = [
                'reasoning' => $finalResult['reasoning'] ?? null,
                'tags' => $finalResult['tags'] ?? [],
                'historical_context' => $finalResult['historical_context'] ?? null,
                'cultural_context' => $finalResult['cultural_context'] ?? null,
                'visual_clues' => $finalResult['visual_clues'] ?? [],
                'alternative_locations' => $finalResult['alternative_locations'] ?? [],
                'exif_data' => $metadata,
                'analysis_source' => $source,
                'analysis_notes' => $finalResult['analysis_notes'] ?? null,
                'continent' => $finalResult['continent'] ?? null,
                'timezone' => $finalResult['timezone'] ?? null,
                'confidence_level' => $finalResult['confidence_level'] ?? null,
                'confidence_factors' => $finalResult['confidence_factors'] ?? [],
                'coordinate_sources' => $finalResult['coordinate_sources'] ?? [],
                'matched_landmark' => $finalResult['matched_landmark'] ?? null,
                'coordinate_confidence' => $finalResult['coordinate_confidence'] ?? null,
            ];
            
            // ✅ SAVE TO DATABASE
            $analysis = Analysis::create([
                'landmark_name' => $finalResult['landmark_name'] ?? 'Unknown Location',
                'local_name' => $finalResult['local_name'] ?? null,
                'latitude' => $finalResult['latitude'] ?? null,
                'longitude' => $finalResult['longitude'] ?? null,
                'country' => $finalResult['country'] ?? null,
                'city' => $finalResult['city'] ?? null,
                'confidence' => $finalResult['confidence'] ?? 0,
                'description' => $finalResult['description'] ?? null,
                'type' => $finalResult['type'] ?? null,
                'image_path' => $path,
                'user_id' => auth()->id(),
                'metadata' => json_encode($metadataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return response()->json([
                'success' => true,
                'analysis_id' => $analysis->id,
                'source' => $source,
                'data' => [
                    'id' => $analysis->id,
                    'landmark_name' => $analysis->landmark_name,
                    'local_name' => $analysis->local_name,
                    'city' => $analysis->city,
                    'country' => $analysis->country,
                    'latitude' => $analysis->latitude,
                    'longitude' => $analysis->longitude,
                    'confidence' => $analysis->confidence,
                    'description' => $analysis->description,
                    'type' => $analysis->type,
                    'image_url' => $path,
                    'metadata' => json_decode($analysis->metadata, true),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Analysis error: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Error processing analysis: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get image data with optional compression
     */
    private function getImageData($file)
    {
        // ✅ Check if GD extension is available
        if (extension_loaded('gd') && function_exists('imagecreatefromstring')) {
            try {
                $compressed = $this->compressImage($file);
                if ($compressed) {
                    return $compressed;
                }
            } catch (\Exception $e) {
                \Log::warning('Image compression failed, using original: ' . $e->getMessage());
            }
        }
        
        // ✅ Fallback: return original image data
        return file_get_contents($file->path());
    }

    /**
     * Compress image to reduce size (if GD available)
     */
    private function compressImage($file)
    {
        // ✅ Check if GD functions exist
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        
        $image = @imagecreatefromstring(file_get_contents($file->path()));
        
        if (!$image) {
            return null;
        }
        
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Resize to max 1024px
        $maxDimension = 1024;
        if ($width > $maxDimension || $height > $maxDimension) {
            $ratio = min($maxDimension / $width, $maxDimension / $height);
            $newWidth = (int)($width * $ratio);
            $newHeight = (int)($height * $ratio);
        } else {
            $newWidth = $width;
            $newHeight = $height;
        }
        
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        ob_start();
        imagejpeg($newImage, null, 80);
        $compressed = ob_get_clean();
        
        imagedestroy($image);
        imagedestroy($newImage);
        
        return $compressed;
    }

    /**
     * Extract REAL metadata from image
     */
    private function extractFullMetadata($file)
    {
        $data = [];
        
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($file->path());
            if ($exif) {
                // ✅ REAL GPS Data
                if (isset($exif['GPSLatitude']) && isset($exif['GPSLongitude'])) {
                    $data['gps'] = [
                        'latitude' => $this->gpsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N'),
                        'longitude' => $this->gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E')
                    ];
                }

                // ✅ REAL Camera Info
                $data['camera'] = [
                    'make' => $exif['Make'] ?? null,
                    'model' => $exif['Model'] ?? null,
                ];

                // ✅ REAL Date/Time
                $data['datetime'] = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
                $data['software'] = $exif['Software'] ?? null;
                $data['copyright'] = $exif['Copyright'] ?? null;
                
                // ✅ REAL Camera Settings
                $data['settings'] = [
                    'aperture' => $exif['ApertureFNumber'] ?? null,
                    'iso' => $exif['ISOSpeedRatings'] ?? null,
                    'focal_length' => $exif['FocalLength'] ?? null,
                ];
            }
        }

        return $data;
    }

    /**
     * Convert GPS coordinates to decimal
     */
    private function gpsToDecimal($gps, $ref)
    {
        $degrees = $gps[0] ?? 0;
        $minutes = $gps[1] ?? 0;
        $seconds = $gps[2] ?? 0;
        
        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
        
        if (in_array($ref, ['S', 'W'])) {
            $decimal = -$decimal;
        }
        
        return $decimal;
    }

    /**
     * Enrich AI result with REAL GPS data
     */
    private function enrichWithGPS($aiResult, $metadata)
    {
        if (!isset($metadata['gps'])) {
            return $aiResult;
        }
        
        $gpsLat = $metadata['gps']['latitude'];
        $gpsLng = $metadata['gps']['longitude'];
        $aiLat = $aiResult['latitude'] ?? null;
        $aiLng = $aiResult['longitude'] ?? null;
        
        // Calculate distance between AI prediction and GPS
        $distance = $this->calculateDistance($aiLat, $aiLng, $gpsLat, $gpsLng);
        
        // If GPS and AI are close (< 100km), use GPS (more accurate)
        if ($distance < 100) {
            $aiResult['latitude'] = $gpsLat;
            $aiResult['longitude'] = $gpsLng;
            $aiResult['confidence'] = min(100, ($aiResult['confidence'] ?? 0) + 10);
            $aiResult['reasoning'] = ($aiResult['reasoning'] ?? '') . ' GPS data from EXIF confirms this location.';
            $aiResult['coordinate_sources'] = ['AI', 'GPS'];
        } 
        // If GPS exists but AI confidence is low, use GPS
        elseif (($aiResult['confidence'] ?? 0) < 60) {
            $aiResult['latitude'] = $gpsLat;
            $aiResult['longitude'] = $gpsLng;
            $aiResult['confidence'] = 90;
            $aiResult['reasoning'] = ($aiResult['reasoning'] ?? '') . ' GPS data from EXIF was used to determine the location.';
            $aiResult['landmark_name'] = 'GPS Location';
            $aiResult['coordinate_sources'] = ['GPS'];
        }
        
        return $aiResult;
    }

    /**
     * Calculate distance between two points
     */
    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        if (!$lat1 || !$lng1 || !$lat2 || !$lng2) return PHP_INT_MAX;
        
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        
        $a = sin($dLat/2) * sin($dLat/2) + 
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
             sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earthRadius * $c;
    }

    /**
     * Create result from REAL GPS data ONLY - Uses live reverse geocoding
     */
    private function createGPSResult($gps)
    {
        $locationName = 'GPS Location';
        $city = 'Unknown';
        $country = 'Unknown';
        $region = 'Unknown';
        
        try {
            // ✅ REAL-TIME reverse geocoding from OpenStreetMap
            $response = Http::timeout(10)->get('https://nominatim.openstreetmap.org/reverse', [
                'lat' => $gps['latitude'],
                'lon' => $gps['longitude'],
                'format' => 'json',
                'zoom' => 10,
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['address'])) {
                    $address = $data['address'];
                    $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? 'Unknown';
                    $country = $address['country'] ?? 'Unknown';
                    $region = $address['state'] ?? $address['region'] ?? 'Unknown';
                    $locationName = $city . ', ' . $country;
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Reverse geocoding failed: ' . $e->getMessage());
        }
        
        // ✅ Return REAL GPS data
        return [
            'landmark_name' => $locationName,
            'local_name' => null,
            'city' => $city,
            'country' => $country,
            'region' => $region,
            'latitude' => $gps['latitude'],
            'longitude' => $gps['longitude'],
            'confidence' => 95,
            'reasoning' => 'Location determined from GPS data embedded in the image metadata.' . 
                           ($city !== 'Unknown' ? ' Reverse geocoding identified this as ' . $city . ', ' . $country . '.' : ''),
            'tags' => ['GPS', 'Geotagged', 'Metadata', 'EXIF'],
            'description' => 'Location extracted from EXIF GPS data.' . 
                             ($city !== 'Unknown' ? ' Located in ' . $city . ', ' . $country . '.' : ''),
            'type' => 'gps',
            'historical_context' => null,
            'cultural_context' => null,
            'visual_clues' => [],
            'alternative_locations' => [],
            'analysis_notes' => 'Location determined from EXIF GPS metadata.',
            'continent' => $this->getContinent($country),
            'timezone' => $this->getTimezone($gps['latitude'], $gps['longitude']),
            'confidence_level' => 'Very High',
            'coordinate_sources' => ['GPS'],
            'coordinate_confidence' => 95,
        ];
    }

    /**
     * Get continent from country
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
     * Get timezone from coordinates
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
     * Fetch image from URL
     */
    public function fetchImage(Request $request)
    {
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to fetch images.'
            ], 401);
        }

        $request->validate(['url' => 'required|url']);
        
        try {
            $response = Http::timeout(30)->get($request->input('url'));
            
            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not fetch image from URL: HTTP ' . $response->status()
                ], 400);
            }

            $content = $response->body();
            $contentType = $response->header('Content-Type');
            
            if (!str_starts_with($contentType, 'image/')) {
                return response()->json([
                    'success' => false,
                    'message' => 'URL does not point to an image. Content-Type: ' . $contentType
                ], 400);
            }

            $extension = match($contentType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg'
            };

            $filename = 'url_' . time() . '.' . $extension;

            return response()->json([
                'success' => true,
                'image_data' => base64_encode($content),
                'mime_type' => $contentType,
                'filename' => $filename,
            ]);

        } catch (\Exception $e) {
            \Log::error('URL fetch error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching image: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get analysis results by ID
     */
    public function getResults($id)
    {
        try {
            $analysis = Analysis::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $analysis->id,
                    'landmark_name' => $analysis->landmark_name,
                    'local_name' => $analysis->local_name,
                    'city' => $analysis->city,
                    'country' => $analysis->country,
                    'latitude' => $analysis->latitude,
                    'longitude' => $analysis->longitude,
                    'confidence' => $analysis->confidence,
                    'description' => $analysis->description,
                    'type' => $analysis->type,
                    'image_url' => $analysis->image_path,
                    'metadata' => json_decode($analysis->metadata, true),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Analysis not found'
            ], 404);
        }
    }

    /**
     * Get session analysis data
     */
    public function getSessionData(Request $request)
    {
        $result = $request->session()->get('analysis_result');
        return $result ? response()->json(['success' => true, 'data' => $result]) 
                       : response()->json(['success' => false, 'message' => 'No data found'], 404);
    }

    /**
     * Clear analysis cache
     */
    public function clearCache(Request $request)
    {
        $request->session()->forget('analysis_result');
        return response()->json(['success' => true, 'message' => 'Cache cleared']);
    }

    /**
     * Get street view URL
     */
    public function streetView(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric'
        ]);
        
        $lat = $request->input('lat');
        $lng = $request->input('lng');
        
        return response()->json([
            'success' => true,
            'url' => "https://www.google.com/maps/@?api=1&map_action=pano&viewpoint={$lat},{$lng}"
        ]);
    }
}