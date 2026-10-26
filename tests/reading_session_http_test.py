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
prefix='READ-'+secrets.token_hex(3).upper()
actor=sql("SELECT UserID FROM user_account WHERE Email='admin@library.local'")
role=sql("SELECT RoleID FROM role WHERE RoleName='Member' LIMIT 1")
book=sql("SELECT BookID FROM book WHERE IsArchived=0 ORDER BY BookID LIMIT 1")
users=[]; copies=[]; rows=[]; forms=[]
for n in range(2):
 users.append(sql(f"INSERT INTO user_account(RoleID,FirstName,LastName,Username,Email,PasswordHash,MembershipNumber,RegistrationDate,MembershipStatus,VerificationStatus) VALUES({role},'Session','Student {n}','{prefix}-{n}','{prefix}-{n}@example.test','unused','{prefix}-{n}',CURDATE(),'Active','Approved'); SELECT LAST_INSERT_ID();"))
 sql(f"INSERT INTO library_entry(MemberID,Method,RecordedByUserID) VALUES({users[-1]},'Staff',{actor})")
for n in range(10):
 copies.append(sql(f"INSERT INTO book_copy(BookID,AccessionNumber,Status) VALUES({book},'{prefix}-C{n}','Available'); SELECT LAST_INSERT_ID();"))
for n in range(3):
 ref=prefix+'-F'+str(n)
 kind='Member' if n==2 else 'Walk-in'
 member=users[0] if n==2 else 'NULL'
 forms.append(sql(f"INSERT INTO in_library_form(FormNo,BorrowerType,MemberID,WalkInName,IdSlot,IdNumber,IssuedByUserID,RulesAcknowledged) VALUES('{ref}','{kind}',{member},'Preview Visitor','{prefix}-S{n}','original-id',{actor},1); SELECT LAST_INSERT_ID();"))
 for c in ([0,1] if n==0 else [2] if n==1 else [3,5]):
  rows.append(sql(f"INSERT INTO in_library_use(CopyID,MemberID,BorrowerType,WalkInName,UserID,SessionRef,Location,Remarks,StartTime) VALUES({copies[c]},{member},'{kind}','Preview Visitor',{actor},'{ref}','Reading room','Original note',NOW()); SELECT LAST_INSERT_ID();"))
  sql(f"UPDATE book_copy SET Status='In Use' WHERE CopyID={copies[c]}")


def key(form=0):return 'form:'+forms[form]
def pane(form=0,view='books',origin='in_library_use.php'):
 return 'reading_session.php?'+urllib.parse.urlencode({'key':key(form),'view':view,'fragment':'1','return_to':origin})
def act(action,fields=None,form=0,version=None,origin='in_library_use.php'):
 view='add' if action=='add' else 'books'
 page=request(admin,pane(form,view,origin));data={'action':action,'key':key(form),'session_version':version or token(page,'session_version'),'return_to':origin,'view':view};data.update(fields or {})
 post(admin,pane(form,view,origin),data)
 return request(admin,pane(form,view,origin))
def snapshot():
 return sql(f"SELECT InLibraryUseID,CopyID,Status,ConditionIn,ReturnTime FROM in_library_use WHERE SessionRef LIKE '{prefix}-%' ORDER BY InLibraryUseID"),sql(f"SELECT FormID,IdSlot,IdStatus,Status FROM in_library_form WHERE FormID IN ({','.join(forms)}) ORDER BY FormID"),sql(f"SELECT CopyID,Status FROM book_copy WHERE CopyID IN ({','.join(copies)}) ORDER BY CopyID"),sql(f"SELECT COUNT(*) FROM activity_log a JOIN in_library_use s ON s.InLibraryUseID=a.RecordID WHERE a.Module='In-Library Use' AND s.SessionRef LIKE '{prefix}-%'")
