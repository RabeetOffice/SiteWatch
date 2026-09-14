<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\App;
use App\Core\Response;

Response::redirect(App::auth()->check() ? base_url('admin/dashboard.php') : base_url('login.php'));
