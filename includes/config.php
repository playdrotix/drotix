<?php
/*
 * DROTIX Tic Tac Toe - configuration
 * 1) Copy this file to  includes/config.php  and fill in your values.
 * 2) Never share config.php or commit it. It holds your Supabase SERVICE key.
 *
 * Data is stored in Supabase (no MySQL). The service-role key is used ONLY on the server
 * (PHP). It must never appear in HTML or JavaScript.
 */
return array(

    // ---------- Supabase ----------
    'supabase_url' => 'https://hbjaisdfhnehspwscifh.supabase.co',     // Project Settings -> API -> Project URL
    'supabase_key' => 'sb_publishable_b3tuqFWj0HypYdEp2cgfxA_ZP7TfuWb',                 // Project Settings -> API -> service_role (secret)

    // Table names used by the website forms (create them with database/schema.sql)
    'tables' => array(
        'contact'   => 'contact_messages',
        'waitlist'  => 'waitlist_signups',
        'deletions' => 'deletion_requests',
        'audit'     => 'admin_audit_log',
        'visits'    => 'site_visits',
        'tournament'=> 'tournament_registrations',
        'presence'  => 'site_presence',
        'notify'    => 'site_notifications',
    ),

    // ---------- Site ----------
    'site_name'     => 'Drotix Tic Tac Toe',
    'site_url'      => 'https://playdrotix.in',
    'support_email' => 'support@playdrotix.in',
    'timezone'      => 'Asia/Kolkata',
    'deletion_days' => 2,                                       // account deletion timeline shown and tracked
    'allowed_origins' => array('https://playdrotix.in', 'https://www.playdrotix.in'),     // forms only accepted from these sites ([] = do not check)
    'trust_proxy_header' => '',                                 // e.g. 'HTTP_CF_CONNECTING_IP' if you use Cloudflare

    // ---------- Admin panel (/admin/) ----------
    // username => password hash. Create a hash with admin/make-hash (see README) or:
    //   php -r "echo password_hash('YourStrongPassword', PASSWORD_DEFAULT);"
    'admins' => array(
        'admin' => '$2y$10$uQf4ew9v5g2ScGLW/K7fVew5uRKnz8RDECuvQmO3XStspPUO19CsO',
    ),
    'session_name'         => 'drotix_admin',
    'session_idle_minutes' => 30,
    'enable_hash_tool'     => false,   // set true ONLY while you create a hash, then set back to false

    
    // ---------- Email ----------
    // 'smtp'  = send through your SMTP provider (recommended, reliable)
    // 'mail'  = PHP mail() (often lands in spam / blocked on shared hosting)
    'mail_driver'        => 'smtp',
    'notify_admin_email' => 'support@playdrotix.in',   // '' = admin notifications off
 
    'smtp' => array(
        'from_email' => 'noreply@playdrotix.in',   // Sender email address
        'from_name'  => 'Drotix Tic Tac Toe',       // Sender name shown in inbox
        'reply_to'   => 'support@playdrotix.in',    // optional, '' = none
 
        'host'       => 'mail.playdrotix.in',         // SMTP host name or IP
        'port'       => 587,                        // 465 (SSL) or 587 (TLS). Avoid 25.
        'encryption' => 'tls',                      // 'ssl' for port 465, 'tls' for port 587, '' = none
        'username'   => 'noreply@playdrotix.in',
        // Better: set env variable SMTP_PASSWORD on the server.
        'password'   => getenv('SMTP_PASSWORD') ?: 'Pravin@7844',  // SMTP password (never commit the password in config.php)
        'timeout'    => 15,                         // seconds
    ),
 
    // Kept for backward compatibility with the mail() driver
    'mail_from' => 'noreply@playdrotix.in',
 
    // ---------- Email notifications (optional, uses PHP mail()) ----------
    'notify_admin_email' => 'support@playdrotix.in',          // '' = off
    'mail_from'          => 'noreply@playdrotix.in',

    // ---------- Data Explorer (admin -> Data Explorer) ----------
    // Read any Supabase table: add one line per table. 'url' and 'key' can be null to use the
    // default project above, or you can point a line at ANOTHER Supabase project (your app's data).
    'explorer' => array(
        array('label' => 'Contact messages',   'url' => null, 'key' => null, 'table' => 'contact_messages'),
        array('label' => 'Deletion requests',  'url' => null, 'key' => null, 'table' => 'deletion_requests'),
        array('label' => 'Waitlist',           'url' => null, 'key' => null, 'table' => 'waitlist_signups'),
        array('label' => 'Site visits',        'url' => null, 'key' => null, 'table' => 'site_visits'),
        array('label' => 'Tournament registrations', 'url' => null, 'key' => null, 'table' => 'tournament_registrations'),
        // array('label' => 'App players', 'url' => 'https://YOUR-APP-PROJECT.supabase.co', 'key' => 'ITS-SERVICE-KEY', 'table' => 'profiles'),
    ),

    // ---------- Optional: delete the player from your app's table when a deletion request is completed ----------
    // Keep 'enabled' => false until you are sure about the table and column names.
    'app_delete' => array(
        'enabled'         => false,
        'url'             => null,        // null = default project above
        'key'             => null,
        'table'           => 'profiles',  // table that holds your players
        'email_column'    => 'email',     // column used to find the player
    ),
);
