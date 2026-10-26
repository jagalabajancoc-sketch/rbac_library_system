"""HTTP regression test. Uses only a server explicitly marked with the same library_test_* database."""
import os, re, json, secrets, subprocess, urllib.request, urllib.parse, http.cookiejar
DB=os.environ.get('LIBRARY_DB_NAME','')
if os.environ.get('LIBRARY_TEST_MODE')!='1' or not re.fullmatch(r'library_test_[A-Za-z0-9_]+',DB):
    raise SystemExit('Refusing tests without LIBRARY_TEST_MODE=1 and a library_test_* database.')
BASE=os.environ.get('LIBRARY_TEST_URL','http://127.0.0.1:8098')
MYSQL=os.environ.get('MYSQL_PATH',r'C:\xampp\mysql\bin\mysql.exe')
def sql(query):
    env=os.environ.copy();env['MYSQL_PWD']=env.get('LIBRARY_DB_PASS','')
    return subprocess.check_output([MYSQL,'-h',env.get('LIBRARY_DB_HOST','127.0.0.1'),'-P',env.get('LIBRARY_DB_PORT','3306'),'-u',env.get('LIBRARY_DB_USER','root'),'-N','-B',DB,'-e',query],env=env,text=True).strip()
def session(): return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(s,path,data=None):
    encoded=None if data is None else urllib.parse.urlencode(data,doseq=True).encode()
    r=s.open(BASE+'/'+path,encoded,timeout=20)
    assert r.headers.get('X-Library-Test-Database')==DB,'Wrong HTTP test database; refusing writes'
    body=r.read().decode()
    assert 'Fatal error' not in body and 'SQLSTATE[' not in body,'Server error: '+body[:300]
    return body
def token(html,name='csrf_token'):
    m=re.search(r'name="'+name+r'" value="([^"]+)"',html);assert m,'Missing '+name
    return m.group(1)
def post(s,path,data):
    data=dict(data);data['csrf_token']=token(request(s,path));return request(s,path,data)
checks=0
def check(value,label):
    global checks
    checks+=1
    assert value,label
    print('PASS '+label)


admin=session();post(admin,'login.php',{'username':'admin@library.local','password':os.environ['LIBRARY_TEST_ADMIN_PASSWORD']})
prefix='TEACHER-'+secrets.token_hex(4).upper();employee='T'+secrets.token_hex(2).upper();password='Borrower-Test-Only!';teacher=session();student=session()
registration={'first_name':'Morgan','last_name':'Reyes','email':prefix+'@example.test','username':prefix,'borrower_id':employee,'member_kind':'Teacher','password':password,'confirm_password':password}
page=post(teacher,'register.php',registration)
uid=sql(f"SELECT UserID FROM user_account WHERE Username='{prefix}'")
check(bool(uid) and sql(f"SELECT CONCAT(MemberKind,':',VerificationStatus) FROM user_account WHERE UserID={uid}")=='Teacher:Pending','teacher self-registration saves the selected type and requires verification')
check(sql(f"SELECT r.RoleName FROM user_account u JOIN role r ON r.RoleID=u.RoleID WHERE u.UserID={uid}")=='Member','teacher is a borrower account without staff privileges')
check(sql(f"SELECT MembershipNumber FROM user_account WHERE UserID={uid}")==employee,'short employee ID works without a student-only format')
post(teacher,'login.php',{'username':prefix,'password':password})
clone=dict(registration,username=prefix+'D',email=prefix+'D@example.test',borrower_id=employee[0]+'-'+employee[1:])
page=post(session(),'register.php',clone)
check('already registered' in page and sql(f"SELECT COUNT(*) FROM user_account WHERE Username='{prefix}D'")=='0','school and employee IDs cannot alias another borrower by changing dashes')
page=post(session(),'register.php',dict(registration,username=prefix+'BAD',email=prefix+'BAD@example.test',borrower_id=prefix+'BAD',member_kind='Admin'))
check('valid borrower type' in page and sql(f"SELECT COUNT(*) FROM user_account WHERE Username='{prefix}BAD'")=='0','unsupported borrower types are rejected')
post(student,'register.php',dict(registration,username=prefix+'S',email=prefix+'S@example.test',borrower_id=prefix+'S',member_kind='Student',first_name='Student'))
suid=sql(f"SELECT UserID FROM user_account WHERE Username='{prefix}S'")
check(sql(f"SELECT MemberKind FROM user_account WHERE UserID={suid}")=='Student','student registration remains supported alongside teachers')
policy=sql('SELECT PolicyID FROM policy WHERE IsBorrowable=1 LIMIT 1');category=sql('SELECT CategoryID FROM category LIMIT 1');section=sql('SELECT SectionID FROM section LIMIT 1');publisher=sql('SELECT PublisherID FROM publisher LIMIT 1')
books=[];copies=[]
for n in range(3):
 bid=sql(f"INSERT INTO book(Title,PolicyID,CategoryID,SectionID,PublisherID,AcquisitionCost) VALUES('{prefix}-{n}',{policy},{category},{section},{publisher},100);SELECT LAST_INSERT_ID();");books.append(bid)
 copies.append(sql(f"INSERT INTO book_copy(BookID,AccessionNumber,Status) VALUES({bid},'{prefix}-C{n}','Available');SELECT LAST_INSERT_ID();"))
 sql(f"INSERT INTO book_copy(BookID,AccessionNumber,Status) VALUES({bid},'{prefix}-SPARE{n}','Available')")
