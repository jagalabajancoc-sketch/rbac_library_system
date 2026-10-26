# Transaction and reservation feedback update

See [FEEDBACK_RELEASE.md](FEEDBACK_RELEASE.md) for the latest grouped workflows, accession entry, return review, migration and tests. Run setup.php normally to apply the additional migration.

# Release 1: database reliability, circulation desk, and catalog search

## Use the updated application

This release keeps the existing PHP/MariaDB stack and interface. The Library Desk has a review dialog before issuance. It retains member details, selected copies, conditions and loan days after server validation failures. Each form has a persistent submission token, so a retry returns the original receipt. Duplicate scans preserve the previously entered loan days. The desk serializes form numbering/ID-slot assignment, while database transactions protect lending and member borrowing limits.

The member catalog searches and filters the complete database and returns 12 titles per page. Search covers title, author, publisher and ISBN; ISBN hyphens are optional. Filters support availability, reference titles, section, category and author. Edition and shelf locations are visible. Search/filter/page state is saved in the URL. Connection failures have a separate retry message, rather than appearing as empty search results. Member borrowing eligibility is still checked on the server when a request is submitted.

## Install or update

Sign staff out while applying database updates. From the project folder in PowerShell:

    & C:\xampp\php\php.exe database\setup.php --status
    & C:\xampp\php\php.exe database\setup.php

The default connection remains localhost:3306 / library_management. Environment overrides are available: LIBRARY_DB_HOST, LIBRARY_DB_PORT, LIBRARY_DB_NAME, LIBRARY_DB_USER and LIBRARY_DB_PASS. Keep passwords and local database exports out of Git.

Updates check for missing base tables and duplicate return records before changing the schema. An advisory lock prevents two installers running together. Each completed migration is recorded in schema_migrations with its checksum. Rerunning a completed update does nothing. Do not edit migrations that have already been applied; create a new migration and append it to migration_files() instead.

MariaDB DDL is not transactional. If installation is interrupted, correct the reported failure and rerun. Do not delete tables to force an import. A partially created base schema needs review before recovery. Fresh installations create an administrator with a random password printed to the terminal; existing installations keep their accounts and passwords.

The installer includes the kiosk, entry, ID custody, combined desk borrowing, teacher membership, collation and release-1 migrations. The last migration also adds verification fields omitted from older reconstructed schemas.

## Backups and restoration checks

On XAMPP, backups now go to C:\xampp\private\library-backups, outside htdocs. Set LIBRARY_BACKUP_DIR to another private absolute path if needed. On other operating systems, explicitly set that variable outside your web document root. Keep any old backups private as well.

    & C:\xampp\php\php.exe database\setup.php --backup
    & C:\xampp\php\php.exe database\verify_backup.php "C:\xampp\private\library-backups\your-backup.sql"

The verification command requires the generated .sha256 companion file. It restores to a randomly named library_test_restore_* database, checks that every expected table exists and can be read, and removes only that temporary database. It never replaces the current database. The database account needs CREATE/DROP privileges for this maintenance command. Backups containing cross-database statements or routines require manual review and are rejected by this automatic verifier. Use a separate MariaDB server through LIBRARY_DB_HOST/PORT for recovery drills when possible.

Before applying any pending update to an existing database, setup.php creates a backup. An unsuccessful backup stops the update.

## Test without touching library records

Tests refuse to run unless LIBRARY_TEST_MODE=1 and LIBRARY_DB_NAME begins with library_test_. The HTTP test also checks that the web server reports that exact test database before submitting forms. Use a separate MariaDB instance/account for tests, preferably with no access to real library data.

In a PowerShell test window (adjust the test-server port):

    $env:LIBRARY_TEST_MODE='1'
    $env:LIBRARY_DB_HOST='127.0.0.1'
    $env:LIBRARY_DB_PORT='3319'
    $env:LIBRARY_DB_NAME='library_test_release1'
    & C:\xampp\php\php.exe database\setup.php
    & C:\xampp\php\php.exe tests\release1_test.php
    & C:\xampp\php\php.exe tests\concurrency_test.php
    & C:\xampp\php\php.exe -S 127.0.0.1:8098

In another PowerShell window, use the same LIBRARY_TEST_MODE and LIBRARY_DB_* values, then:

    $env:LIBRARY_TEST_ADMIN_PASSWORD='the temporary password printed during test setup'
    $env:LIBRARY_TEST_URL='http://127.0.0.1:8098'
    python tests\release1_http_test.py

These tests add synthetic records only to the selected test database. They cover search beyond 2,000 titles, pagination, filtering, literal wildcard characters, shelf availability, repeated form submissions, competing borrowers, ID custody, registration, verification, borrowing, partial returns, overdue fines and payment. The legacy shell scripts are also restricted to library_test_* databases; desk_form_test.sh still needs its original DEMO/LIB fixtures.

## Scope

This release does not add email delivery, renewals, inventory reconciliation, or a framework migration. Those remain later roadmap items. It also does not alter historical lending discrepancies automatically. Review consistency messages printed during an upgrade with library staff before correcting records.
