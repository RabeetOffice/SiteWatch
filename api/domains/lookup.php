<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Domains\DomainName;
use App\Services\ServiceFactory;

/*
 * Look up any domain (registration, age and hosting) without storing the result.
 */
Api::boot(['POST'], permission: 'domains.lookup');

$host = DomainName::fromInput(Request::string('query'));
if ($host === null) {
    Response::error('Enter a domain name such as example.com.', ['query' => 'Enter a domain name or website address, for example example.com.'], 422);
}

// Each lookup queries registries and DNS on the user's behalf, so keep a modest per-session limit.
$session = App::session();
$recent = array_values(array_filter((array) $session->get('_domain_lookups', []), static fn ($t): bool => is_int($t) && $t > time() - 600));
if (count($recent) >= 30) {
    Response::error('Too many lookups in the last 10 minutes. Please wait a few minutes and try again.', [], 429);
}
$recent[] = time();
$session->set('_domain_lookups', $recent);
$session->release();
set_time_limit(120);

Response::success('', ['domain' => ServiceFactory::domainService()->lookup($host)]);
