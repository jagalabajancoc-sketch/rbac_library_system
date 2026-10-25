-- Bibliophilia: one-time database update for the Borrow Request release.
-- HOW: BACK UP FIRST, then phpMyAdmin -> select the `library_management` database -> Import -> choose this file.
-- Run it ONCE. (A re-runnable command-line version exists: php database/migrate_borrow_request.php)
-- Written for MariaDB 10.1+ (XAMPP). Nothing is deleted.

-- 1. Rename the request tables (foreign keys follow automatically)
RENAME TABLE `loan_request` TO `borrow_request`, `loan_request_detail` TO `borrow_request_detail`;

-- 2. Request type: Loan -> Borrow  (types are now Borrow | Reservation)
ALTER TABLE `borrow_request` MODIFY `RequestType` ENUM('Loan','Borrow','Reservation') NOT NULL DEFAULT 'Borrow';
UPDATE `borrow_request` SET `RequestType` = 'Borrow' WHERE `RequestType` = 'Loan';
ALTER TABLE `borrow_request` MODIFY `RequestType` ENUM('Borrow','Reservation') NOT NULL DEFAULT 'Borrow';

-- 3. Why a request was rejected / cancelled
ALTER TABLE `borrow_request` ADD COLUMN IF NOT EXISTS `DecisionReason` VARCHAR(255) NULL AFTER `ApprovalDate`;

-- 4. Indexes for the request, loan, return and availability queries
CREATE INDEX IF NOT EXISTS `idx_br_user_status`  ON `borrow_request` (`UserID`, `ApprovalStatus`);
CREATE INDEX IF NOT EXISTS `idx_br_status_type`  ON `borrow_request` (`ApprovalStatus`, `RequestType`, `RequestDate`);
CREATE INDEX IF NOT EXISTS `idx_br_copy`         ON `borrow_request` (`AssignedCopyID`);
CREATE INDEX IF NOT EXISTS `idx_brd_book`        ON `borrow_request_detail` (`BookID`);
CREATE INDEX IF NOT EXISTS `idx_loan_borrower`   ON `loan` (`BorrowerUserID`);
CREATE INDEX IF NOT EXISTS `idx_loan_request`    ON `loan` (`RequestID`);
CREATE INDEX IF NOT EXISTS `idx_ld_copy`         ON `loan_detail` (`CopyID`);
CREATE INDEX IF NOT EXISTS `idx_ld_due`          ON `loan_detail` (`DueDate`);
CREATE INDEX IF NOT EXISTS `idx_bc_book_status`  ON `book_copy` (`BookID`, `Status`, `IsArchived`);
CREATE INDEX IF NOT EXISTS `idx_ilu_copy_status` ON `in_library_use` (`CopyID`, `Status`);

-- 5. A loan item can be returned only once
CREATE UNIQUE INDEX IF NOT EXISTS `uq_return_loan_detail` ON `return_detail` (`LoanDetailID`);
