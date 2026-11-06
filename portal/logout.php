<?php
/**
 * portal/logout.php - Redirect / wrapper to canonical community logout
 *
 * Ensures /portal/logout.php never 404s if accessed directly or via cached links.
 */
require_once __DIR__ . '/../logout.php';
