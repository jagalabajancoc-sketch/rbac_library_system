"""HTTP regression test. Uses only a server explicitly marked with the same library_test_* database."""
import html, os, re, json, secrets, subprocess, urllib.request, urllib.parse, http.cookiejar
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

admin=session()
post(admin,'login.php',{'username':'admin@library.local','password':os.environ['LIBRARY_TEST_ADMIN_PASSWORD']})
prefix='EDIT-'+secrets.token_hex(3).upper()
actor=sql("SELECT UserID FROM user_account WHERE Email='admin@library.local'")
role=sql("SELECT RoleID FROM role WHERE RoleName='Member' LIMIT 1")
book=sql("SELECT BookID FROM book WHERE IsArchived=0 ORDER BY BookID LIMIT 1")
users=[]; copies=[]; rows=[]; forms=[]
for n in range(2):
 users.append(sql(f"INSERT INTO user_account(RoleID,FirstName,LastName,Username,Email,PasswordHash,MembershipNumber,RegistrationDate,MembershipStatus,VerificationStatus) VALUES({role},'Session','Student {n}','{prefix}-{n}','{prefix}-{n}@example.test','unused','{prefix}-{n}',CURDATE(),'Active','Approved'); SELECT LAST_INSERT_ID();"))
 sql(f"INSERT INTO library_entry(MemberID,Method,RecordedByUserID) VALUES({users[-1]},'Staff',{actor})")
for n in range(6):
 copies.append(sql(f"INSERT INTO book_copy(BookID,AccessionNumber,Status) VALUES({book},'{prefix}-C{n}','Available'); SELECT LAST_INSERT_ID();"))
for n in range(3):
 ref=prefix+'-F'+str(n)
 kind='Member' if n==2 else 'Walk-in'
 member=users[0] if n==2 else 'NULL'
 forms.append(sql(f"INSERT INTO in_library_form(FormNo,BorrowerType,MemberID,WalkInName,IdSlot,IdNumber,IssuedByUserID,RulesAcknowledged) VALUES('{ref}','{kind}',{member},'Preview Visitor','{prefix}-S{n}','original-id',{actor},1); SELECT LAST_INSERT_ID();"))
 for c in ([0,1] if n==0 else [2] if n==1 else [3,5]):
  rows.append(sql(f"INSERT INTO in_library_use(CopyID,MemberID,BorrowerType,WalkInName,UserID,SessionRef,Location,Remarks,StartTime) VALUES({copies[c]},{member},'{kind}','Preview Visitor',{actor},'{ref}','Reading room','Original note',NOW()); SELECT LAST_INSERT_ID();"))
  sql(f"UPDATE book_copy SET Status='In Use' WHERE CopyID={copies[c]}")

def session_key(rid):
 return 'form:'+sql(f"SELECT f.FormID FROM in_library_form f JOIN in_library_use r ON r.SessionRef=f.FormNo WHERE r.InLibraryUseID={rid}")
def pane(rid,view='books'):
 return 'reading_session.php?'+urllib.parse.urlencode({'key':session_key(rid),'book':rid,'view':view,'fragment':'1','return_to':'in_library_use.php'})
def details(rid):
 d=json.loads(sql(f"SELECT JSON_OBJECT('id',InLibraryUseID,'location',COALESCE(Location,''),'remarks',COALESCE(Remarks,''),'student',WalkInName,'contact',COALESCE(WalkInContact,'')) FROM in_library_use WHERE InLibraryUseID={rid}"))
 page=request(admin,pane(rid,'edit'));d['version']=token(page,'version');d['session_version']=token(page,'session_version')
 d['slot']=sql(f"SELECT IdSlot FROM in_library_form WHERE FormID={session_key(rid).split(':')[1]}")
 return d
def edit(rid,changes=None,correction=False,version=None):
 d=details(rid)
 data={'action':'correct' if correction else 'edit','key':session_key(rid),'session_id':rid,'version':version or d['version'],'session_version':d['session_version'],'location':d['location'],'remarks':d['remarks'],'walk_in_name':d['student'],'walk_in_contact':d['contact'],'slot':d['slot'] or ''}
 data.update(changes or {})
 post(admin,pane(rid),data)
 return request(admin,pane(rid))
