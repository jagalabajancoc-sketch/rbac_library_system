#!/usr/bin/env bash
# End-to-end check of the In-Library Desk (form + ID custody) against a THROWAWAY database.
# Needs: php, curl, MariaDB with root and no password. Uses a dedicated library_test_* database.
# Legacy fixture test: requires DEMO-* copies and LIB-* member IDs in the test DB.
# Use php tests/release1_test.php for self-contained coverage.
set -u
cd "$(dirname "$0")/.."
source tests/environment.sh
PORT=8098; B=http://127.0.0.1:$PORT; J=/tmp/desk_jar
q(){ mariadb  -N -B "$DB" -e "$1"; }
pass=0; fail=0
ok(){ pass=$((pass+1)); echo "  PASS  $1"; }; bad(){ fail=$((fail+1)); echo "  FAIL  $1  (got: $2)"; }
eq(){ [ "$2" = "$3" ] && ok "$1" || bad "$1" "'$2' != '$3'"; }
has(){ echo "$2" | grep -q "$3" && ok "$1" || bad "$1" "$2"; }

mariadb -e "CREATE DATABASE IF NOT EXISTS $DB CHARACTER SET utf8mb4" || exit 1
# Arbitrary dumps may contain USE/DROP DATABASE statements. Require reviewed fixture data in the test DB.
php database/setup.php || exit 1

