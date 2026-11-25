<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The UI must not animate a pin to fallback coordinates. */
class CoordinatesMissingUiTest extends TestCase
{
    public function test_ui_shows_a_message_instead_of_a_pin(): void
    {
        $js = (string) file_get_contents(base_path('public/js/analysis.js'));

        $this->assertStringContainsString('coordinates_missing', $js);
        $this->assertStringContainsString('Location could not be pinned - coordinates missing', $js);
        // Strict gate before any map / Street View URL is built.
        $this->assertStringContainsString('Number.isFinite', $js);
        $this->assertStringContainsString('if (!isValidCoord(lat, lng))', $js);
        $this->assertStringContainsString('no URL built', $js);
        // No invented Eiffel Tower fallback for a missing coordinate.
        $this->assertStringNotContainsString('48.8584', $js);
        $this->assertStringNotContainsString('2.2945', $js);
        $this->assertMatchesRegularExpression('/data\.coordinates_missing \|\| !hasCoords/', $js);
    }
}
