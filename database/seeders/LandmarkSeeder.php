<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Landmark;

class LandmarkSeeder extends Seeder
{
    public function run()
    {
        $landmarks = [
            // Cambodia
            ['name' => 'Angkor Wat', 'city' => 'Siem Reap', 'country' => 'Cambodia', 'region' => 'Siem Reap', 'latitude' => 13.4125, 'longitude' => 103.8670, 'type' => 'historical', 'description' => 'The largest religious monument in the world, built in the 12th century.'],
            ['name' => 'Bayon Temple', 'city' => 'Siem Reap', 'country' => 'Cambodia', 'region' => 'Siem Reap', 'latitude' => 13.4410, 'longitude' => 103.8591, 'type' => 'historical'],
            ['name' => 'Banteay Srei', 'city' => 'Siem Reap', 'country' => 'Cambodia', 'region' => 'Siem Reap', 'latitude' => 13.5989, 'longitude' => 103.9628, 'type' => 'historical'],
            ['name' => 'Phnom Penh', 'city' => 'Phnom Penh', 'country' => 'Cambodia', 'region' => 'Phnom Penh', 'latitude' => 11.5564, 'longitude' => 104.9282, 'type' => 'city'],
            ['name' => 'Royal Palace Phnom Penh', 'city' => 'Phnom Penh', 'country' => 'Cambodia', 'region' => 'Phnom Penh', 'latitude' => 11.5639, 'longitude' => 104.9314, 'type' => 'landmark'],
            ['name' => 'Tuol Sleng Genocide Museum', 'city' => 'Phnom Penh', 'country' => 'Cambodia', 'region' => 'Phnom Penh', 'latitude' => 11.5445, 'longitude' => 104.9182, 'type' => 'historical'],
            ['name' => 'Indigenous People Statue', 'city' => 'Phnom Penh', 'country' => 'Cambodia', 'region' => 'Phnom Penh', 'latitude' => 11.5588, 'longitude' => 104.9167, 'type' => 'landmark'],
            
            // USA
            ['name' => 'Statue of Liberty', 'city' => 'New York', 'country' => 'United States', 'region' => 'New York', 'latitude' => 40.6892, 'longitude' => -74.0445, 'type' => 'landmark'],
            ['name' => 'Golden Gate Bridge', 'city' => 'San Francisco', 'country' => 'United States', 'region' => 'California', 'latitude' => 37.8199, 'longitude' => -122.4783, 'type' => 'landmark'],
            ['name' => 'Empire State Building', 'city' => 'New York', 'country' => 'United States', 'region' => 'New York', 'latitude' => 40.7488, 'longitude' => -73.9857, 'type' => 'landmark'],
            ['name' => 'Central Park', 'city' => 'New York', 'country' => 'United States', 'region' => 'New York', 'latitude' => 40.7812, 'longitude' => -73.9665, 'type' => 'landmark'],
            
            // UK
            ['name' => 'Big Ben', 'city' => 'London', 'country' => 'United Kingdom', 'region' => 'England', 'latitude' => 51.5007, 'longitude' => -0.1246, 'type' => 'landmark'],
            ['name' => 'London Eye', 'city' => 'London', 'country' => 'United Kingdom', 'region' => 'England', 'latitude' => 51.5033, 'longitude' => -0.1195, 'type' => 'landmark'],
            ['name' => 'Buckingham Palace', 'city' => 'London', 'country' => 'United Kingdom', 'region' => 'England', 'latitude' => 51.5014, 'longitude' => -0.1419, 'type' => 'landmark'],
            
            // France
            ['name' => 'Eiffel Tower', 'city' => 'Paris', 'country' => 'France', 'region' => 'Île-de-France', 'latitude' => 48.8584, 'longitude' => 2.2945, 'type' => 'landmark'],
            ['name' => 'Louvre Museum', 'city' => 'Paris', 'country' => 'France', 'region' => 'Île-de-France', 'latitude' => 48.8606, 'longitude' => 2.3376, 'type' => 'landmark'],
            ['name' => 'Notre Dame', 'city' => 'Paris', 'country' => 'France', 'region' => 'Île-de-France', 'latitude' => 48.8529, 'longitude' => 2.3499, 'type' => 'landmark'],
            
            // Italy
            ['name' => 'Colosseum', 'city' => 'Rome', 'country' => 'Italy', 'region' => 'Lazio', 'latitude' => 41.8902, 'longitude' => 12.4922, 'type' => 'historical'],
            ['name' => 'Leaning Tower of Pisa', 'city' => 'Pisa', 'country' => 'Italy', 'region' => 'Tuscany', 'latitude' => 43.7229, 'longitude' => 10.3964, 'type' => 'landmark'],
            
            // Japan
            ['name' => 'Tokyo Tower', 'city' => 'Tokyo', 'country' => 'Japan', 'region' => 'Tokyo', 'latitude' => 35.6586, 'longitude' => 139.7454, 'type' => 'landmark'],
            ['name' => 'Mount Fuji', 'city' => 'Fujinomiya', 'country' => 'Japan', 'region' => 'Shizuoka', 'latitude' => 35.3606, 'longitude' => 138.7274, 'type' => 'natural'],
            
            // India
            ['name' => 'Taj Mahal', 'city' => 'Agra', 'country' => 'India', 'region' => 'Uttar Pradesh', 'latitude' => 27.1751, 'longitude' => 78.0421, 'type' => 'historical'],
            ['name' => 'Gateway of India', 'city' => 'Mumbai', 'country' => 'India', 'region' => 'Maharashtra', 'latitude' => 18.9219, 'longitude' => 72.8347, 'type' => 'landmark'],
            
            // China
            ['name' => 'Great Wall of China', 'city' => 'Beijing', 'country' => 'China', 'region' => 'Beijing', 'latitude' => 40.4319, 'longitude' => 116.5704, 'type' => 'historical'],
            ['name' => 'Forbidden City', 'city' => 'Beijing', 'country' => 'China', 'region' => 'Beijing', 'latitude' => 39.9163, 'longitude' => 116.3972, 'type' => 'landmark'],
            
            // Australia
            ['name' => 'Sydney Opera House', 'city' => 'Sydney', 'country' => 'Australia', 'region' => 'New South Wales', 'latitude' => -33.8568, 'longitude' => 151.2153, 'type' => 'landmark'],
            
            // Egypt
            ['name' => 'Pyramids of Giza', 'city' => 'Giza', 'country' => 'Egypt', 'region' => 'Giza', 'latitude' => 29.9792, 'longitude' => 31.1342, 'type' => 'historical'],
            
            // Brazil
            ['name' => 'Christ the Redeemer', 'city' => 'Rio de Janeiro', 'country' => 'Brazil', 'region' => 'Rio de Janeiro', 'latitude' => -22.9519, 'longitude' => -43.2105, 'type' => 'landmark'],
            
            // Thailand
            ['name' => 'Grand Palace Bangkok', 'city' => 'Bangkok', 'country' => 'Thailand', 'region' => 'Bangkok', 'latitude' => 13.7500, 'longitude' => 100.4914, 'type' => 'landmark'],
        ];

        foreach ($landmarks as $landmark) {
            Landmark::create($landmark);
        }
    }
}