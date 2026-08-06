# Kiln Timer

A phone-sized web app for standing in the kiln room and working out *when to press START*,
what preheat to program, and what to do at each stage of the firing.

Built for the Waterloo Potter's Workshop electric kilns — three computer kilns on
Bartlett V6-CF Series 700 controllers (badged Euclid Kilns) and the manual R-85 with an
LT-3K kiln sitter. Procedures come from the WPW Electric Kiln Manual, June 2022 rev.
January & July 2023.

## What it does

- **Plan** — tell it when you want the firing finished and how long the program takes;
  it gives you the preheat hold to type into `PRHT`, or tells you to press START later.
- **Timeline** — the whole firing as a band plus a list of every point of interest:
  the first hour you must be present, the preheat handover, the last two hours, CPLT,
  cooling, the earliest safe unload, and the end of your booking.
- **Booking deadline** — tell it when everything has to be unloaded and cleaned up
  (bookings run 48 hours, so usually 6 pm on the last day). It checks that the kiln has
  its 20 hours to cool before then, and tells you the latest the firing can finish.
- **Controller** — a reproduction of the panel that walks you through the firing
  keypress by keypress, lighting each key and showing what the display should read.
  The readout is a real 7-segment display drawn as SVG by `lib/led.php`:

      echo led_svg('IdLE');
      echo led_svg('3.20', ['digits' => 4, 'glow' => 1.3, 'align' => 'right']);

  Segments carry `data-digit` / `data-seg`, so JavaScript re-lights the same SVG by
  toggling a class — `led_masks($text)` gives the bitmask per digit, and the font table
  in `led_font()` stays the only place characters are defined.
- **Checklist** — the manual's loading / firing / turning off / unloading procedure,
  ticked off live and saved with the firing.
- **Firings** — every firing kept in a JSON file, filterable by person and kiln, each row
  editable and deletable. Once you have logged a few, the planner offers your own average
  program length instead of the manual's estimate.
- **Kilns** — a read-only view of the kiln manifest: controller, power switch, venting,
  quirks, plus the shared kiln-room notes.
- **People** — who can reserve and fire the kilns, with each person's firing count, hours
  fired, kilns used and last firing.
- **Kiln behaviour** — per kiln: firings, potters, last used, average length, and average
  drift against what was programmed. A kiln drifting steadily later is one whose elements
  are getting tired.

## Database

Storage is MySQL. Credentials are constants in `config/db.php` — copy
`config/db.example.php` to it on the server and fill in host, database, user and password.
That file is gitignored, so the password never enters the repository.

    cp config/db.example.php config/db.php

Then open `install.php`. It creates the schema and can import whatever is still in the old
`data/*.json` files. It is reachable without signing in **only until the first user exists**
— sign-in needs the `users` table, so it cannot be the gate on creating it — and requires a
login from then on.

Six tables:

    firings         one row per firing, with the planned/actual columns flattened
    firing_temps    hourly readings, FK to firings, ON DELETE CASCADE
    firing_checks   ticked checklist items and when, PK (firing_id, item)
    firing_cones    the witness cone pack, PK (firing_id, slot)
    users           Google profiles, unique on google_sub, linked to a roster person
    settings        name/value, one row per setting

`lib/store.php` assembles and disassembles the nested record shape the rest of the app uses,
so nothing above it changed in the move from JSON: same function names, same arrays.
`lib/db.php` holds the PDO connection and never throws — a database that is down means the
login page and installer still render and say why.

The manifests stayed as files on purpose. `config/kilns.json` and `config/people.json` are
configuration you edit on the server and read read-only in the UI, not app-written data.

## Text reminders

Optional, off until somebody asks for it. Each user is asked once, the first time
they sign in, and the answer is stored either way so it is never asked twice.

    cp config/sms.example.php config/sms.php

`SMS_PROVIDER` is `log` to begin with: messages are written to `data/sms.log` instead of
being sent, which is how you should run it until the wording and timing look right. Set it
to `twilio` and fill in the SID, token and from-number to send for real. `off` disables it.

Then a cron entry:

    */15 * * * * /usr/bin/php /path/to/kiln/cron.php >> /path/to/kiln/data/cron.log 2>&1

`cron.php` looks for firings whose next visit falls inside the next 24 hours and texts
whoever fired it — once per visit. "Once" is enforced by a unique key on
`(firing_id, visit, channel)` in the `reminders` table, claimed *before* the message is
sent, so running it more often, or twice at the same moment, cannot send twice. A failed
send is left recorded as failed rather than retried every quarter of an hour; clear the row
to try again.

`php cron.php --dry-run` prints what it would send without sending or recording anything.

## Signing in

Every page needs a Google account. Nothing — plan, firings, kilns, people, settings, or the
API — is reachable signed out. There is no allowlist: completing the Google flow makes you a
user of the app.

Set it up once:

1. Create an OAuth client at `console.cloud.google.com/apis/credentials`, type
   **Web application**.
2. Open `login.php` in a browser. It prints the exact **redirect URI** to paste into
   *Authorised redirect URIs* in the Google console.
3. `cp config/auth.example.json config/auth.json` on the server and fill in the client ID
   and secret. That file holds a secret and is gitignored.

Profiles land in the `users` table: Google subject id, email, name, picture. Sessions are PHP
sessions in an HttpOnly, SameSite=Lax cookie, marked Secure over HTTPS, with the session id
regenerated on sign-in.

### Who fired it

There is no roster to maintain — users are the only kind of person the app knows. But a
firing does not require one, because imported logs are full of people who will never sign in.
Each firing carries two columns:

    fired_by       users.id, or 0
    fired_by_name  a bare name, used when fired_by is 0

