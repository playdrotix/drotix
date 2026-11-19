-- =====================================================================
-- DROTIX web admin accounts (/admin/ login): profile, change password,
-- "Forgot password" by email.  Website schema `public` (not the game).
-- Supabase -> SQL Editor -> paste -> Run.  Safe to run again.
-- After running: log in once with your current admin username/password;
-- the account is copied into this table automatically.
-- =====================================================================

create table if not exists public.admin_users (
  id                  uuid primary key default gen_random_uuid(),
  username            text not null,
  email               text,
  display_name        text,
  password_hash       text not null,              -- bcrypt (PHP password_hash), never the plain password
  disabled            boolean not null default false,
  last_login_at       timestamptz,
  password_changed_at timestamptz,
  reset_token_hash    text,                       -- sha256 of the emailed reset token (one-time)
  reset_expires_at    timestamptz,
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now()
);

create unique index if not exists admin_users_username_key on public.admin_users (lower(username));
create unique index if not exists admin_users_email_key    on public.admin_users (lower(email)) where email is not null;
create index        if not exists admin_users_reset_idx    on public.admin_users (reset_token_hash) where reset_token_hash is not null;

-- Only the server (Secret key) may read or write admin accounts. No public access at all.
alter table public.admin_users enable row level security;
revoke all on public.admin_users from anon, authenticated;
grant all on public.admin_users to service_role;

-- Optional: set the email of the existing "admin" account right away
-- (otherwise set it in Admin -> My profile after logging in once):
-- update public.admin_users set email = 'you@playdrotix.in' where lower(username) = 'admin';

-- Check:
select username, email, display_name, disabled, last_login_at, password_changed_at from public.admin_users;
