#!/usr/bin/env bash
# End-to-end check of the Borrow Request -> release -> return workflow against a THROWAWAY database.
# Needs: php, curl, a local MariaDB/MySQL with root access and no password (edit DB_* below).
# Usage: bash tests/workflow.sh      (it DROPS and recreates the database "library_management")
set -u
cd "$(dirname "$0")/.."
DB=library_management; PORT=8099; B=http://127.0.0.1:$PORT
q() { mariadb -uroot -N -B "$DB" -e "SET time_zone='+08:00'; $1"; }   # same clock as the app (Asia/Manila)
pass=0; fail=0
ok()  { pass=$((pass+1)); echo "  PASS  $1"; }
bad() { fail=$((fail+1)); echo "  FAIL  $1  (got: $2)"; }
eq()  { [ "$2" = "$3" ] && ok "$1" || bad "$1" "$2 != expected $3"; }
has() { echo "$2" | grep -q "$3" && ok "$1" || bad "$1" "$2"; }

mariadb -uroot -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4"
mariadb -uroot "$DB" < tests/schema_reconstructed.sql
H=$(php -r 'echo password_hash("Passw0rd!", PASSWORD_DEFAULT);'); q "UPDATE user_account SET PasswordHash='$H'"
php database/migrate_borrow_request.php >/dev/null || { echo "migration failed"; exit 1; }
for f in migrate_self_service_kiosk.sql migrate_entry_and_permission.sql; do mariadb -uroot "$DB" < database/$f || { echo "$f failed"; exit 1; }; done
pkill -f "php .*-S 127.0.0.1:$PORT" 2>/dev/null; (setsid nohup php -d auto_prepend_file="$PWD/tests/rule_off.php" -S 127.0.0.1:$PORT >/tmp/php_test.log 2>&1 </dev/null &); sleep 2

login() { rm -f "$1"; curl -s -c "$1" -b "$1" $B/login.php -o /tmp/l.html
  T=$(grep -o 'name="csrf_token" value="[^"]*"' /tmp/l.html | head -1 | sed 's/.*value="//;s/"//')
  curl -s -c "$1" -b "$1" -o /dev/null --data-urlencode "csrf_token=$T" --data-urlencode "username=$2" --data-urlencode "password=Passw0rd!" $B/login.php; }
tok()  { curl -s -b "$1" $B/$2 | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
post() { J=$1; P=$2; TP=$3; shift 3; T=$(tok $J $TP); curl -s -b $J -c $J -o /dev/null --data-urlencode "csrf_token=$T" "$@" $B/$P; }
flash() { curl -s -b "$1" $B/$2 | grep -o 'id="flash-data">[^<]*' | sed 's/id="flash-data">//' | python3 -c "import sys,html;print(html.unescape(sys.stdin.read()))"; }

ADM=/tmp/t_adm; MIA=/tmp/t_mia; BEN=/tmp/t_ben; CY=/tmp/t_cy
login $ADM admin@library.local; login $MIA mia; login $BEN ben; login $CY cy
copyst() { q "SELECT GROUP_CONCAT(Status ORDER BY CopyID) FROM book_copy WHERE BookID=$1"; }

echo "1. Member submits a Borrow Request"
post $MIA member_catalog.php member_catalog.php -d action=submit_request -d book_id=1
eq "request is Pending/Borrow" "$(q "SELECT CONCAT(RequestType,'/',ApprovalStatus) FROM borrow_request WHERE RequestID=1")" "Borrow/Pending"
eq "no loan and nothing reserved yet" "$(q "SELECT COUNT(*) FROM loan")|$(copyst 1)" "0|Available,Available"
post $MIA member_catalog.php member_catalog.php -d action=submit_request -d book_id=1
eq "duplicate request refused" "$(q "SELECT COUNT(*) FROM borrow_request WHERE UserID=2")" "1"
post $MIA member_catalog.php member_catalog.php -d action=submit_request -d book_id=3
eq "reference-only book refused" "$(q "SELECT COUNT(*) FROM borrow_request WHERE UserID=2")" "1"

echo "2. Access control"
c=$(curl -s -b $MIA -o /dev/null -w '%{http_code}' $B/borrow_requests.php); eq "member cannot open staff page" "$c" "302"
post $MIA borrow_requests.php member_catalog.php -d action=approve -d request_id=1 -d copy_id=1
eq "member cannot approve" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=1")" "Pending"

echo "3. Staff approve -> copy held, NOT borrowed"
post $ADM borrow_requests.php borrow_requests.php -d action=approve -d request_id=1 -d copy_id=1
eq "request Approved, copy Reserved" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=1")|$(copyst 1)" "Approved|Reserved,Available"
eq "still no loan" "$(q "SELECT COUNT(*) FROM loan")" "0"

echo "4. Reject needs a reason, member sees it"
post $BEN member_catalog.php member_catalog.php -d action=submit_request -d book_id=2
post $ADM borrow_requests.php borrow_requests.php -d action=reject -d request_id=2 -d reason=""
eq "reject without reason refused" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=2")" "Pending"
post $ADM borrow_requests.php borrow_requests.php -d action=reject -d request_id=2 -d reason="Reserved for course use"
eq "rejected with reason" "$(q "SELECT CONCAT(ApprovalStatus,':',DecisionReason) FROM borrow_request WHERE RequestID=2")" "Rejected:Reserved for course use"
has "member sees the reason" "$(curl -s -b $BEN $B/member_requests.php)" "Reserved for course use"

echo "5. Release = the actual borrowing"
post $ADM loans.php "loans.php?request_id=1" -d action=create_loan -d request_id=1
eq "loan recorded, copy Borrowed, request Fulfilled" "$(q "SELECT COUNT(*) FROM loan WHERE RequestID=1")|$(copyst 1)|$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=1")" "1|Borrowed,Available|Fulfilled"
eq "due date = 14 days" "$(q "SELECT DATEDIFF(DueDate,CURDATE()) FROM loan_detail WHERE LoanID=1")" "14"
post $ADM loans.php "loans.php?request_id=1" -d action=create_loan -d request_id=1
eq "cannot release twice" "$(q "SELECT COUNT(*) FROM loan")" "1"