H=$(php -r 'echo password_hash("Passw0rd!", PASSWORD_DEFAULT);'); q "UPDATE user_account SET PasswordHash='$H'"
(setsid nohup php -S 127.0.0.1:$PORT >/tmp/php_desk.log 2>&1 </dev/null &); sleep 2
rm -f $J
tok(){ curl -s -b $J -c $J "$B/$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
flash(){ curl -s -b $J -c $J "$B/desk_form.php" | grep -o 'id="flash-data">[^<]*' | sed 's/id="flash-data">//' | python3 -c "import sys,html;print(html.unescape(sys.stdin.read()).strip())"; }
post(){ T=$(tok desk_form.php); curl -s -b $J -c $J -o /dev/null --data-urlencode "csrf_token=$T" "$@" $B/desk_form.php; }
T=$(tok login.php); curl -s -b $J -c $J -o /dev/null --data-urlencode "csrf_token=$T" -d username=admin --data-urlencode password='Passw0rd!' $B/login.php
C1=$(q "SELECT CopyID FROM book_copy WHERE AccessionNumber='DEMO-2026-000001'"); C2=$(q "SELECT CopyID FROM book_copy WHERE AccessionNumber='DEMO-2026-000002'"); C3=$(q "SELECT CopyID FROM book_copy WHERE AccessionNumber='DEMO-2026-000003'")

echo "Desk page and lookups"
eq  "desk page loads" "$(curl -s -b $J -o /tmp/o.html -w '%{http_code}' $B/desk_form.php)" 200
has "student lookup finds a registered ID" "$(curl -s -b $J "$B/desk_form.php?api=member&q=LIB-2026-00007")" '"found":true'
has "unknown ID is not found" "$(curl -s -b $J "$B/desk_form.php?api=member&q=NOPE-1")" '"found":false'

LOANS0=$(q "SELECT COUNT(*) FROM loan")
echo "Issuing the form"
post -d action=issue -d student_id=LIB-2026-00007 -d photo_checked=1 -d "copy_ids[]=$C1"
has "refused without the rules accepted" "$(flash)" "accept the in-library rules"
post -d action=issue -d student_id=LIB-2026-00007 -d ack=1 -d "copy_ids[]=$C1"
has "refused without the photo check" "$(flash)" "compared the photo"
post -d action=issue -d student_id=ZZZ-999 -d ack=1 -d photo_checked=1 -d "copy_ids[]=$C1"
has "refused for an unregistered ID" "$(flash)" "not found"
eq  "nothing was recorded by the refusals" "$(q "SELECT COUNT(*) FROM in_library_form")" 0
post -d action=issue -d student_id=LIB-2026-00007 -d ack=1 -d photo_checked=1 -d verify_method=Scanned -d "copy_ids[]=$C1" -d "copy_ids[]=$C2" -d "cond_out[$C2]=Fair" -d purpose=Research -d location="Study Area"
eq  "form issued, ID held" "$(q "SELECT CONCAT(IdStatus,'/',Status,'/',IdVerifyMethod,'/',Purpose) FROM in_library_form WHERE MemberID=7")" "Held/Open/Scanned/Research"
eq  "first free slot suggested = 1" "$(q "SELECT IdSlot FROM in_library_form WHERE MemberID=7")" 1
eq  "the activity log has the real record id (bug fix)" "$(q "SELECT COUNT(*) FROM activity_log WHERE Module='In-Library Desk' AND RecordID>0")" 1
eq  "one in-library record per copy, linked to the form" "$(q "SELECT COUNT(*) FROM in_library_use u JOIN in_library_form f ON f.FormNo=u.SessionRef WHERE u.Status='Active'")" 2
eq  "book condition at hand-out saved" "$(q "SELECT ConditionOut FROM in_library_use WHERE CopyID=$C2")" Fair
eq  "copies are now In Use" "$(q "SELECT GROUP_CONCAT(Status) FROM book_copy WHERE CopyID IN ($C1,$C2)")" "In Use,In Use"
eq  "no loan was created" "$(q "SELECT COUNT(*) FROM loan")" "$LOANS0"
post -d action=issue -d student_id=LIB-2026-00007 -d ack=1 -d photo_checked=1 -d "copy_ids[]=$C3"
has "same student cannot open a second form while the ID is held" "$(flash)" "already has an open form"
post -d action=issue -d borrower_type=Walk-in -d walk_in_name="Visitor One" -d id_type="Government ID" -d id_number=G-123 -d ack=1 -d photo_checked=1 -d "copy_ids[]=$C1"
has "a book already in use cannot be put on another form" "$(flash)" "another active session"
post -d action=issue -d borrower_type=Walk-in -d walk_in_name="Visitor One" -d id_type="Government ID" -d id_number=G-123 -d ack=1 -d photo_checked=1 -d "copy_ids[]=$C3"
eq  "visitor form gets the next slot (2)" "$(q "SELECT IdSlot FROM in_library_form WHERE BorrowerType='Walk-in'")" 2
eq  "visitor's ID type and number recorded" "$(q "SELECT CONCAT(IdType,'/',IdNumber) FROM in_library_form WHERE BorrowerType='Walk-in'")" "Government ID/G-123"
eq  "two IDs held now" "$(q "SELECT COUNT(*) FROM in_library_form WHERE IdStatus='Held'")" 2

echo "Returning books and the ID"
FID=$(q "SELECT FormID FROM in_library_form WHERE MemberID=7"); S1=$(q "SELECT InLibraryUseID FROM in_library_use WHERE CopyID=$C1 AND Status='Active'"); S2=$(q "SELECT InLibraryUseID FROM in_library_use WHERE CopyID=$C2 AND Status='Active'")
post -d action=return -d form_id=$FID -d release=1
has "ID cannot be handed back while books are out" "$(flash)" "still out"
post -d action=return -d form_id=$FID -d "ret[$S1]=Good"
eq  "one book back: ID is still held" "$(q "SELECT CONCAT(IdStatus,'/',Status) FROM in_library_form WHERE FormID=$FID")" "Held/Open"
eq  "returned copy is Available again" "$(q "SELECT Status FROM book_copy WHERE CopyID=$C1")" Available
post -d action=return -d form_id=$FID -d "ret[$S2]=Damaged" -d release=1
has "damaged book needs a note before the ID is released" "$(flash)" "Add a note"
eq  "…and that failed attempt changed nothing (ID held, book still out)" "$(q "SELECT IdStatus FROM in_library_form WHERE FormID=$FID")/$(q "SELECT Status FROM in_library_use WHERE InLibraryUseID=$S2")" "Held/Active"
post -d action=return -d form_id=$FID -d release=1 -d "ret[$S2]=Damaged" -d note="Cover torn at the spine"
eq  "form closed and ID returned" "$(q "SELECT CONCAT(IdStatus,'/',Status,'/',IdReturnNote) FROM in_library_form WHERE FormID=$FID")" "Returned/Closed/Cover torn at the spine"
eq  "ID hand-back recorded with who and when" "$(q "SELECT (IdReturnedByUserID IS NOT NULL AND IdReturnedAt IS NOT NULL) FROM in_library_form WHERE FormID=$FID")" 1
eq  "damaged copy is NOT put back on the shelf" "$(q "SELECT Status FROM book_copy WHERE CopyID=$C2")" Damaged
eq  "condition on return saved" "$(q "SELECT ConditionIn FROM in_library_use WHERE InLibraryUseID=$S2")" Damaged
post -d action=return -d form_id=$FID -d release=1
has "a closed form cannot be changed again" "$(flash)" "already closed"
eq  "slot 1 is free again" "$(q "SELECT COUNT(*) FROM in_library_form WHERE IdSlot='1' AND IdStatus='Held'")" 0

echo "Pages"
for p in "desk_form.php" "desk_form.php?tab=history" "in_library_use.php" "desk_form.php?slip=$(q "SELECT FormNo FROM in_library_form WHERE MemberID=7")"; do
  code=$(curl -s -b $J -o /tmp/o.html -w '%{http_code}' "$B/$p"); errs=$(grep -c 'Fatal error\|Warning:\|Notice:\|Deprecated:' /tmp/o.html)
  eq "$p renders without PHP errors" "$code/$errs" "200/0"
done
has "Read-Here Books page shows the ID slot for the visitor's book" "$(curl -s -b $J $B/in_library_use.php)" "ID · slot 2"
has "history lists the closed form" "$(curl -s -b $J "$B/desk_form.php?tab=history")" "ILF-"
echo; echo "RESULT: $pass passed, $fail failed"; [ $fail -eq 0 ]
