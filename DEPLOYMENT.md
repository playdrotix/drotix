# Drotix Tournament Gaming Platform - Deployment Guide

## Overview
This is a comprehensive tournament gaming platform built on the existing Drotix Tic Tac Toe codebase. It extends the PHP/Supabase architecture with:

- **User Authentication**: Email/password registration, login, Facebook OAuth
- **Tournament System**: Registration, brackets, real-time matches, chat
- **Private Rooms**: Create/join rooms with shareable deep links
- **Suspension Appeals**: Users can appeal bans/suspensions
- **User Reports/Tickets**: Submit and track support tickets
- **Real-time Notifications**: Admin and user notifications with read receipts
- **Admin Dashboard**: Full management of users, tournaments, matches, rooms, appeals, reports

## Quick Start

### 1. Database Setup
Run both SQL files in Supabase SQL Editor:
```sql
-- 1. Base schema
\i database/schema.sql

-- 2. Tournament extensions
\i database/schema_v2.sql
```

### 2. Configuration
Copy `includes/config.sample.php` to `includes/config.php` and fill in:

```php
'supabase_url' => 'https://YOUR-PROJECT.supabase.co',
'supabase_key' => 'YOUR-SERVICE-ROLE-KEY',
'supabase_anon_key' => 'YOUR-ANON-KEY',  // For realtime

'facebook' => array(
    'app_id' => '1453266169543653',
    'app_secret' => 'YOUR_FACEBOOK_APP_SECRET',
    'redirect_uri' => 'https://yourdomain.com/auth/facebook/callback/',
),

'admins' => array(
    'admin' => 'PASSWORD_HASH_HERE',  // Generate with: php -r "echo password_hash('YourPassword', PASSWORD_DEFAULT);"
),
```

### 3. Facebook OAuth Setup
1. Go to Facebook Developers Console
2. Add product: **Facebook Login**
3. Settings → Valid OAuth Redirect URIs: `https://yourdomain.com/auth/facebook/callback/`
4. App Review → Request `email` and `public_profile` permissions
5. Add your domain to **App Domains** and **Site URL**

### 4. Web Server (Nginx)
```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    root /path/to/drotix-tic-tac-toe;
    index index.php index.html;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    # Security headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header Referrer-Policy same-origin;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to sensitive files
    location ~ /includes/ { deny all; }
    location ~ /database/ { deny all; }
    location ~ \.sql$ { deny all; }
    location ~ \.md$ { deny all; }

    # API endpoints
    location /api/ {
        try_files $uri $uri/ /api/index.php?$query_string;
    }

    # Clean URLs for auth pages
    location /auth/ {
        try_files $uri $uri/ /auth/index.php?$query_string;
    }

    location /tournaments/ {
        try_files $uri $uri/ /tournaments/index.php?$query_string;
    }

    location /rooms/ {
        try_files $uri $uri/ /rooms/index.php?$query_string;
    }

    location /admin/ {
        try_files $uri $uri/ /admin/index.php?$query_string;
    }
}
```

### 5. PHP Requirements
- PHP 8.1+
- Extensions: `curl`, `json`, `mbstring`, `openssl`, `session`
- Composer not required (no external dependencies)

### 6. Supabase Realtime (Optional)
For real-time features without polling:
1. Enable Realtime in Supabase Dashboard for tables:
   - `tournament_matches`
   - `private_rooms`
   - `user_notifications`
   - `report_messages`
   - `tourney_chat_messages`
   - `room_chat_messages`
2. Add RLS policies for authenticated users to subscribe to their own data
3. Add `supabase_realtime_url` to config.php

