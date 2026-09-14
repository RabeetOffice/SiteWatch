<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Bootstraps a JSON API endpoint: method check, authentication, CSRF for state-changing requests.
 *
 *   require __DIR__ . '/../../bootstrap.php';
 *   Api::boot(['POST']);
 *   ... Response::success(...)
 */
final class Api
{
    /**
     * @param array<int, string> $methods Allowed HTTP methods.
     * @param bool $auth Require an authenticated administrator.
     * @param bool|null $csrf Require a CSRF token. Defaults to true for every non-GET method.
     */
    public static function boot(array $methods = ['GET'], bool $auth = true, ?bool $csrf = null): void
    {
        ErrorHandler::setApiMode(true);

        $method = Request::method();
        $methods = array_map('strtoupper', $methods);
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        if (!in_array($method, $methods, true)) {
            if (!headers_sent()) {
                header('Allow: ' . implode(', ', $methods));
            }
            Response::error('Method not allowed.', [], 405);
        }

        if ($auth && !App::auth()->check()) {
            Response::error('Authentication required. Please sign in again.', [], 401);
        }

        $csrf ??= ($method !== 'GET');
        if ($csrf && !App::csrf()->validateRequest()) {
            // 403 rather than the non-standard 419: Apache rewrites unknown status codes to 500.
            Response::error('Your session has expired or the security token is invalid. Please reload the page and try again.', [], 403);
        }
    }

    /**
     * Pagination parameters from the query string, clamped to sane values.
     *
     * @return array{page: int, per_page: int, offset: int}
     */
    public static function pagination(int $defaultPerPage = 25, int $maxPerPage = 100): array
    {
        $page = max(1, Request::int('page', 1));
        $perPage = Request::int('per_page', $defaultPerPage);
        $perPage = max(5, min($maxPerPage, $perPage));
        return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
    }
}
