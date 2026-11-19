<?php
/*
 * DROTIX MOBILE APP connection (admin panel -> "Mobile App" section)
 * ------------------------------------------------------------------
 * This file is ONLY for reading/managing the mobile game data (Supabase schema `drotix`).
 * The website + web admin settings stay in includes/config.php (separate file).
 *
 * 1) On the server copy this file to  includes/app_config.php
 * 2) Fill in the 3 values below and save.
 * 3) Never commit or share app_config.php (it is in .gitignore).
 */
return array(

    // Supabase project URL of the app (same project as the website)
    'supabase_url' => 'https://hbjaisdfhnehspwscifh.supabase.co',

    // Supabase -> Project Settings -> API Keys -> "Publishable key" (starts with sb_publishable_)
    // (old projects: the "anon public" key). NEVER put the secret / service_role key here.
    'anon_key' => 'PASTE_PUBLISHABLE_KEY_HERE',

    // A Supabase account of the app that is an app admin (drotix.profiles.is_admin = true).
    // The server signs in with it automatically, so the admin panel needs only ONE login.
    'email'    => 'PASTE_ADMIN_EMAIL_HERE',
    'password' => 'PASTE_ADMIN_PASSWORD_HERE',
);
