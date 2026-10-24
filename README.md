# OSAS Venue Reservation System

A combined system for reserving and managing campus venues:

- **`public/`** — the student-facing site. Sign up, verify your email with an
  OTP, sign in, browse venues, and submit reservation requests.
- **`admin/`** — the Vice Admin panel. Approve new student accounts, vote on
  reservation requests (majority decides), manage venues/users, and view
  reports.

Both apps now share **one database** (`osas_db`), which is what actually ties
them together — a student's signup and an admin's approval are two steps of
the same flow, not two disconnected systems.

## How the two sides connect

1. A student signs up on `public/signup.php` and verifies their email with a
   6-digit code (`public/verify.php`). Their account is created with
   `status = 'pending'`.
2. That account immediately shows up on the admin panel's **Pending
   Account** page (`admin/index.html#accounts`).
3. Once a Vice Admin approves it, the account's `status` becomes `active`
   and the student can sign in. If rejected, they can't sign in and are
   told to contact OSAS.
4. Once signed in, the student submits a reservation request
   (`public/student.php` → **New Reservation**), which lands in the admin's
   **Approval Queue** with `status = 'pending'`.
5. Admins vote to approve or decline (majority of all admin accounts
   decides — see `admin/README` notes below). The result, and any
   "marked completed" action, is written back as a **notification** the
   student sees on their own **Notification** tab.
6. Borrowable resources (chairs, tables, fans, etc.) are managed from the
   admin's **Inventory** page (`admin/index.html#inventory`) — add a
   resource with its stock count, edit it, or delete it. Whatever's listed
   there is exactly what shows up in the dropdown on the student's **New
   Reservation** form. Picking one there pops up a "how many?" prompt
   capped at the current stock; the chosen resources are submitted with
   the reservation and their stock is deducted immediately
   (`public/api/reservation_create.php`, inside a DB transaction so two
   students can't both over-borrow the last few units).

## Setup (XAMPP / MAMP / WAMP or any PHP+MySQL host)

1. Copy this whole folder (`osas-system/`) into your server's web root, e.g.
   `C:\xampp\htdocs\osas-system\`.
2. Start Apache and MySQL.
3. Open `http://localhost/phpmyadmin` → **Import** → choose
   `database/osas_db.sql` → Go. This creates the `osas_db` database with
   both apps' tables and some sample data (see below).
4. Both `public/config.php` and `admin/config/db.php` default to
   `host=localhost` (or `127.0.0.1` for admin), user `root`, no password —
   the standard XAMPP defaults. If your MySQL differs, edit those two files,
   or set `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` as environment
   variables (both files read the env vars first).
5. **Email (OTP) setup — required for signup/password reset to actually
   send codes.** Set these environment variables on your server (never hard-
   code real credentials into the PHP files):
   - `MAIL_USER` — a Gmail address with an **App Password** created at
     https://myaccount.google.com/apppasswords (requires 2-Step
     Verification to be turned on first)
   - `MAIL_PASS` — that 16-character app password
   - Optionally `MAIL_FROM_NAME` (defaults to "OSAS")

   If these aren't set, `verify.php` shows a generic "we couldn't send that
   email" message instead of ever exposing a code, and the real SMTP error
   is written to your PHP error log (look for `[send_otp_email]`).
6. **File uploads.** `public/uploads/` has to be writable by PHP. On XAMPP for
   Windows this already works with no extra setup; on Linux/macOS run
   `chmod -R 775 public/uploads`. Don't delete the `.htaccess` in that folder —
   it's what stops uploaded files from being downloadable by guessing a URL.
   If students will attach several large files at once, raise
   `upload_max_filesize` and `post_max_size` in `php.ini` (the system already
   detects and explains the "body too large" case, but it can't lift the limit
   for you).
7. Visit `http://localhost/osas-system/public/login.php` for the student
   site, and `http://localhost/osas-system/admin/login.html` for the admin
   panel.

## Demo logins (from the sample data in `database/osas_db.sql`)

| Portal  | Username / Email               | Password        | Notes |
|---------|---------------------------------|-----------------|-------|
| Admin   | `admin`                         | `admin123`      | Office: Admin |
| Admin   | `academic`                      | `admin123`      | Office: Academic Affairs |
| Admin   | `osas`                          | `admin123`      | Office: OSAS |
| Student | `kirits@ucc.edu.ph`             | `Student123!`   | Already approved — can log in right away |
| Student | `vicentetulog@gmail.com`        | `Student123!`   | Seeded as **pending** — try logging in before/after approving it from the admin panel to see the flow |

