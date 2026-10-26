# Latest update: transaction workflows

The photographed review feedback is implemented. See [database/FEEDBACK_RELEASE.md](database/FEEDBACK_RELEASE.md) for behavior, database relationships, installation and validation.

# Current release: reliable database updates, desk review, and catalog search

Read [database/SETUP.md](database/SETUP.md) for installation, updates, backups, and isolated tests. Run `php database/setup.php --status` to check migrations, then `php database/setup.php` to apply pending updates.

**The historical installation instructions below are superseded.** The current application uses `user_account`, not separate `login`/`staff`/`member` tables. Do not import a full SQL snapshot over existing records, and do not run tests against the library database.

---

# Bibliophilia — Library Management System

A complete PHP + Bootstrap admin system built on your **updated**
`library_management` schema (the one with the dedicated `login` table
and the Borrow Request table). Tested end-to-end on PHP 8.3 +
MariaDB 10.11 — login, CRUD, multi-copy loans, partial returns,
auto-generated fines, payments, Borrow Requests, reservations, and reports all
verified working.

## 1. Requirements

- PHP 8.1+ with `pdo_mysql` extension enabled
- MySQL 5.7+ or MariaDB 10.4+
- Any web server (Apache/XAMPP/WAMP/Nginx) or `php -S` for local testing

## 2. Installation

1. **Create the database and import the schema**, in this order:
   ```
   mysql -u root -p -e "CREATE DATABASE library_management CHARACTER SET utf8mb4;"
   mysql -u root -p library_management < database/library_management_original.sql
   mysql -u root -p library_management < database/setup_additions.sql
   ```
   - `library_management_original.sql` is your schema, **completely
     unchanged** — no structural modifications were needed this time,
     since it already includes a `login` table for authentication.
   - `setup_additions.sql` only **seeds** starter data: roles, a
     default admin account (in both `staff` and `login`), sections,
     categories, publishers, book prices, and policies, so the system
     is usable immediately.

