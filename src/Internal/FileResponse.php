<?php

declare(strict_types=1);

namespace Rapira\Symfony\Internal;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prepares a file for a transport that sends its bytes instead of interpreting reverse-proxy headers.
 *
 * @internal
 */
final class FileResponse
{
    public static function prepare(BinaryFileResponse $response, Request $request): void
    {
        // HttpKernel may already have prepared this response with trusted X-Sendfile enabled.
        // Removing only the request trigger would leave its generated path header on the response.
        $type = $request->headers->get('X-Sendfile-Type');
        if ($type !== null && $type !== '') {
            // Older Symfony releases also accept arbitrary sendfile header names.
            $file = $response->getFile();
            $path = $file->getRealPath();
            if ($response->headers->get($type) === ($path === false ? $file->getPathname() : $path)) {
                $response->headers->remove($type);
            }
        }
        foreach (['X-Sendfile', 'X-Accel-Redirect', 'X-LIGHTTPD-send-file'] as $header) {
            $response->headers->remove($header);
        }

        $request = clone $request;
        $request->headers->remove('X-Sendfile-Type');
        // Rebuild the body length and range state suppressed by the earlier prepare().
        $response->prepare($request);
    }
}
