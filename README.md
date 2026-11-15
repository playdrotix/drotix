# DROTIX Tic Tac Toe: website + PHP forms + admin (Supabase)

Static HTML website (Squid-Game-style theme) with folder URLs, plus a small PHP backend.
All form data is stored in **Supabase** (no MySQL). Needs PHP 7.4+ with the `curl` and `mbstring` extensions.

## URLs
/ · /features/ · /how-to-play/ · /faq/ · /about/ · /contact/ · /delete-account/ · /press/ ·
/privacy-policy/ · /terms-of-use/ · /404.html
Backend: /api/submit/ (forms) · /admin/ (login) with Messages, Deletion requests, Waitlist, Data Explorer.

## Folder map
    index.html, features/, faq/, contact/, ...   each page = folder + index.html
    assets/                  css, js, images, characters, logo
    api/submit/index.php     receives the 3 website forms and saves them to Supabase
    admin/                   admin panel (login, dashboard, messages, deletions, waitlist, explorer)
    includes/                config + PHP helpers (blocked from the browser)
    database/schema.sql      tables to create in Supabase (blocked from the browser)
    .htaccess                Apache rules (private folders, headers, 404 page)
    deploy/nginx.conf.sample sample settings if your VPS uses Nginx instead of Apache

## Setup (about 15 minutes)
1. **Supabase**: create a project. Open SQL Editor, paste `database/schema.sql`, run it.
   Then Project Settings -> API: copy the *Project URL* and the **service_role** key.
2. **Upload** all files to the web root of playdrotix.in (cPanel File Manager or FTP).
3. **Config**: copy `includes/config.sample.php` to `includes/config.php` and fill in
   `supabase_url`, `supabase_key`. Check `allowed_origins` (your real site address with https, both playdrotix.in and www.playdrotix.in are already listed).
4. **Admin password**: in config.php set `'enable_hash_tool' => true`, open `/admin/make-hash/`,
   type a strong password, copy the hash into `'admins' => array('admin' => '...')`, then set
   `enable_hash_tool` back to `false`. (Or run: `php -r "echo password_hash('YourPassword', PASSWORD_DEFAULT);"`)
