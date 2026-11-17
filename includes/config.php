<?php
/*
 * DROTIX Tournament Gaming Platform - configuration
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
    'supabase_anon_key' => 'sb_publishable_b3tuqFWj0HypYdEp2cgfxA_ZP7TfuWb',                    // Project Settings -> API -> anon/public key (for client-side realtime)

    // Table names used by the website forms (create them with database/schema.sql and schema_v2.sql)
    'tables' => array(
        'contact'           => 'contact_messages',
        'waitlist'          => 'waitlist_signups',
        'deletions'         => 'deletion_requests',
        'audit'             => 'admin_audit_log',
        'visits'            => 'site_visits',
        'tournament'        => 'tournament_registrations',
        'presence'          => 'site_presence',
        'notify'            => 'site_notifications',
        // New tables for tournament platform
        'users'             => 'users',
        'user_sessions'     => 'user_sessions',
        'fb_oauth_states'   => 'facebook_oauth_states',
        'tournaments'       => 'tournaments',
        'tourney_participants' => 'tournament_participants',
        'tourney_matches'   => 'tournament_matches',
        'tourney_chat'      => 'tournament_chat_messages',
        'private_rooms'     => 'private_rooms',
        'room_participants' => 'room_participants',
        'room_chat'         => 'room_chat_messages',
        'suspension_appeals' => 'suspension_appeals',
        'user_reports'      => 'user_reports',
        'report_messages'   => 'report_messages',
        'user_notifications' => 'user_notifications',
    ),

    // ---------- Site ----------
    'site_name'     => 'Drotix Tournament Gaming',
    'site_url'      => 'https://playdrotix.in',
    'support_email' => 'support@playdrotix.in',
    'timezone'      => 'Asia/Kolkata',
    'deletion_days' => 2,                                       // account deletion timeline shown and tracked
    'allowed_origins' => array('https://playdrotix.in', 'https://www.playdrotix.in'),     // forms only accepted from these sites ([] = do not check)
    'trust_proxy_header' => '',                                 // e.g. 'HTTP_CF_CONNECTING_IP' if you use Cloudflare

    // ---------- Authentication ----------
    'auth' => array(
        'session_lifetime_days' => 30,                          // remember me duration
        'session_idle_hours' => 24,                             // auto-logout after inactivity
        'password_min_length' => 8,
        'require_email_verification' => true,                   // set false for testing
        'verification_token_hours' => 24,
        'reset_token_hours' => 2,
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
    ),

    // ---------- Facebook OAuth ----------
    'facebook' => array(
        'app_id' => '1453266169543653',                         // Your Facebook App ID
        'app_secret' => 'f67f8c70f8ab6177d256984cfa60639e',             // Project Settings -> Facebook Login -> Settings -> App Secret
        'redirect_uri' => 'https://playdrotix.in/auth/facebook/callback/',  // Must match FB App settings
        'scopes' => array('email', 'public_profile'),
        'graph_version' => 'v18.0',
    ),

    // ---------- Tournament Settings ----------
    'tournament' => array(
        'default_board_size' => 3,
        'default_win_condition' => 3,
        'default_turn_time' => 20,
        'auto_start_delay_minutes' => 5,                        // delay after match ready before auto-start
        'dispute_window_hours' => 1,                            // time to dispute result
        'walkover_minutes' => 5,                                // opponent no-show = walkover
        'max_concurrent_matches_per_user' => 1,
    ),

    // ---------- Private Rooms ----------
    'rooms' => array(
        'code_length' => 6,
        'code_charset' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',   // no confusing chars
        'default_ttl_hours' => 24,
        'max_rooms_per_user' => 5,
    ),

    // ---------- Admin panel (/admin/) ----------
    // username => password hash. Create a hash with admin/make-hash (see README) or:
    //   php -r "echo password_hash('YourStrongPassword', PASSWORD_DEFAULT);"
    'admins' => array(
        'admin' => '$2a$12$3s2ohE0WaoCRrBRtdmpHNOR46Etw8XAtfQf/GXUKL20GR8bBd.aK6',
    ),
    'session_name'         => 'drotix_admin',
    'session_idle_minutes' => 30,
    'enable_hash_tool'     => false,   // set true ONLY while you create a hash, then set back to false

    // ---------- Email notifications (optional, uses PHP mail()) ----------
    'notify_admin_email' => 'support@playdrotix.in',          // '' = off
    'mail_from'          => 'no-reply@playdrotix.in',

    // ---------- Data Explorer (admin -> Data Explorer) ----------
    // Read any Supabase table: add one line per table. 'url' and 'key' can be null to use the
    // default project above, or you can point a line at ANOTHER Supabase project (your app's data).
    'explorer' => array(
        array('label' => 'Contact messages',   'url' => null, 'key' => null, 'table' => 'contact_messages'),
        array('label' => 'Deletion requests',  'url' => null, 'key' => null, 'table' => 'deletion_requests'),
        array('label' => 'Waitlist',           'url' => null, 'key' => null, 'table' => 'waitlist_signups'),
        array('label' => 'Site visits',        'url' => null, 'key' => null, 'table' => 'site_visits'),
        array('label' => 'Tournament registrations', 'url' => null, 'key' => null, 'table' => 'tournament_registrations'),
        array('label' => 'Users',              'url' => null, 'key' => null, 'table' => 'users'),
        array('label' => 'Tournaments',        'url' => null, 'key' => null, 'table' => 'tournaments'),
        array('label' => 'Tournament matches', 'url' => null, 'key' => null, 'table' => 'tournament_matches'),
        array('label' => 'Private rooms',      'url' => null, 'key' => null, 'table' => 'private_rooms'),
        array('label' => 'Suspension appeals', 'url' => null, 'key' => null, 'table' => 'suspension_appeals'),
        array('label' => 'User reports',       'url' => null, 'key' => null, 'table' => 'user_reports'),
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

    // ---------- Push/Realtime (for future use) ----------
    'realtime' => array(
        'enabled' => true,
        'supabase_realtime_url' => 'wss://hbjaisdfhnehspwscifh.supabase.co/realtime/v1',  // Project Settings -> API -> Realtime URL
    ),
);