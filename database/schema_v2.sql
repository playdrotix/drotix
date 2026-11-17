-- DROTIX Tournament Gaming Platform - Extended Schema
-- Run this in Supabase SQL Editor after the base schema.sql
-- This adds: users, sessions, tournaments, matches, private rooms, appeals, reports, notifications

-- ===== USERS & AUTHENTICATION =====

create table if not exists public.users (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  email           text not null unique,
  password_hash   text,                    -- null for Facebook-only accounts
  username        text not null unique,    -- display name in game
  avatar_url      text,
  provider        text not null default 'email' check (provider in ('email', 'facebook')),
  provider_id     text,                    -- Facebook user ID when provider='facebook'
  email_verified  boolean not null default false,
  verification_token text,                -- for email verification
  verify_token_expiry timestamptz,
  reset_token     text,                    -- for password reset
  reset_token_expiry timestamptz,
  status          text not null default 'active' check (status in ('active', 'suspended', 'banned', 'pending_verification')),
  suspension_reason text,
  suspended_at    timestamptz,
  suspended_until timestamptz,             -- null = permanent
  coins           integer not null default 1000,
  total_wins      integer not null default 0,
  total_losses    integer not null default 0,
  total_draws     integer not null default 0,
  rating          integer not null default 1000,
  last_login_at   timestamptz,
  last_login_ip   text,
  visitor_id      text,                    -- links to site_visits/presence
  unique (provider, provider_id)
);

create index if not exists users_email_idx on public.users (email);
create index if not exists users_username_idx on public.users (username);
create index if not exists users_status_idx on public.users (status);
create index if not exists users_visitor_idx on public.users (visitor_id);
create index if not exists users_provider_idx on public.users (provider, provider_id);

-- User sessions for persistent login
create table if not exists public.user_sessions (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  user_id         uuid not null references public.users(id) on delete cascade,
  session_token   text not null unique,
  expires_at      timestamptz not null,
  ip              text,
  user_agent      text,
  last_activity   timestamptz not null default now()
);

create index if not exists user_sessions_user_idx on public.user_sessions (user_id);
create index if not exists user_sessions_token_idx on public.user_sessions (session_token);
create index if not exists user_sessions_expires_idx on public.user_sessions (expires_at);

-- Facebook OAuth states (CSRF protection)
create table if not exists public.facebook_oauth_states (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  state           text not null unique,
  redirect_url    text,                    -- where to send user after auth
  expires_at      timestamptz not null default (now() + interval '10 minutes')
);

create index if not exists fb_oauth_state_idx on public.facebook_oauth_states (state);
create index if not exists fb_oauth_expires_idx on public.facebook_oauth_states (expires_at);


-- ===== TOURNAMENTS =====

create table if not exists public.tournaments (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  name            text not null,
  slug            text not null unique,    -- for URLs: drotix-cup-season-1
  description     text,
  banner_url      text,
  status          text not null default 'draft' check (status in ('draft', 'registration_open', 'registration_closed', 'in_progress', 'completed', 'cancelled')),
  format          text not null default 'single_elimination' check (format in ('single_elimination', 'double_elimination', 'round_robin', 'swiss')),
  max_players     integer not null default 32,
  min_players     integer not null default 8,
  entry_fee       integer not null default 0,     -- in coins
  prize_pool      integer not null default 0,     -- in coins
  prize_distribution jsonb,                 -- e.g., {"1": 0.5, "2": 0.3, "3": 0.2}
  registration_starts_at timestamptz,
  registration_ends_at   timestamptz,
  starts_at       timestamptz,
  ends_at         timestamptz,
  board_size      integer not null default 3 check (board_size in (3, 4, 5)),
  win_condition   integer not null default 3,     -- 3 for 3x3, 4 for 4x4/5x5
  turn_time_seconds integer not null default 20,
  settings        jsonb not null default '{}',    -- additional settings
  created_by      uuid references public.users(id)
);

