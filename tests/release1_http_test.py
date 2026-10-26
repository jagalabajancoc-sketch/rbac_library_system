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
admin=session();request(admin,'login.php')
post(admin,'login.php',{'username':'admin@library.local','password':os.environ['LIBRARY_TEST_ADMIN_PASSWORD']})
check('Library Desk' in request(admin,'desk_form.php'),'administrator can open the upgraded desk')
member=session();prefix='HTTP-'+secrets.token_hex(4).upper();password='Test-Release1-Only!'
post(member,'register.php',{'first_name':'HTTP','last_name':'Member','email':prefix+'@example.test','username':prefix,'student_id':prefix,'password':password,'confirm_password':password})
member_id=sql(f"SELECT UserID FROM user_account WHERE Username='{prefix}'")
check(bool(member_id),'member registration succeeds on a fresh schema')
post(admin,'members.php',{'action':'approve_verification','member_id':member_id})
check(sql(f'SELECT VerificationStatus FROM user_account WHERE UserID={member_id}')=='Approved','librarian verification succeeds on a fresh schema')
post(member,'login.php',{'username':prefix,'password':password})
policy=sql('SELECT PolicyID FROM policy WHERE IsBorrowable=1 LIMIT 1')
category=sql('SELECT CategoryID FROM category LIMIT 1'); section=sql('SELECT SectionID FROM section LIMIT 1'); publisher=sql('SELECT PublisherID FROM publisher LIMIT 1')
books=[];copies=[]
for n in range(2):
    book=sql(f"INSERT INTO book (Title,PolicyID,CategoryID,SectionID,PublisherID) VALUES ('{prefix}-{n}',{policy},{category},{section},{publisher}); SELECT LAST_INSERT_ID();")
    books.append(book)
    for c in range(2):
        cp=sql(f"INSERT INTO book_copy (BookID,AccessionNumber) VALUES ({book},'{prefix}-{n}-{c}'); SELECT LAST_INSERT_ID();")
        if c==0:copies.append(cp)
page=request(admin,'desk_form.php');issue_token=token(page,'issue_token')
data={'action':'issue','borrower_type':'Member','student_id':prefix,'photo_checked':'1','copy_ids[]':copies,'issue_token':issue_token}
for cp in copies: data[f'use[{cp}]']='Borrow';data[f'days[{cp}]']='7'
failed=post(admin,'desk_form.php',data)
check('Your entries have been kept' in failed and prefix in failed,'validation failure preserves the member and scanned copies')
check(token(failed,'issue_token')==issue_token,'retry keeps its original idempotency token')
data['ack']='1'
success=post(admin,'desk_form.php',data)
check('Form issued' in success,'desk confirms a two-book loan and displays a receipt')
loan=sql(f'SELECT LoanID FROM loan WHERE BorrowerUserID={member_id} ORDER BY LoanID DESC LIMIT 1')
check(bool(loan) and sql(f'SELECT COUNT(*) FROM loan_detail WHERE LoanID={loan}')=='2','both copies are on one loan')
post(admin,'desk_form.php',data)
check(sql(f'SELECT COUNT(*) FROM loan WHERE BorrowerUserID={member_id}')=='1','HTTP retry does not create a duplicate loan')
details=sql(f'SELECT LoanDetailID FROM loan_detail WHERE LoanID={loan} ORDER BY LoanDetailID').splitlines()
sql(f"UPDATE loan_detail SET DueDate=DATE_SUB(CURDATE(),INTERVAL 2 DAY) WHERE LoanDetailID={details[0]}")
review=post(admin,'returns.php',{'action':'preview_return',f'return_conditions[{details[0]}]':'Good'})
post(admin,'returns.php',{'action':'process_return','review_token':token(review,'review_token')})
check(sql(f'SELECT ReturnStatus FROM return_header WHERE LoanID={loan} ORDER BY ReturnID DESC LIMIT 1')=='Partial','first returned book leaves the loan partially returned')
fine=sql(f'SELECT FineID FROM fine WHERE LoanDetailID={details[0]}')
check(bool(fine),'overdue return generates a fine')
amount=sql(f'SELECT FineAmount FROM fine WHERE FineID={fine}')
post(admin,'payments.php',{'action':'pay','fine_id':fine,'amount_tendered':amount,'payment_method':'Cash'})
check(sql(f'SELECT PaymentStatus FROM fine WHERE FineID={fine}')=='Paid','fine payment settles the balance')
review=post(admin,'returns.php',{'action':'preview_return',f'return_conditions[{details[1]}]':'Good'})
post(admin,'returns.php',{'action':'process_return','review_token':token(review,'review_token')})
check(sql(f'SELECT ReturnStatus FROM return_header WHERE LoanID={loan} ORDER BY ReturnID DESC LIMIT 1')=='Complete','returning the remaining book completes the return')
post(member,'member_catalog.php',{'action':'submit_request','book_id':books[0],'submission_token':token(request(member,'member_catalog.php'),'submission_token')})
check(sql(f"SELECT COUNT(*) FROM borrow_request WHERE UserID={member_id} AND ApprovalStatus='Pending'")=='1','catalog still submits member borrow requests')
result=json.loads(request(member,'member_catalog.php?api=catalog&q='+prefix))
check(result['total']==2 and len(result['books'])==2,'authenticated search API returns the expected titles')
print('HTTP workflow passed: registration, verification, borrowing, retry, partial return, fine, payment, completion, request.')