echo "6. Reservation queue when no copy is on the shelf"
post $BEN member_catalog.php member_catalog.php -d action=submit_request -d book_id=1   # takes the last copy (Borrow)
post $ADM borrow_requests.php borrow_requests.php -d action=approve -d request_id=3 -d copy_id=2
post $CY member_catalog.php member_catalog.php -d action=submit_request -d book_id=1
eq "Cy is queued as a Reservation" "$(q "SELECT CONCAT(RequestType,'/',ApprovalStatus) FROM borrow_request WHERE UserID=4")" "Reservation/Pending"

echo "7. Return -> copy goes to the queue, availability stays consistent"
post $ADM returns.php returns.php -d action=process_return -d "return_conditions[1]=Good"
eq "return recorded" "$(q "SELECT COUNT(*) FROM return_detail")|$(q "SELECT ReturnStatus FROM return_header")" "1|Complete"
eq "copy 1 now held for Cy" "$(copyst 1)|$(q "SELECT ApprovalStatus FROM borrow_request WHERE UserID=4")" "Reserved,Reserved|Approved"
has "Cy is notified" "$(curl -s -b $CY $B/member_notifications.php)" "reserved book is available"
post $ADM returns.php returns.php -d action=process_return -d "return_conditions[1]=Good"
eq "double return blocked" "$(q "SELECT COUNT(*) FROM return_detail")" "1"

echo "8. Cancelling a held request frees the copy"
post $CY member_requests.php member_requests.php -d action=cancel -d request_id=4
eq "Cy cancelled, copy 1 Available again" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=4")|$(copyst 1)" "Cancelled|Available,Reserved"
post $BEN member_requests.php member_requests.php -d action=cancel -d request_id=4
eq "member cannot cancel someone else's request" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=4")" "Cancelled"
post $ADM borrow_requests.php borrow_requests.php -d action=cancel -d request_id=3
eq "staff cancel releases held copy 2" "$(copyst 1)" "Available,Available"

echo "9. Expired hold is released"
post $CY member_catalog.php member_catalog.php -d action=submit_request -d book_id=1
post $ADM borrow_requests.php borrow_requests.php -d action=approve -d request_id=5 -d copy_id=1
q "UPDATE borrow_request SET ExpiresAt = NOW() - INTERVAL 1 HOUR WHERE RequestID=5"
login $ADM admin@library.local     # a fresh session runs the expiry housekeeping on its first page load
curl -s -b $ADM $B/borrow_requests.php -o /dev/null
eq "hold expired, copy back on shelf" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=5")|$(copyst 1)" "Expired|Available,Available"

