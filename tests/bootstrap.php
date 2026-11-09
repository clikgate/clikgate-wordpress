<?php
/**
 * Unit-test bootstrap: a small in-memory WordPress (options, transients, hooks, HTTP, cron) so the plugin's
 * classes run offline without a WordPress install. Each test calls WpStub::reset().
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
// The plugin's own version (its header), so a release bump needs no test change
preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents(dirname(__DIR__) . '/clikgate.php'), $clikgate_version);
define('CLIKGATE_VERSION', $clikgate_version[1]);
define('CLIKGATE_FILE', dirname(__DIR__) . '/clikgate.php');
define('CLIKGATE_DIR', dirname(__DIR__));

require __DIR__ . '/stubs/wordpress.php';
require __DIR__ . '/stubs/woocommerce.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/includes/autoload.php';
