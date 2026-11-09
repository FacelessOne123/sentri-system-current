<?php
/**
 * tools/gps-bridge/router.php - front controller for the local GPS bridge
 * ============================================================================
 * `php -S 127.0.0.1:8787 tools/gps-bridge/router.php` routes every request
 * through bridge.php. The built-in server hands the router the raw request,
 * so this file sets the request path/method and includes the real handler.
 *
 * Usage:
 *   php -S 127.0.0.1:8787 tools/gps-bridge/router.php --server=<url>
 *
 * (Use tools/gps-bridge/start.sh, which wires this up for you.)
 */

declare(strict_types=1);

// The built-in server passes CLI arguments to the router script, so the
// bridge's own option parsing still works.
require __DIR__ . '/bridge.php';