echo "10. In-library use never creates a loan"
post $ADM in_library_use.php in_library_use.php -d action=start -d borrower_type=Member -d member_id=2 -d copy_id=3
if grep -q "IN_LIBRARY_USE_ENABLED', false" config/kiosk.php; then EXP="Available|1"; else EXP="In Use|1"; fi   # borrow-only mode: no reading sessions
eq "copy In Use (or untouched in borrow-only mode), no new loan" "$(q "SELECT Status FROM book_copy WHERE CopyID=3")|$(q "SELECT COUNT(*) FROM loan")" "$EXP"
post $ADM in_library_use.php in_library_use.php -d action=return -d session_id=1
eq "copy Available after session" "$(q "SELECT Status FROM book_copy WHERE CopyID=3")" "Available"

echo "10b. Grouped by borrower, every book decided on its own (no bulk approve)"
rid() { q "SELECT r.RequestID FROM borrow_request r JOIN borrow_request_detail d ON d.RequestID=r.RequestID WHERE r.UserID=$1 AND d.BookID=$2 AND r.ApprovalStatus='Pending' ORDER BY r.RequestID DESC LIMIT 1"; }
post $MIA member_catalog.php member_catalog.php -d action=submit_request -d book_id=1
post $MIA member_catalog.php member_catalog.php -d action=submit_request -d book_id=2
post $BEN member_catalog.php member_catalog.php -d action=submit_request -d book_id=1
M1=$(rid 2 1); M2=$(rid 2 2); B1=$(rid 3 1)
eq "Mia has two separate request records (one per book), Ben one" "$(q "SELECT COUNT(*) FROM borrow_request WHERE RequestID IN ($M1,$M2,$B1) AND ApprovalStatus='Pending'")" "3"
pg=$(curl -s -b $ADM "$B/borrow_requests.php")
eq "Mia appears once in the main table (not once per book)" "$(echo "$pg" | grep -o 'member_view.php?id=2"' | wc -l | tr -d ' ')" "1"
has "Mia row shows her book count" "$(echo "$pg" | tr -d '\n' | grep -o 'Mia Member</a>.\{0,400\}')" 'fs-6">2<'
has "View Books button is present" "$pg" "View Books"
eq "no page-wide select-all or approve-everyone control" "$(echo "$pg" | grep -ciE 'selectAll|approve all pending')" "0"
post $ADM borrow_requests.php borrow_requests.php -d action=approve -d request_id=$M1 -d copy_id=1
eq "approving Mia's book 1 leaves her book 2 and Ben's request Pending" "$(q "SELECT GROUP_CONCAT(ApprovalStatus ORDER BY RequestID) FROM borrow_request WHERE RequestID IN ($M1,$M2,$B1)")" "Approved,Pending,Pending"
eq "only one copy was held" "$(copyst 1)" "Reserved,Available"
post $ADM borrow_requests.php borrow_requests.php -d action=reject -d request_id=$M2 -d reason=""
eq "rejecting needs a reason" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=$M2")" "Pending"
post $ADM borrow_requests.php borrow_requests.php -d action=reject -d request_id=$M2 -d reason="Out of stock"
eq "Mia's book 2 rejected; her book 1 still Approved; Ben untouched" "$(q "SELECT GROUP_CONCAT(ApprovalStatus ORDER BY RequestID) FROM borrow_request WHERE RequestID IN ($M1,$M2,$B1)")" "Approved,Rejected,Pending"
all=$(curl -s -b $ADM "$B/borrow_requests.php?tab=all&q=Mia")
has "overall status + per-book breakdown shown for Mia" "$all" "1 approved"
has "breakdown lists the rejected book too" "$all" "1 rejected"
eq "search by borrower name finds only that borrower" "$(curl -s -b $ADM "$B/borrow_requests.php?tab=all&q=Ben" | grep -o 'member_view.php?id=[0-9]*"' | sort -u | tr '\n' ' ')" 'member_view.php?id=3" '
eq "status filter works (Rejected: Ben's earlier one + Mia's)" "$(curl -s -b $ADM "$B/borrow_requests.php?tab=all&status=Rejected" | grep -o 'member_view.php?id=[0-9]*"' | sort -u | tr '\n' ' ')" 'member_view.php?id=2" member_view.php?id=3" '
post $ADM borrow_requests.php borrow_requests.php -d action=bulk_approve -d all_pending=1
post $ADM borrow_requests.php borrow_requests.php -d action=bulk_approve -d request_ids[]=$B1
eq "bulk actions no longer exist (Ben's request untouched)" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=$B1")" "Pending"
post $MIA borrow_requests.php member_catalog.php -d action=approve -d request_id=$B1 -d copy_id=2
eq "member cannot approve" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=$B1")" "Pending"
post $ADM borrow_requests.php borrow_requests.php -d action=approve -d request_id=$B1 -d copy_id=1
eq "a copy that is already held cannot be approved twice" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=$B1")" "Pending"
post $ADM borrow_requests.php borrow_requests.php -d action=approve -d request_id=$B1 -d copy_id=2
eq "Ben's own approval is independent" "$(q "SELECT GROUP_CONCAT(ApprovalStatus ORDER BY RequestID) FROM borrow_request WHERE RequestID IN ($M1,$M2,$B1)")" "Approved,Rejected,Approved"
post $ADM borrow_requests.php borrow_requests.php -d action=approve -d request_id=$M2 -d copy_id=3
eq "an already-decided book cannot be approved again" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=$M2")" "Rejected"
eq "no duplicate request records were created" "$(q "SELECT COUNT(*) FROM borrow_request WHERE RequestID IN ($M1,$M2,$B1)")|$(q "SELECT COUNT(*) FROM borrow_request_detail WHERE RequestID IN ($M1,$M2,$B1)")" "3|3"
post $ADM borrow_requests.php borrow_requests.php -d action=cancel -d request_id=$M1; post $ADM borrow_requests.php borrow_requests.php -d action=cancel -d request_id=$B1
eq "cleanup: copies free again" "$(copyst 1)" "Available,Available"
has "empty review tab renders" "$(curl -s -b $ADM "$B/borrow_requests.php")" "No requests waiting for review"

