<?php
/**
 * reset_password.php - COMMUNITY-ONLY password reset page.
 *
 * Officials and administrators NEVER reset passwords on community pages:
 * their tokens route to their own realm pages (config/auth.php:
 * reset_url_for_role). The whole page is rendered by the shared engine
 * (core/password_reset_engine.php); only the realm + branding live here.
 *
 * $SENTRI_RESET keys:
 *   realm         the realm session whose cookie/page owns this flow
 *   allowed_roles roles whose reset tokens this page accepts (rest of the
 *                 roles are auto-forwarded to their own realm's page)
 *   brand         page wording/branding
 */
$SENTRI_RESET = [
  'realm'         => 'community',
  'allowed_roles' => ['community', 'user'],
  'brand'         => [
    'asset_prefix' => 'assets/',
    'title'        => 'Reset Password',
    'brand_href'   => 'index.php',
    'brand_icon'   => 'fa-shield-halved',
    'brand_name'   => 'SenTri',
    'icon'         => 'fa-lock-open',
    'heading'      => 'Set New Password',
    'btn'          => 'Reset Password',
    'expired_cta'  => 'Request New Reset Link',
    'links'        => [
      ['login.php', 'fa-arrow-left', 'Back to Community Sign In'],
      ['gov/login.php', 'fa-landmark', 'Government Portal Sign In'],
      ['admin.php', 'fa-user-shield', 'Administrator Sign In'],
    ],
  ],
];
require_once __DIR__ . '/core/password_reset_engine.php';