Change these before putting the system anywhere near production traffic.

## Majority voting (Approval Queue)

There are 3 seeded admin accounts. Each gets one vote per reservation; a
simple majority of *all* admin accounts decides (3 admins → 2 votes, 5 → 3,
etc. — this is computed automatically, so adding more admins to the
`admins` table doesn't require a code change). Once decided, voting locks.
Go to **Settings → Office** in the admin panel to relabel an account as
"Vice Academic Affairs" / "Vice OSAS" / etc.

## Requirements, auto-completion and post-event reports

Three things were added on top of the combined system.

### 1. Requirement files are mandatory on a reservation

A student can no longer send a reservation with nothing attached. The **New
Reservation** form has a file container (click or drag-and-drop) that accepts
both documents and pictures — letter of request, concept paper, venue layout,
poster mockups — and each file can be given a short label so OSAS knows what
it's looking at.

- Accepted: JPG, PNG, WEBP, GIF, PDF, DOC, DOCX, XLS, XLSX, TXT
- Limits: 10MB per file, 10 files per request
- Files are validated **by sniffing their actual bytes** (`finfo`), not by the
  filename or the type the browser claims — both of which are trivially faked.
- They're written to `public/uploads/requirements/` under a random filename,
  and that folder's `.htaccess` blocks direct URL access. The only way to read
  one is through `public/api/attachment.php` (student — own files only) or
  `admin/api/attachment.php` (staff), both of which check the session first.
- If the database transaction fails for any reason, the files that were just
  written are deleted again, so disk and database can't drift apart.

On the admin side, a **Requirements** column appears on the Dashboard, the
Approval Queue and All Reservation. Clicking it opens a viewer with a picture
grid (click any picture to enlarge) and a list of documents to open. Students
can see their own attachments again from the **Files** button on My
Reservation.

### 2. Events complete themselves; the student files the report, OSAS reviews it

Once a reservation's end time has passed, its status flips from `approved` to
`completed` on its own and the student is notified. There's no cron job in
this project, so the sweep (`shared/events.php`) runs whenever a page that
shows reservation statuses is loaded — one indexed query, always correct by
the time anyone looks. "Mark done" is still there as an early override.

A **green `!` button appears in the Report column** on My Reservation for any
finished event the student hasn't reported on yet. Clicking it opens the
post-event report form — filled in by the **student**, not OSAS:

- actual attendance, prepared by, condition of the borrowed resources,
  condition of the venue
- summary of the event, issues encountered, recommendations
- a picture container for event documentation — **at least one photo is
  required**, each with an optional caption

Submitting sets the report to **pending review** and notifies nobody but the
student ("awaiting OSAS review"). On the admin side the same reservation now
shows a green `!` meaning *a report is waiting for you*, which opens the
review modal. A Vice Admin can:

- **Approve** it — the report locks, the student sees "Approved", and it
  counts as documented on the Reports page
- **Request revision** with remarks — the report goes back to the student,
  who sees the remarks in red on My Reservation with their previous answers
  pre-filled, corrects them and resubmits. Photos already uploaded are kept,
  so they only need to add anything that was missing.

A resubmission keeps the same report number — it's the same report, corrected
— and drops back into the review queue. Approved reports can't be edited
again by either side.

Every ownership check is part of the query itself (`WHERE ... AND student_id = ?`),
so there's no path by which one student can read or submit against another's
reservation.

> **Timezone note.** The sweep compares against an explicit `Asia/Manila`
> timestamp rather than MySQL's `NOW()`. It has to: `public/config.php`
> deliberately forces PHP and its MySQL session to UTC (so OTP expiry maths
> can't drift), while the admin panel uses the server default — comparing
> against the database clock would let the two portals flip a reservation to
> Done up to 8 hours apart. Set `APP_TIMEZONE` if the campus isn't in Manila.

### 3. Reports page = the post-event report register, exported as PDF

The Reports page was rebuilt around post-event reports rather than raw
reservations:

- headline figures (reports filed, awaiting report, total attendance, photos)
- a **reporting completion** card — how many finished events are documented
- an **Awaiting a Report** table you can file from directly, with events
  overdue by more than a week flagged in red
- reports per venue, and the full register with resource/venue condition pills

**Export is now PDF instead of CSV.** Two flavours:

- one report — `Download PDF` inside the report viewer, or the `PDF` link in
  the register row: event details, the full narrative, and the documentation
  photos laid out two per row with captions
- the whole filtered register — the `⤓ Export PDF` button: summary figures,
  reports-by-venue chart, the register table, and the list of events still
  awaiting a report. Tick *"Include full reports & photos"* to append every
  report in full.

The PDF is produced by `admin/includes/pdf.php`, a small writer built for this
project. **It has no dependencies** — no Composer, no vendored library, no
internet — because this system gets dropped into an `htdocs` folder and
imported through phpMyAdmin, and a library would have to be vendored in
anyway. It uses the core Helvetica faces with real glyph metrics for word
wrapping, breaks tables across pages while repeating their header row, and
embeds photos as JPEG. PNG/WEBP/GIF are converted by GD on the way in, which
also flattens transparency onto white so pictures don't come out with black
backgrounds. If GD isn't available the report still exports, with the photos
replaced by an "image unavailable" placeholder.

### New tables

| Table | What it holds |
|-------|---------------|
| `reservation_attachments` | requirement files/pictures per reservation (metadata; bytes on disk) |
| `post_event_reports` | one report per finished reservation |
| `post_event_photos` | documentation pictures per report |

**Already have data you don't want to lose?** Import
`database/migration_requirements_post_events.sql` instead of re-importing the
whole schema — it only adds the three tables. Starting fresh? Re-importing
`database/osas_db.sql` already includes them.

## Double-booking protection, reschedule requests and event history

**Upgrading an existing install?** Import `database/migration_reschedule_history.sql`
once in phpMyAdmin (it keeps all your data). Fresh installs: import your
`osas_db.sql`, then that migration. Until it is imported the student portal
still works, but Reschedule / Cancel are disabled and the admin
*Reschedule Requests* page shows a setup message.

### Double booking
- A slot is **blocked** by any `pending` or `approved` reservation at the same
  venue with an overlapping time. Back-to-back bookings (one ends when the
  next starts) are allowed. Declined, cancelled and completed reservations
  never block a slot but are never deleted.
- **Backend** (`shared/scheduling.php`, used by both portals): the check runs
  again inside the database transaction after locking the venue row, so two
  students submitting the same slot at the same moment cannot both succeed.
  It guards new reservations, reschedule approval, accepting a suggestion,
  and the admin's *Approve* vote on a reservation.
- **Frontend**: the New Reservation form shows a hourly slot grid for the
  chosen venue/date. Booked and Pending slots are disabled and labelled; typing
  a time that overlaps shows a conflict message immediately, and submitting is
  blocked.
- Automatic suggestions (other times the same day, the same time on nearby
  days, other venues at the same time) appear whenever a time is unavailable.
  Hours used for the grid/suggestions default to 07:00–21:00
  (`SCHED_OPEN` / `SCHED_CLOSE` environment variables). Bookings outside
  those hours are still accepted, as before.

### Calendar status
Student **Calendar** tab: pick a venue to colour each day *Available*
(nothing booked), *Pending* (only pending requests) or *Booked* (has an
approved booking). Select a day to see its slot grid, or "Book this date".
With "All venues" the day panel lists each venue's status. The admin
**Schedules** page gained a venue filter and a ↻ marker on rescheduled events.

### Rescheduling (student request → admin decision)
1. Student: *Upcoming Events* (or *My Reservation*) → **Request Reschedule**
   with a new date/time and a reason. If the time is taken they can pick an
   available alternative or send the request anyway.
2. Admin: **Reschedule Requests** → **Approve**, **Reject** (reason required)
   or **Suggest** another available schedule. Approve is refused by the server
   if the time is still taken. A single admin decides (no majority vote).
3. Approved → the reservation's date/time is updated and the original
   schedule is written to `reservation_schedule_history`. A *suggested* time
   only applies if the student accepts it.
Nothing is ever rescheduled automatically.

### Upcoming Events / Past Events
- **Upcoming Events**: your pending/approved events that haven't finished.
- **Past Events**: completed, cancelled and rescheduled events with search and
  outcome/venue filters. Rescheduled events show **Original** and **New**
  schedules; the details popup shows the full chain.
- Students can now **cancel** a pending/approved reservation. It is kept
  (status `cancelled`), its borrowed resources go back into stock, and it shows
  under Past Events.

### New files
`shared/scheduling.php`, `public/api/availability.php`,
`public/api/reschedule_request.php`, `public/api/reservation_cancel.php`,
`admin/api/reschedules.php`, `database/migration_reschedule_history.sql`.

## What changed when the two systems were combined

- **Inventory & resource borrowing (new).** Vice Admins manage a list of
  borrowable resources (name + stock) from the new **Inventory** page.
  Students pick from that same list on **New Reservation**, are prompted
  for a quantity capped at current stock, and stock is deducted the moment
  they submit — see `resources` / `reservation_resources` in
  `database/osas_db.sql`, `admin/api/resources.php`, and the resource
  picker in `public/student.php` + `public/api/reservation_create.php`.
- **One database, one set of tables.** The old `app_db` and `vice_db` had
  different, incompatible shapes for `users`, `venues`, and `reservations`.
  They're now unified — see `database/osas_db.sql` for the full schema.
- **Signup → approval → login is now a real, connected flow.** Previously,
  the student site's signup+OTP flow and the admin panel's "Pending
  Account" queue were two separate features from two separate apps that
  didn't talk to each other. Now a self-registered student genuinely can't
  log in until a Vice Admin approves them.
- **Notifications actually get written now.** The `notifications` table
  existed before but nothing ever inserted into it. Reservation decisions,
  completions, and account approvals/rejections now notify the student.
- **Fixed a real double-booking gap.** Nothing previously stopped two
  overlapping reservations from being submitted for the same venue and
  time; `api/reservation_create.php` now checks for conflicts.
- **Admin-set venue status is now enforced.** A venue an admin marks
  "Under Maintenance" is excluded from the student's booking dropdown
  (previously that status was set from the admin side but never read
  anywhere else).
- **Removed a hard-coded, leaked Gmail app password** that was committed
  in `includes/mailer.php`. Mail credentials must now be supplied via
  environment variables, and the app fails with a clear message if they're
  missing instead of silently trying to authenticate with a real secret
  that shouldn't have been in source control.
- **The Profile "Save Changes" button now actually saves**, via a new
  `public/api/profile_update.php` endpoint (it was a placeholder `alert()`
  before).
- **Consistent status naming.** The student portal used `rejected` for a
  declined reservation while the admin portal used `declined` for the same
  concept; everything now uses `declined` (`rejected` is reserved for
  account-approval decisions specifically, which is a different thing).
- **Small responsive/styling fixes**: reservation status pills for
  "approved"/"declined"/"completed" had no CSS color rules on the student
  calendar (only "pending"/"confirmed" did); the dashboard's stat-card grid
  now uses `auto-fit` so it doesn't break when a 5th card (Pending
  Accounts) was added.
- Both apps' security practices (PDO prepared statements everywhere,
  bcrypt password hashing, output escaping, session-based auth) were kept
  as-is — they were already solid.

