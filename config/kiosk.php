<?php
/**
 * Student self-service kiosk settings (kiosk.php). Edit here — no database changes needed.
 */

/* Master switch. false = kiosk.php shows "temporarily unavailable" and registers nothing. */
define('KIOSK_ENABLED', true);

/* Only these device addresses may use the kiosk. Empty = any device that can reach the site.
   Recommended on a real network, e.g. ['192.168.1.50', '192.168.1.51']. */
define('KIOSK_ALLOWED_IPS', []);

/* Seconds without any touch/keypress before the screen clears itself (privacy). */
define('KIOSK_IDLE_SECONDS', 90);

/* Seconds the "session started" screen stays up before the kiosk resets for the next student. */
define('KIOSK_DONE_SECONDS', 12);

/* Wrong-ID protection: after this many unknown IDs from one device, wait before allowing more. */
define('KIOSK_MAX_ID_ATTEMPTS', 5);
define('KIOSK_LOCKOUT_MINUTES', 5);

/* Optional reading areas offered on the confirm step. Empty list hides the question. */
const KIOSK_LOCATIONS = ['Reading Room 1', 'Reading Room 2', 'Study Area', "Children's Corner"];

/* Books in one self-service session. This is only a sanity cap against a runaway scanner;
   it is NOT a borrowing limit (in-library use has none in the existing rules). */
define('KIOSK_MAX_COPIES_PER_SESSION', 20);

/* ---- Capitol University procedure ---- */
/* Library entrance: students scan their school ID at entry.php; the scan is logged (date/time) and verified. */
define('ENTRY_GATE_ENABLED', true);
define('ENTRY_RESET_SECONDS', 6);          // how long the welcome / denied message stays on the gate screen

/* Book requests from the kiosk need an entry scan for today (the student must have scanned in at the door).
   Requests entered by the librarian at the desk never need it; the librarian sees a warning instead. */
define('KIOSK_REQUIRE_ENTRY', true);

/* Entrance screen: let students show their ID to the device camera (barcode or QR). Needs HTTPS (or localhost).
   The handheld-scanner / typing box always stays available as a backup. */
define('ENTRY_CAMERA_ENABLED', true);

/* Entrance screen: also READ the printed ID number (e.g. "ID NO. 02-2425-014468") from the camera image, for school IDs
   that have no barcode / QR. Runs on the device (Tesseract.js) — no image is uploaded. The first use downloads about 10 MB
   of language data from a CDN, then the browser keeps it. Students are matched by the Student ID they entered when registering. */
define('ENTRY_OCR_ENABLED', false);   // off: students scan the QR code on their Member Card instead

/* Attendance only: the QR scan records time-in once per day. There is no time-out / session tracking (as at Capitol University). */

/* Borrow-only mode. true = the library lets students BORROW only: no "Read here" sessions, no reading-area / duration
   tracking, and the "Read-Here Books" page is hidden from the menu. Set to true to bring in-library reading back.
   Library-use-only (reference) books then cannot be requested at all. */
define('IN_LIBRARY_USE_ENABLED', true);   // on: in-library reading is handled at the librarian desk (desk_form.php), the librarian keeps the ID


/* ---- Librarian desk form: the librarian keeps the student's ID until the book is returned (desk_form.php) ---- */
/* Number of places (trays / slots) where held IDs are kept. The next free number is suggested on every form. */
define('DESK_ID_SLOTS', 60);
/* Library closing time (24h, HH:MM). IDs still held after this time are flagged so none are forgotten overnight. */
define('DESK_CLOSING_TIME', '17:00');
/* The student must have scanned in at the entrance today before a form is issued. false = only a warning. */
define('DESK_REQUIRE_ENTRY', false);
/* Rules read to / acknowledged by the student on every form (shown on the screen and the printed slip). */
const DESK_RULES = [
    'Books are for use inside the library only and must not leave the reading area.',
    'The librarian keeps your ID until every book on this form is returned to the desk.',
    'Handle books with care. You are responsible for any damage or loss.',
    'Return all books to the librarian before closing time to get your ID back.',
];
const DESK_PURPOSES = ['Reading', 'Research', 'Assignment', 'Review / Study', 'Reference', 'Other'];
/* Days a book may be borrowed for, chosen on the desk form (the policy's loan days are the default). */
const DESK_MIN_LOAN_DAYS = 1;
const DESK_MAX_LOAN_DAYS = 60;
const DESK_WALKIN_ID_TYPES = ['School ID (other school)', 'Government ID', "Driver's License", 'Other ID'];


/* ---- Borrowing rule: keep copies in the library ----
   Number of copies of a title that must ALWAYS stay on the shelf. A borrow (or a hold for pick-up) is refused if it would leave
   fewer than this many copies in the library. Example with 1: a title with 2 copies lets only 1 be borrowed; a title with
   a single copy cannot be borrowed at all (it can still be read in the library). 0 = no restriction. */
if (!defined('BORROW_KEEP_IN_LIBRARY')) define('BORROW_KEEP_IN_LIBRARY', 1);   // (a test run can define it first)


/* Rules read to / acknowledged by the student when the form includes BORROWED books (shown on the screen and the printed slip).
   The ID is NOT kept for borrowed books — only for books read in the library. */
const DESK_BORROW_RULES = [
    'Borrowed books may leave the library and must be returned on or before the due date shown on the form.',
    'Only one copy of a title may be borrowed at a time.',
    'A late return is charged the library fine; damaged or lost books must be paid for or replaced.',
];
