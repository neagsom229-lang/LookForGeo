<?php

namespace Tests\Unit;

use App\Services\Gemini\GeminiResultValidator;
use PHPUnit\Framework\TestCase;

class GeminiResultValidatorTest extends TestCase
{
    private GeminiResultValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new GeminiResultValidator;
    }

    public function test_valid_result_passes(): void
    {
        $result = [
            'landmark_name' => 'Eiffel Tower',
            'city' => 'Paris',
            'country' => 'France',
            'region' => 'Ile-de-France',
            'latitude' => 48.8584,
            'longitude' => 2.2945,
            'confidence' => 90,
            'reasoning' => 'Distinctive iron lattice tower',
            'description' => 'The Eiffel Tower',
            'vantage_point' => 'ground',
            'visual_clues' => ['tower'],
            'tags' => ['landmark'],
            'candidates' => [
                [
                    'location' => 'Eiffel Tower',
                    'city' => 'Paris',
                    'country' => 'France',
                    'latitude' => 48.8584,
                    'longitude' => 2.2945,
                    'probability' => 92,
                    'reasoning' => 'Distinctive structure',
                ],
            ],
        ];

        $this->assertSame([], $this->validator->validate($result));
        $this->assertTrue($this->validator->isValid($result));
    }

    public function test_missing_required_fields_fail(): void
    {
        $result = ['landmark_name' => 'Eiffel Tower'];

        $errors = $this->validator->validate($result);

        $this->assertContains('city', $errors);
        $this->assertContains('country', $errors);
        $this->assertContains('reasoning', $errors);
        $this->assertContains('description', $errors);
        $this->assertContains('confidence(type)', $errors);
    }

    public function test_empty_required_strings_fail(): void
    {
        $result = [
            'landmark_name' => '   ',
            'city' => 'Paris',
            'country' => 'France',
            'reasoning' => 'x',
            'description' => 'x',
            'confidence' => 50,
        ];

        $this->assertContains('landmark_name', $this->validator->validate($result));
    }

    public function test_out_of_range_confidence_fails(): void
    {
        $result = [
            'landmark_name' => 'Eiffel Tower',
            'city' => 'Paris',
            'country' => 'France',
            'reasoning' => 'x',
            'description' => 'x',
            'confidence' => 120,
        ];

        $this->assertContains('confidence(range)', $this->validator->validate($result));
    }

    public function test_out_of_range_coordinates_fail(): void
    {
        $result = [
            'landmark_name' => 'Eiffel Tower',
            'city' => 'Paris',
            'country' => 'France',
            'reasoning' => 'x',
            'description' => 'x',
            'confidence' => 50,
            'latitude' => 95.0,
            'longitude' => 181.0,
        ];

        $errors = $this->validator->validate($result);

        $this->assertContains('latitude(range)', $errors);
        $this->assertContains('longitude(range)', $errors);
    }

    public function test_wrong_vantage_point_fails(): void
    {
        $result = [
            'landmark_name' => 'Eiffel Tower',
            'city' => 'Paris',
            'country' => 'France',
            'reasoning' => 'x',
            'description' => 'x',
            'confidence' => 50,
            'vantage_point' => 'sideways',
        ];

        $this->assertContains('vantage_point(enum)', $this->validator->validate($result));
    }
}