## Project structure

```
osas-system/
├── database/
│   ├── osas_db.sql          Full schema + sample data — import this ONE file
│   └── migration_requirements_post_events.sql
│                            Adds only the 3 new tables, for existing installs
├── shared/                  Used by BOTH portals, so they can't disagree
│   ├── uploads.php          File validation + safe storage + streaming
│   └── events.php           Auto-completion rule, report numbering, labels
├── public/                  Student portal
│   ├── config.php
│   ├── includes/            db.php, functions.php, mailer.php
│   ├── api/                 reservation_create.php, profile_update.php,
│   │                        attachment.php
│   ├── uploads/             Runtime file storage (must be writable)
│   │   ├── requirements/    Requirement files + pictures
│   │   ├── post-events/     Event documentation photos
│   │   └── .htaccess        Blocks direct URL access to both
│   ├── login.php, signup.php, verify.php, forgot-password.php,
│   │   reset-password.php, student.php, dashboard.php, logout.php, index.php
│   └── pictures/
└── admin/                   Vice Admin panel
    ├── config/db.php
    ├── includes/pdf.php     Dependency-free PDF writer
    ├── api/                 auth.php, dashboard.php, accounts.php,
    │                        reservations.php, venues.php, resources.php,
    │                        users.php, reports.php, settings.php,
    │                        requirements.php, post_events.php,
    │                        attachment.php, report_pdf.php, _bootstrap.php
    ├── assets/css/style.css, assets/js/{api.js,app.js}
    └── index.html, login.html
```

## Ideas for next steps (not implemented here)

- "Remember me" on the student login is currently just a checkbox with no
  backing logic — could be wired up with a long-lived, hashed remember
  token.
- Email notifications (not just in-app ones) when a reservation is decided.
- Admin ability to edit/cancel a reservation on a student's behalf.
- Self-service password change from the student Settings tab.
