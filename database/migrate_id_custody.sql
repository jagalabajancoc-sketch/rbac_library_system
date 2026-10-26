-- Bibliophilia: Librarian-desk "In-Library Form" with ID custody (the librarian keeps the student's ID until the book is back).
-- HOW: BACK UP FIRST. Import AFTER migrate_self_service_kiosk.sql (phpMyAdmin -> select library_management -> Import). MariaDB 10.1+.
-- Additive only: nothing is dropped, renamed or rewritten.

-- 1. One row per desk visit = the digital version of the paper form. in_library_use rows link to it through SessionRef = FormNo.
CREATE TABLE IF NOT EXISTS `in_library_form` (
  `FormID`            INT AUTO_INCREMENT PRIMARY KEY,
  `FormNo`            VARCHAR(20)  NOT NULL,                     -- e.g. ILF-261025-0003 (same value as in_library_use.SessionRef)
  `BorrowerType`      ENUM('Member','Walk-in') NOT NULL DEFAULT 'Member',
  `MemberID`          INT NULL,
  `WalkInName`        VARCHAR(150) NULL,
  `WalkInContact`     VARCHAR(100) NULL,
  `IdType`            VARCHAR(40)  NOT NULL DEFAULT 'School ID',  -- what the librarian is holding
  `IdNumber`          VARCHAR(60)  NULL,
  `IdVerifyMethod`    ENUM('Scanned','Typed','Visual') NOT NULL DEFAULT 'Typed',
  `IdPhotoChecked`    TINYINT(1) NOT NULL DEFAULT 0,             -- librarian confirmed photo/name match the person at the desk
  `IdSlot`            VARCHAR(20)  NULL,                         -- where the physical ID is kept (tray / slot number)
  `Purpose`           VARCHAR(40)  NOT NULL DEFAULT 'Reading',
  `PurposeNote`       VARCHAR(255) NULL,
  `Location`          VARCHAR(100) NULL,
  `RulesAcknowledged` TINYINT(1) NOT NULL DEFAULT 0,
  `AcknowledgedAt`    DATETIME NULL,
  `IssuedByUserID`    INT NOT NULL,
  `IssuedAt`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ExpectedReturnAt`  DATETIME NULL,                             -- normally closing time
  `IdStatus`          ENUM('Held','Returned') NOT NULL DEFAULT 'Held',
  `IdReturnedAt`      DATETIME NULL,
  `IdReturnedByUserID` INT NULL,
  `IdReturnNote`      VARCHAR(255) NULL,
  `Status`            ENUM('Open','Closed') NOT NULL DEFAULT 'Open',
  UNIQUE KEY `uq_ilf_no` (`FormNo`),
  KEY `idx_ilf_member_status` (`MemberID`, `Status`),
  KEY `idx_ilf_status_issued` (`Status`, `IssuedAt`),
  KEY `idx_ilf_slot_status` (`IdSlot`, `IdStatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Condition of each book when handed out and when it comes back
ALTER TABLE `in_library_use`
  ADD COLUMN IF NOT EXISTS `ConditionOut` ENUM('Good','Fair','Damaged') NOT NULL DEFAULT 'Good',
  ADD COLUMN IF NOT EXISTS `ConditionIn`  ENUM('Good','Fair','Damaged','Lost') NULL;
