<?php
/** Read-only member grouping; searches select members, never truncate their transactions. */
function borrowed_books_groups(string $search, int $page): array
{
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = "WHERE (CAST(l.LoanID AS CHAR) LIKE ? OR CONCAT(m.FirstName,' ',m.LastName) LIKE ? OR m.MembershipNumber LIKE ? OR EXISTS (
            SELECT 1 FROM loan_detail d JOIN book_copy c ON c.CopyID=d.CopyID JOIN book b ON b.BookID=c.BookID
            WHERE d.LoanID=l.LoanID AND (b.Title LIKE ? OR c.AccessionNumber LIKE ?)))";
        $params = array_fill(0, 5, '%' . $search . '%');
    }
    $total = (int)fetch_value("SELECT COUNT(DISTINCT m.UserID) FROM loan l JOIN user_account m ON m.UserID=l.BorrowerUserID $where", $params);
    $pg = pagination_calc($total, 10, $page);
    $ids = array_column(fetch_all("SELECT m.UserID, MAX(l.LoanID) AS LatestMatch
        FROM loan l JOIN user_account m ON m.UserID=l.BorrowerUserID $where
        GROUP BY m.UserID ORDER BY LatestMatch DESC, m.UserID
        LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params), 'UserID');
    $groups = [];
    foreach ($ids as $id) $groups[(int)$id] = ['records'=>[], 'total_items'=>0, 'outstanding'=>0, 'overdue'=>0, 'earliest_due'=>null];
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $records = fetch_all("SELECT l.LoanID,l.LoanDate,l.BorrowerUserID AS MemberID,
            m.FirstName,m.LastName,m.MembershipNumber,m.MembershipStatus,
            s.FirstName AS StaffFirst,s.LastName AS StaffLast,
            (SELECT COUNT(*) FROM loan_detail d WHERE d.LoanID=l.LoanID) AS TotalItems,
            (SELECT COUNT(*) FROM loan_detail d WHERE d.LoanID=l.LoanID AND NOT EXISTS
                (SELECT 1 FROM return_detail r WHERE r.LoanDetailID=d.LoanDetailID)) AS StillBorrowed
            FROM loan l JOIN user_account m ON m.UserID=l.BorrowerUserID
            LEFT JOIN user_account s ON s.UserID=l.ProcessedByUserID
            WHERE m.UserID IN ($marks) ORDER BY l.LoanID DESC", $ids);
        foreach ($records as $record) {
            $id = (int)$record['MemberID'];
            $groups[$id]['member'] = $record;
            $groups[$id]['records'][] = $record;
            $groups[$id]['total_items'] += (int)$record['TotalItems'];
            $groups[$id]['outstanding'] += (int)$record['StillBorrowed'];
        }
    }
    if ($ids) {
        $dues=fetch_all("SELECT l.BorrowerUserID,d.DueDate FROM loan_detail d JOIN loan l ON l.LoanID=d.LoanID
            WHERE l.BorrowerUserID IN ($marks) AND NOT EXISTS(SELECT 1 FROM return_detail r WHERE r.LoanDetailID=d.LoanDetailID)", $ids);
        foreach($dues as $due){
            $g=&$groups[(int)$due['BorrowerUserID']];
            if(is_overdue($due['DueDate']))$g['overdue']++;
            if($due['DueDate'] && (!$g['earliest_due'] || $due['DueDate']<$g['earliest_due']))$g['earliest_due']=$due['DueDate'];
            unset($g);
        }
    }
    return ['pagination'=>$pg, 'groups'=>$groups];
}
