-- Bibliophilia: Library Entry scanning + Librarian-approved book use (Capitol University procedure).
-- HOW: BACK UP FIRST. Import AFTER database/migrate_self_service_kiosk.sql (phpMyAdmin -> Import). MariaDB 10.1+.
-- Additive only. Foreign keys are intentionally omitted so the script works whatever integer type your
-- existing user_account / book_copy keys use; the application enforces the relationships.

-- 1. Entry log: every ID scan at the library door (allowed or denied) with date and time
CREATE TABLE IF NOT EXISTS `library_entry` (
  `EntryID`          INT AUTO_INCREMENT PRIMARY KEY,
  `MemberID`         INT NULL,                                   -- NULL when the scanned ID is not registered
  `EntryTime`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ExitTime`         DATETIME NULL,
  `Result`           ENUM('Allowed','Denied') NOT NULL DEFAULT 'Allowed',
  `DenyReason`       VARCHAR(120) NULL,
  `Method`           ENUM('Scanner','Staff') NOT NULL DEFAULT 'Scanner',
  `RecordedByUserID` INT NULL,                                   -- staff, for manual entries / check-outs
  `IPAddress`        VARCHAR(45) NULL,
  INDEX `idx_le_member_time` (`MemberID`, `EntryTime`),
  INDEX `idx_le_time` (`EntryTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. A student's request to use / borrow book(s). Nothing happens until a librarian decides.
CREATE TABLE IF NOT EXISTS `use_request` (
  `UseRequestID`    INT AUTO_INCREMENT PRIMARY KEY,
  `MemberID`        INT NOT NULL,
  `EntryID`         INT NULL,                                    -- the entry scan this request was made under
  `Source`          ENUM('Kiosk','Desk') NOT NULL DEFAULT 'Kiosk',
  `Status`          ENUM('Pending','Decided','Cancelled') NOT NULL DEFAULT 'Pending',
  `RequestedAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `DecidedByUserID` INT NULL,
  `DecidedAt`       DATETIME NULL,
  `CreatedByUserID` INT NULL,                                    -- librarian, when entered at the desk
  INDEX `idx_ur_status_time` (`Status`, `RequestedAt`),
  INDEX `idx_ur_member` (`MemberID`, `Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. One row per physical copy asked for, each approved/rejected on its own
CREATE TABLE IF NOT EXISTS `use_request_item` (
  `ItemID`         INT AUTO_INCREMENT PRIMARY KEY,
  `UseRequestID`   INT NOT NULL,
  `CopyID`         INT NOT NULL,
  `RequestedUse`   ENUM('In-Library','Borrow') NOT NULL,
  `Decision`       ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `ApprovedAs`     ENUM('In-Library','Borrow') NULL,
  `RejectReason`   VARCHAR(255) NULL,
  `InLibraryUseID` INT NULL,                                     -- record created when approved for in-library use
  `LoanID`         INT NULL,                                     -- loan created when approved for borrowing
  INDEX `idx_uri_request` (`UseRequestID`),
  INDEX `idx_uri_copy` (`CopyID`, `Decision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
