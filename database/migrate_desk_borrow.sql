-- Bibliophilia: ONE desk form for both "read here" and "borrow" (the Library Desk takes over issuing loans).
-- HOW: BACK UP FIRST. Import AFTER migrate_id_custody.sql (phpMyAdmin -> select library_management -> Import). MariaDB 10.1+.
-- Additive only: existing forms, loans and sessions are untouched.

-- A form that has only borrowed books keeps no ID, so it needs "NotRequired"; and it remembers the loan it created.
ALTER TABLE `in_library_form`
  MODIFY COLUMN `IdStatus` ENUM('Held','Returned','NotRequired') NOT NULL DEFAULT 'Held',
  ADD COLUMN IF NOT EXISTS `LoanID` INT NULL;