5. Open **/admin/login/** and log in. The dashboard shows if Supabase is connected.
6. **Test on the live site**: send the contact form, the waitlist form and a deletion request,
   then check them in the admin. (If you test on a different address, add it to `allowed_origins`.)
7. When SSL works, remove the `#` signs in `.htaccess` to force https.
8. In Google Play Console and App Store Connect use: Privacy policy URL = /privacy-policy/,
   Account deletion URL = /delete-account/, Support URL = /contact/.

## Deletion requests (2 days)
The delete form saves `status = pending` and `due_at = now + 2 days` (change `deletion_days` in config).
Admin -> Deletion requests lists open requests by deadline and flags overdue ones.
Recommended routine: check that the request really comes from the account owner (ask them to reply from the
account email) -> status *verifying* -> delete the player -> status *completed* (a timestamp is saved).
Optional shortcut: in config.php set `app_delete` to `enabled => true` with your player table and email column.
The request page then shows the matching player rows and a "Delete from app data" button (you must type the email
to confirm). Keep it off until you are sure about the table name.

## Data Explorer (read any table)
Open `includes/config.php` -> `explorer`. Add one line per table:
`array('label' => 'App players', 'url' => 'https://YOUR-APP.supabase.co', 'key' => 'SERVICE-KEY', 'table' => 'profiles')`
`url` and `key` can be `null` to use the default project. The page shows rows, pagination and CSV export. It is read-only.

## Security notes
* The service_role key lives only in `includes/config.php` on the server. Never put it in HTML or JavaScript.
* Tables have Row Level Security on and no public policies, so the public anon key cannot read them.
* Admin login: password hashing, CSRF tokens, 5 failed tries = 15 minute lock, 30 minute idle logout, noindex.
* Forms: hidden spam field, "too fast" check, 5 submissions per 10 minutes per visitor and form, origin check.
* Nginx instead of Apache? Add: `location ~ ^/(includes|database)/ { deny all; }` and `location ~ /\. { deny all; }`,
  keep `index index.html index.php;` and use the normal PHP-FPM block. If you can, put `includes/` above the web root.
* Do not upload `config.php` anywhere public or share it.

## If the live site shows old styling (only the bottom/footer is unstyled)
This happens when the server, a CDN (for example Cloudflare) or the browser still serves an OLD copy of the CSS file.
1. Upload the **whole** `assets/` folder again. There are now two stylesheets: `assets/css/style.css` and the new
   `assets/css/social.css` (follow and share section). Upload in **binary** mode (File Manager upload is fine).
2. Open `https://playdrotix.in/assets/css/social.css` in the browser. You must see CSS text starting with `/* ===== social`.
   If you get a 404, the file was not uploaded or the folder is wrong.
3. Clear caches: server cache (hosting panel), Cloudflare (Caching -> Purge Everything), then hard refresh the browser (Ctrl+F5).
4. Page links now carry a version number (`style.css?v=...`), and CSS/JS are cached for only 5 minutes (`.htaccess` for Apache,
   `deploy/nginx.conf.sample` for Nginx on a VPS). Nginx ignores `.htaccess`, so use the sample file.
5. After you edit any CSS or JS later, change the `?v=` number in the HTML pages (or just purge the cache).

## Social media (username: playdrotix)
The footer has **Follow and connect** buttons for Instagram, Facebook, Snapchat and LinkedIn, plus a **Share** button
(WhatsApp, Facebook, LinkedIn, Snapchat, native phone share and Copy link). The mobile menu also shows the follow icons.
To change a profile link, edit `SITE.social` at the top of `assets/js/main.js` (one place, every page updates).
LinkedIn is set to a company page (`/company/playdrotix/`). If it is a personal profile, change it to `/in/playdrotix/`.
Instagram has no web share link, so people share to it from the phone's Share sheet.

## Please check before launch
* I could not run PHP in my build environment. The pages and JavaScript were tested against a mock of the API,
  and all PHP files were checked for syntax balance, but please run the test in step 6 on a staging copy first.
* "Supabase" is how I read your "सुपरवाइज" in the voice message.
* Address on the site: Kasidih, Sakchi, Jamshedpur, Jharkhand 831001, India. PIN 831001 comes from public PIN
  directories. I left out the district because postal records list this area under East Singhbhum while you said
  West Singhbhum. Edit the address in: contact, footer, press and privacy pages (search the files for "Kasidih").
* Privacy policy and Terms are templates. Have a lawyer review them. I set the governing law to India, courts in
  Jamshedpur, and "reply within 2 working days". Change them if needed.
* Remaining items for you to decide: age rating line in the privacy policy and FAQ, price/ads line in FAQ and press kit,
  store links (assets/js/main.js, `SITE.playStore` and `SITE.appStore`), official store badges after launch.


## What is new in this version (v2)
1. **Game board fix**: all cells on 3x3, 4x4 and 5x5 are now exactly the same size on every phone (empty rows no longer shrink).
   Files: `assets/css/game.css`, `assets/js/game.js`.
2. **Visitor history**: every page view is saved to the `site_visits` table by `assets/js/track.js` (added to all pages).
   Admin -> **Site visitors** shows total visits, unique visitors, today / 7 days / 30 days, top pages, devices, sources, full history and CSV export.
3. **Tournament registration form**: the Drotix Cup popup (and the "Register now" button on the Tournament slide) opens a form:
   full name, player name, email, WhatsApp / phone, city, level, consent. Saved in `tournament_registrations`.
   Admin -> **Tournament sign-ups**: search, filter, mark New / Confirmed / Waitlisted / Rejected, open WhatsApp, remove, export CSV.
   A direct link to the form: `https://playdrotix.in/#register-cup`.

**To upgrade an existing install:** (a) run the new block at the bottom of `database/schema.sql` in Supabase SQL Editor;
(b) add two lines to `tables` in `includes/config.php`: `'visits' => 'site_visits', 'tournament' => 'tournament_registrations',`
(see `config.sample.php`); (c) upload all files and purge the cache. Privacy policy was updated for the visit counter.
Note: I could not run PHP here, so test both new forms and both new admin pages once on the live site.