create index if not exists tournaments_status_idx on public.tournaments (status);
create index if not exists tournaments_slug_idx on public.tournaments (slug);
create index if not exists tournaments_dates_idx on public.tournaments (registration_starts_at, registration_ends_at, starts_at);

-- Tournament participants (registrations)
create table if not exists public.tournament_participants (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  tournament_id   uuid not null references public.tournaments(id) on delete cascade,
  user_id         uuid not null references public.users(id) on delete cascade,
  status          text not null default 'registered' check (status in ('registered', 'confirmed', 'waitlisted', 'checked_in', 'eliminated', 'withdrawn', 'disqualified')),
  seed            integer,                     -- tournament seed
  checked_in_at   timestamptz,
  eliminated_at   timestamptz,
  eliminated_round integer,
  final_position  integer,
  coins_won       integer not null default 0,
  unique (tournament_id, user_id)
);

create index if not exists tourney_part_tourney_idx on public.tournament_participants (tournament_id);
create index if not exists tourney_part_user_idx on public.tournament_participants (user_id);
create index if not exists tourney_part_status_idx on public.tournament_participants (status);

-- Tournament matches
create table if not exists public.tournament_matches (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  tournament_id   uuid not null references public.tournaments(id) on delete cascade,
  round           integer not null,            -- 1 = first round, etc.
  match_number    integer not null,            -- order within round
  bracket_position text,                       -- e.g., "R1M1", "R2M1", "QF1", "SF1", "F1"
  player1_id      uuid references public.users(id) on delete set null,
  player2_id      uuid references public.users(id) on delete set null,
  winner_id       uuid references public.users(id) on delete set null,
  status          text not null default 'pending' check (status in ('pending', 'scheduled', 'ready', 'in_progress', 'completed', 'walkover', 'disputed', 'cancelled')),
  scheduled_at    timestamptz,
  started_at      timestamptz,
  completed_at    timestamptz,
  -- Game state
  board_size      integer not null default 3,
  win_condition   integer not null default 3,
  turn_time_seconds integer not null default 20,
  current_turn    uuid references public.users(id), -- whose turn it is
  board_state     jsonb,                       -- current board: ["X", "", "O", ...]
  move_history    jsonb not null default '[]', -- array of {player_id, position, timestamp}
  result          jsonb,                       -- {winner_id, loser_id, draws: 0, reason: "win|draw|forfeit|disconnect"}
  -- For dispute resolution
  dispute_reason  text,
  disputed_by     uuid references public.users(id),
  disputed_at     timestamptz,
  resolved_by     uuid references public.users(id), -- admin
  resolved_at     timestamptz,
  admin_notes     text
);

create index if not exists tourney_match_tourney_idx on public.tournament_matches (tournament_id);
create index if not exists tourney_match_round_idx on public.tournament_matches (tournament_id, round);
create index if not exists tourney_match_status_idx on public.tournament_matches (status);
create index if not exists tourney_match_players_idx on public.tournament_matches (player1_id, player2_id);
create index if not exists tourney_match_bracket_idx on public.tournament_matches (bracket_position);

-- Tournament chat messages (for match communication)
create table if not exists public.tournament_chat_messages (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  match_id        uuid not null references public.tournament_matches(id) on delete cascade,
  user_id         uuid not null references public.users(id) on delete cascade,
  message         text not null,
  type            text not null default 'chat' check (type in ('chat', 'system', 'admin')),
  read_by         uuid[] not null default '{}'  -- array of user_ids who have read
);

create index if not exists tourney_chat_match_idx on public.tournament_chat_messages (match_id, created_at);


-- ===== PRIVATE ROOMS =====