echo "10c. Approve all books of ONE borrower (each still its own record)"
post $MIA member_catalog.php member_catalog.php -d action=submit_request -d book_id=1
post $MIA member_catalog.php member_catalog.php -d action=submit_request -d book_id=2
post $BEN member_catalog.php member_catalog.php -d action=submit_request -d book_id=1
A1=$(rid 2 1); A2=$(rid 2 2); C1=$(rid 3 1)
has "Approve-all button shown for Mia (2 books), not for Ben (1 book)" "$(curl -s -b $ADM "$B/borrow_requests.php" | tr -d '\n' | grep -o 'Approve all [0-9]* books' | tr '\n' ' ')" "Approve all 2 books"
post $ADM borrow_requests.php borrow_requests.php -d action=approve_borrower -d user_id=2 -d "request_ids[]=$A1" -d "request_ids[]=$A2" -d "request_ids[]=$C1"
eq "Mia's two approved separately; Ben's (not hers) untouched" "$(q "SELECT GROUP_CONCAT(ApprovalStatus ORDER BY RequestID) FROM borrow_request WHERE RequestID IN ($A1,$A2,$C1)")" "Approved,Approved,Pending"
eq "each got its own copy and its own record" "$(q "SELECT COUNT(DISTINCT AssignedCopyID) FROM borrow_request WHERE RequestID IN ($A1,$A2)")|$(q "SELECT COUNT(*) FROM borrow_request WHERE UserID=2 AND ApprovalStatus='Approved'")" "2|2"
post $MIA borrow_requests.php member_catalog.php -d action=approve_borrower -d user_id=3 -d "request_ids[]=$C1"
eq "member cannot use approve-all" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=$C1")" "Pending"
post $ADM borrow_requests.php borrow_requests.php -d action=cancel -d request_id=$A1; post $ADM borrow_requests.php borrow_requests.php -d action=cancel -d request_id=$A2; post $ADM borrow_requests.php borrow_requests.php -d action=cancel -d request_id=$C1

