-- Bibliophilia: one-time database update for Student Self-Service In-Library Use.
-- HOW: BACK UP FIRST, then phpMyAdmin -> select the `library_management` database -> Import -> choose this file.
-- Written for MariaDB 10.1+ (XAMPP). Additive only: nothing is dropped, renamed or rewritten,
-- and every existing in-library record keeps working (new columns default to "Staff" / empty).

-- 1. in_library_use: where the record came from, which kiosk session it belongs to, and the reading area
ALTER TABLE `in_library_use`
  ADD COLUMN IF NOT EXISTS `Source`     ENUM('Staff','Kiosk') NOT NULL DEFAULT 'Staff',
  ADD COLUMN IF NOT EXISTS `SessionRef`  VARCHAR(20)  NULL,
  ADD COLUMN IF NOT EXISTS `Location`    VARCHAR(100) NULL;

CREATE INDEX IF NOT EXISTS `idx_ilu_member_status` ON `in_library_use` (`MemberID`, `Status`);
CREATE INDEX IF NOT EXISTS `idx_ilu_session_ref`   ON `in_library_use` (`SessionRef`);

-- 2. Kiosk audit trail + failed-ID rate limiting. Stores no student ID text and no personal details:
--    only the event type, the member's internal user id (when identified), the device address and a short note.
CREATE TABLE IF NOT EXISTS `kiosk_event` (
  `EventID`   INT AUTO_INCREMENT PRIMARY KEY,
  `EventTime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `EventType` VARCHAR(30)  NOT NULL,
  `MemberID`  INT NULL,
  `IPAddress` VARCHAR(45)  NULL,
  `Detail`    VARCHAR(255) NULL,
  INDEX `idx_ke_type_ip_time` (`EventType`, `IPAddress`, `EventTime`),
  INDEX `idx_ke_time` (`EventTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
