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
prefix='BATCH-'+secrets.token_hex(4).upper();member=session();password='Feedback-Test-Only!'
post(member,'register.php',{'first_name':'Batch','last_name':'Member','email':prefix+'@example.test','username':prefix,'student_id':prefix,'password':password,'confirm_password':password})
uid=sql(f"SELECT UserID FROM user_account WHERE Username='{prefix}'")
post(admin,'members.php',{'action':'approve_verification','member_id':uid})
post(member,'login.php',{'username':prefix,'password':password})
policy=sql('SELECT PolicyID FROM policy WHERE IsBorrowable=1 LIMIT 1');category=sql('SELECT CategoryID FROM category LIMIT 1');section=sql('SELECT SectionID FROM section LIMIT 1');publisher=sql('SELECT PublisherID FROM publisher LIMIT 1')
books=[]
for n in range(2):
    bid=sql(f"INSERT INTO book (Title,PolicyID,CategoryID,SectionID,PublisherID,AcquisitionCost) VALUES ('{prefix}-{n}',{policy},{category},{section},{publisher},100); SELECT LAST_INSERT_ID();")
    books.append(bid)
    post(admin,'book_copies.php',{'action':'add_copies','book_id':bid,'num_copies':'2','accession_numbers':f'{prefix}-{n}-A\n{prefix}-{n}-B'})
    check(sql(f'SELECT COUNT(*) FROM book_copy WHERE BookID={bid}')=='2','manual accession form creates copies '+str(n))
submission=token(request(member,'member_catalog.php'),'submission_token')
data={'action':'submit_requests','book_ids[]':books,'submission_token':submission}
page=post(member,'member_catalog.php',data)
tx=sql(f'SELECT MAX(TransactionID) FROM request_transaction WHERE UserID={uid}')
check('RQ-'+tx.zfill(6) in page and sql(f'SELECT COUNT(*) FROM borrow_request WHERE TransactionID={tx}')=='2','basket redirects to one grouped transaction')
post(member,'member_catalog.php',data)
check(sql(f'SELECT COUNT(*) FROM request_transaction WHERE UserID={uid}')=='1','repeated HTTP submission does not create another transaction')
ids=sql(f'SELECT RequestID FROM borrow_request WHERE TransactionID={tx} ORDER BY RequestID').splitlines()
page=post(admin,'borrow_requests.php',{'action':'approve','transaction_id':tx,'request_ids[]':ids,'list_query':'q='+prefix+'&tab=all&from=2020-01-01'})
check(sql(f"SELECT COUNT(*) FROM borrow_request WHERE TransactionID={tx} AND ApprovalStatus='Approved'")=='2','staff approves multiple request items in one action')
check('value="'+prefix+'"' in page and 'value="2020-01-01"' in page,'approval preserves the member search and date filters')
page=post(admin,'borrow_requests.php',{'action':'release','transaction_id':tx,'request_ids[]':ids,'list_query':'q='+prefix+'&tab=all'})
loan=sql(f'SELECT MAX(ReleasedLoanID) FROM borrow_request WHERE TransactionID={tx}')
check(sql(f'SELECT COUNT(*) FROM loan_detail WHERE LoanID={loan}')=='2' and 'Transaction #'+loan in page,'batch release records two books and preserves the request list')
page=request(member,'member_loans.php?q='+loan)
check('Transaction #'+loan in page and all(prefix+'-'+str(n) in page for n in range(2)),'member sees every item grouped under its loan')
details=sql(f'SELECT LoanDetailID FROM loan_detail WHERE LoanID={loan} ORDER BY LoanDetailID').splitlines()
sql(f"UPDATE loan_detail SET DueDate=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE LoanDetailID={details[0]}")
quote=post(admin,'returns.php',{'action':'preview_return','list_query':'q='+prefix+'&tab=borrowed&page=1',f'return_conditions[{details[0]}]':'Lost',f'return_conditions[{details[1]}]':'Good'})
check('Review return and penalties' in quote and sql(f'SELECT COUNT(*) FROM return_detail WHERE LoanDetailID IN ({','.join(details)})')=='0','return review calculates charges before writing records')
check('q='+prefix+'&amp;tab=borrowed&amp;page=1' in quote,'return review keeps the original list for Back without saving')
review_token=token(quote,'review_token')
receipt=post(admin,'returns.php',{'action':'process_return','review_token':review_token})
check('Return receipt RT-' in receipt and 'Record payment' in receipt,'return receipt includes assessed penalties and payment links')
check('q='+prefix+'&amp;tab=borrowed&amp;page=1' in receipt,'return receipt restores the original search and page')
post(admin,'returns.php',{'action':'process_return','review_token':review_token})
check(sql(f'SELECT COUNT(*) FROM return_detail WHERE LoanDetailID IN ({','.join(details)})')=='2','repeated return confirmation reopens the receipt')
# Every changed list can execute both empty and populated search paths.
for endpoint in ['index.php','borrow_requests.php','returns.php','returns.php?tab=returned','loans.php','fines.php','payments.php','categories.php','publishers.php','sections.php','policies.php','roles.php','donors.php','staff.php','books.php','book_copies.php','reports.php?report=borrow_requests']:
    request(admin,endpoint)
    request(admin,endpoint+('&' if '?' in endpoint else '?')+'q='+prefix)
check(True,'staff list searches and pagination queries render without server errors')
for endpoint in ['member_requests.php','member_loans.php','member_loans.php?tab=history','member_fines.php']:
    request(member,endpoint+('&' if '?' in endpoint else '?')+'q='+prefix)
check(True,'member search pages render while remaining scoped to the signed-in member')
check('No matching transactions' in request(member,'member_requests.php?q=NOT-A-REAL-TRANSACTION'),'empty transaction searches do not show unrelated requests')
check('href="borrow_requests.php"' in request(admin,'borrow_requests.php?q='+prefix),'clear-search link stays on the requests page')
print('Feedback HTTP workflow passed.')