2. **Configure the database connection** in `config/db.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'library_management');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

3. **Copy the project folder** into your web server's document root
   (e.g. `htdocs/library_management` for XAMPP), or run it locally with:
   ```
   php -S localhost:8000
   ```

4. **Log in** at `login.php`:
   - Email / Username: `admin@library.local`
   - Password: `admin123`

   Change this password from the **Staff** page after your first login.

## 3. How the schema is used

### Authentication — the `login` table
Your schema already separates credentials from identity: `login` holds
`Username`, `PasswordHash`, `UserType` ('Staff' or 'Member'), and a
nullable FK to either `staff` or `member`. This UI only authenticates
`UserType = 'Staff'` accounts (the member side is left for a future
member-facing portal, if you build one).

- Creating a staff member in the **Staff** page also creates a matching
  `login` row (Username = the staff email).
- Editing a staff member keeps `login.Username` in sync with their email.
- Each staff row has a lock/unlock button that flips `login.IsActive`
  — a disabled account can't sign in but the staff record stays intact.
- Deleting a staff member removes their `login` row automatically via
  the schema's `ON DELETE CASCADE` foreign key — nothing extra needed.

### Borrow Requests and reservations — `borrow_request`
Both live in one table (`borrow_request` + `borrow_request_detail`, formerly
`loan_request*`). `RequestType` is fixed when the request is created:
`Borrow` (a copy was on the shelf) or `Reservation` (queued for an unavailable
book). Only `ApprovalStatus` changes: Pending → Approved (copy held) →
Fulfilled (released as a loan), or Rejected / Cancelled / Expired.
`DecisionReason` records why a request was rejected or cancelled.

## 4. What's included

- `login.php` / `logout.php` — staff authentication via the `login` table
- `index.php` — dashboard (summary cards + recent activity)
- `books.php` — book/title catalog with readable lookups and multi-author support
- `book_copies.php` — individual physical copies, accession numbers, status, donor linkage
- `authors.php`, `categories.php`, `publishers.php`, `sections.php`,
  `book_prices.php`, `policies.php`, `donors.php`, `roles.php` — master data
- `members.php` / `member_view.php` — member records and full
  borrowing/reservation/fine history
- `staff.php` — staff accounts + their `login` credentials (see above)
- `borrow_requests.php` — Borrow Requests and the reservation queue, grouped by borrower (View Books → approve & hold, reject with reason, cancel, release — one book at a time)
- `loans.php` — multi-copy loan transactions with live availability checking
- `returns.php` — **partial returns**: some copies can be returned while
  others stay borrowed; the loan is Partial until every copy is back,
  then Complete
- `fines.php` / `payments.php` — fines auto-generate on return (overdue
  penalty from the book's policy rate × days late; damage/loss charges
  from the Book Prices table) and can be paid off, partially or in full
- `reports.php` — book, member, loan, reservation, financial, and donation reports

## 5. Design notes

- A **Book** is the catalog/title record; a **Book Copy** is the actual
  physical item with its own accession number and status — preserved
  everywhere in the UI.
- Foreign keys are always displayed as readable names, never raw IDs.
- Donor information lives on the physical copy (`book_copy.DonorID`).
- **No schema changes at all** were required for this version — your
  updated dump is imported completely as-is.

## 6. Default data seeded by `setup_additions.sql`

| Table      | Seeded rows |
|------------|-------------|
| `role`     | Admin, Librarian, Assistant |
| `staff` + `login` | 1 admin account (admin@library.local / admin123) |
| `section`  | Reference, Fiction, Science, Computer, History |
| `category` | Science, Mathematics, Information Technology, Fiction, History |
| `publisher`| Penguin Books, O'Reilly Media, Pearson Education, McGraw-Hill |
| `book_price` | New (₱500), Good (₱400), Fair (₱250), Damaged (₱100) |
| `policy`   | Standard Circulation, Reference Only, Reserve / High Demand |

All of this can be edited or deleted from the UI once you're logged in.


## 7. Borrow Request update (upgrade notes)

**Before first use, back up the database and update it once.** Until you do, the
app shows a "Database update needed" page.

- **XAMPP / phpMyAdmin (easiest):** open phpMyAdmin, select `library_management`,
  choose *Import*, and import `database/migrate_borrow_request.sql` (run it once).
- **Command line:** `C:\xampp\php\php.exe database\migrate_borrow_request.php`
  (or `php database/migrate_borrow_request.php`) — safe to run repeatedly.

Both do the same thing. It renames the request tables, changes request type
`Loan` → `Borrow`, adds `borrow_request.DecisionReason`, adds indexes and a
UNIQUE index so a loan item can only be returned once, then prints a read-only
consistency report. Nothing is deleted.

Workflow: member submits a Borrow Request (or Reservation if no copy is on the
shelf) → staff approve (a copy is *held*, status Reserved) or reject (reason
required) → staff **release** the copy from *Borrow Requests → Ready for
Release*, which is when the loan and due date are recorded → return.
Reservation-queue entries are offered returned copies automatically, oldest
first. Holds not collected in time expire (24 h Borrow, 48 h Reservation;
constants in `includes/functions.php`).

Automated check against a throwaway database: `bash tests/workflow.sh`.

## 8. Borrow Requests screen — grouped by borrower, decided book by book

No database change is needed. Every book a member requests is already its own row in
`borrow_request` (+ one `borrow_request_detail` row), each with its own `ApprovalStatus`.
The screen only **groups those rows by borrower for display**.

- Main table: **Borrower · Total Books · Borrow Date · Status · View Books**. A borrower with several
  books appears once. *Status* is the overall state (Pending if anything still needs a decision, then
  Approved, otherwise the common status) with a small breakdown such as "1 approved · 1 rejected".
- **View Books** expands that borrower's list: title, request #, borrow date, due date (the real date once
  released, otherwise "set on release (N days)"), the book's own status, and its own **Approve / Reject**
  (or Release / Cancel) buttons. The list stays open after an action so the next book can be handled.
- Approving or rejecting a book changes **only that one request**. For speed, an open borrower with 2+ approvable books also has **Approve all N books**: it approves those books one by one, each as its own record with its own copy and status (a book with no copy on the shelf is skipped and reported). The bulk "Approve selected / Approve all /
  Reject selected" controls and their server actions were removed; `approve`, `reject` and `cancel`
  are the only POST actions and each takes a single `request_id`.
- Search (borrower, card no., book title), request-date range and status filter (History / All tabs);
  the tabs are the quick status filters. Pagination counts borrowers, not books.
- Responsive: on phones each borrower becomes a card and each book a stacked card with full-width buttons.

## Borrowing limit & alerts (Browse Books / Dashboard)

- **Limit:** `policy.MemberMaxBooks` (default **3**). Admins change it under **Policies → Borrowing Limits**.
- **What counts:** books currently out and unreturned (returns free a slot immediately). Open requests
  (Pending / Approved) also reserve a slot, as the existing request rules already required; they are shown
  separately as "Pending Requests", never as "borrowed".
- `member_borrow_status()` (includes/functions.php) is the single source for the summary bar, the amber
  "Almost at Your Borrowing Limit" notice, and the red "Borrowing Limit Reached!" alert.
- At the limit, cards show a **Limit Reached** button that opens the alert instead of the request dialog; the server
  still rejects any submission (`create_borrow_request` re-checks inside a locked transaction).
- `member_borrow_status.php` returns the status as JSON; the Browse Books page polls it every 30 s and on tab focus,
  so the summary and buttons update after a staff return or decision without a reload.
- Books the member already holds or has requested show "You Have This" / "Already Requested" instead of a request button.

## Capitol University procedure: entry scan → librarian permission → use / borrow record

Based on the librarian interview: students scan their school ID on entry, and must ask the librarian before using or borrowing a book.

1. **Library entry** — `entry.php` (entrance screen, ID scanner = keyboard input). Verifies the ID belongs to a registered, active, verified member and
   logs date/time in `library_entry`. Unregistered / inactive IDs are turned away and logged. Staff view: **Library Entry** (`library_visits.php`):
   inside now, entry log, manual entry, check-out.
2. **Asking permission** — `kiosk.php` (student picks the exact copy and chooses *Read here* or *Borrow*) or the librarian enters it at the desk.
   This only creates a **pending** `use_request`; nothing is reserved, started or borrowed. Kiosk requests need an entry scan for today
   (`KIOSK_REQUIRE_ENTRY` in `config/kiosk.php`).
3. **Librarian decision** — **Librarian Desk** (`use_requests.php`, Admin/Librarian only). Shows the student (status, entry time, books in use,
   borrowing limit, fines) and each copy (live availability, library-use-only flag); each book is approved for **in-library use** (creates the
   `in_library_use` record — no loan, no due date), approved for **borrowing** (creates a loan with the policy's due date, enforcing the existing
   borrowing limit / fines / verification rules), or **rejected with a reason**. Any failed rule cancels the whole decision and nothing changes.
4. **Records** — `in_library_use.php` (+ history), `loans.php`, `returns.php` keep working; Decided tab on the desk keeps the audit trail.

**Database:** back up, then import in order `database/migrate_self_service_kiosk.sql` and `database/migrate_entry_and_permission.sql`.
**Settings:** `config/kiosk.php` (kiosk/gate on-off, allowed device IPs, idle reset, lockout, reading areas, require-entry).
**Test:** (1) open `entry.php`, scan `LIB-2026-00010` → welcome + time; scan again → "already checked in"; scan `XXX` → turned away.
(2) `kiosk.php` → same ID → search `BK-00004-01` → *Read here* → Send → reference UR-xxxxx, *not approved yet*. (3) Librarian Desk → verify student/book →
approve "read in library" → appears in In-Library Use. (4) Repeat with *Borrow* → loan with due date appears in Loans. (5) Reject with a reason → shown in Decided.
(6) Kiosk without an entry scan → asked to scan in first.

### Entrance screen — camera scanning
`entry.php` reads the barcode or QR on the school ID with the device camera (library: html5-qrcode 2.3.8 from jsDelivr, using the browser's native
detector when available). Video is processed on the device only; just the decoded ID text is sent for the usual server check.
**Camera access requires HTTPS or `localhost`** — over plain `http://192.168.x.x` browsers block it and the page falls back to the handheld-scanner / typing box.
Turn it off with `ENTRY_CAMERA_ENABLED` in `config/kiosk.php`.

