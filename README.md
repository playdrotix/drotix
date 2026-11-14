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