request(teacher,'member_catalog.php',{'action':'submit_requests','book_ids[]':[books[0]],'submission_token':secrets.token_hex(32),'csrf_token':token(request(teacher,'member_profile.php'))})
check(sql(f'SELECT COUNT(*) FROM request_transaction WHERE UserID={uid}')=='0','unverified teachers cannot bypass borrowing approval')
page=request(admin,'members.php?kind=Teacher&q='+prefix)
check('Morgan Reyes' in page and 'Teacher · Borrower' in page,'Borrowers list identifies teacher type during verification')
post(admin,'members.php',{'action':'approve_verification','member_id':uid})
check(sql(f'SELECT VerificationStatus FROM user_account WHERE UserID={uid}')=='Approved','librarian verifies the teacher account')
lookup=json.loads(request(admin,'desk_form.php?api=member&q='+employee))
check(lookup['found'] and lookup['kind']=='Teacher' and not lookup['blocked'],'Library Desk resolves a teacher through the same borrower ID lookup')
page=request(teacher,'member_card.php');profile=request(teacher,'member_profile.php')
check('Borrower Card' in page and 'Teacher' in page and 'Borrower Portal' in page and employee in page,'teacher receives a borrower card and portal with the correct identity')
check('Borrower type' in profile and 'Teacher' in profile,'profile displays teacher classification')
post(teacher,'member_profile.php',{'action':'update_profile','first_name':'Morgan','last_name':'Reyes','email':prefix+'@example.test','member_kind':'Student'})
check(sql(f'SELECT MemberKind FROM user_account WHERE UserID={uid}')=='Teacher','borrowers cannot silently change their verified type through profile edits')
try:
 response=request(teacher,'members.php')
 check('name="action" value="save"' not in response,'teacher cannot access borrower administration')