create table if not exists public.private_rooms (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  host_id         uuid not null references public.users(id) on delete cascade,
  room_code       text not null unique,        -- 6-char code for sharing
  slug            text not null unique,        -- for deep links: room/abc123
  name            text,                        -- optional room name
  status          text not null default 'waiting' check (status in ('waiting', 'in_progress', 'completed', 'abandoned')),
  board_size      integer not null default 3 check (board_size in (3, 4, 5)),
  win_condition   integer not null default 3,
  turn_time_seconds integer not null default 20,
  is_ranked       boolean not null default false,
  max_players     integer not null default 2,
  -- Game state (same as tournament_matches)
  player1_id      uuid references public.users(id) on delete set null,
  player2_id      uuid references public.users(id) on delete set null,
  winner_id       uuid references public.users(id) on delete set null,
  current_turn    uuid references public.users(id),
  board_state     jsonb,
  move_history    jsonb not null default '[]',
  result          jsonb,
  started_at      timestamptz,
  completed_at    timestamptz,
  expires_at      timestamptz not null default (now() + interval '24 hours') -- auto-cleanup
);

create index if not exists private_rooms_host_idx on public.private_rooms (host_id);
create index if not exists private_rooms_code_idx on public.private_rooms (room_code);
create index if not exists private_rooms_slug_idx on public.private_rooms (slug);
create index if not exists private_rooms_status_idx on public.private_rooms (status);
create index if not exists private_rooms_expires_idx on public.private_rooms (expires_at);

-- Room participants (for spectating or future multi-player)
create table if not exists public.room_participants (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  room_id         uuid not null references public.private_rooms(id) on delete cascade,
  user_id         uuid not null references public.users(id) on delete cascade,
  role            text not null default 'player' check (role in ('player', 'spectator')),
  joined_at       timestamptz not null default now(),
  left_at         timestamptz,
  unique (room_id, user_id)
);

create index if not exists room_part_room_idx on public.room_participants (room_id);
create index if not exists room_part_user_idx on public.room_participants (user_id);

-- Room chat messages
create table if not exists public.room_chat_messages (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  room_id         uuid not null references public.private_rooms(id) on delete cascade,
  user_id         uuid not null references public.users(id) on delete cascade,
  message         text not null,
  type            text not null default 'chat' check (type in ('chat', 'system'))
);

create index if not exists room_chat_room_idx on public.room_chat_messages (room_id, created_at);


-- ===== SUSPENSION APPEALS =====

create table if not exists public.suspension_appeals (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  user_id         uuid not null references public.users(id) on delete cascade,
  username        text not null,               -- username at time of appeal
  email           text not null,
  reason          text not null,               -- why they think suspension is wrong
  evidence        text,                        -- screenshots, links, etc.
  status          text not null default 'pending' check (status in ('pending', 'under_review', 'approved', 'rejected', 'escalated')),
  reviewed_by     uuid references public.users(id), -- admin
  reviewed_at     timestamptz,
  review_notes    text,
  admin_response  text                         -- message sent to user
);

create index if not exists suspension_appeals_user_idx on public.suspension_appeals (user_id);
create index if not exists suspension_appeals_status_idx on public.suspension_appeals (status);
create index if not exists suspension_appeals_created_idx on public.suspension_appeals (created_at desc);


-- ===== USER REPORTS / TICKETS =====

create table if not exists public.user_reports (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  reporter_id     uuid not null references public.users(id) on delete cascade,
  reported_user_id uuid references public.users(id) on delete set null, -- null for general issues
  type            text not null check (type in ('cheating', 'harassment', 'inappropriate_name', 'bug', 'payment', 'other')),
  subject         text not null,
  description     text not null,
  evidence        text,                        -- screenshots, videos, links
  status          text not null default 'open' check (status in ('open', 'investigating', 'resolved', 'rejected', 'escalated')),
  priority        text not null default 'normal' check (priority in ('low', 'normal', 'high', 'urgent')),
  assigned_to     uuid references public.users(id), -- admin
  resolved_by     uuid references public.users(id),
  resolved_at     timestamptz,
  resolution      text,                        -- what was done
  admin_notes     text
);

