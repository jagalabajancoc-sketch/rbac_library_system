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


member=session();prefix='ACTIVE-'+secrets.token_hex(4).upper();password='Test-Requests-Only!'
post(member,'register.php',{'first_name':'Request','last_name':'Test','email':prefix+'@example.test','username':prefix,'borrower_id':prefix,'member_kind':'Teacher','password':password,'confirm_password':password})
uid=sql(f"SELECT UserID FROM user_account WHERE Username='{prefix}'")
post(member,'login.php',{'username':prefix,'password':password})
book=sql('SELECT BookID FROM book LIMIT 1');txs=[];ids=[]
try:
 for state in ['Pending','Approved','Fulfilled','Cancelled','Rejected','Expired']:
  tx=sql(f'INSERT INTO request_transaction(UserID) VALUES({uid});SELECT LAST_INSERT_ID();');txs.append(tx)
  rid=sql(f"INSERT INTO borrow_request(UserID,TransactionID,RequestType,ApprovalStatus) VALUES({uid},{tx},'Borrow','{state}');SELECT LAST_INSERT_ID();");ids.append(rid)
  sql(f'INSERT INTO borrow_request_detail(RequestID,BookID) VALUES({rid},{book})')
 # Mixed submission: pending and issued siblings must be separated at item level.
 mixed=sql(f"INSERT INTO borrow_request(UserID,TransactionID,RequestType,ApprovalStatus) VALUES({uid},{txs[0]},'Borrow','Fulfilled');SELECT LAST_INSERT_ID();");ids.append(mixed)
 sql(f'INSERT INTO borrow_request_detail(RequestID,BookID) VALUES({mixed},{book})')
 def visible(page,tx):return 'id="requestModal'+tx+'"' in page
 page=request(member,'member_requests.php')
 check(all(visible(page,t) for t in txs[:2]) and not any(visible(page,t) for t in txs[2:]),'default view contains only pending and approved submissions')
 check('Issued — check record' not in page and '· 1 book(s)' in page,'issued siblings are excluded from mixed-submission details and counts')
 history=request(member,'member_requests.php?tab=history')
 check(all(visible(history,t) for t in txs[3:]) and not any(visible(history,t) for t in txs[:3]),'history contains only cancelled rejected and expired submissions')
 ready=request(member,'member_requests.php?tab=ready')
 check(visible(ready,txs[1]) and not visible(ready,txs[0]),'ready-for-pickup filter remains available')
 old=request(member,'member_requests.php?tab=all&status=Fulfilled')
 check(visible(old,txs[0]) and not visible(old,txs[2]),'old all/fulfilled URLs cannot restore issued books to requests')
 sql(f"UPDATE borrow_request SET ApprovalStatus='Cancelled' WHERE RequestID={ids[0]}")
 page=request(member,'member_requests.php')
 check(not visible(page,txs[0]),'submission leaves active list after its final pending item closes')
 check('href="my_borrowed_books.php"' in page,'issued-book destination is linked clearly')
 print('7 active-request HTTP checks passed.')
finally:
 sql(f"DELETE FROM borrow_request_detail WHERE RequestID IN ({','.join(ids)});DELETE FROM borrow_request WHERE UserID={uid};DELETE FROM request_transaction WHERE UserID={uid};DELETE FROM user_account WHERE UserID={uid};")
