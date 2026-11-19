-- =====================================================================
-- DROTIX admin panel -> "Mobile App" section: one-time Supabase setup
-- Run in Supabase -> SQL Editor. Safe to run more than once.
-- It does NOT create or change any game table, column or function.
-- =====================================================================

-- 1) Let the server's Secret (service_role) key read/write the game schema `drotix`.
--    (Custom schemas do not get these grants automatically.)
grant usage on schema drotix to service_role;
grant all privileges on all tables    in schema drotix to service_role;
grant all privileges on all sequences in schema drotix to service_role;
grant execute        on all functions in schema drotix to service_role;
alter default privileges in schema drotix grant all privileges on tables    to service_role;
alter default privileges in schema drotix grant all privileges on sequences to service_role;
alter default privileges in schema drotix grant execute        on functions to service_role;

-- 2) Make ONE player an app admin (needed for the game's own admin functions:
--    create tournament, Start now / bracket, push broadcast, report & appeal decisions,
--    online now, safety tab). Replace the email with your own app account, then run.
update drotix.profiles
   set is_admin = true
 where id = (select id from auth.users where lower(email) = lower('YOUR_APP_EMAIL@example.com'));

-- Check:
select p.id, p.username, p.is_admin, u.email
  from drotix.profiles p join auth.users u on u.id = p.id
 where p.is_admin;

-- 3) The schema must be exposed to the API (usually already done because the app uses it):
--    Supabase -> Project Settings -> Data API -> Exposed schemas -> add "drotix" -> Save.