create index if not exists user_reports_reporter_idx on public.user_reports (reporter_id);
create index if not exists user_reports_reported_idx on public.user_reports (reported_user_id);
create index if not exists user_reports_status_idx on public.user_reports (status);
create index if not exists user_reports_type_idx on public.user_reports (type);
create index if not exists user_reports_priority_idx on public.user_reports (priority);

-- Report messages (conversation between user and admin)
create table if not exists public.report_messages (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  report_id       uuid not null references public.user_reports(id) on delete cascade,
  user_id         uuid not null references public.users(id) on delete cascade, -- who sent it
  message         text not null,
  is_admin        boolean not null default false,
  read_by_user    boolean not null default false,  -- user has read
  read_by_admin   boolean not null default false   -- admin has read
);

create index if not exists report_messages_report_idx on public.report_messages (report_id, created_at);
create index if not exists report_messages_user_idx on public.report_messages (user_id);


-- ===== USER NOTIFICATIONS (enhanced from site_notifications) =====

create table if not exists public.user_notifications (
  id              uuid primary key default gen_random_uuid(),
  created_at      timestamptz not null default now(),
  user_id         uuid not null references public.users(id) on delete cascade,
  type            text not null check (type in (
    'tournament_registered', 'tournament_confirmed', 'tournament_reminder', 'match_ready', 'match_starting', 'match_result',
    'room_invite', 'room_joined', 'room_starting',
    'appeal_update', 'report_update', 'report_reply',
    'admin_message', 'system_announcement', 'coin_reward', 'level_up',
    'suspension_warning', 'suspension_appeal_result'
  )),
  title           text not null,
  body            text,
  data            jsonb not null default '{}',    -- action URLs, match IDs, etc.
  read_at         timestamptz,
  action_url      text,                           -- deep link for notification
  expires_at      timestamptz                     -- auto-hide after
);

create index if not exists user_notif_user_idx on public.user_notifications (user_id, created_at desc);
create index if not exists user_notif_unread_idx on public.user_notifications (user_id, read_at) where read_at is null;
create index if not exists user_notif_type_idx on public.user_notifications (type);
create index if not exists user_notif_expires_idx on public.user_notifications (expires_at);


-- ===== ROW LEVEL SECURITY =====

-- Users can read their own data, admins (service role) can read all
alter table public.users enable row level security;
alter table public.user_sessions enable row level security;
alter table public.facebook_oauth_states enable row level security;
alter table public.tournaments enable row level security;
alter table public.tournament_participants enable row level security;
alter table public.tournament_matches enable row level security;
alter table public.tournament_chat_messages enable row level security;
alter table public.private_rooms enable row level security;
alter table public.room_participants enable row level security;
alter table public.room_chat_messages enable row level security;
alter table public.suspension_appeals enable row level security;
alter table public.user_reports enable row level security;
alter table public.report_messages enable row level security;
alter table public.user_notifications enable row level security;

-- Revoke all from anon/authenticated (server-only access with service role)
revoke all on table public.users from anon, authenticated;
revoke all on table public.user_sessions from anon, authenticated;
revoke all on table public.facebook_oauth_states from anon, authenticated;
revoke all on table public.tournaments from anon, authenticated;
revoke all on table public.tournament_participants from anon, authenticated;
revoke all on table public.tournament_matches from anon, authenticated;
revoke all on table public.tournament_chat_messages from anon, authenticated;
revoke all on table public.private_rooms from anon, authenticated;
revoke all on table public.room_participants from anon, authenticated;
revoke all on table public.room_chat_messages from anon, authenticated;
revoke all on table public.suspension_appeals from anon, authenticated;
revoke all on table public.user_reports from anon, authenticated;
revoke all on table public.report_messages from anon, authenticated;
revoke all on table public.user_notifications from anon, authenticated;

-- Note: All access is via server-side PHP using service_role key
-- For real-time features, we'll use Supabase Realtime with RLS policies
-- that allow authenticated users to subscribe to their own data