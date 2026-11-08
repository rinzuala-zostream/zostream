<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PythonController extends Controller
{
    public function runFuzzyMatch(Request $request)
    {
        $title = $request->input('title');

        // Call FastAPI endpoint on localhost
        $response = Http::get('http://127.0.0.1:8001/search', [
            'title' => $title,
        ]);

        if ($response->successful()) {
            return response()->json($response->json());
        } else {
            return response()->json(['error' => 'Python API error'], 500);
        }
    }
}
