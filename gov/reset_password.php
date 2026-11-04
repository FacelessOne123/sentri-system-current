<?php
/**
 * gov/reset_password.php - GOVERNMENT-ONLY password reset page.
 *
 * Barangay / LGU / first-responder officials set (and reset) their password
 * here, on a page of their own realm. Freshly provisioned accounts land here
 * straight from the gov portal's forced first sign-in password change
 * (core/login_handler.php -> reset_url_for_role). Community members and
 * administrators have their own realm pages - a token for their role is
 * auto-forwarded there by the engine, never processed here.
 *
 * $SENTRI_RESET keys: see reset_password.php / the engine header.
 */
$SENTRI_RESET = [
  'realm'         => 'gov',
  'allowed_roles' => ['barangay', 'lgu', 'first_responder'],
  'brand'         => [
    'asset_prefix' => '../assets/',
    'title'        => 'Government Portal - Reset Password',
    'brand_href'   => 'login.php',
    'brand_icon'   => 'fa-landmark',
    'brand_name'   => 'SenTri Government',
    'icon'         => 'fa-lock-open',
    'heading'      => 'Set New Password',
    'btn'          => 'Set My Password',
    'expired_cta'  => 'Request New Reset Link',
    'links'        => [
      ['login.php', 'fa-arrow-left', 'Back to Government Portal Sign In'],
    ],
  ],
];
require_once __DIR__ . '/../core/password_reset_engine.php';