## Directory Structure
```
drotix-tic-tac-toe/
├── index.php                 # Home page
├── assets/
│   ├── css/
│   │   ├── style.css         # Main theme
│   │   ├── tournament.css    # Tournament/game styles
│   │   ├── game.css          # Playable game
│   │   └── arena.css         # Arena/leaderboard
│   ├── js/
│   │   ├── main.js           # Core functionality
│   │   ├── game.js           # Playable game logic
│   │   └── arena.js          # Arena carousel
│   └── img/                  # SVGs, logos
├── auth/                     # Authentication pages
│   ├── register/
│   ├── login/
│   ├── logout/
│   ├── facebook/
│   ├── verify/
│   ├── forgot/
│   ├── reset/
│   ├── banned/
│   ├── suspended/
│   └── dashboard/
├── tournaments/              # Tournament system
│   ├── index.php             # List + detail
│   ├── register/
│   ├── match/
│   ├── withdraw/
│   ├── waitlist/
│   └── watch/
├── rooms/                    # Private rooms
│   ├── index.php             # List + join
│   ├── create/
│   └── room.php              # Room game
├── appeal/                   # Suspension appeals
├── reports/                  # User reports/tickets
├── notifications/            # User notifications
├── settings/                 # User settings
├── admin/                    # Admin panel
│   ├── index.php             # Dashboard
│   ├── users/
│   ├── tournaments/
│   ├── matches/
│   ├── rooms/
│   ├── appeals/
│   ├── reports/
│   ├── messages/
│   ├── deletions/
│   ├── waitlist/
│   ├── visitors/
│   ├── explorer/
│   └── health/
├── api/                      # API endpoints
│   ├── submit/               # Form submissions
│   ├── live/                 # Live presence
│   ├── notify/               # Notifications
│   ├── tournament/
│   │   ├── move/             # Make move
│   │   ├── match/            # Match state
│   │   └── chat/             # Match chat
│   ├── room/
│   │   ├── move/             # Make move
│   │   ├── match/            # Room state
│   │   └── chat/             # Room chat
│   ├── notifications/read/   # Mark read
│   └── admin/
│       ├── notifications/    # Admin notif counts
│       ├── report-messages/  # Report messages
│       └── report-message/read/
├── includes/
│   ├── config.sample.php     # Configuration template
│   ├── config.php            # Your config (gitignored)
│   ├── bootstrap.php         # Core helpers
│   ├── auth.php              # Auth helpers
│   ├── supabase.php          # Supabase client
│   └── admin_layout.php      # Admin layout
├── database/
│   ├── schema.sql            # Base tables
│   └── schema_v2.sql         # Tournament tables
└── DEPLOYMENT.md             # This file
```

## Key Features Implementation

### Tournament Flow
1. **Registration**: `/tournaments/register/?id=TOURNAMENT_ID`
2. **Bracket Generation**: Admin generates brackets in `/admin/tournaments/`
3. **Match Play**: `/tournaments/match/?id=MATCH_ID` (real-time polling)
4. **Chat**: Built-in match chat via `/api/tournament/chat/`
5. **Spectate**: `/tournaments/watch/?id=TOURNAMENT_ID`

### Private Rooms
1. **Create**: `/rooms/create/`
2. **Join**: `/rooms/?code=ABC123` or `/rooms/?slug=room-name`
3. **Share**: Deep links work directly; room codes are 6-char alphanumeric

### Admin Real-time
- Notification badges update every 15 seconds
- Report messages show read receipts (user/admin)
- Auto-refresh on report detail pages (30s interval)
- Visibility change triggers immediate refresh

### Facebook Login
- Initiate: `/auth/facebook/?return=/dashboard/`
- Callback: `/auth/facebook/callback/`
- Links to existing email accounts automatically

## Security Notes
- All API endpoints validate origin against `allowed_origins`
- Rate limiting on all forms (5 req/10min per IP)
- CSRF tokens on all state-changing forms
- Password hashing with `PASSWORD_DEFAULT` (bcrypt)
- Session cookies: Secure, HttpOnly, SameSite=Lax
- Admin sessions: Separate cookie, stricter settings
- SQL injection prevented via PostgREST parameterized queries

## Customization

### Theme Colors
Edit CSS variables in `assets/css/style.css`:
```css
:root {
  --bg: #0A0A0C;
  --surface: #17171C;
  --magenta: #ED1B76;
  --pink: #FF4A99;
  --teal: #2FB8B4;
  --gold: #FFC24D;
}
```

### Tournament Defaults
Edit `config.php`:
```php
'tournament' => array(
    'default_board_size' => 3,
    'default_turn_time' => 20,
    'walkover_minutes' => 5,
),
```

### Room Settings
```php
'rooms' => array(
    'code_length' => 6,
    'default_ttl_hours' => 24,
    'max_rooms_per_user' => 5,
),
```

## Monitoring & Maintenance

### Logs
- PHP errors: Check web server error log
- Supabase errors: Check Supabase Dashboard → Logs
- Application logs: `error_log()` calls in PHP

### Cleanup Jobs
Set up cron jobs:
```bash
# Daily: Clean expired rooms (older than 24h)
0 3 * * * php /path/to/cleanup_rooms.php

# Daily: Clean old rate limit files
0 4 * * * find /tmp -name 'drotix_rl_*' -mtime +1 -delete

# Hourly: Clean expired OAuth states
0 * * * * php /path/to/cleanup_oauth.php
```

## Troubleshooting

### "Supabase not configured"
→ Copy `config.sample.php` to `config.php` and fill in credentials

### "Could not read tables"
→ Run both `schema.sql` and `schema_v2.sql` in Supabase

### Facebook login fails
→ Check App Secret, Redirect URI, and that app is in Live mode

### Real-time not working
→ Enable Realtime in Supabase for required tables
→ Check RLS policies allow authenticated user access

### Admin panel 404
→ Ensure Nginx `try_files` includes `/admin/index.php`

## License
Proprietary - Drotix Tournament Gaming Platform