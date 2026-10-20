<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApiDocumentationController
{
    public function index(): BinaryFileResponse
    {
        return response()->file(public_path('vendor/swagger-ui/index.html'), ['Cache-Control' => 'public, max-age=300']);
    }

    public function specification(): BinaryFileResponse
    {
        return response()->file(public_path('openapi.json'), ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=300']);
    }
}
