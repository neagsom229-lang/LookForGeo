// app/Http/Controllers/PhotoGeolocationController.php

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Intervention\Image\ImageManager;
use PHPExif\Reader\Reader;

class PhotoGeolocationController extends Controller
{
    public function identify(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|max:16384', // 16MB max
        ]);

        $photo = $request->file('photo');
        $tempPath = $photo->getPathname();

        // 1. Extract EXIF data
        $exifData = $this->extractExif($tempPath);
        
        // 2. If GPS found in EXIF, use it directly
        if ($exifData && isset($exifData['GPS'])) {
            $coords = $this->exifToCoordinates($exifData['GPS']);
            return $this->findNearbyPlaces($coords['lat'], $coords['lng']);
        }

        // 3. If no GPS, use Vision AI
        return $this->analyzeWithVision($tempPath);
    }

    private function extractExif($path)
    {
        $reader = Reader::factory(Reader::TYPE_NATIVE);
        $image = $reader->read($path);
        
        return $image->getExif();
    }

    private function exifToCoordinates($gpsData)
    {
        // Convert EXIF GPS format (degrees/minutes/seconds) to decimal
        $lat = $this->gpsToDecimal(
            $gpsData['GPSLatitude'],
            $gpsData['GPSLatitudeRef']
        );
        $lng = $this->gpsToDecimal(
            $gpsData['GPSLongitude'],
            $gpsData['GPSLongitudeRef']
        );
        
        return ['lat' => $lat, 'lng' => $lng];
    }

    private function gpsToDecimal($coordinate, $hemisphere)
    {
        $degrees = count($coordinate) > 0 ? $this->gpsToFloat($coordinate[0]) : 0;
        $minutes = count($coordinate) > 1 ? $this->gpsToFloat($coordinate[1]) : 0;
        $seconds = count($coordinate) > 2 ? $this->gpsToFloat($coordinate[2]) : 0;

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
        
        if ($hemisphere === 'S' || $hemisphere === 'W') {
            $decimal = -$decimal;
        }
        
        return $decimal;
    }

    private function gpsToFloat($gpsPart)
    {
        $parts = explode('/', $gpsPart);
        if (count($parts) <= 1) {
            return floatval($parts[0]);
        }
        return floatval($parts[0]) / floatval($parts[1]);
    }
}