-- =====================================================================
-- DROTIX admin panel -> "Mobile App" section: complete Supabase setup
-- Supabase -> SQL Editor -> paste ALL of this -> Run.  Safe to run again.
-- It does NOT create, change or delete any game table, column or function,
-- and it does not touch player data (except step 2: one player becomes admin).
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1) Server key access to the game schema `drotix`
--    (the admin panel reads/writes the game data on the server with the Secret key)
-- ---------------------------------------------------------------------
grant usage on schema drotix to service_role;
grant all privileges on all tables    in schema drotix to service_role;
grant all privileges on all sequences in schema drotix to service_role;
grant execute        on all functions in schema drotix to service_role;
alter default privileges in schema drotix grant all privileges on tables    to service_role;
alter default privileges in schema drotix grant all privileges on sequences to service_role;
alter default privileges in schema drotix grant execute        on functions to service_role;


-- ---------------------------------------------------------------------
-- 2) Make ONE player an app admin
--    Needed for the game's own admin functions: tournaments + Start now, push broadcast,
--    report / appeal decisions, Online now, Safety tab, Legal, App releases, Coupons.
--    >>> Replace the email with YOUR app account email, then run. <<<
-- ---------------------------------------------------------------------
update drotix.profiles
   set is_admin = true
 where id = (select id from auth.users where lower(email) = lower('YOUR_APP_EMAIL@example.com'));


-- ---------------------------------------------------------------------
-- 3) Storage bucket for APK files (App releases page). Does nothing if it exists.
-- ---------------------------------------------------------------------
insert into storage.buckets (id, name, public)
values ('drotix-app-updates', 'drotix-app-updates', true)
on conflict (id) do nothing;


-- ---------------------------------------------------------------------
-- 4) Website admin audit table (used by "Mobile App -> Audit log -> This panel").
--    Already created by database/schema.sql; this only creates it if missing.
-- ---------------------------------------------------------------------
create table if not exists public.admin_audit_log (
  id          uuid primary key default gen_random_uuid(),
  created_at  timestamptz not null default now(),
  admin_user  text,
  action      text not null,
  target      text,
  details     text
);
alter table public.admin_audit_log enable row level security;   -- only the server key can read/write it


-- =====================================================================
-- CHECKS (read-only). Run them and look at the results.
-- =====================================================================

-- A) Who is app admin?  (must show at least 1 row with your email)
select p.id, p.username, p.is_admin, u.email
  from drotix.profiles p
  join auth.users u on u.id = p.id
 where p.is_admin;

-- B) The game's admin functions exist?  (Coupons / Legal / Releases / Online / Safety)
select p.proname as function_name
  from pg_proc p join pg_namespace n on n.oid = p.pronamespace
 where n.nspname = 'drotix'
   and p.proname in ('admin_list_coupons','admin_save_coupon','admin_set_coupon_active','admin_coupon_redemptions',
                     'admin_list_legal','admin_publish_legal','admin_list_app_releases','admin_publish_app_release',
                     'admin_online_now','admin_user_social','admin_remove_block','admin_cancel_challenge','admin_clear_status_text',
                     'admin_create_tournament','admin_generate_bracket','admin_send_notification','admin_review_appeal','admin_update_report')
 order by 1;

-- C) Coupon money tracking: which table stores coin purchases and their price?
--    (send this result to the developer if "Money spent" does not show on a coupon)
select table_name, column_name, data_type
  from information_schema.columns
 where table_schema = 'drotix'
   and table_name ~ '(purchase|iap|order|payment|billing|transaction)'
 order by table_name, ordinal_position;

-- D) Is the APK bucket there and public?
select id, public from storage.buckets where id = 'drotix-app-updates';