## Student ID and the entrance QR

Students enter their **Student ID** (e.g. `02-2425-014468`) when they register. It is stored as the
membership number, shown as the QR code on **My Student Card**, and scanned at `entry.php` to record attendance
(time-in only, once per day). Open `entry.php` over **HTTPS** on the phone/laptop camera. Staff can correct a
student's ID in Students → Edit. Accounts created earlier keep their old `LIB-…` number until edited.

## Librarian desk form (`use_requests.php`)

How it works at the desk, matching the Capitol University procedure:

1. The student takes the book(s) they want, goes to the librarian and **hands over their ID**.
2. The librarian scans or types the ID. The screen shows who the student is, whether they scanned in today
   (with a **Record entry now** button if they did not), how many books they have out, and whether they may borrow.
3. The librarian scans each book's accession number. For every book the screen shows whether it can be borrowed,
   why not if it cannot, and the loan period and **due date**.
4. The librarian chooses **Approve** or **Reject** (with a reason) per book and presses **Confirm decisions**.
   Nothing is saved before this step. Approved books are recorded as a loan with the due date; rejections keep
   their reason. Everything is saved together or not at all.
5. A slip appears with the result and due dates (printable); **Next student** starts a fresh form.

Requests that students send from the kiosk still appear under *Waiting for decision* and are decided the same way.
Scanning an ID never approves a book — only the librarian's decision does. Only Admin and Librarian roles can decide.


