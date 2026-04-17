<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PageAnalysisController extends Controller
{
    public function analyze(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url', 'starts_with:http://,https://'],
        ]);

        $serviceBaseUrl = rtrim((string) config('services.playwright_service.url', 'http://localhost:3001'), '/');
        $serviceUrl = $serviceBaseUrl . '/analyze';

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->asJson()
                ->post($serviceUrl, [
                    'url' => $validated['url'],
                ]);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'Page analysis service is unavailable.',
                'error' => $e->getMessage(),
            ], 502);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to contact page analysis service.',
                'error' => $e->getMessage(),
            ], 502);
        }

        $payload = $response->json();

        if (is_array($payload)) {
            return response()->json($payload, $response->status());
        }

        return response()->json([
            'message' => 'Page analysis failed.',
            'status' => $response->status(),
            'error' => $response->body(),
        ], $response->status());
    }
}
