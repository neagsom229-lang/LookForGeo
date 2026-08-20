<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TestController extends Controller
{
    public function testAll()
    {
        return response()->json([
            'status' => 'success',
            'message' => 'TraceGeo API is working!',
            'timestamp' => now()->toDateTimeString(),
            'server_time' => date('Y-m-d H:i:s')
        ]);
    }
}