## In-Library Desk — form + ID custody (`desk_form.php`)

Follows the library's real in-library procedure: the student goes to the librarian's desk, hands over an ID and fills in a form;
**the librarian keeps the ID until the book is returned, then gives it back.** The system records every step and removes the paper form.

**Database:** back up, then import `database/migrate_id_custody.sql` (after `migrate_self_service_kiosk.sql`). New table `in_library_form`
(one row per desk visit) plus `ConditionOut` / `ConditionIn` on `in_library_use`. Nothing is dropped. `IN_LIBRARY_USE_ENABLED` is now `true`.

**At the desk (Circulation → In-Library Desk)**
1. Scan or type the Student ID. The screen shows who it is, membership status, entrance scan today, unpaid fines and any open form.
   The librarian ticks *“I compared the photo and name on the ID with the person at the desk”* (required). Visitors: name, ID type and ID number.
2. Scan each book's accession number (or search by title). Only available copies can be added. Mark the book's condition.
3. Purpose, reading area, optional note.
4. Student accepts the rules (`DESK_RULES` in `config/kiosk.php`). The next free **ID slot** is suggested; one student cannot have two open forms.
5. **Issue form & hold ID** → copies become *In Use*, the ID is logged as held in that slot, and a printable slip opens (student signature line).

**Giving books back:** the **IDs in the librarian's custody** list shows every held ID with its slot, books, live duration and flags
(*Long session*, *Past closing* — closing time is `DESK_CLOSING_TIME`). *Return books* takes books back one by one with their condition;
the **ID can be handed back only when every book is returned**. A damaged or lost book needs a note first, and is not put back on the shelf.
Every issue, return and ID hand-back is saved with who did it and when (also in the Activity Log). **Form history** lists all closed forms.

**Who can do what:** only Admin / Librarian can issue a form (they grant permission); Admin / Librarian / Assistant can take books back and hand IDs back.
The kiosk and Librarian Desk pages still work; sessions they create simply have no ID slot.

