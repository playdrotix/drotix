-- DROTIX Tic Tac Toe : Supabase schema
-- Run this once in Supabase -> SQL Editor.
-- Row Level Security is ON with no public policies, so the public (anon) key can NOT read or write these tables.
-- Only your PHP server (service-role key) can use them.

create table if not exists public.contact_messages (
  id          uuid primary key default gen_random_uuid(),
  created_at  timestamptz not null default now(),
  name        text not null,
  email       text not null,
  topic       text,
  message     text not null,
  status      text not null default 'new' check (status in ('new','read','replied','archived')),
  admin_note  text,
  ip          text,
  user_agent  text
);

create table if not exists public.waitlist_signups (
  id          uuid primary key default gen_random_uuid(),
  created_at  timestamptz not null default now(),
  email       text not null unique,
  source      text,
  ip          text
);

create table if not exists public.deletion_requests (
  id            uuid primary key default gen_random_uuid(),
  created_at    timestamptz not null default now(),
  username      text not null,
  email         text not null,
  notes         text,
  status        text not null default 'pending' check (status in ('pending','verifying','completed','rejected')),
  due_at        timestamptz not null default (now() + interval '2 days'),   -- 2-day deletion timeline
  completed_at  timestamptz,
  admin_note    text,
  ip            text,
  user_agent    text
);

create table if not exists public.admin_audit_log (
  id          uuid primary key default gen_random_uuid(),
  created_at  timestamptz not null default now(),
  admin_user  text,
  action      text,
  target      text,
  details     text
);

create index if not exists contact_messages_created_idx  on public.contact_messages (created_at desc);
create index if not exists contact_messages_status_idx   on public.contact_messages (status);
create index if not exists waitlist_signups_created_idx  on public.waitlist_signups (created_at desc);
create index if not exists deletion_requests_status_idx  on public.deletion_requests (status, due_at);
create index if not exists admin_audit_log_created_idx   on public.admin_audit_log (created_at desc);

alter table public.contact_messages  enable row level security;
alter table public.waitlist_signups  enable row level security;
alter table public.deletion_requests enable row level security;
alter table public.admin_audit_log   enable row level security;

revoke all on table public.contact_messages  from anon, authenticated;
revoke all on table public.waitlist_signups  from anon, authenticated;
revoke all on table public.deletion_requests from anon, authenticated;
revoke all on table public.admin_audit_log   from anon, authenticated;


-- ===== v2: site visitors + tournament registrations (run this block again safely; it uses IF NOT EXISTS) =====
create table if not exists public.site_visits (
  id          uuid primary key default gen_random_uuid(),
  created_at  timestamptz not null default now(),
  visitor_id  text not null,                 -- random id kept in the visitor's browser
  session_id  text,
  is_new      boolean not null default false, -- true on the very first visit of that browser
  path        text,
  referrer    text,
  device      text,                           -- mobile / tablet / desktop
  language    text,
  screen      text,
  ip          text,
  user_agent  text
);
create index if not exists site_visits_created_idx on public.site_visits (created_at desc);
create index if not exists site_visits_visitor_idx on public.site_visits (visitor_id);
create index if not exists site_visits_new_idx     on public.site_visits (is_new);

create table if not exists public.tournament_registrations (
  id          uuid primary key default gen_random_uuid(),
  created_at  timestamptz not null default now(),
  tournament  text not null default 'Drotix Cup Season 1',
  name        text not null,
  email       text not null,
  phone       text not null,
  username    text not null,                  -- in-game / player name
  city        text,
  experience  text,                           -- beginner / intermediate / pro
  status      text not null default 'new' check (status in ('new','confirmed','waitlisted','rejected')),
  admin_note  text,
  ip          text,
  user_agent  text,
  unique (tournament, email)
);
create index if not exists tournament_reg_created_idx on public.tournament_registrations (created_at desc);
create index if not exists tournament_reg_status_idx  on public.tournament_registrations (status);

alter table public.site_visits               enable row level security;
alter table public.tournament_registrations  enable row level security;
revoke all on table public.site_visits              from anon, authenticated;
revoke all on table public.tournament_registrations from anon, authenticated;


-- ===== v3: live presence + site notifications (safe to run again) =====
create table if not exists public.site_presence (
  id          uuid primary key default gen_random_uuid(),
  visitor_id  text not null unique,
  last_seen   timestamptz not null default now(),
  path        text
);
create index if not exists site_presence_seen_idx on public.site_presence (last_seen desc);

create table if not exists public.site_notifications (
  id          uuid primary key default gen_random_uuid(),
  created_at  timestamptz not null default now(),
  visitor_id  text not null,
  kind        text not null default 'reply',      -- reply | status | info
  title       text not null,
  body        text,
  ref_table   text,
  ref_id      text,
  read_at     timestamptz
);
create index if not exists site_notif_visitor_idx on public.site_notifications (visitor_id, created_at desc);
create index if not exists site_notif_ref_idx     on public.site_notifications (ref_id);

alter table public.contact_messages          add column if not exists visitor_id text;
alter table public.tournament_registrations  add column if not exists visitor_id text;

alter table public.site_presence       enable row level security;
alter table public.site_notifications  enable row level security;
revoke all on table public.site_presence      from anon, authenticated;
revoke all on table public.site_notifications from anon, authenticated;
