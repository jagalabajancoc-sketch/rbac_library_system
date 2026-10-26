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
def check(value,label):
    assert value,label
    print('PASS '+label)


admin=session();post(admin,'login.php',{'username':'admin@library.local','password':os.environ['LIBRARY_TEST_ADMIN_PASSWORD']})
prefix='LIST-'+secrets.token_hex(4).upper()
role=sql("SELECT RoleID FROM role WHERE RoleName='Member' LIMIT 1")
users=[];books=[];requests=[];txs=[]
for n in range(2):
 users.append(sql(f"INSERT INTO user_account(RoleID,FirstName,LastName,Username,Email,PasswordHash,MembershipNumber,MembershipStatus,VerificationStatus,RegistrationDate) VALUES({role},'Alex','Rivera {n}','{prefix}-{n}','{prefix}-{n}@example.test','!test!','{prefix}-{n}','Active','Approved',CURDATE());SELECT LAST_INSERT_ID();"))
policy=sql('SELECT PolicyID FROM policy WHERE IsBorrowable=1 LIMIT 1');category=sql('SELECT CategoryID FROM category LIMIT 1');section=sql('SELECT SectionID FROM section LIMIT 1');publisher=sql('SELECT PublisherID FROM publisher LIMIT 1')
for n,title in enumerate(['Introduction to Physics','World History','Creative Writing','Digital Literacy']):
 bid=sql(f"INSERT INTO book(Title,PolicyID,CategoryID,SectionID,PublisherID,AcquisitionCost) VALUES('{title} {prefix}',{policy},{category},{section},{publisher},100);SELECT LAST_INSERT_ID();");books.append(bid)
 for c in range(2):sql(f"INSERT INTO book_copy(BookID,AccessionNumber,Status) VALUES({bid},'{prefix}-{n}-{c}','Available')")
 uid=users[0 if n<3 else 1]
 tx=sql(f'INSERT INTO request_transaction(UserID) VALUES({uid});SELECT LAST_INSERT_ID();');txs.append(tx)
 rid=sql(f"INSERT INTO borrow_request(UserID,TransactionID,RequestType,ApprovalStatus) VALUES({uid},{tx},'Borrow','Pending');SELECT LAST_INSERT_ID();");requests.append(rid)
 sql(f'INSERT INTO borrow_request_detail(RequestID,BookID) VALUES({rid},{bid})')
def statuses():return sql('SELECT ApprovalStatus FROM borrow_request WHERE RequestID IN ('+','.join(requests)+') ORDER BY RequestID')
def act(action,ids,**extra):return post(admin,'borrow_requests.php',{'action':action,'borrower_id':users[0],'request_ids[]':ids,**extra})
try:
 page=request(admin,'borrow_requests.php?q='+prefix)
 check('Introduction to Physics' in page and 'World History' in page and 'Select all pending (3)' in page,'all books and bulk controls appear directly on borrower list')
 check('data-bs-target="#requestModal' not in page,'staff approval no longer requires per-submission modals')
 check(page.count('name="borrower_id"')==2,'separate borrowers keep separate selection scopes')
 before=statuses();act('approve',[requests[0],requests[3]])
 check(statuses()==before,'cross-borrower selection is rejected without changes')
 post(admin,'borrow_requests.php',{'action':'approve','transaction_id':txs[0],'request_ids[]':requests[:2]})
 check(statuses()==before,'legacy transaction scope cannot accidentally approve other submissions')
 sql(f"UPDATE book_copy SET Status='Lost' WHERE BookID={books[1]}")
 act('approve',requests[:2])
 check(statuses()==before and sql(f"SELECT COUNT(*) FROM book_copy WHERE BookID={books[0]} AND Status='Reserved'")=='0','unavailable second book rolls back first approval and copy reservation')
 sql(f"UPDATE book_copy SET Status='Available' WHERE BookID={books[1]}")
 act('approve',requests[:2])
 check(statuses().splitlines()==['Approved','Approved','Pending','Pending'],'one approval processes books across two submissions and leaves unchecked books pending')
 act('release',requests[:2])
 loans=sql('SELECT ReleasedLoanID FROM borrow_request WHERE RequestID IN ('+','.join(requests[:2])+')').splitlines()
 check(loans[0]==loans[1] and loans[0]!='NULL','approved selections release together under one borrowing transaction')
 check(sql(f'SELECT COUNT(*) FROM loan_detail WHERE LoanID={loans[0]}')=='2','combined borrowing transaction contains both book copies')
 before=statuses();act('approve',requests[:2]);check(statuses()==before,'stale approval cannot reapprove fulfilled books')
 act('reject',[requests[2]]);check(statuses().splitlines()[2]=='Pending','rejecting still requires a reason')
 act('reject',[requests[2]],reason='Borrower requested another edition');check(statuses().splitlines()[2]=='Rejected','individual selection still supports rejection')
 print('12 bulk-request checks passed. PREVIEW '+prefix)
 if os.environ.get('KEEP_SESSION_PREVIEW')=='1':
  sql(f"UPDATE book_copy SET Status='Available' WHERE BookID IN ({','.join(books[:2])});UPDATE borrow_request SET ApprovalStatus='Pending',AssignedCopyID=NULL,ReleasedLoanID=NULL WHERE RequestID IN ({','.join(requests[:3])});DELETE FROM loan_detail WHERE LoanID={loans[0]};DELETE FROM loan WHERE LoanID={loans[0]};")
finally:
 if os.environ.get('KEEP_SESSION_PREVIEW')!='1':
  u=','.join(users);b=','.join(books);rs=','.join(requests)
  sql(f"UPDATE loan SET RequestID=NULL WHERE BorrowerUserID IN ({u});DELETE FROM borrow_request_detail WHERE RequestID IN ({rs});DELETE FROM borrow_request WHERE RequestID IN ({rs});DELETE FROM request_transaction WHERE UserID IN ({u});DELETE d FROM loan_detail d JOIN loan l ON l.LoanID=d.LoanID WHERE l.BorrowerUserID IN ({u});DELETE FROM loan WHERE BorrowerUserID IN ({u});DELETE FROM book_copy WHERE BookID IN ({b});DELETE FROM book WHERE BookID IN ({b});DELETE FROM user_account WHERE UserID IN ({u});")