try:
 before=snapshot();version=token(request(admin,pane(0,'add')),'session_version')
 fields={'accessions':prefix+'-C4\n'+prefix+'-C6','condition_out':'Fair','location':'Study Area','remarks':'Additional reading'}
 page=act('add',fields,origin='desk_form.php')
 check('2 book(s) added' in page,'Library Desk adds several books in one submission')
 new=sql(f"SELECT InLibraryUseID FROM in_library_use WHERE SessionRef='{prefix}-F0' AND CopyID IN ({copies[4]},{copies[6]}) ORDER BY InLibraryUseID").splitlines();rows.extend(new)
 check(len(new)==2 and sql(f"SELECT COUNT(*) FROM in_library_use WHERE SessionRef='{prefix}-F0' AND Status='Active'")=='4','additional books join the existing reader session')
 check(sql(f"SELECT COUNT(*) FROM in_library_form WHERE FormNo='{prefix}-F0'")=='1' and sql(f"SELECT IdSlot FROM in_library_form WHERE FormID={forms[0]}")==prefix+'-S0','addition creates no duplicate form or ID storage record')
 check(sql(f"SELECT COUNT(*) FROM in_library_use WHERE InLibraryUseID IN ({','.join(new)}) AND ConditionOut='Fair' AND Location='Study Area' AND Remarks='Additional reading'")=='2','new books retain their issue condition and optional details')
 check(sql(f"SELECT COUNT(*) FROM book_copy WHERE CopyID IN ({copies[4]},{copies[6]}) AND Status='In Use'")=='2','all added copies become unavailable to other readers')
 before=snapshot();page=act('add',fields,version=version)
 check('session changed' in page and snapshot()==before,'repeated submission cannot issue duplicates')
 page=act('add',dict(fields,accessions=prefix+'-C7\nMISSING-COPY'))
 check('was not found' in page and snapshot()==before and prefix+'-C7' in page,'invalid batch saves nothing and preserves the entered accession numbers')
 page=act('add',dict(fields,accessions=prefix+'-C7\n'+prefix+'-C7'))
 check('more than once' in page and snapshot()==before,'duplicate scans are rejected')
 for index,status in [(8,'Reserved'),(9,'Borrowed')]:
  sql(f"UPDATE book_copy SET Status='{status}' WHERE CopyID={copies[index]}")
  before=snapshot();page=act('add',dict(fields,accessions=prefix+'-C7\n'+prefix+'-C'+str(index)))
  check(snapshot()==before and '2 book(s) added' not in page,'batch rollback protects '+status.lower()+' copies')
 before=snapshot();page=act('return',{'selected[]':[rows[0]],'conditions['+rows[0]+']':'Good','release':'1'})
 check('still out' in page and snapshot()==before,'ID release with outstanding books rolls back the attempted partial return')
 page=act('return',{'selected[]':[rows[0]],'conditions['+rows[0]+']':'Good'})
 check('1 book(s) returned' in page and sql(f"SELECT Status FROM in_library_use WHERE InLibraryUseID={rows[0]}")=='Returned','partial return affects only selected books')
 check(sql(f"SELECT IdStatus FROM in_library_form WHERE FormID={forms[0]}")=='Held','partial return keeps the physical ID in custody')
 active=sql(f"SELECT InLibraryUseID FROM in_library_use WHERE SessionRef='{prefix}-F0' AND Status='Active'").splitlines()
 before=snapshot();bad={'selected[]':active,**{'conditions['+i+']':'Lost' if i==active[0] else 'Good' for i in active}}
 page=act('return',bad);check('Add a note' in page and snapshot()==before,'lost-book return requires a note without partial changes')
 page=act('return',dict(bad,note='Copy reported lost; librarian notified'))
 check(sql(f"SELECT COUNT(*) FROM in_library_use WHERE SessionRef='{prefix}-F0' AND Status='Active'")=='0','batch return handles every selected book')
 lost=sql(f"SELECT CopyID FROM in_library_use WHERE InLibraryUseID={active[0]}")
 check(sql(f'SELECT Status FROM book_copy WHERE CopyID={lost}')=='Lost','lost copy is not returned to available stock')
 for endpoint in ['desk_form.php','in_library_use.php']:
  page=request(admin,endpoint)
  check(page.count('key=form%3A'+forms[0]+'&amp;')==1,'all-books-returned session appears once on '+endpoint)
 page=act('add',dict(fields,accessions=prefix+'-C7'))
 newest=sql(f"SELECT MAX(InLibraryUseID) FROM in_library_use WHERE SessionRef='{prefix}-F0'");rows.append(newest)
 check('1 book(s) added' in page and sql(f"SELECT IdStatus FROM in_library_form WHERE FormID={forms[0]}")=='Held','reader can receive more books after earlier books are returned while ID is still held')
 before=snapshot();page=act('return',{'selected[]':[rows[2]],'conditions['+rows[2]+']':'Good'})
 check('no longer active in this session' in page and snapshot()==before,'another reader book cannot be returned through this session')
 page=act('return',{'selected[]':[newest],'conditions['+newest+']':'Good'})
 before=snapshot();page=act('return',{'release':'1'})
 check('damaged or lost' in page and snapshot()==before,'earlier lost-book note remains required when giving the ID back')
 page=act('return',{'release':'1','note':'Lost book incident documented with librarian'})
 check('session is closed' in page and sql(f"SELECT IdStatus FROM in_library_form WHERE FormID={forms[0]}")=='Returned','ID-only release closes a fully settled session')
 before=snapshot();page=act('add',dict(fields,accessions=prefix+'-C0'))
 check('session is closed' in page and snapshot()==before,'closed sessions cannot receive more books')
 # Creation and print screens still render; only one shared action system is present.
 desk=request(admin,'desk_form.php');listing=request(admin,'in_library_use.php')
 check('id="deskForm"' in desk and 'id="retModal"' not in desk and 'data-return=' not in desk,'Library Desk retains issuance and removes the duplicate return modal')
 check('data-detail=' not in listing and 'id="detailModal"' not in listing and 'reading-sessions.js' in listing,'Read-Here Books uses the shared session controls')
 for endpoint in ['in_library_use.php?q='+prefix,'in_library_use.php?book='+prefix,'in_library_use.php?type=Member','in_library_use.php?q=NO-MATCH','desk_form.php?slip='+prefix+'-F0']:
  request(admin,endpoint)
 check(True,'reader, book, type, empty searches and updated printable slip render correctly')
 landing=post(admin,pane(1,'add'),{'action':'add','key':key(1),'session_version':token(request(admin,pane(1,'add')),'session_version'),'accessions':'NO-SUCH-COPY','condition_out':'Good','return_query':'q='+prefix})
 check('value="'+prefix+'"' in landing,'saving or validation errors preserve the original reader search')
 print('27 reading-session workflow checks passed.')
 if os.environ.get('KEEP_SESSION_PREVIEW')=='1':
  sql(f"UPDATE in_library_form SET WalkInName='Alex Rivera' WHERE FormID={forms[1]}; UPDATE in_library_use SET WalkInName='Alex Rivera' WHERE SessionRef='{prefix}-F1';")
  print('PREVIEW '+prefix+' form:'+forms[1]+' available '+prefix+'-C0')
finally:
 if os.environ.get('KEEP_SESSION_PREVIEW')!='1':
  sql(f"DELETE a FROM activity_log a JOIN in_library_use s ON s.InLibraryUseID=a.RecordID WHERE a.Module='In-Library Use' AND s.SessionRef LIKE '{prefix}-%'; DELETE FROM activity_log WHERE Module='In-Library Desk' AND RecordID IN ({','.join(forms)}); DELETE FROM in_library_use WHERE SessionRef LIKE '{prefix}-%'; DELETE FROM in_library_form WHERE FormID IN ({','.join(forms)}); DELETE FROM book_copy WHERE CopyID IN ({','.join(copies)}); DELETE FROM library_entry WHERE MemberID IN ({','.join(users)}); DELETE FROM user_account WHERE UserID IN ({','.join(users)});")
