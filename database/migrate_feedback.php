<?php
// Add transaction headers without merging or renumbering historical records.
(function(PDO $p) {
    $p->exec("CREATE TABLE IF NOT EXISTS request_transaction (
        TransactionID INT AUTO_INCREMENT PRIMARY KEY, UserID INT NOT NULL,
        SubmittedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        SubmissionToken CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
        FOREIGN KEY (UserID) REFERENCES user_account(UserID)
    ) ENGINE=InnoDB");
    $p->exec("ALTER TABLE borrow_request ADD COLUMN IF NOT EXISTS TransactionID INT NULL,
        ADD COLUMN IF NOT EXISTS ReleasedLoanID INT NULL");
    $p->exec("INSERT IGNORE INTO request_transaction (TransactionID,UserID,SubmittedAt)
        SELECT RequestID,UserID,RequestDate FROM borrow_request WHERE TransactionID IS NULL");
    $p->exec("UPDATE borrow_request SET TransactionID=RequestID WHERE TransactionID IS NULL");
    $p->exec("UPDATE borrow_request r SET ReleasedLoanID=(SELECT MIN(l.LoanID) FROM loan l WHERE l.RequestID=r.RequestID)
        WHERE ReleasedLoanID IS NULL");
    $p->exec("CREATE TABLE IF NOT EXISTS return_batch (
        ReturnBatchID INT AUTO_INCREMENT PRIMARY KEY, ProcessedByUserID INT NOT NULL,
        CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ProcessedByUserID) REFERENCES user_account(UserID)
    ) ENGINE=InnoDB");
    $p->exec("ALTER TABLE return_header ADD COLUMN IF NOT EXISTS ReturnBatchID INT NULL");
    $p->exec("INSERT IGNORE INTO return_batch (ReturnBatchID,ProcessedByUserID,CreatedAt)
        SELECT rh.ReturnID,rh.ProcessedByUserID,COALESCE(MIN(rd.ReturnDate),NOW())
        FROM return_header rh LEFT JOIN return_detail rd ON rd.ReturnID=rh.ReturnID
        WHERE rh.ReturnBatchID IS NULL GROUP BY rh.ReturnID,rh.ProcessedByUserID");
    $p->exec("UPDATE return_header SET ReturnBatchID=ReturnID WHERE ReturnBatchID IS NULL");
    foreach ([['borrow_request','fk_request_transaction','TransactionID','request_transaction','TransactionID'],
              ['borrow_request','fk_request_released_loan','ReleasedLoanID','loan','LoanID'],
              ['return_header','fk_return_batch','ReturnBatchID','return_batch','ReturnBatchID']] as [$table,$name,$col,$parent,$key]) {
        $q=$p->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
        $q->execute([$table,$name]);
        if (!$q->fetchColumn()) $p->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (`$col`) REFERENCES `$parent` (`$key`)");
    }
    $p->exec('CREATE INDEX IF NOT EXISTS idx_transaction_member_date ON request_transaction (UserID,SubmittedAt)');
})($p);