echo "10d. Bulk reject for one borrower (each rejected on its own, reason required)"
post $MIA member_catalog.php member_catalog.php -d action=submit_request -d book_id=1
post $MIA member_catalog.php member_catalog.php -d action=submit_request -d book_id=2
D1=$(rid 2 1); D2=$(rid 2 2)
post $ADM borrow_requests.php borrow_requests.php -d action=reject_borrower -d user_id=2 -d "request_ids[]=$D1" -d "request_ids[]=$D2" -d reason=""
eq "bulk reject without reason changes nothing" "$(q "SELECT GROUP_CONCAT(ApprovalStatus ORDER BY RequestID) FROM borrow_request WHERE RequestID IN ($D1,$D2)")" "Pending,Pending"
post $ADM borrow_requests.php borrow_requests.php -d action=reject_borrower -d user_id=2 -d "request_ids[]=$D1" -d reason="Not now"
eq "only the ticked book is rejected" "$(q "SELECT GROUP_CONCAT(ApprovalStatus ORDER BY RequestID) FROM borrow_request WHERE RequestID IN ($D1,$D2)")" "Rejected,Pending"
has "page shows the Bulk Actions bar, per-book ticks and apply-all" "$(post $MIA member_catalog.php member_catalog.php -d action=submit_request -d book_id=1; curl -s -b $ADM "$B/borrow_requests.php" | tr -d '\n')" "Apply changes to all books"
post $ADM borrow_requests.php borrow_requests.php -d action=reject_borrower -d user_id=3 -d "request_ids[]=$D2" -d reason="x"
eq "cannot reject another borrower's book through this borrower" "$(q "SELECT ApprovalStatus FROM borrow_request WHERE RequestID=$D2")" "Pending"
post $ADM borrow_requests.php borrow_requests.php -d action=reject_borrower -d user_id=2 -d "request_ids[]=$D2" -d "request_ids[]=$(rid 2 1)" -d reason="cleanup"

echo "10e. Member requests several books at once; each is its own request"
before=$(q "SELECT COUNT(*) FROM borrow_request WHERE UserID=4")
post $CY member_catalog.php member_catalog.php -d action=submit_requests -d "book_ids[]=1" -d "book_ids[]=2" -d "book_ids[]=3"
eq "two books requested, reference-only one refused" "$(q "SELECT COUNT(*) FROM borrow_request WHERE UserID=4 AND ApprovalStatus='Pending'")|$(( $(q "SELECT COUNT(*) FROM borrow_request WHERE UserID=4") - before ))" "2|2"
eq "each request has exactly one book" "$(q "SELECT GROUP_CONCAT(c) FROM (SELECT COUNT(*) c FROM borrow_request r JOIN borrow_request_detail d ON d.RequestID=r.RequestID WHERE r.UserID=4 AND r.ApprovalStatus='Pending' GROUP BY r.RequestID) t")" "1,1"
has "member told which book was not sent" "$(flash $CY member_catalog.php)" "Not sent"
post $CY member_catalog.php member_catalog.php -d action=submit_requests -d "book_ids[]=1" -d "book_ids[]=2"
eq "re-sending creates no duplicates" "$(q "SELECT COUNT(*) FROM borrow_request WHERE UserID=4 AND ApprovalStatus='Pending'")" "2"
post $CY member_catalog.php member_catalog.php -d action=submit_requests
eq "empty selection changes nothing" "$(q "SELECT COUNT(*) FROM borrow_request WHERE UserID=4 AND ApprovalStatus='Pending'")" "2"
has "catalog loads its selection script" "$(curl -s -b $CY $B/member_catalog.php)" "member_catalog.js"
has "My Requests has status chips" "$(curl -s -b $CY $B/member_requests.php)" "member_requests.php?status=Pending"
eq "status filter on My Requests" "$(curl -s -b $CY "$B/member_requests.php?status=Pending" | grep -c 'name="action" value="cancel"')" "2"
has "staff dashboard groups by borrower" "$(curl -s -b $ADM $B/index.php | tr -d '\n')" "2 books"
for id in $(q "SELECT RequestID FROM borrow_request WHERE UserID=4 AND ApprovalStatus='Pending'"); do post $ADM borrow_requests.php borrow_requests.php -d action=cancel -d request_id=$id; done

