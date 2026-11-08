<?php

namespace App\Http\Middleware;

use App\Support\Api\V4Response;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class V4ResponseEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (ValidationException $exception) {
            $response = V4Response::error(
                'VALIDATION_FAILED',
                'The submitted data is invalid.',
                422,
                $exception->errors()
            );
        } catch (ModelNotFoundException) {
            $response = V4Response::error('RESOURCE_NOT_FOUND', 'Resource not found.', 404);
        } catch (AuthorizationException $exception) {
            $response = V4Response::error(
                'FORBIDDEN',
                $exception->getMessage() ?: 'This action is not allowed.',
                403
            );
        } catch (Throwable $exception) {
            Log::error('Unhandled v4 API exception', [
                'request_id' => $request->attributes->get('request_id'),
                'path' => $request->path(),
                'exception' => $exception,
            ]);

            $response = V4Response::error(
                'INTERNAL_ERROR',
                'An unexpected error occurred.',
                500
            );
        }

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        return $this->normalize($request, $response);
    }

    private function normalize(Request $request, JsonResponse $response): JsonResponse
    {
        $payload = $response->getData(true);
        $requestId = (string) $request->attributes->get('request_id', '');
        $client = (array) $request->attributes->get('client_context', []);
        $meta = [
            'request_id' => $requestId,
            'api_version' => '4',
            'client' => $client,
        ];

        if (
            is_array($payload)
            && array_key_exists('success', $payload)
            && array_key_exists('data', $payload)
            && array_key_exists('message', $payload)
            && array_key_exists('meta', $payload)
            && array_key_exists('error', $payload)
        ) {
            $payload['meta'] = array_merge($meta, (array) ($payload['meta'] ?? []));
            $response->setData($payload);

            return $response;
        }

        $status = $response->getStatusCode();
        $legacyStatus = is_array($payload)
            ? strtolower((string) ($payload['status'] ?? ''))
            : '';
        $successful = $status >= 200
            && $status < 400
            && ! in_array($legacyStatus, ['error', 'failed', 'fail'], true);

        if ($successful) {
            $message = is_array($payload) ? ($payload['message'] ?? null) : null;
            $data = $this->successData($payload);
            $legacyMeta = is_array($payload) ? (array) ($payload['meta'] ?? []) : [];
            $normalized = V4Response::success(
                $data,
                is_string($message) ? $message : null,
                array_merge($meta, $legacyMeta),
                $status
            );
        } else {
            $errorStatus = $status >= 400 ? $status : 400;
            $message = is_array($payload)
                ? (string) ($payload['message'] ?? 'Request failed.')
                : 'Request failed.';
            $details = is_array($payload)
                ? ($payload['errors'] ?? $payload['error'] ?? null)
                : null;
            $code = is_array($payload) && is_string($payload['code'] ?? null)
                ? $payload['code']
                : $this->errorCode($errorStatus);
            $normalized = V4Response::error($code, $message, $errorStatus, $details, $meta);
        }

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $normalized->headers->set($name, $values);
        }

        return $normalized;
    }

    private function successData(mixed $payload): mixed
    {
        // Controllers are moved behind the v4 boundary incrementally. Keep a
        // bridged controller's complete payload inside `data` so its client
        // adapter can migrate without losing fields. Native v4 controllers
        // return V4Response directly and therefore bypass this bridge.
        return $payload;
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'RESOURCE_NOT_FOUND',
            409 => 'CONFLICT',
            422 => 'VALIDATION_FAILED',
            429 => 'RATE_LIMITED',
            default => $status >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_FAILED',
        };
    }
}
