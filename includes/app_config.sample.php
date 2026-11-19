<?php
/*
 * DROTIX MOBILE APP connection (admin panel -> "Mobile App" section)
 * ------------------------------------------------------------------
 * Separate from the website settings (includes/config.php).
 * No login is needed inside the Mobile App section: whoever is logged in to /admin/ can see and manage
 * all app data. The server uses the keys below; they never reach the browser.
 *
 * 1) On the server copy this file to  includes/app_config.php
 * 2) Fill in the values and save. Never commit or share app_config.php (it is in .gitignore).
 *    If you leave service_key empty, the supabase_key from includes/config.php is used.
 */
return array(

    // Supabase project URL of the app
    'supabase_url' => 'https://hbjaisdfhnehspwscifh.supabase.co',

    // Supabase -> Project Settings -> API Keys -> "Secret key" (starts with sb_secret_)   (old projects: service_role key)
    // Full access to the app data, used ONLY on the server.
    'service_key' => 'PASTE_SECRET_KEY_HERE',

    // Supabase -> Project Settings -> API Keys -> "Publishable key" (starts with sb_publishable_). Kept for reference.
    'anon_key' => 'PASTE_PUBLISHABLE_KEY_HERE',
);
