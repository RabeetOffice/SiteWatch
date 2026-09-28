<?php

declare(strict_types=1);

/** Moved in 2.0: now a tab of the Settings page. */

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/navigation.php';

sw_redirect('admin/settings.php', ['tab' => 'notifications']);
