<?php
/**
 * admin/reset_password.php - ADMIN-ONLY password reset page.
 *
 * Administrator accounts set their password here; a token issued for the
 * admin role always routes to this page (config/auth.php: reset_url_for_role)
 * and is never processed on community or government pages. Community
 * members and officials are auto-forwarded to their own realm's page.
 *
 * $SENTRI_RESET keys: see reset_password.php / the engine header.
 */
$SENTRI_RESET = [
  'realm'         => 'admin',
  'allowed_roles' => ['admin'],
  'brand'         => [
    'asset_prefix' => '../assets/',
    'title'        => 'Administrator - Reset Password',
    'brand_href'   => '../admin.php',
    'brand_icon'   => 'fa-user-shield',
    'brand_name'   => 'SenTri Administration',
    'icon'         => 'fa-lock-open',
    'heading'      => 'Set New Password',
    'btn'          => 'Set My Password',
    'expired_cta'  => 'Request New Reset Link',
    'links'        => [
      ['../admin.php', 'fa-arrow-left', 'Back to Administrator Sign In'],
    ],
  ],
];
require_once __DIR__ . '/../core/password_reset_engine.php';