**Also fixed:** `start_in_library_session()` returned a record id of 0 (read after a later query); it now returns the real id.

**Test:** `SQL=/path/library_management_FIXED_v27.sql bash tests/desk_form_test.sh` (throwaway database; 36 checks).


## Borrow Desk (formerly "Librarian Desk") and the keep-a-copy rule

**Purpose.** The Borrow Desk (`use_requests.php`) is where the librarian decides about *taking a book home*. It is the only place a
librarian approves or rejects borrowing at the counter; scanning an ID never approves a book. Two ways in:
1. **Student at the desk** — scan the ID, scan each book, press Approve / Reject per book, then *Confirm decisions* (one save, all or nothing).
2. **Waiting for decision** — requests students sent from the kiosk, decided the same way.

Reading a book *inside* the library is a different job and has its own page, **In-Library Desk** (ID kept by the librarian). The sidebar now says
**Borrow Desk** so the three Circulation pages are no longer confused with each other.

**Keep-a-copy rule** (`BORROW_KEEP_IN_LIBRARY` in `config/kiosk.php`, default `1`; `0` turns it off).
A borrow, or a hold for pick-up, is refused if it would leave fewer than that many copies of the title on the shelf.
- 2 copies on the shelf → only 1 can be borrowed (also when one student asks for both at once).
- 1 copy → cannot be borrowed (it can still be read in the library).
- 5 copies on the shelf with 2 already out → 3 on the shelf, 2 may still be borrowed.
It is checked in one shared place for **every** route: Borrow Desk, *Loans → walk-in loan*, approving a student's Borrow Request (the hold),
and the reservation queue (a returned copy is only offered to the next student if a spare copy remains). The check locks the title's copies, so two
librarians cannot take "the last spare copy" at the same moment.

**Availability is automatic.** There is no stored counter to drift: every screen counts `book_copy.Status` live, and loans, returns, holds, expired holds and
reading sessions already change that status. Right after a transaction the numbers are correct everywhere.
What staff and students see: the desk shows *On shelf: X of Y copies* and a yellow **Last copy stays in library** badge with the reason;
the student catalog shows **In library only** and offers a reservation instead of a borrow.

**Tests.** `bash tests/workflow.sh` (rule switched off through `tests/rule_off.php`, so the older tests keep testing what they were written for) and
`php tests/rule_test.php "$PWD"` (16 checks of the rule itself). One older check, *"copy In Use … no new loan"*, was already failing in `lms_desk_form_v27`
because in-library use is now switched on; it is unrelated to this change.

## Library Desk — one form for reading here AND borrowing
`desk_form.php` (menu: **Library Desk**) is now the only place a new loan is issued; the old *New Walk-in Loan* on `loans.php` was removed
(Loans keeps the loan list, loan details and releasing approved Borrow Requests).
- Each book on the form is **Read here** or **Borrow**.
  - **Read here** → in-library record; the librarian **keeps the student's ID** in a slot until the book is back (unchanged).
  - **Borrow** → a normal loan with the book's policy **loan days and due date** (shown on the line, the confirmation and the printed slip);
    **no ID is kept**. A form with only borrowed books closes immediately; the books are returned on the **Returns** page.
- Rules checked for borrowed lines: verification, membership status, unpaid fines, borrowing limit, library-use-only books, the
  *keep a copy in the library* rule, and **one copy of a title per borrower** (also blocks a title the student already has out).
  Teachers register as members, so they get exactly the same form, days and due dates (there is no separate Teacher account type).
- Visitors (walk-ins) can only read here. Reading needs the librarian; assistants can still issue borrow-only forms. With
  `IN_LIBRARY_USE_ENABLED = false` the form is borrow-only.
- **Import** `database/migrate_desk_borrow.sql` after `migrate_id_custody.sql`.
- Menu: *Library Desk* = walk-up form · *Borrow Desk* = approve kiosk/online requests · *Read-Here Books* = what is being read now · *Loans* = loan records.