except urllib.error.HTTPError as e:check(e.code==403,'teacher cannot access borrower administration')
post(admin,'library_visits.php',{'action':'manual_entry','student_id':employee})
check(sql(f"SELECT COUNT(*) FROM library_entry WHERE MemberID={uid} AND Result='Allowed'")=='1','teacher attendance uses the common entrance record')
kiosk=session();kp=request(kiosk,'kiosk.php');csrf=re.search(r'"csrf"\s*:\s*"([^"]+)"',kp).group(1)
req=urllib.request.Request(BASE+'/kiosk_api.php',data=json.dumps({'action':'identify','student_id':employee}).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':csrf})
kr=kiosk.open(req);assert kr.headers.get('X-Library-Test-Database')==DB;kd=json.loads(kr.read())
check(kd['ok'],'teacher can identify at the borrower kiosk after entry')
post(teacher,'member_catalog.php',{'action':'submit_requests','book_ids[]':[books[0]],'submission_token':secrets.token_hex(32)})
tx=sql(f'SELECT MAX(TransactionID) FROM request_transaction WHERE UserID={uid}');rid=sql(f'SELECT RequestID FROM borrow_request WHERE TransactionID={tx}')
check(bool(tx) and bool(rid),'verified teacher submits a portal borrowing request')
post(admin,'borrow_requests.php',{'action':'approve','transaction_id':tx,'request_ids[]':[rid]})
post(admin,'borrow_requests.php',{'action':'release','transaction_id':tx,'request_ids[]':[rid]})
loan=sql(f'SELECT ReleasedLoanID FROM borrow_request WHERE RequestID={rid}')
check(loan!='NULL' and sql(f'SELECT BorrowerUserID FROM loan WHERE LoanID={loan}')==uid,'approved teacher request releases a book to the correct borrower')
check(prefix+'-0' in request(teacher,'member_loans.php'),'teacher sees issued books in My Borrowed Books')
def return_loan(lid):
 detail=sql(f'SELECT LoanDetailID FROM loan_detail WHERE LoanID={lid}')
 quote=post(admin,'returns.php',{'action':'preview_return','return_conditions['+detail+']':'Good'})
 post(admin,'returns.php',{'action':'process_return','review_token':token(quote,'review_token')})
 return sql(f'SELECT COUNT(*) FROM return_detail WHERE LoanDetailID={detail}')=='1'
check(return_loan(loan),'teacher borrowing returns through the existing Returns workflow')
def issue(copy,use,slot=''):
 page=request(admin,'desk_form.php');return post(admin,'desk_form.php',{'action':'issue','issue_token':token(page,'issue_token'),'borrower_type':'Member','student_id':employee,'copy_ids[]':[copy],'use['+copy+']':use,'cond_out['+copy+']':'Good','days['+copy+']':'1','photo_checked':'1','ack':'1','slot':slot,'purpose':'Reading'})
issue(copies[0],'Borrow');deskloan=sql(f'SELECT MAX(LoanID) FROM loan WHERE BorrowerUserID={uid}')
check(deskloan!=loan and sql(f'SELECT COUNT(*) FROM loan_detail WHERE LoanID={deskloan}')=='1','teacher can also borrow directly at Library Desk')
check(return_loan(deskloan),'direct desk borrowing shares the same return workflow')
issue(copies[1],'Read',prefix+'-ID');form=sql(f"SELECT FormID FROM in_library_form WHERE MemberID={uid} AND IdStatus='Held'")
check(bool(form) and sql(f'SELECT IdType FROM in_library_form WHERE FormID={form}')=='Employee ID','teacher reading session records an employee ID in custody')
def rp():return 'reading_session.php?'+urllib.parse.urlencode({'key':'form:'+form,'fragment':'1'})
page=request(admin,rp());post(admin,rp(),{'action':'add','key':'form:'+form,'session_version':token(page,'session_version'),'accessions':prefix+'-C2','condition_out':'Good'})
ref=sql(f'SELECT FormNo FROM in_library_form WHERE FormID={form}')
check(sql(f"SELECT COUNT(*) FROM in_library_use WHERE MemberID={uid} AND SessionRef='{ref}' AND Status='Active'")=='2','teacher receives additional books in the same reading session')
ids=sql(f"SELECT InLibraryUseID FROM in_library_use WHERE SessionRef='{ref}'").splitlines();page=request(admin,rp())
post(admin,rp(),{'action':'return','key':'form:'+form,'session_version':token(page,'session_version'),'selected[]':ids,'release':'1',**{'conditions['+i+']':'Good' for i in ids}})
check(sql(f"SELECT CONCAT(Status,':',IdStatus) FROM in_library_form WHERE FormID={form}")=='Closed:Returned','teacher reading books and employee ID return together')
report=request(admin,'reports.php?report=members_all&q='+prefix)
check('Morgan Reyes' in report and '<td>Teacher</td>' in report and 'Borrower type' in report,'borrower report includes teacher classification')
for endpoint in ['members.php','member_view.php?id='+uid,'desk_form.php','library_visits.php','use_requests.php','in_library_use.php','in_library_use_history.php']:
 page=request(admin,endpoint)
 check('Student ID' not in page and 'Student / Teacher' not in page,'borrower terminology on '+endpoint.split('?')[0])
staff_data={'action':'save','first_name':'Staff-created','last_name':'Teacher','email':prefix+'STAFF@example.test','borrower_id':prefix+'STAFF','member_kind':'Teacher','registration_date':'2026-10-26','membership_status':'Active'}
post(admin,'members.php',staff_data)
staff_uid=sql(f"SELECT UserID FROM user_account WHERE Email='{prefix}STAFF@example.test'")
check(bool(staff_uid) and sql(f"SELECT CONCAT(MemberKind,':',VerificationStatus) FROM user_account WHERE UserID={staff_uid}")=='Teacher:Approved','staff can register a verified teacher borrower')
post(admin,'members.php',dict(staff_data,member_id=staff_uid,first_name='Updated'))
check(sql(f"SELECT CONCAT(FirstName,':',MemberKind) FROM user_account WHERE UserID={staff_uid}")=='Updated:Teacher','staff editing preserves teacher classification')
post(admin,'members.php',dict(staff_data,member_id=staff_uid,borrower_id=employee))
check(sql(f"SELECT MembershipNumber FROM user_account WHERE UserID={staff_uid}")==prefix+'STAFF','staff cannot assign another borrower ID to a teacher')
print(str(checks)+' teacher/borrower checks passed. PREVIEW '+prefix+' '+employee+' user '+uid)