def state():
 return sql(f"SELECT InLibraryUseID,CopyID,MemberID,WalkInName,WalkInContact,Location,Remarks,StartTime,ReturnTime FROM in_library_use WHERE InLibraryUseID IN ({','.join(rows)}) ORDER BY InLibraryUseID"),sql(f"SELECT FormID,MemberID,WalkInName,IdSlot,IdNumber FROM in_library_form WHERE FormID IN ({','.join(forms)}) ORDER BY FormID"),sql(f"SELECT CopyID,Status FROM book_copy WHERE CopyID IN ({','.join(copies)}) ORDER BY CopyID"),sql(f"SELECT COUNT(*) FROM activity_log WHERE Module='In-Library Use' AND RecordID IN ({','.join(rows)})")
try:
 initial_time=sql(f'SELECT StartTime FROM in_library_use WHERE InLibraryUseID={rows[0]}')
 stale=details(rows[0])['version']
 page=edit(rows[0],{'location':'','remarks':'Corrected note','walk_in_name':'Alex Rivera','walk_in_contact':'Visitor ID checked','slot':prefix+'-NEW'})
 check('Session changes saved.' in page,'edit saves with rendered version token')
 check(sql(f"SELECT COUNT(*) FROM in_library_use WHERE SessionRef='{prefix}-F0' AND WalkInName='Alex Rivera' AND WalkInContact='Visitor ID checked'")=='2','visitor identity stays consistent across shared active books')
 check(sql(f"SELECT IdSlot FROM in_library_form WHERE FormID={forms[0]}")==prefix+'-NEW','shared held-ID storage slot changes')
 check(sql(f"SELECT Location IS NULL FROM in_library_use WHERE InLibraryUseID={rows[0]}")=='1','reading area stays optional')
 check(sql(f"SELECT Remarks FROM in_library_use WHERE InLibraryUseID={rows[1]}")=='Original note','book remarks remain specific to the selected record')
 before=state();page=edit(rows[0],{'remarks':'Must not save','slot':prefix+'-S1'})
 check('already holds another ID' in page and state()==before,'occupied ID slot rolls back every edit and audit insert')
 page=edit(rows[0],{'remarks':'Stale change'},version=stale)
 check('changed while you were editing' in page and state()==before,'stale updates cannot overwrite newer edits')
 page=edit(rows[0]);check('Session changes saved.' in page and state()==before,'saving unchanged values succeeds without duplicate audit records')
 changes={'correction_kind':'book','accession':prefix+'-C4','condition_out':'Fair','reason':'Wrong accession scanned','verified':'1'}
 page=edit(rows[0],dict(changes,reason=''),True);check('Enter a reason' in page and state()==before,'assignment correction requires a reason')
 page=edit(rows[0],dict(changes,accession=prefix+'-C2'),True);check('not available' in page and state()==before,'an occupied replacement copy cannot be assigned')
 page=edit(rows[0],changes,True)
 check('Assignment corrected' in page and sql(f'SELECT CopyID FROM in_library_use WHERE InLibraryUseID={rows[0]}')==copies[4],'book correction updates the selected reading record')
 check(sql(f'SELECT Status FROM book_copy WHERE CopyID={copies[0]}')=='Available' and sql(f'SELECT Status FROM book_copy WHERE CopyID={copies[4]}')=='In Use','old and new physical-copy availability stay consistent')
 check(sql(f'SELECT StartTime FROM in_library_use WHERE InLibraryUseID={rows[0]}')==initial_time,'assignment correction preserves the start time')
 audit=sql(f"SELECT Description FROM activity_log WHERE RecordID={rows[0]} AND Module='In-Library Use'")
 check('Wrong accession scanned' in audit and prefix+'-C0' in audit and prefix+'-C4' in audit,'audit records the reason and original/replacement accession numbers')
 page=edit(rows[3],{'correction_kind':'student','student_id':prefix+'-1','reason':'Wrong student selected','verified':'1'},True)
 check('Assignment corrected' in page and sql(f"SELECT COUNT(*) FROM in_library_use WHERE SessionRef='{prefix}-F2' AND MemberID={users[1]}")=='2','student correction updates every active book in the shared session')
 check(sql(f'SELECT MemberID FROM in_library_form WHERE FormID={forms[2]}')==users[1] and sql(f'SELECT IdNumber FROM in_library_form WHERE FormID={forms[2]}')==prefix+'-1','student correction updates the shared form and held ID')
 d=details(rows[3]);sql(f"UPDATE in_library_use SET Status='Returned',ReturnTime=NOW() WHERE InLibraryUseID={rows[3]}")
 before=state();post(admin,pane(rows[3]),{'action':'edit','key':session_key(rows[3]),'session_id':rows[3],'version':d['version'],'session_version':d['session_version'],'remarks':'Must not save'});page=request(admin,pane(rows[3]))
 check(('session changed' in page or 'session has ended' in page) and state()==before,'completed records cannot be edited')
 page=edit(rows[4],{'correction_kind':'student','student_id':prefix+'-0','reason':'Cannot rewrite completed history','verified':'1'},True)
 check('includes completed' in page and state()==before,'shared sessions with completed books cannot be reassigned')
 before=state()
 try:
  request(admin,pane(rows[0]),{'action':'edit','key':session_key(rows[0]),'session_id':rows[0],'version':details(rows[0])['version'],'remarks':'No token'})
  raise AssertionError('Missing token accepted')
 except urllib.error.HTTPError as e:check(e.code==403 and state()==before,'missing CSRF token rejected without changes')
 assistant_role=sql("SELECT RoleID FROM role WHERE RoleName='Assistant' LIMIT 1")
 password_hash=subprocess.check_output([os.environ.get('PHP_PATH',r'C:\xampp\php\php.exe'),'-r',"echo password_hash('Edit-Test-Only!',PASSWORD_DEFAULT);"],text=True)
 assistant_id=sql(f"INSERT INTO user_account(RoleID,FirstName,LastName,Username,PasswordHash,MembershipStatus,VerificationStatus) VALUES({assistant_role},'Session','Assistant','{prefix}-assistant','{password_hash}','Active','Approved'); SELECT LAST_INSERT_ID();")
 users.append(assistant_id)
 assistant=session();post(assistant,'login.php',{'username':prefix+'-assistant','password':'Edit-Test-Only!'})
 page=request(assistant,pane(rows[0],'detail'))
 check('Edit details' not in page and 'Correct assignment' not in page,'assistant can view sessions without librarian edit controls')
 before=state()
 try:
  page=post(assistant,pane(rows[0]),{'action':'edit','key':session_key(rows[0]),'session_id':rows[0],'version':details(rows[0])['version'],'remarks':'Unauthorized'})
 except urllib.error.HTTPError as e:
  assert e.code==403
 check(state()==before,'server rejects unauthorized direct edit submissions')
 try:
  post(assistant,pane(rows[0]),{'action':'add','key':session_key(rows[0]),'session_version':details(rows[0])['session_version'],'accessions':prefix+'-C0','condition_out':'Good'})
  raise AssertionError('Assistant added books')
 except urllib.error.HTTPError as e:check(e.code==403 and state()==before,'only librarians and administrators can add reading books')
 print('22 session-edit HTTP checks passed.')
 if os.environ.get('KEEP_SESSION_PREVIEW')=='1':
  print('PREVIEW '+prefix)
finally:
 if os.environ.get('KEEP_SESSION_PREVIEW')!='1':
  sql(f"DELETE FROM activity_log WHERE Module='In-Library Use' AND RecordID IN ({','.join(rows)}); DELETE FROM in_library_use WHERE InLibraryUseID IN ({','.join(rows)}); DELETE FROM in_library_form WHERE FormID IN ({','.join(forms)}); DELETE FROM book_copy WHERE CopyID IN ({','.join(copies)}); DELETE FROM library_entry WHERE MemberID IN ({','.join(users)}); DELETE FROM user_account WHERE UserID IN ({','.join(users)});")
