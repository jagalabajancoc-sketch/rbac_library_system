-- Bibliophilia: teachers borrow at the Library Desk with the SAME form as students (and the days borrowed are chosen on the form).
-- HOW: BACK UP FIRST. Import AFTER migrate_desk_borrow.sql (phpMyAdmin -> select library_management -> Import). MariaDB 10.1+.
-- Additive only: every existing member stays a "Student"; mark teachers on the Members page (Type).
ALTER TABLE `user_account`
  ADD COLUMN IF NOT EXISTS `MemberKind` ENUM('Student','Teacher') NOT NULL DEFAULT 'Student';
