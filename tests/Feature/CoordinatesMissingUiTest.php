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
        $this->assertStringContainsString('!result.coordinates_missing &&', $js);
        $this->assertMatchesRegularExpression('/if \(pinnable\) \{/', $js);
        $this->assertMatchesRegularExpression('/data\.coordinates_missing \|\| !hasCoords/', $js);
    }
}
