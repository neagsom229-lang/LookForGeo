<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Static checks for the confidence colour bands. The UI bands are computed
 * in analysis.js and styled in analysis.css; green must never appear below
 * 50 % or on a result flagged low_confidence.
 */
class ConfidenceBandUiTest extends TestCase
{
    public function test_confidence_pill_uses_explicit_band_classes(): void
    {
        $js = (string) file_get_contents(base_path('public/js/analysis.js'));

        $this->assertStringContainsString('band-${tier}', $js);
        $this->assertStringContainsString("confidence >= 80 ? 'high' : confidence >= 50 ? 'medium' : 'low'", $js);
        $this->assertStringContainsString("data.low_confidence ? 'low'", $js);
        $this->assertStringNotContainsString('dot.style.background', $js);
    }

    public function test_css_never_uses_success_colour_below_50(): void
    {
        $css = (string) file_get_contents(base_path('public/css/analysis.css'));

        $this->assertStringContainsString('.pill-confidence.band-low .dot { background: var(--danger); }', $css);
        $this->assertStringContainsString('.pill-confidence.band-medium .dot { background: var(--warning); }', $css);
        $this->assertStringContainsString('.pill-confidence.band-high .dot { background: var(--success); }', $css);

        $lowBlock = $this->blockFor($css, '.pill-confidence.band-low');
        $midBlock = $this->blockFor($css, '.pill-confidence.band-medium');
        $this->assertStringNotContainsString('--success', $lowBlock);
        $this->assertStringNotContainsString('--success', $midBlock);
    }

    private function blockFor(string $css, string $selector): string
    {
        $start = strpos($css, $selector);
        $this->assertNotFalse($start);
        $end = strpos($css, '}', $start);

        return substr($css, $start, $end - $start);
    }
}