The user id wins when both are set, so linking an old record to a real account later is a
matter of setting `fired_by` — the name can stay where it is as a record of what the paper log
said. `firing_firer_name()` is the only place that resolves the two into something to display.

In the UI it is one control: a list of everyone who has signed in, plus "Someone else…",
which reveals a name box. The **Users** page lists accounts (sortable, paginated) with a
second table of names that appear in the log but have no account.

## Installing

Copy the whole folder into your web root. PHP 7.4 or newer, no dependencies, no build step.

The one requirement: the web server must be able to write to `data/`.

    chmod 775 data

`data/firings.json` is created on the first save. `data/.htaccess` blocks direct HTTP
access to it on Apache; on nginx, add a location block denying `/data/` or move
`DATA_FILE` (in `lib/config.php`) above the web root.

There is no login. Anyone who can reach the URL can read and edit your firings — put it
behind HTTP basic auth if that matters.

## Settings

The gear in the top right. Anything the app does arithmetic with is editable there and
stored in `data/settings.json`:

- **General** — time zone, default kiln and program, and the hour your unload deadline
  falls on (18 = 6 pm).
- **Preheat** — the ramp allowance and how coarsely the answer is rounded.
- **Presence and cooling** — the first-hour and last-two-hours rules, the 20–24 hour
  cooling window, and the glaze cone to warn above.
- **Program lengths** — the starting-point hours for the four Cone Fire programs.

Values are clamped to sane ranges on save and anything you have moved off its default is
flagged. "Reset to defaults" puts everything back; deleting `data/settings.json` does the
same thing.

`PREHEAT_OVERHEAD_HOURS` deserves a note: it is how long the preheat ramp to 93°C takes
before the hold you programmed starts counting. The manual's rule of thumb is about 1
hour; the arithmetic from a 21°C room at 60°F/hr is closer to 2h10m. If firings keep
finishing late, raise it. It is also editable per-plan in the planner form.

## Things you will want to change in code

`lib/config.php` — only the three path constants (`APP_NAME`, `DATA_FILE`,
`SETTINGS_FILE`) now live here; the rest are `define()`d from the settings file. Add a
setting by adding a row to `settings_spec()` in `lib/settings.php` and a `define()` here.

## The manifests

Two hand-edited JSON files describe your studio. Both are read by the app and shown
read-only in the UI — they are deliberately not editable from a phone.

**The live files are not in this repository.** Only `config/kilns.example.json` and
`config/people.example.json` are tracked. On the server, copy each to its live name once:

    cp config/kilns.example.json  config/kilns.json
    cp config/people.example.json config/people.json

and edit the live copies. The app reads the live file if it exists and falls back to the
example otherwise, saying so in the UI. This matters: if the live files shipped with the
code, every upload would overwrite whatever you had edited on the server. `data/` works
the same way for the same reason.

`config/kilns.json` — one entry per kiln: name, kind (`computer` or `manual`), blurb,
controller, power switch, venting, quirks, notes, plus blank placeholders for the things
you can only check at the kiln (make, chamber size, max cone, shelves, posts,
thermocouples). Blank fields are hidden rather than shown empty. **Any extra field you add
appears on the Kilns page automatically**, with its key tidied into a label; add a kiln and
it appears in the planner too. There is a `studio` block for kiln-room-wide notes.

`config/people.json` — who can reserve and fire. Keep each `id` stable: firing records
store it, and renaming one orphans past firings (they still display, marked as no longer
listed). `can_fire` means approved to fire independently, `can_supervise` means they can be
named as the supervising member on someone else's firing, and `active: false` retires
someone without losing their history.

If either file is missing or malformed the app falls back to built-in defaults and says so
in a banner rather than breaking.

`lib/data.php`

- `controller_keypad()` — the key grid. Rows of keys, each with an `id` and a `label`.
  Rearrange to match the real panel; the walkthrough follows automatically.
- `speed_keys()` — which key selects each Cone Fire speed. Confirmed from photographs in
  `resources/`: the panel has four dedicated buttons down the right-hand side.
- `program_list()` — program lengths and default cones.
- `cone_table()` — cone temperatures in °C.
- `checklist_computer()` / `checklist_manual()` — the checklists. Item `id`s are the
  storage keys, so renaming an id loses that tick on old records; changing `text` is safe.

## Files

    index.php        the planner: form, answer, timeline, controller walkthrough
    firing.php       one firing record: checklist, actual times, cone packs, temps
    firings.php      history and per-program averages
    kilns.php        read-only kiln manifest
    people.php       read-only roster, with each person's firing history
    settings.php     the settings panel
    config/          the hand-edited manifests: kilns.json, people.json
    lib/config.php   paths, and the constants built from the settings file
    lib/settings.php setting definitions, defaults, validation, storage
    lib/led.php      the 7-segment LED readout, drawn as inline SVG
    lib/data.php     manual content: kilns, programs, cones, keypad, checklists
    lib/timing.php   the preheat arithmetic and the timeline
    lib/store.php    JSON storage — swap for SQL later without touching anything else
    lib/helpers.php  formatting
    partials/        header, footer, timeline
    assets/          one stylesheet, one script

## Moving to a database later

Everything touching storage is in `lib/store.php`: `store_load`, `store_save`,
`firings_all`, `firing_get`, `firing_create`, `firing_update`, `firing_delete`, plus
`firing_history_estimate`. Reimplement those against PDO and the rest of the app does not
know the difference.
