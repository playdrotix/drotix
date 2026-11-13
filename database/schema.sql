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
