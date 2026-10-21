<?php

namespace App\Http\Controllers\Api\PublicApi;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Serves the public API contract with a content-hash ETag so integrators can cache it. */
class OpenApiController
{
    public function __invoke(Request $request): Response
    {
        $spec = file_get_contents(resource_path('openapi/public-v1.json'));

        $response = response($spec, 200, ['Content-Type' => 'application/json'])
            ->setEtag(md5($spec))
            ->setPublic()
            ->setMaxAge(300);

        $response->isNotModified($request);

        return $response;
    }
}