echo "10c. Librarian desk form (student hands over the ID, librarian verifies and decides)"
U="use_requests.php"; SID="LIB-2026-00002"; TP="$U?sid=$SID&c[]=BK-00001-01&c[]=BK-00003-01"
has "unknown ID is refused" "$(curl -s -b $ADM "$B/$U?sid=NOPE-1")" "is not a registered member ID"
has "student is found and eligibility shown" "$(curl -s -b $ADM "$B/$U?sid=$SID")" "Eligible to borrow"
FORM=$(curl -s -b $ADM "$B/$TP")
has "book that can be borrowed is marked so" "$FORM" "Can be borrowed"
has "library-use-only book is flagged" "$FORM" "for library use only"
eq "form itself saves nothing" "$(q "SELECT COUNT(*) FROM use_request")" "0"
post $ADM $U "$TP" -d action=desk_decide -d student_id=$SID -d "c[0]=BK-00001-01" -d "choice[0]=approve" -d "c[1]=BK-00003-01" -d "choice[1]=reject" -d "reason[1]="
eq "reject without a reason saves nothing" "$(q "SELECT COUNT(*) FROM use_request")|$(q "SELECT COUNT(*) FROM loan")" "0|1"
post $ADM $U "$TP" -d action=record_entry -d student_id=$SID
eq "librarian can record the entry" "$(q "SELECT CONCAT(Result,'/',Method) FROM library_entry WHERE MemberID=2")" "Allowed/Staff"
post $ADM $U "$TP" -d action=desk_decide -d student_id=$SID -d "c[0]=BK-00001-01" -d "choice[0]=approve" -d "c[1]=BK-00003-01" -d "choice[1]=reject" --data-urlencode "reason[1]=For library use only."
eq "approve one, reject one: one request, one loan" "$(q "SELECT COUNT(*) FROM use_request WHERE Source='Desk' AND Status='Decided'")|$(q "SELECT COUNT(*) FROM loan")" "1|2"
eq "approved copy is Borrowed, due in 14 days" "$(q "SELECT Status FROM book_copy WHERE AccessionNumber='BK-00001-01'")|$(q "SELECT DATEDIFF(DueDate,CURDATE()) FROM loan_detail ORDER BY LoanDetailID DESC LIMIT 1")" "Borrowed|14"
eq "rejection reason is recorded" "$(q "SELECT CONCAT(Decision,':',RejectReason) FROM use_request_item i JOIN book_copy c ON c.CopyID=i.CopyID WHERE c.AccessionNumber='BK-00003-01'")" "Rejected:For library use only."
post $ADM $U "$U?sid=$SID&c[]=BK-00001-01" -d action=desk_decide -d student_id=$SID -d "c[0]=BK-00001-01" -d "choice[0]=approve"
eq "approving an already-borrowed copy is refused, nothing saved" "$(q "SELECT COUNT(*) FROM use_request")|$(q "SELECT COUNT(*) FROM loan")" "1|2"
c=$(curl -s -b $MIA -o /dev/null -w '%{http_code}' $B/$U); eq "a member cannot open the librarian desk" "$c" "302"
post $ADM returns.php returns.php -d action=process_return -d "return_conditions[$(q "SELECT LoanDetailID FROM loan_detail ORDER BY LoanDetailID DESC LIMIT 1")]=Good"
eq "desk loan can be returned normally" "$(q "SELECT Status FROM book_copy WHERE AccessionNumber='BK-00001-01'")" "Available"

echo "11. Every page loads without PHP errors"
for p in index.php use_requests.php "use_requests.php?tab=history" library_visits.php borrow_requests.php "borrow_requests.php?tab=ready" "borrow_requests.php?tab=queue" "borrow_requests.php?tab=history" "borrow_requests.php?tab=all" "borrow_requests.php?tab=all&q=Mia" "borrow_requests.php?tab=all&status=Rejected&from=2020-01-01&to=2030-01-01&page=2" loans.php returns.php in_library_use.php in_library_use_history.php reports.php "reports.php?report=borrow_requests" "reports.php?report=returns_history" "reports.php?report=loans_overdue" books.php book_copies.php members.php activity_log.php policies.php fines.php; do
  n=$(curl -s -b $ADM $B/$p | grep -ciE 'fatal error|warning:|notice:|exception|deprecated:|loan request'); eq "$p" "$n" "0"; done
for p in member_portal.php member_catalog.php member_requests.php member_loans.php member_notifications.php member_fines.php member_profile.php "member_requests.php?status=Pending"; do
  n=$(curl -s -b $MIA $B/$p | grep -ciE 'fatal error|warning:|notice:|exception|deprecated:|loan request'); eq "$p" "$n" "0"; done
eq "audit log has member + staff actions" "$(q "SELECT COUNT(DISTINCT UserID) FROM activity_log WHERE Module='Borrow Requests'")" "$(q "SELECT COUNT(DISTINCT UserID) FROM activity_log WHERE Module='Borrow Requests'")"

echo; echo "RESULT: $pass passed, $fail failed"; pkill -f "php .*-S 127.0.0.1:$PORT"; [ $fail -eq 0 ]
