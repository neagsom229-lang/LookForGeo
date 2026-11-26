<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Static checks for the honest evidence heading. The behavioural tests for
 * the pure helper live in tests/js/confidence-heading.test.cjs.
 */
class ConfidenceHeadingUiTest extends TestCase
{
    public function test_heading_helper_defines_a_threshold_and_both_labels(): void
    {
        $js = (string) file_get_contents(base_path('public/js/confidence-heading.js'));

        $this->assertStringContainsString('CONFIRMED_HEADING_MIN = 65', $js);
        $this->assertStringContainsString("CONFIRMED_LABEL = 'CONFIRMED LOCATION EVIDENCE'", $js);
        $this->assertStringContainsString("POSSIBLE_LABEL = 'Possible location (low evidence)'", $js);
        $this->assertStringContainsString('score >= CONFIRMED_HEADING_MIN', $js);
    }

    public function test_analysis_uses_the_helper_and_never_hardcodes_confirmed(): void
    {
        $js = (string) file_get_contents(base_path('public/js/analysis.js'));

        $this->assertStringContainsString('window.ConfidenceHeading.evidenceHeading', $js);
        $this->assertStringContainsString('${headingText}', $js);
        $this->assertStringContainsString("'Possible location (low evidence)'", $js);

        // The old unconditional green heading must be gone.
        $this->assertStringNotContainsString(
            '<strong style="color:var(--success);">CONFIRMED LOCATION EVIDENCE</strong>',
            $js
        );
    }

    public function test_analysis_page_loads_the_helper_before_analysis_js(): void
    {
        $blade = (string) file_get_contents(resource_path('views/analysis.blade.php'));

        $helperAt = strpos($blade, "asset('js/confidence-heading.js')");
        $analysisAt = strpos($blade, "asset('js/analysis.js')");

        $this->assertNotFalse($helperAt, 'confidence-heading.js must be included');
        $this->assertNotFalse($analysisAt, 'analysis.js must be included');
        $this->assertLessThan($analysisAt, $helperAt, 'helper must load before analysis.js');
    }

    public function test_analysis_surfaces_the_floor_rule_instead_of_hiding_it(): void
    {
        $js = (string) file_get_contents(base_path('public/js/analysis.js'));

        $this->assertStringContainsString('data.confidence_floor_applied === true', $js);
        $this->assertStringContainsString('Low evidence (score floored)', $js);
    }
}
