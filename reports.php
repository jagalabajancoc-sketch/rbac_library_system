<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Reports';

$reportGroups = [
    'Book Reports' => [
        'book_inventory'     => 'Book Inventory Report',
        'books_available'    => 'Available Copies Report',
        'books_damaged_lost' => 'Damaged/Lost Copies Report',
        'books_donated'      => 'Donated Books Report',
    ],
    'Borrowing Reports' => [
        'borrow_requests'   => 'Borrow Requests Report',
        'borrowing_history' => 'Borrowing History Report',
        'returns_history'   => 'Returns Report',
        'loans_current'     => 'Current Loans Report',
        'loans_overdue'     => 'Overdue Loans Report',
        'loans_partial'     => 'Partially Returned Loans Report',
        'loans_completed'   => 'Completed Loans Report',
    ],
    'Member/Borrower Reports' => [
        'members_all'    => 'All Members Report',
        'members_active' => 'Active Borrowers Report',
    ],
    'Fine & Payment Reports' => [
        'fines'            => 'Fine Report',
        'fines_unpaid'     => 'Unpaid Fines Report',
        'fines_paid'       => 'Paid Fines Report',
        'payments_history' => 'Payment History Report',
    ],
    'Donation Report' => [
        'donations' => 'Donation Report',
    ],
];

if (!has_permission('view_financial_reports')) {
    unset($reportGroups['Fine & Payment Reports']);
}

$report = $_GET['report'] ?? 'book_inventory';
$validReports = [];
foreach ($reportGroups as $items) foreach ($items as $k => $_) $validReports[] = $k;
if (!in_array($report, $validReports, true)) $report = $validReports[0] ?? 'book_inventory';

$fromDate     = trim($_GET['from'] ?? '');
$toDate       = trim($_GET['to']   ?? '');
$search       = trim($_GET['q']    ?? '');
$borrowerId   = $_GET['borrower_id'] ?? '';
$borrowStatus = $_GET['bstat'] ?? '';

$validDate = fn($d) => $d !== '' && strtotime($d) !== false;
if ($fromDate !== '' && !$validDate($fromDate)) $fromDate = '';
if ($toDate   !== '' && !$validDate($toDate))   $toDate   = '';
if ($fromDate && $toDate && strtotime($fromDate) > strtotime($toDate)) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}
$dateActive = ($fromDate !== '' && $toDate !== '');
$searchLike = $search !== '' ? "%$search%" : null;

$borrowerReports = [
    'borrowing_history', 'loans_current', 'loans_overdue', 'borrow_requests', 'returns_history',
    'loans_partial', 'loans_completed', 'members_active',
    'fines', 'fines_unpaid', 'fines_paid', 'payments_history',
];
$showBorrowerFilter = in_array($report, $borrowerReports, true);

/* Borrower dropdown — only user_account rows with the Member role. */
$borrowerOptions = fetch_all(
    "SELECT ua.UserID AS id, ua.LastName, ua.FirstName
       FROM user_account ua
       JOIN role r ON r.RoleID = ua.RoleID
      WHERE r.RoleName = 'Member'
      ORDER BY ua.LastName, ua.FirstName"
);

$borrowerName = '';
if ($borrowerId !== '') {
    $row = fetch_one(
        "SELECT CONCAT(LastName, ', ', FirstName) AS n FROM user_account WHERE UserID = ?",
        [$borrowerId]
    );
    if ($row) $borrowerName = $row['n'];
    else      $borrowerId   = '';
}

$filterBits = [];
$filterBits[] = $dateActive
    ? 'Period: ' . fmt_date($fromDate) . ' — ' . fmt_date($toDate)
    : 'Period: All Records';
if ($search !== '')       $filterBits[] = 'Search: ' . $search;
if ($borrowerName !== '') $filterBits[] = 'Borrower: ' . $borrowerName;
if ($borrowStatus !== '' && $report === 'borrowing_history') $filterBits[] = 'Status: ' . $borrowStatus;

$summary  = [];
$columns  = [];
$htmlRows = [];
$emptyMsg = 'No records found for the selected filters.';

