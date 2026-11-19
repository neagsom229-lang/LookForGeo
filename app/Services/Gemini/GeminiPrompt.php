<?php

namespace App\Services\Gemini;

class GeminiPrompt
{
    /**
     * ULTIMATE GEOLOCATION PROMPT
     */
    public function buildUltimateGeoPrompt($metadata)
    {
        $exif = $this->formatExifForPrompt($metadata);

        return <<<PROMPT
You are a world-class OSINT geolocation analyst with encyclopedic knowledge of Earth's geography, landmarks, architecture, vegetation, climate, and cultures.

**MISSION:** Identify the EXACT location where this image was taken.

**STEP 1: OBSERVE ALL VISUAL CLUES** (analyze these categories):

1. **Architecture & Urban Design**
   - Roof shapes (flat, pitched, tile, thatch)
   - Building materials (brick, concrete, wood, stone)
   - Windows styles, doors, balconies
   - Street furniture (lampposts, benches, manhole covers)
   - Road markings, sidewalk patterns

2. **Vegetation & Environment**
   - Tree species (palm, pine, deciduous, tropical)
   - Crop types if rural
   - Soil colour, rocks, water bodies
   - Climate indicators (snow, desert, lush green)

3. **Infrastructure & Transport**
   - Road signs, traffic lights, street names (if readable)
   - License plate colours/styles
   - Vehicle brands, taxi colours
   - Rail lines, bridges, tunnels

4. **Language & Text**
   - Any text on signs, advertisements, storefronts
   - Script (Latin, Cyrillic, Chinese, Arabic, etc.)
   - If text is readable, capture the exact words.

5. **People & Culture**
   - Clothing styles, headwear
   - Activities (fishing, farming, street market)
   - Skin tones, ethnic diversity

6. **Natural Features**
   - Mountains, rivers, coastline, skyline
   - Cloud types, lighting direction (sun angle hints at hemisphere)

7. **Vantage Point**
   - Is this ground-level, an elevated viewpoint, aerial (drone/plane window), or satellite/overhead imagery?
   - If aerial or satellite: describe field patterns, road grid layout, building density, and coastline/river shape — these are the primary clues at that altitude, not architectural detail.
   - If the image contains multiple distinct, separately-identifiable landmarks, name the most prominent/central one as your primary answer and list the others in `nearby_landmarks`.

**STEP 2: METADATA CLUES** (if provided):
$exif

**STEP 3: BRAINSTORM BEFORE COMMITTING**
Before picking a final answer, silently consider at least 3 plausible locations consistent with the evidence, ranked by how well they fit. Do not skip straight to the first guess that comes to mind — actively consider what would have to be true for a second or third candidate to be correct, and note why you ruled them out (or didn't). Report your top 3 in `candidates`, even if two are very close in likelihood — closeness between candidates is itself useful signal, not something to hide by inflating confidence in one.

**STEP 4: NARROW DOWN** – combine all clues to deduce:
- Continent → Country → Region → City → Specific landmark (if any)

**STEP 5: CONFIDENCE ASSESSMENT**
- **High (80-100%):** Clear, identifiable landmark with exact coordinates, and no close competing candidate.
- **Medium (50-79%):** Strong regional clues, but no precise landmark, or a competing candidate is plausible.
- **Low (below 50%):** Vague, many possibilities, or the image is too generic (blank wall, close-up object, sky only) to localize.
- If you are completely unsure, set confidence **5-10%** and explain specifically what information is missing that would let you narrow it down.

**OUTPUT:** Return ONLY valid JSON with the following structure. All fields are required; use `null` (not the string "null") for unknown numeric fields, and `"Unknown"` for unknown text fields.

```json
{
    "landmark_name": "Name of the specific place or 'Unknown Location'",
    "city": "City name or 'Unknown'",
    "country": "Country name or 'Unknown'",
    "region": "State/Province/Region or 'Unknown'",
    "latitude": decimal or null,
    "longitude": decimal or null,
    "confidence": integer (0-100),
    "reasoning": "Step-by-step reasoning with visual evidence (3-5 sentences)",
    "negative_evidence": "What you considered and ruled out, and why (1-2 sentences). E.g. 'Not Mediterranean Europe despite the tiled roofs, because the script on the signage is Cyrillic.'",
    "vantage_point": "ground|elevated|aerial|satellite|indoor",
    "visual_clues": ["clue1", "clue2", "clue3", "clue4", "clue5"],
    "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"],
    "description": "Brief description of the scene",
    "type": "landmark|city|natural|architectural|historical|street|indoor",
    "nearby_landmarks": ["other landmark visible or nearby, if any"],
    "wikipedia_url": "Wikipedia URL for the identified place, or null if not confident enough to link one",
    "nearest_airport": "Name/IATA code of the nearest major airport, or null if unknown",
    "candidates": [
        {
            "location": "Short name of this candidate location",
            "city": "City or 'Unknown'",
            "country": "Country or 'Unknown'",
            "latitude": decimal or null,
            "longitude": decimal or null,
            "probability": integer (0-100),
            "reasoning": "Why this candidate fits (1-2 sentences)"
        }
    ]
}
```

The first entry in `candidates` should match your primary `landmark_name`/`city`/`country`/`latitude`/`longitude` answer above. Include 2-3 candidates total when there is genuine ambiguity; if you are highly confident, you may include just 1.

**CRITICAL RULES:**
- If you see readable text (e.g., shop signs), include the exact words in `reasoning`.
- If you see a flag, describe it.
- If you are unsure, set confidence to a low number and explain what is missing.
- **NEVER** return an empty or null reasoning. Always explain your thought process.
- **ALWAYS** provide coordinates if you have a strong guess (even if approximate) — for a generic guess, use the nearest city center coordinates rather than leaving both null.
- For images too generic to localize (blank wall, close-up object, sky only), say so plainly in `reasoning` and `negative_evidence` rather than inventing a specific-sounding but baseless answer.

Now analyze the image and return ONLY the JSON.
PROMPT;
    }

    /**
     * Format EXIF for prompt
     */
    private function formatExifForPrompt($metadata)
    {
        if (empty($metadata)) {
            return 'No EXIF data available.';
        }

        $lines = [];
        if (isset($metadata['gps']['latitude'], $metadata['gps']['longitude'])) {
            $lines[] = "GPS Coordinates (from camera): {$metadata['gps']['latitude']}, {$metadata['gps']['longitude']}";
        }
        if (isset($metadata['camera']['make'])) {
            $model = $metadata['camera']['model'] ?? '';
            $lines[] = "Camera: {$metadata['camera']['make']} {$model}";
        }
        if (isset($metadata['datetime'])) {
            $lines[] = "DateTime: {$metadata['datetime']}";
        }
        if (isset($metadata['settings']['iso'])) {
            $lines[] = "ISO: {$metadata['settings']['iso']}";
        }

        return empty($lines) ? 'No EXIF data available.' : implode("\n", $lines);
    }

}