switch ($report) {

case 'book_inventory': {
    $where  = 'b.IsArchived = 0';
    $params = [];
    if ($dateActive) { $where .= ' AND b.AcquisitionDate BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (b.Title LIKE ? OR b.ISBN LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; }

    $summary = [
        ['label' => 'Total Titles', 'value' => (int) fetch_value("SELECT COUNT(*) FROM book b WHERE $where", $params)],
        ['label' => 'Total Copies', 'value' => (int) fetch_value("SELECT COUNT(*) FROM book_copy bc JOIN book b ON b.BookID = bc.BookID WHERE $where AND bc.IsArchived = 0", $params)],
        ['label' => 'Available',    'value' => (int) fetch_value("SELECT COUNT(*) FROM book_copy bc JOIN book b ON b.BookID = bc.BookID WHERE $where AND bc.IsArchived = 0 AND bc.Status='Available'", $params)],
        ['label' => 'Borrowed',     'value' => (int) fetch_value("SELECT COUNT(*) FROM book_copy bc JOIN book b ON b.BookID = bc.BookID WHERE $where AND bc.IsArchived = 0 AND bc.Status='Borrowed'", $params)],
        ['label' => 'Damaged/Lost', 'value' => (int) fetch_value("SELECT COUNT(*) FROM book_copy bc JOIN book b ON b.BookID = bc.BookID WHERE $where AND bc.IsArchived = 0 AND bc.Status IN ('Damaged','Lost')", $params)],
    ];

    $rows = fetch_all(
        "SELECT b.Title, b.ISBN,
                (SELECT GROUP_CONCAT(CONCAT(a.FirstName,' ',a.LastName) SEPARATOR ', ')
                   FROM book_author ba JOIN author a ON a.AuthorID = ba.AuthorID
                  WHERE ba.BookID = b.BookID) AS Authors,
                (SELECT COUNT(*) FROM book_copy bc WHERE bc.BookID = b.BookID AND bc.IsArchived = 0) AS TotalCopies,
                (SELECT COUNT(*) FROM book_copy bc WHERE bc.BookID = b.BookID AND bc.IsArchived = 0 AND bc.Status='Available') AS Available,
                (SELECT COUNT(*) FROM book_copy bc WHERE bc.BookID = b.BookID AND bc.IsArchived = 0 AND bc.Status='Borrowed')  AS Borrowed,
                (SELECT COUNT(*) FROM book_copy bc WHERE bc.BookID = b.BookID AND bc.IsArchived = 0 AND bc.Status='Lost')      AS Lost,
                (SELECT COUNT(*) FROM book_copy bc WHERE bc.BookID = b.BookID AND bc.IsArchived = 0 AND bc.Status='Damaged')   AS Damaged
           FROM book b WHERE $where ORDER BY b.Title", $params);

    $columns = ['Title','Author(s)','ISBN','Total Copies','Available','Borrowed','Lost','Damaged'];
    foreach ($rows as $r) {
        $htmlRows[] = '<tr>'
            . '<td class="fw-semibold">' . h($r['Title']) . '</td>'
            . '<td>' . h($r['Authors'] ?: '—') . '</td>'
            . '<td>' . h($r['ISBN'] ?: '—') . '</td>'
            . '<td class="text-center">' . h($r['TotalCopies']) . '</td>'
            . '<td class="text-center"><span class="badge text-bg-success">' . h($r['Available']) . '</span></td>'
            . '<td class="text-center"><span class="badge text-bg-warning">' . h($r['Borrowed']) . '</span></td>'
            . '<td class="text-center">' . ((int)$r['Lost'] > 0 ? '<span class="badge text-bg-dark">' . h($r['Lost']) . '</span>' : '0') . '</td>'
            . '<td class="text-center">' . ((int)$r['Damaged'] > 0 ? '<span class="badge text-bg-danger">' . h($r['Damaged']) . '</span>' : '0') . '</td>'
            . '</tr>';
    }
    break;
}

case 'books_available': {
    $where  = "bc.Status = 'Available' AND bc.IsArchived = 0";
    $params = [];
    if ($dateActive) { $where .= ' AND b.AcquisitionDate BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (b.Title LIKE ? OR bc.AccessionNumber LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; }

    $rows = fetch_all(
        "SELECT bc.AccessionNumber, b.Title, b.ISBN, b.AcquisitionDate, p.PublisherName
           FROM book_copy bc
           JOIN book b ON b.BookID = bc.BookID
           LEFT JOIN publisher p ON p.PublisherID = b.PublisherID
          WHERE $where ORDER BY b.Title, bc.AccessionNumber", $params);

    $summary = [['label' => 'Available Copies', 'value' => count($rows)]];
    $columns = ['Accession #','Title','ISBN','Publisher','Acquired'];
    foreach ($rows as $r) {
        $htmlRows[] = '<tr>'
            . '<td class="fw-semibold">' . h($r['AccessionNumber']) . '</td>'
            . '<td>' . h($r['Title']) . '</td>'
            . '<td>' . h($r['ISBN'] ?: '—') . '</td>'
            . '<td>' . h($r['PublisherName'] ?: '—') . '</td>'
            . '<td>' . fmt_date($r['AcquisitionDate']) . '</td>'
            . '</tr>';
    }
    break;
}

case 'books_damaged_lost': {
    $where  = "bc.Status IN ('Damaged','Lost') AND bc.IsArchived = 0";
    $params = [];
    if ($dateActive) { $where .= ' AND b.AcquisitionDate BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (b.Title LIKE ? OR bc.AccessionNumber LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; }

    $rows = fetch_all(
        "SELECT bc.AccessionNumber, b.Title, bc.Status, p.PublisherName,
                (SELECT rd.ReturnCondition FROM return_detail rd
                  JOIN loan_detail ld ON ld.LoanDetailID = rd.LoanDetailID
                 WHERE ld.CopyID = bc.CopyID
                 ORDER BY rd.ReturnDetailID DESC LIMIT 1) AS LastCondition,
                (SELECT rd.ReturnDate FROM return_detail rd
                  JOIN loan_detail ld ON ld.LoanDetailID = rd.LoanDetailID
                 WHERE ld.CopyID = bc.CopyID
                 ORDER BY rd.ReturnDetailID DESC LIMIT 1) AS LastReturnDate
           FROM book_copy bc
           JOIN book b ON b.BookID = bc.BookID
           LEFT JOIN publisher p ON p.PublisherID = b.PublisherID
          WHERE $where ORDER BY bc.Status, b.Title, bc.AccessionNumber", $params);

    $damaged = 0; $lost = 0;
    foreach ($rows as $r) { if ($r['Status'] === 'Damaged') $damaged++; else $lost++; }
    $summary = [
        ['label' => 'Total Damaged/Lost', 'value' => count($rows)],
        ['label' => 'Damaged', 'value' => $damaged],
        ['label' => 'Lost',    'value' => $lost],
    ];
    $columns = ['Accession #','Title','Publisher','Status','Condition','Last Return'];
    foreach ($rows as $r) {
        $statusHtml = $r['Status'] === 'Damaged'
            ? '<span class="badge text-bg-danger">Damaged</span>'
            : '<span class="badge text-bg-dark">Lost</span>';
        $htmlRows[] = '<tr>'
            . '<td class="fw-semibold">' . h($r['AccessionNumber']) . '</td>'
            . '<td>' . h($r['Title']) . '</td>'
            . '<td>' . h($r['PublisherName'] ?: '—') . '</td>'
            . '<td>' . $statusHtml . '</td>'
            . '<td>' . h($r['LastCondition'] ?: '—') . '</td>'
            . '<td>' . ($r['LastReturnDate'] ? fmt_date($r['LastReturnDate']) : '—') . '</td>'
            . '</tr>';
    }
    break;
}

case 'books_donated': {
    $where  = 'd.DonorID IS NOT NULL';
    $params = [];
    if ($dateActive) { $where .= ' AND b.AcquisitionDate BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (b.Title LIKE ? OR d.DonorName LIKE ? OR bc.AccessionNumber LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike; }

    $rows = fetch_all(
        "SELECT bc.AccessionNumber, b.Title, b.AcquisitionDate, bc.Status,
                d.DonorName, d.DonorType
           FROM book_copy bc
           JOIN book b ON b.BookID = bc.BookID
           JOIN donor d ON d.DonorID = b.DonorID
          WHERE $where ORDER BY b.AcquisitionDate DESC, b.Title", $params);

    $donors = [];
    foreach ($rows as $r) { $donors[$r['DonorName']] = true; }
    $summary = [
        ['label' => 'Donated Copies', 'value' => count($rows)],
        ['label' => 'Total Donors',   'value' => count($donors)],
    ];
    $columns = ['Accession #','Book','Donor','Donor Type','Acquired','Status'];
    foreach ($rows as $r) {
        $htmlRows[] = '<tr>'
            . '<td class="fw-semibold">' . h($r['AccessionNumber']) . '</td>'
            . '<td>' . h($r['Title']) . '</td>'
            . '<td>' . h($r['DonorName']) . '</td>'
            . '<td>' . h($r['DonorType'] ?: '—') . '</td>'
            . '<td>' . fmt_date($r['AcquisitionDate']) . '</td>'
            . '<td>' . status_badge($r['Status']) . '</td>'
            . '</tr>';
    }
    break;
}

case 'borrowing_history': {
    $where  = '1=1';
    $params = [];

    if ($fromDate !== '') { $where .= ' AND DATE(l.LoanDate) >= ?'; $params[] = $fromDate; }
    if ($toDate   !== '') { $where .= ' AND DATE(l.LoanDate) <= ?'; $params[] = $toDate;   }
    if ($search !== '') {
        $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ? OR b.Title LIKE ? OR bc.AccessionNumber LIKE ?)';
        $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike;
    }
    if ($borrowerId !== '') { $where .= ' AND l.BorrowerUserID = ?'; $params[] = $borrowerId; }

    if ($borrowStatus === 'Borrowed') {
        $where .= " AND rd.ReturnDetailID IS NULL AND ld.DueDate >= CURDATE()";
    } elseif ($borrowStatus === 'Returned') {
        $where .= " AND rd.ReturnDetailID IS NOT NULL";
    } elseif ($borrowStatus === 'Overdue') {
        $where .= " AND rd.ReturnDetailID IS NULL AND ld.DueDate < CURDATE()";
    }

    $rows = fetch_all(
        "SELECT l.LoanID, l.LoanDate, ld.LoanDetailID, ld.DueDate,
                m.UserID AS MemberID, m.FirstName, m.LastName,
                b.Title, bc.AccessionNumber,
                rd.ReturnDate
           FROM loan l
           JOIN user_account m ON m.UserID = l.BorrowerUserID
           JOIN loan_detail ld ON ld.LoanID = l.LoanID
           JOIN book_copy bc ON bc.CopyID = ld.CopyID
           JOIN book b ON b.BookID = bc.BookID
           LEFT JOIN return_detail rd
                  ON rd.ReturnDetailID = (
                      SELECT MAX(rd2.ReturnDetailID) FROM return_detail rd2
                       WHERE rd2.LoanDetailID = ld.LoanDetailID
                  )
          WHERE $where
          ORDER BY l.LoanDate DESC, ld.LoanDetailID DESC",
        $params
    );

    $borrowedCnt = 0; $overdueCnt = 0; $returnedCnt = 0; $lateCnt = 0;
    $today = strtotime(date('Y-m-d') . ' 23:59:59');
    foreach ($rows as $row) {
        if (!empty($row['ReturnDate'])) {
            $returnedCnt++;
            if (strtotime($row['ReturnDate']) > strtotime($row['DueDate'] . ' 23:59:59')) $lateCnt++;
        } elseif (strtotime($row['DueDate'] . ' 23:59:59') < $today) {
            $overdueCnt++;
        } else {
            $borrowedCnt++;
        }
    }
    $summary = [
        ['label' => 'Total Records',      'value' => count($rows)],
        ['label' => 'Currently Borrowed', 'value' => $borrowedCnt],
        ['label' => 'Overdue',            'value' => $overdueCnt],
        ['label' => 'Returned',           'value' => $returnedCnt],
        ['label' => 'Returned Late',      'value' => $lateCnt],
    ];
    $columns = ['Loan #','Borrower','Book','Accession #','Borrow Date','Due Date','Return Date','Status'];
    foreach ($rows as $row) {
        if (!empty($row['ReturnDate'])) {
            $late = strtotime($row['ReturnDate']) > strtotime($row['DueDate'] . ' 23:59:59');
            $statusHtml = $late
                ? '<span class="badge text-bg-info">Returned Late</span>'
                : '<span class="badge text-bg-success">Returned</span>';
        } elseif (strtotime($row['DueDate'] . ' 23:59:59') < $today) {
            $statusHtml = status_badge('Overdue');
        } else {
            $statusHtml = status_badge('Borrowed');
        }
        $returnCell = $row['ReturnDate'] ? fmt_date($row['ReturnDate']) : '<span class="text-muted">—</span>';

        $htmlRows[] = '<tr>'
            . '<td>#' . h($row['LoanID']) . '</td>'
            . '<td class="fw-semibold">' . h($row['FirstName'] . ' ' . $row['LastName']) . '</td>'
            . '<td>' . h($row['Title']) . '</td>'
            . '<td>' . h($row['AccessionNumber']) . '</td>'
            . '<td>' . fmt_datetime($row['LoanDate']) . '</td>'
            . '<td>' . fmt_date($row['DueDate']) . '</td>'
            . '<td>' . $returnCell . '</td>'
            . '<td>' . $statusHtml . '</td>'
            . '</tr>';
    }
    break;
}

case 'loans_current': {
    $where  = "rd.ReturnDetailID IS NULL AND bc.Status = 'Borrowed'";
    $params = [];
    if ($dateActive) { $where .= ' AND ld.DueDate BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ? OR b.Title LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike; }
    if ($borrowerId !== '') { $where .= ' AND l.BorrowerUserID = ?'; $params[] = $borrowerId; }

    $rows = fetch_all(
        "SELECT l.LoanID, l.LoanDate, ld.DueDate,
                m.FirstName, m.LastName,
                b.Title, bc.AccessionNumber
           FROM loan_detail ld
           JOIN loan l       ON l.LoanID   = ld.LoanID
           JOIN user_account m ON m.UserID = l.BorrowerUserID
           JOIN book_copy bc ON bc.CopyID  = ld.CopyID
           JOIN book b       ON b.BookID   = bc.BookID
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
          WHERE $where
          ORDER BY ld.DueDate ASC", $params);

    $summary = [
        ['label' => 'Current Items', 'value' => count($rows)],
        ['label' => 'Unique Loans',  'value' => count(array_unique(array_column($rows, 'LoanID')))],
    ];
    $columns = ['Loan #','Member','Book','Accession #','Borrow Date','Due Date','Status'];
    foreach ($rows as $r) {
        $overdue = strtotime($r['DueDate'] . ' 23:59:59') < strtotime(date('Y-m-d') . ' 23:59:59');
        $htmlRows[] = '<tr>'
            . '<td>#' . h($r['LoanID']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td>' . h($r['Title']) . '</td>'
            . '<td>' . h($r['AccessionNumber']) . '</td>'
            . '<td>' . fmt_datetime($r['LoanDate']) . '</td>'
            . '<td class="' . ($overdue ? 'text-danger fw-semibold' : '') . '">' . fmt_date($r['DueDate']) . '</td>'
            . '<td>' . ($overdue ? status_badge('Overdue') : status_badge('Borrowed')) . '</td>'
            . '</tr>';
    }
    break;
}

case 'loans_overdue': {
    $where  = "rd.ReturnDetailID IS NULL AND bc.Status = 'Borrowed' AND ld.DueDate < CURDATE()";
    $params = [];
    if ($dateActive) { $where .= ' AND ld.DueDate BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ? OR b.Title LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike; }
    if ($borrowerId !== '') { $where .= ' AND l.BorrowerUserID = ?'; $params[] = $borrowerId; }

    $rows = fetch_all(
        "SELECT l.LoanID, ld.DueDate,
                m.FirstName, m.LastName, m.Email, m.Phone,
                b.Title, bc.AccessionNumber,
                DATEDIFF(CURDATE(), ld.DueDate) AS DaysOverdue
           FROM loan_detail ld
           JOIN loan l       ON l.LoanID   = ld.LoanID
           JOIN user_account m ON m.UserID = l.BorrowerUserID
           JOIN book_copy bc ON bc.CopyID  = ld.CopyID
           JOIN book b       ON b.BookID   = bc.BookID
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
          WHERE $where
          ORDER BY DaysOverdue DESC", $params);

    $totalDays = 0;
    foreach ($rows as $r) $totalDays += (int)$r['DaysOverdue'];
    $summary = [
        ['label' => 'Overdue Items',   'value' => count($rows)],
        ['label' => 'Total Days Late', 'value' => $totalDays],
    ];
    $columns = ['Loan #','Member','Contact','Book','Accession #','Due Date','Days Overdue'];
    foreach ($rows as $r) {
        $contact = trim(($r['Email'] ?? '') . ' ' . ($r['Phone'] ?? ''));
        $htmlRows[] = '<tr>'
            . '<td>#' . h($r['LoanID']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td class="small">' . h($contact ?: '—') . '</td>'
            . '<td>' . h($r['Title']) . '</td>'
            . '<td>' . h($r['AccessionNumber']) . '</td>'
            . '<td class="text-danger fw-semibold">' . fmt_date($r['DueDate']) . '</td>'
            . '<td><span class="badge text-bg-danger">' . h($r['DaysOverdue']) . ' day(s)</span></td>'
            . '</tr>';
    }
    break;
}

case 'loans_partial': {
    $where  = '';
    $params = [];
    if ($search !== '') {
        $where = 'AND (m.FirstName LIKE ? OR m.LastName LIKE ?)';
        $params[] = $searchLike; $params[] = $searchLike;
    }
    if ($borrowerId !== '') { $where .= ' AND l.BorrowerUserID = ?'; $params[] = $borrowerId; }

    $rows = fetch_all(
        "SELECT l.LoanID, l.LoanDate,
                m.FirstName, m.LastName,
                COUNT(ld.LoanDetailID) AS TotalItems,
                SUM(CASE WHEN rd.ReturnDetailID IS NULL THEN 1 ELSE 0 END) AS StillBorrowed,
                SUM(CASE WHEN rd.ReturnDetailID IS NOT NULL THEN 1 ELSE 0 END) AS ReturnedItems
           FROM loan l
           JOIN user_account m ON m.UserID = l.BorrowerUserID
           JOIN loan_detail ld ON ld.LoanID = l.LoanID
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
          WHERE 1=1 $where
          GROUP BY l.LoanID, l.LoanDate, m.FirstName, m.LastName
          HAVING StillBorrowed > 0 AND StillBorrowed < TotalItems
          ORDER BY l.LoanID DESC", $params);

    $summary = [
        ['label' => 'Partial Loans',   'value' => count($rows)],
        ['label' => 'Items Still Out', 'value' => array_sum(array_column($rows, 'StillBorrowed'))],
    ];
    $columns = ['Loan #','Member','Borrow Date','Returned','Still Out','Total'];
    foreach ($rows as $r) {
        $htmlRows[] = '<tr>'
            . '<td>#' . h($r['LoanID']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td>' . fmt_datetime($r['LoanDate']) . '</td>'
            . '<td><span class="badge text-bg-success">' . h($r['ReturnedItems']) . '</span></td>'
            . '<td><span class="badge text-bg-warning">' . h($r['StillBorrowed']) . '</span></td>'
            . '<td>' . h($r['TotalItems']) . '</td>'
            . '</tr>';
    }
    break;
}

case 'loans_completed': {
    $where  = '';
    $params = [];
    if ($dateActive) { $where = 'AND DATE(l.LoanDate) BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') {
        $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ?)';
        $params[] = $searchLike; $params[] = $searchLike;
    }
    if ($borrowerId !== '') { $where .= ' AND l.BorrowerUserID = ?'; $params[] = $borrowerId; }

    $rows = fetch_all(
        "SELECT l.LoanID, l.LoanDate,
                m.FirstName, m.LastName,
                COUNT(ld.LoanDetailID) AS TotalItems,
                MAX(rd.ReturnDate) AS LastReturnDate
           FROM loan l
           JOIN user_account m ON m.UserID = l.BorrowerUserID
           JOIN loan_detail ld ON ld.LoanID = l.LoanID
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
          WHERE 1=1 $where
          GROUP BY l.LoanID, l.LoanDate, m.FirstName, m.LastName
          HAVING SUM(CASE WHEN rd.ReturnDetailID IS NULL THEN 1 ELSE 0 END) = 0
          ORDER BY l.LoanID DESC", $params);

    $summary = [
        ['label' => 'Completed Loans', 'value' => count($rows)],
        ['label' => 'Total Items',     'value' => array_sum(array_column($rows, 'TotalItems'))],
    ];
    $columns = ['Loan #','Member','Borrow Date','Last Return','Total Items'];
    foreach ($rows as $r) {
        $htmlRows[] = '<tr>'
            . '<td>#' . h($r['LoanID']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td>' . fmt_datetime($r['LoanDate']) . '</td>'
            . '<td>' . ($r['LastReturnDate'] ? fmt_date($r['LastReturnDate']) : '—') . '</td>'
            . '<td>' . h($r['TotalItems']) . '</td>'
            . '</tr>';
    }
    break;
}

case 'borrow_requests': {
    $where = '1=1'; $params = [];
    if ($dateActive) { $where .= ' AND DATE(rq.RequestDate) BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($borrowerId !== '') { $where .= ' AND rq.UserID = ?'; $params[] = $borrowerId; }
    if ($search !== '') {
        $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ? OR EXISTS (SELECT 1 FROM borrow_request_detail d JOIN book b ON b.BookID = d.BookID WHERE d.RequestID = rq.RequestID AND b.Title LIKE ?))';
        array_push($params, $searchLike, $searchLike, $searchLike);
    }
    $rows = fetch_all(
        "SELECT rq.RequestID, rq.RequestType, rq.RequestDate, rq.ApprovalStatus, rq.ApprovalDate, rq.DecisionReason,
                m.FirstName, m.LastName, s.FirstName AS SF, s.LastName AS SL,
                (SELECT GROUP_CONCAT(b.Title SEPARATOR ', ') FROM borrow_request_detail d JOIN book b ON b.BookID = d.BookID WHERE d.RequestID = rq.RequestID) AS Books,
                (SELECT l.LoanID FROM loan l WHERE l.RequestID = rq.RequestID LIMIT 1) AS LoanID
           FROM borrow_request rq
           JOIN user_account m ON m.UserID = rq.UserID
           LEFT JOIN user_account s ON s.UserID = rq.ApprovedByUserID
          WHERE $where ORDER BY rq.RequestID DESC", $params);
    $tally = [];
    foreach ($rows as $r) $tally[$r['ApprovalStatus']] = ($tally[$r['ApprovalStatus']] ?? 0) + 1;
    $summary = [['label' => 'Requests', 'value' => count($rows)]];
    foreach (['Pending','Approved','Fulfilled','Rejected','Cancelled','Expired'] as $st) {
        if (!empty($tally[$st])) $summary[] = ['label' => $st, 'value' => $tally[$st]];
    }
    $columns = ['#','Type','Member','Book(s)','Requested','Status','Decided','Decided by / reason','Loan #'];
    foreach ($rows as $r) {
        $by = trim(($r['SF'] ?? '') . ' ' . ($r['SL'] ?? ''));
        $htmlRows[] = '<tr><td>#' . h($r['RequestID']) . '</td><td>' . h($r['RequestType']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td>' . h($r['Books'] ?: '—') . '</td><td>' . fmt_datetime($r['RequestDate']) . '</td>'
            . '<td>' . status_badge($r['ApprovalStatus']) . '</td>'
            . '<td>' . ($r['ApprovalDate'] ? fmt_date($r['ApprovalDate']) : '—') . '</td>'
            . '<td class="small">' . h(trim($by . ($r['DecisionReason'] ? ' — ' . $r['DecisionReason'] : '')) ?: '—') . '</td>'
            . '<td>' . ($r['LoanID'] ? '#' . h($r['LoanID']) : '—') . '</td></tr>';
    }
    break;
}

case 'returns_history': {
    $where = '1=1'; $params = [];
    if ($dateActive) { $where .= ' AND DATE(rd.ReturnDate) BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($borrowerId !== '') { $where .= ' AND l.BorrowerUserID = ?'; $params[] = $borrowerId; }
    if ($search !== '') { $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ? OR b.Title LIKE ?)'; array_push($params, $searchLike, $searchLike, $searchLike); }
    $rows = fetch_all(
        "SELECT rd.ReturnDate, rd.ReturnCondition, l.LoanID, ld.DueDate, m.FirstName, m.LastName,
                b.Title, bc.AccessionNumber,
                (SELECT COALESCE(SUM(f.FineAmount),0) FROM fine f WHERE f.LoanDetailID = ld.LoanDetailID) AS FineTotal
           FROM return_detail rd
           JOIN loan_detail ld ON ld.LoanDetailID = rd.LoanDetailID
           JOIN loan l ON l.LoanID = ld.LoanID
           JOIN user_account m ON m.UserID = l.BorrowerUserID
           JOIN book_copy bc ON bc.CopyID = ld.CopyID
           JOIN book b ON b.BookID = bc.BookID
          WHERE $where ORDER BY rd.ReturnDate DESC", $params);
    $late = 0; $fines = 0.0;
    foreach ($rows as $r) { if (substr((string)$r['ReturnDate'],0,10) > $r['DueDate']) $late++; $fines += (float)$r['FineTotal']; }
    $summary = [
        ['label' => 'Items Returned', 'value' => count($rows)],
        ['label' => 'Returned Late',  'value' => $late],
        ['label' => 'Fines Charged',  'value' => money($fines)],
    ];
    $columns = ['Returned','Loan #','Member','Book','Accession #','Due Date','Condition','Fine'];
    foreach ($rows as $r) {
        $isLate = substr((string)$r['ReturnDate'],0,10) > $r['DueDate'];
        $htmlRows[] = '<tr><td>' . fmt_datetime($r['ReturnDate']) . '</td><td>#' . h($r['LoanID']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td>' . h($r['Title']) . '</td><td>' . h($r['AccessionNumber']) . '</td>'
            . '<td class="' . ($isLate ? 'text-danger' : '') . '">' . fmt_date($r['DueDate']) . '</td>'
            . '<td>' . h($r['ReturnCondition'] ?: '—') . '</td>'
            . '<td>' . ((float)$r['FineTotal'] > 0 ? money($r['FineTotal']) : '—') . '</td></tr>';
    }
    break;
}

case 'members_all': {
    $where  = "r.RoleName = 'Member'";
    $params = [];
    if ($dateActive) { $where .= ' AND m.RegistrationDate BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ? OR m.Email LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike; }

    $rows = fetch_all(
        "SELECT m.UserID AS MemberID, m.FirstName, m.LastName, m.Email, m.Phone,
                m.RegistrationDate, m.MembershipStatus, m.IsActive,
                (SELECT COUNT(*) FROM loan l WHERE l.BorrowerUserID = m.UserID) AS LoanCount
           FROM user_account m
           JOIN role r ON r.RoleID = m.RoleID
          WHERE $where
          ORDER BY m.LastName, m.FirstName", $params);

    $active = 0; $inactive = 0; $expired = 0;
    foreach ($rows as $r) {
        if ($r['MembershipStatus'] === 'Active') $active++;
        elseif ($r['MembershipStatus'] === 'Inactive') $inactive++;
        elseif ($r['MembershipStatus'] === 'Expired') $expired++;
    }
    $summary = [
        ['label' => 'Total Members', 'value' => count($rows)],
        ['label' => 'Active',        'value' => $active],
        ['label' => 'Inactive',      'value' => $inactive],
        ['label' => 'Expired',       'value' => $expired],
    ];
    $columns = ['Member #','Name','Email','Phone','Registered','Membership','Loans'];
    foreach ($rows as $r) {
        $htmlRows[] = '<tr>'
            . '<td>#' . h($r['MemberID']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td>' . h($r['Email']) . '</td>'
            . '<td>' . h($r['Phone'] ?: '—') . '</td>'
            . '<td>' . fmt_date($r['RegistrationDate']) . '</td>'
            . '<td>' . membership_badge($r['MembershipStatus']) . '</td>'
            . '<td>' . h($r['LoanCount']) . '</td>'
            . '</tr>';
    }
    break;
}

case 'members_active': {
    $where  = "rd.ReturnDetailID IS NULL AND bc.Status = 'Borrowed'";
    $params = [];
    if ($search !== '') {
        $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ?)';
        $params[] = $searchLike; $params[] = $searchLike;
    }
    if ($borrowerId !== '') { $where .= ' AND m.UserID = ?'; $params[] = $borrowerId; }

    $rows = fetch_all(
        "SELECT m.UserID AS MemberID, m.FirstName, m.LastName, m.Email, m.Phone,
                m.MembershipStatus,
                COUNT(DISTINCT l.LoanID) AS ActiveLoans,
                COUNT(ld.LoanDetailID) AS ActiveItems,
                MIN(ld.DueDate) AS NextDue,
                SUM(CASE WHEN ld.DueDate < CURDATE() THEN 1 ELSE 0 END) AS OverdueItems
           FROM user_account m
           JOIN role r ON r.RoleID = m.RoleID
           JOIN loan l ON l.BorrowerUserID = m.UserID
           JOIN loan_detail ld ON ld.LoanID = l.LoanID
           JOIN book_copy bc ON bc.CopyID = ld.CopyID
           LEFT JOIN return_detail rd ON rd.LoanDetailID = ld.LoanDetailID
          WHERE r.RoleName = 'Member' AND $where
          GROUP BY m.UserID, m.FirstName, m.LastName, m.Email, m.Phone, m.MembershipStatus
          ORDER BY m.LastName, m.FirstName", $params);

    $summary = [
        ['label' => 'Active Borrowers', 'value' => count($rows)],
        ['label' => 'Items Out',        'value' => array_sum(array_column($rows, 'ActiveItems'))],
        ['label' => 'Overdue Items',    'value' => array_sum(array_column($rows, 'OverdueItems'))],
    ];
    $columns = ['Member #','Name','Email','Phone','Active Loans','Items Out','Next Due','Overdue'];
    foreach ($rows as $r) {
        $overdueCell = (int)$r['OverdueItems'] > 0
            ? '<span class="badge text-bg-danger">' . h($r['OverdueItems']) . '</span>'
            : '<span class="text-muted">0</span>';
        $htmlRows[] = '<tr>'
            . '<td>#' . h($r['MemberID']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td>' . h($r['Email']) . '</td>'
            . '<td>' . h($r['Phone'] ?: '—') . '</td>'
            . '<td>' . h($r['ActiveLoans']) . '</td>'
            . '<td><span class="badge text-bg-warning">' . h($r['ActiveItems']) . '</span></td>'
            . '<td>' . ($r['NextDue'] ? fmt_date($r['NextDue']) : '—') . '</td>'
            . '<td>' . $overdueCell . '</td>'
            . '</tr>';
    }
    break;
}

case 'fines': {
    $where  = '1=1';
    $params = [];
    if ($dateActive) { $where .= ' AND DATE(f.FineDate) BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ? OR b.Title LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike; }
    if ($borrowerId !== '') { $where .= ' AND l.BorrowerUserID = ?'; $params[] = $borrowerId; }

    $rows = fetch_all(
        "SELECT f.FineID, f.FineAmount, f.Reason, f.FineDate, f.PaymentStatus,
                m.FirstName, m.LastName,
                b.Title, bc.AccessionNumber,
                (SELECT COALESCE(SUM(p.PaidAmount),0) FROM payment p WHERE p.FineID = f.FineID) AS PaidSoFar
           FROM fine f
           JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
           JOIN loan l         ON l.LoanID        = ld.LoanID
           JOIN user_account m ON m.UserID        = l.BorrowerUserID
           JOIN book_copy bc   ON bc.CopyID       = ld.CopyID
           JOIN book b         ON b.BookID        = bc.BookID
          WHERE $where
          ORDER BY f.FineDate DESC, f.FineID DESC", $params);

    $tAmt = 0; $tPaid = 0; $up = 0; $pd = 0;
    foreach ($rows as $r) {
        $tAmt  += (float)$r['FineAmount'];
        $tPaid += (float)$r['PaidSoFar'];
        if ($r['PaymentStatus'] === 'Unpaid') $up++; else $pd++;
    }
    $summary = [
        ['label' => 'Total Fines',     'value' => count($rows)],
        ['label' => 'Unpaid',          'value' => $up],
        ['label' => 'Paid',            'value' => $pd],
        ['label' => 'Total Billed',    'value' => money($tAmt)],
        ['label' => 'Total Collected', 'value' => money($tPaid)],
    ];
    $columns = ['Fine #','Member','Book','Reason','Amount','Paid','Status','Date'];
    foreach ($rows as $r) {
        $htmlRows[] = '<tr>'
            . '<td>#' . h($r['FineID']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td>' . h($r['Title']) . '<div class="small text-muted">Copy ' . h($r['AccessionNumber']) . '</div></td>'
            . '<td>' . h($r['Reason']) . '</td>'
            . '<td class="fw-semibold">' . money($r['FineAmount']) . '</td>'
            . '<td>' . money($r['PaidSoFar']) . '</td>'
            . '<td>' . status_badge($r['PaymentStatus']) . '</td>'
            . '<td>' . fmt_date($r['FineDate']) . '</td>'
            . '</tr>';
    }
    break;
}

case 'fines_unpaid': {
    $where  = "f.PaymentStatus = 'Unpaid'";
    $params = [];
    if ($dateActive) { $where .= ' AND DATE(f.FineDate) BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ? OR b.Title LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike; }
    if ($borrowerId !== '') { $where .= ' AND l.BorrowerUserID = ?'; $params[] = $borrowerId; }

    $rows = fetch_all(
        "SELECT f.FineID, f.FineAmount, f.Reason, f.FineDate,
                m.FirstName, m.LastName, m.Email, m.Phone,
                b.Title
           FROM fine f
           JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
           JOIN loan l         ON l.LoanID        = ld.LoanID
           JOIN user_account m ON m.UserID        = l.BorrowerUserID
           JOIN book_copy bc   ON bc.CopyID       = ld.CopyID
           JOIN book b         ON b.BookID        = bc.BookID
          WHERE $where
          ORDER BY f.FineDate DESC", $params);

    $total = 0;
    foreach ($rows as $r) $total += (float)$r['FineAmount'];
    $summary = [
        ['label' => 'Unpaid Fines',      'value' => count($rows)],
        ['label' => 'Total Outstanding', 'value' => money($total)],
    ];
    $columns = ['Fine #','Member','Contact','Book','Reason','Amount','Date'];
    foreach ($rows as $r) {
        $contact = trim(($r['Email'] ?? '') . ' ' . ($r['Phone'] ?? ''));
        $htmlRows[] = '<tr>'
            . '<td>#' . h($r['FineID']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td class="small">' . h($contact ?: '—') . '</td>'
            . '<td>' . h($r['Title']) . '</td>'
            . '<td>' . h($r['Reason']) . '</td>'
            . '<td class="fw-semibold text-danger">' . money($r['FineAmount']) . '</td>'
            . '<td>' . fmt_date($r['FineDate']) . '</td>'
            . '</tr>';
    }
    break;
}

case 'fines_paid': {
    $where  = "f.PaymentStatus = 'Paid'";
    $params = [];
    if ($dateActive) { $where .= ' AND DATE(f.FineDate) BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ? OR b.Title LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike; }
    if ($borrowerId !== '') { $where .= ' AND l.BorrowerUserID = ?'; $params[] = $borrowerId; }

    $rows = fetch_all(
        "SELECT f.FineID, f.FineAmount, f.Reason, f.FineDate,
                m.FirstName, m.LastName,
                b.Title,
                (SELECT COALESCE(SUM(p.PaidAmount),0) FROM payment p WHERE p.FineID = f.FineID) AS PaidSoFar
           FROM fine f
           JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
           JOIN loan l         ON l.LoanID        = ld.LoanID
           JOIN user_account m ON m.UserID        = l.BorrowerUserID
           JOIN book_copy bc   ON bc.CopyID       = ld.CopyID
           JOIN book b         ON b.BookID        = bc.BookID
          WHERE $where
          ORDER BY f.FineDate DESC", $params);

    $total = 0;
    foreach ($rows as $r) $total += (float)$r['FineAmount'];
    $summary = [
        ['label' => 'Paid Fines',    'value' => count($rows)],
        ['label' => 'Total Settled', 'value' => money($total)],
    ];
    $columns = ['Fine #','Member','Book','Reason','Amount','Paid','Date'];
    foreach ($rows as $r) {
        $htmlRows[] = '<tr>'
            . '<td>#' . h($r['FineID']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td>' . h($r['Title']) . '</td>'
            . '<td>' . h($r['Reason']) . '</td>'
            . '<td>' . money($r['FineAmount']) . '</td>'
            . '<td class="text-success">' . money($r['PaidSoFar']) . '</td>'
            . '<td>' . fmt_date($r['FineDate']) . '</td>'
            . '</tr>';
    }
    break;
}

case 'payments_history': {
    $where  = '1=1';
    $params = [];
    if ($dateActive) { $where .= ' AND DATE(p.PaymentDate) BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (m.FirstName LIKE ? OR m.LastName LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; }
    if ($borrowerId !== '') { $where .= ' AND l.BorrowerUserID = ?'; $params[] = $borrowerId; }

    $rows = fetch_all(
        "SELECT p.PaymentID, p.PaidAmount, p.PaymentMethod, p.PaymentDate,
                f.FineID, f.Reason,
                m.FirstName, m.LastName, b.Title
           FROM payment p
           JOIN fine f         ON f.FineID        = p.FineID
           JOIN loan_detail ld ON ld.LoanDetailID = f.LoanDetailID
           JOIN loan l         ON l.LoanID        = ld.LoanID
           JOIN user_account m ON m.UserID        = l.BorrowerUserID
           JOIN book_copy bc   ON bc.CopyID       = ld.CopyID
           JOIN book b         ON b.BookID        = bc.BookID
          WHERE $where
          ORDER BY p.PaymentID DESC", $params);

    $total = 0;
    foreach ($rows as $r) $total += (float)$r['PaidAmount'];
    $summary = [
        ['label' => 'Payments',     'value' => count($rows)],
        ['label' => 'Total Amount', 'value' => money($total)],
    ];
    $columns = ['Receipt #','Member','Fine #','Book','Reason','Amount','Method','Date'];
    foreach ($rows as $r) {
        $htmlRows[] = '<tr>'
            . '<td>#' . h($r['PaymentID']) . '</td>'
            . '<td class="fw-semibold">' . h($r['FirstName'] . ' ' . $r['LastName']) . '</td>'
            . '<td>#' . h($r['FineID']) . '</td>'
            . '<td>' . h($r['Title']) . '</td>'
            . '<td>' . h($r['Reason']) . '</td>'
            . '<td class="fw-semibold">' . money($r['PaidAmount']) . '</td>'
            . '<td>' . h($r['PaymentMethod']) . '</td>'
            . '<td>' . fmt_datetime($r['PaymentDate']) . '</td>'
            . '</tr>';
    }
    break;
}

case 'donations': {
    $where  = '1=1';
    $params = [];
    if ($dateActive) { $where .= ' AND b.AcquisitionDate BETWEEN ? AND ?'; $params[] = $fromDate; $params[] = $toDate; }
    if ($search !== '') { $where .= ' AND (d.DonorName LIKE ? OR b.Title LIKE ?)'; $params[] = $searchLike; $params[] = $searchLike; }

    $rows = fetch_all(
        "SELECT d.DonorID, d.DonorName, d.DonorType, d.ContactInfo,
                b.Title, b.AcquisitionDate,
                bc.AccessionNumber, bc.Status
           FROM book_copy bc
           JOIN book b ON b.BookID = bc.BookID
           JOIN donor d ON d.DonorID = b.DonorID
          WHERE $where
          ORDER BY b.AcquisitionDate DESC, d.DonorName, b.Title", $params);

    $donors = [];
    foreach ($rows as $r) { $donors[$r['DonorID']] = true; }
    $summary = [
        ['label' => 'Donated Copies', 'value' => count($rows)],
        ['label' => 'Total Donors',   'value' => count($donors)],
    ];
    $columns = ['Donor','Donor Type','Contact','Book','Accession #','Acquired','Status'];
    foreach ($rows as $r) {
        $htmlRows[] = '<tr>'
            . '<td class="fw-semibold">' . h($r['DonorName']) . '</td>'
            . '<td>' . h($r['DonorType'] ?: '—') . '</td>'
            . '<td class="small">' . h($r['ContactInfo'] ?: '—') . '</td>'
            . '<td>' . h($r['Title']) . '</td>'
            . '<td>' . h($r['AccessionNumber']) . '</td>'
            . '<td>' . fmt_date($r['AcquisitionDate']) . '</td>'
            . '<td>' . status_badge($r['Status']) . '</td>'
            . '</tr>';
    }
    break;
}

default: $emptyMsg = 'Report not recognized.';
}

$activeTitle = 'Report';
foreach ($reportGroups as $items) if (isset($items[$report])) { $activeTitle = $items[$report]; break; }

$totalRows = count($htmlRows);
$pg = pagination_calc($totalRows, PER_PAGE_DEFAULT, pagination_page());
if ($pg['total_pages'] > 1) {
    $htmlRows = array_slice($htmlRows, $pg['offset'], $pg['per_page']);
}

/* ---------- PRINT MODE ---------- */
if (isset($_GET['print'])) {
    $staffName = 'Staff';
    $s = current_staff();
    if ($s && !empty($s['Name'])) $staffName = $s['Name'];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($activeTitle) ?> — Bibliophilia Library</title>
<style>
  @page { size: A4; margin: 14mm 12mm; }
  body { font-family: 'Helvetica Neue', 'Segoe UI', Arial, sans-serif; font-size: 10.5px; color: #1a1a1a; background: #eef1f5; margin: 0; }
  .pdf-toolbar { position: sticky; top: 0; z-index: 100; background: #0f2942; color: #fff; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; }
  .pdf-toolbar .title { font-size: 14px; font-weight: 600; }
  .pdf-toolbar .actions a, .pdf-toolbar .actions button { background: #fff; color: #0f2942; border: none; padding: 7px 16px; border-radius: 4px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; margin-left: 8px; }
  .sheet { max-width: 210mm; margin: 20px auto; background: #fff; padding: 18mm 14mm; box-shadow: 0 4px 18px rgba(0,0,0,.1); }
  .rpt-header { display: flex; justify-content: space-between; padding-bottom: 14px; border-bottom: 3px double #0f2942; margin-bottom: 20px; }
  .rpt-header .brand { font-family: Georgia, serif; font-size: 15px; font-weight: 700; color: #0f2942; }
  .rpt-header .brand-sub { font-size: 9.5px; color: #6b7280; text-transform: uppercase; letter-spacing: 1.5px; }
  .rpt-header .meta { text-align: right; font-size: 9.5px; color: #6b7280; line-height: 1.6; }
  .rpt-title-block { text-align: center; margin-bottom: 22px; }
  .rpt-title-block h1 { font-family: Georgia, serif; font-size: 22px; color: #0f2942; margin: 0 0 6px; }
  .rpt-title-block .range { display: inline-block; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db; padding: 5px 18px; font-size: 11px; color: #374151; }
  .rpt-summary { border: 1px solid #0f2942; border-radius: 3px; padding: 14px 8px; margin-bottom: 22px; background: #fbfcfe; }
  .rpt-summary .label { text-transform: uppercase; font-size: 8.5px; letter-spacing: 1.5px; color: #6b7280; text-align: center; margin-bottom: 10px; font-weight: 700; }
  .rpt-summary .grid { display: flex; justify-content: space-around; flex-wrap: wrap; }
  .rpt-summary .item { flex: 1 1 0; text-align: center; padding: 4px 10px; border-right: 1px solid #e5e7eb; min-width: 100px; }
  .rpt-summary .item:last-child { border-right: none; }
  .rpt-summary .n { font-family: Georgia, serif; font-size: 20px; font-weight: 700; color: #0f2942; }
  .rpt-summary .l { font-size: 8.5px; text-transform: uppercase; letter-spacing: .8px; color: #6b7280; margin-top: 5px; }
  table.rpt-table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
  table.rpt-table thead th { background: #0f2942; color: #fff; text-align: left; padding: 8px 9px; font-size: 8.5px; font-weight: 600; text-transform: uppercase; }
  table.rpt-table tbody td { padding: 6px 9px; border: 1px solid #e5e7eb; }
  table.rpt-table tbody tr:nth-child(even) { background: #f9fafb; }
  .badge { display: inline-block; padding: 2px 6px; font-size: 8px; font-weight: 700; text-transform: uppercase; border-radius: 8px; }
  .text-bg-success { background: #d1fae5; color: #065f46; }
  .text-bg-warning { background: #fef3c7; color: #92400e; }
  .text-bg-danger  { background: #fee2e2; color: #991b1b; }
  .text-bg-info    { background: #dbeafe; color: #1e40af; }
  .text-bg-dark    { background: #1f2937; color: #fff; }
  .text-bg-secondary { background: #e5e7eb; color: #374151; }
  .text-bg-primary { background: #dbeafe; color: #1e40af; }
  .text-bg-light   { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
  .empty-state { text-align: center; padding: 40px 20px; color: #6b7280; font-style: italic; border: 1px dashed #d1d5db; border-radius: 4px; }
  .rpt-footer { margin-top: 32px; padding-top: 16px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; font-size: 9px; color: #6b7280; }
  .signature-line { display: inline-block; min-width: 190px; border-top: 1px solid #374151; padding-top: 4px; margin-top: 32px; font-size: 9px; }
  @media print {
    body { background: #fff; font-size: 10px; }
    .pdf-toolbar { display: none !important; }
    .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; }
    table.rpt-table thead th, table.rpt-table tbody tr:nth-child(even) { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>

<div class="pdf-toolbar">
  <div class="title">Report Preview</div>
  <div class="actions">
    <button onclick="window.print()">Print / Save as PDF</button>
    <a href="reports.php?<?= h(http_build_query(array_diff_key($_GET, ['print' => 1]))) ?>">← Back</a>
  </div>
</div>

<div class="sheet">
  <div class="rpt-header">
    <div>
      <div class="brand">Bibliophilia Library Management System</div>
      <div class="brand-sub">Official Report</div>
    </div>
    <div class="meta">
      <div>Generated on <strong><?= h(date('F j, Y g:i A')) ?></strong></div>
      <div>Generated by <strong><?= h($staffName) ?></strong></div>
      <div>Reference: <strong><?= h(strtoupper(substr($report, 0, 4)) . '-' . date('YmdHis')) ?></strong></div>
    </div>
  </div>

  <div class="rpt-title-block">
    <h1><?= h($activeTitle) ?></h1>
    <div class="range"><?= h(implode('  ·  ', $filterBits)) ?></div>
  </div>

  <?php if ($summary): ?>
    <div class="rpt-summary">
      <div class="label">Summary</div>
      <div class="grid">
        <?php foreach ($summary as $s): ?>
          <div class="item">
            <div class="n"><?= h($s['value']) ?></div>
            <div class="l"><?= h($s['label']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$htmlRows): ?>
    <div class="empty-state"><?= h($emptyMsg) ?></div>
  <?php else: ?>
    <table class="rpt-table">
      <thead><tr><?php foreach ($columns as $col): ?><th><?= h($col) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($htmlRows as $row): ?><?= $row ?><?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>

  <div class="rpt-footer">
    <div>
      <div><strong><?= h($activeTitle) ?></strong></div>
      <div><?= count($htmlRows) ?> record(s) on this page</div>
      <div>System-generated report.</div>
    </div>
    <div style="text-align:center;">
      <div class="signature-line">Prepared by: <?= h($staffName) ?></div>
      <div style="font-size:8.5px;color:#6b7280;">Library Staff</div>
    </div>
  </div>
</div>

<script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
    <?php
    exit;
}

require __DIR__ . '/includes/layout_top.php';
?>

<div class="row g-3">
  <div class="col-lg-3">
    <div class="card">
      <div class="card-header">Report Categories</div>
      <div class="list-group list-group-flush">
        <?php foreach ($reportGroups as $group => $items): ?>
          <div class="list-group-item bg-light fw-semibold small text-uppercase text-muted"><?= h($group) ?></div>
          <?php foreach ($items as $key => $label): ?>
            <a href="reports.php?report=<?= h($key) ?>"
               class="list-group-item list-group-item-action <?= $report === $key ? 'active' : '' ?>">
              <?= h($label) ?>
            </a>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-9">

    <form class="card p-3 mb-3" method="get">
      <input type="hidden" name="report" value="<?= h($report) ?>">
      <div class="row g-2 align-items-end">

        <?php if ($showBorrowerFilter): ?>
          <div class="col-md-4">
            <label class="form-label small mb-1">Borrower</label>
            <select name="borrower_id" class="form-select" onchange="this.form.submit()">
              <option value="">All Borrowers</option>
              <?php foreach ($borrowerOptions as $b): ?>
                <option value="<?= h($b['id']) ?>"
                        <?= (string)$borrowerId === (string)$b['id'] ? 'selected' : '' ?>>
                  <?= h($b['LastName'] . ', ' . $b['FirstName']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div class="col-md-<?= $showBorrowerFilter ? '3' : '4' ?>">
          <label class="form-label small mb-1">From Date</label>
          <input type="date" name="from" class="form-control" value="<?= h($fromDate) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-md-<?= $showBorrowerFilter ? '3' : '4' ?>">
          <label class="form-label small mb-1">To Date</label>
          <input type="date" name="to" class="form-control" value="<?= h($toDate) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-md-<?= $showBorrowerFilter ? '2' : '4' ?>">
          <label class="form-label small mb-1">Search</label>
          <input type="search" name="q" class="form-control" value="<?= h($search) ?>" placeholder="Type to filter…" oninput="debReport(this.form)">
        </div>

        <?php if ($report === 'borrowing_history'): ?>
          <div class="col-md-12">
            <label class="form-label small mb-1">Borrowing Status</label>
            <select name="bstat" class="form-select" onchange="this.form.submit()">
              <option value=""         <?= $borrowStatus === ''         ? 'selected' : '' ?>>All</option>
              <option value="Borrowed" <?= $borrowStatus === 'Borrowed' ? 'selected' : '' ?>>Borrowed</option>
              <option value="Returned" <?= $borrowStatus === 'Returned' ? 'selected' : '' ?>>Returned</option>
              <option value="Overdue"  <?= $borrowStatus === 'Overdue'  ? 'selected' : '' ?>>Overdue</option>
            </select>
          </div>
        <?php endif; ?>

        <?php if ($dateActive || $search !== '' || $borrowerId !== '' || $borrowStatus !== ''): ?>
          <div class="col-12 small text-muted mt-1">
            Active filters:
            <?php if ($borrowerName !== ''): ?>
              <span class="badge text-bg-light border">Borrower: <?= h($borrowerName) ?></span>
            <?php endif; ?>
            <?php if ($dateActive): ?>
              <span class="badge text-bg-light border">Date: <?= fmt_date($fromDate) ?> – <?= fmt_date($toDate) ?></span>
            <?php endif; ?>
            <?php if ($search !== ''): ?>
              <span class="badge text-bg-light border">Search: <?= h($search) ?></span>
            <?php endif; ?>
            <?php if ($borrowStatus !== ''): ?>
              <span class="badge text-bg-light border">Status: <?= h($borrowStatus) ?></span>
            <?php endif; ?>
            <a href="reports.php?report=<?= h($report) ?>" class="ms-2 small">Clear all</a>
          </div>
        <?php endif; ?>
      </div>
    </form>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <div class="fw-semibold"><?= h($activeTitle) ?></div>
          <div class="small text-muted"><?= h(implode(' · ', $filterBits)) ?></div>
        </div>
        <a href="?<?= h(http_build_query(array_merge($_GET, ['print' => 1]))) ?>"
           target="_blank" class="btn btn-sm btn-primary">
          <i class="bi bi-file-earmark-pdf"></i> Generate PDF
        </a>
      </div>

      <div class="px-3 py-2 border-bottom bg-light small text-muted"><?= pagination_info($pg) ?></div>

      <div class="card-body pb-0">
        <div class="border rounded">
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead><tr><?php foreach ($columns as $col): ?><th><?= h($col) ?></th><?php endforeach; ?></tr></thead>
              <tbody>
                <?php if (!$htmlRows): ?>
                  <tr><td colspan="<?= max(1, count($columns)) ?>" class="text-center text-muted py-4">
                    <?= h($emptyMsg) ?>
                  </td></tr>
                <?php endif; ?>
                <?php foreach ($htmlRows as $row): ?><?= $row ?><?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php if ($pg['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($pg, $_GET) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
var _debR;
function debReport(form) {
  clearTimeout(_debR);
  _debR = setTimeout(function () { form.submit(); }, 450);
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>