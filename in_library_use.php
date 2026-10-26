<?php
require_once __DIR__.'/includes/auth.php';
require_permission('process_in_library_use');
require_once __DIR__.'/includes/reading_sessions.php';
$page_title='Read-Here Books';
$page_subtitle='Manage each reader’s books together — add books, record returns and manage the stored ID';
if ($_SERVER['REQUEST_METHOD']==='POST') {csrf_guard();flash('warning','Open Manage session to use the updated reading controls.');redirect('in_library_use.php');}
$q=trim((string)($_GET['q']??''));$bookQuery=trim((string)($_GET['book']??''));$type=($_GET['type']??'');
$source="(SELECT CONCAT('form:',f.FormID) AS SessionKey,f.FormNo COLLATE utf8mb4_unicode_ci AS Ref,NULL AS SeedID,f.MemberID,f.WalkInName COLLATE utf8mb4_unicode_ci AS WalkInName,f.BorrowerType COLLATE utf8mb4_unicode_ci AS BorrowerType,f.IssuedAt AS Started
 FROM in_library_form f WHERE f.Status='Open' AND f.IdStatus='Held'
 UNION ALL
 SELECT CONCAT('session:',s.InLibraryUseID),s.SessionRef COLLATE utf8mb4_unicode_ci,s.InLibraryUseID,s.MemberID,s.WalkInName COLLATE utf8mb4_unicode_ci,s.BorrowerType COLLATE utf8mb4_unicode_ci,s.StartTime
 FROM in_library_use s WHERE s.Status='Active' AND NOT EXISTS(SELECT 1 FROM in_library_form f WHERE f.FormNo=s.SessionRef)
 AND s.InLibraryUseID=(SELECT MIN(x.InLibraryUseID) FROM in_library_use x WHERE x.Status='Active' AND ((s.SessionRef IS NOT NULL AND s.SessionRef<>'' AND x.SessionRef=s.SessionRef) OR x.InLibraryUseID=s.InLibraryUseID))) rs";
$where=[];$params=[];
if($q!==''){$where[]="(CONCAT(u.FirstName,' ',u.LastName) LIKE ? OR u.MembershipNumber LIKE ? OR rs.WalkInName LIKE ? OR rs.Ref LIKE ?)";for($i=0;$i<4;$i++)$params[]='%'.$q.'%';}
if($bookQuery!==''){$where[]="EXISTS(SELECT 1 FROM in_library_use x JOIN book_copy bc ON bc.CopyID=x.CopyID JOIN book b ON b.BookID=bc.BookID WHERE ((rs.Ref IS NOT NULL AND rs.Ref<>'' AND x.SessionRef=rs.Ref) OR x.InLibraryUseID=rs.SeedID) AND (b.Title LIKE ? OR bc.AccessionNumber LIKE ?))";$params[]='%'.$bookQuery.'%';$params[]='%'.$bookQuery.'%';}
if(in_array($type,['Member','Walk-in'],true)){$where[]='rs.BorrowerType=?';$params[]=$type;}
$from='FROM '.$source.' LEFT JOIN user_account u ON u.UserID=rs.MemberID'.($where?' WHERE '.implode(' AND ',$where):'');
$pg=pagination_calc((int)fetch_value('SELECT COUNT(*) '.$from,$params),12,pagination_page());
$keys=fetch_all('SELECT rs.SessionKey '.$from.' ORDER BY rs.Started ASC,rs.SessionKey LIMIT '.$pg['per_page'].' OFFSET '.$pg['offset'],$params);
$heldCount=(int)fetch_value("SELECT COUNT(*) FROM in_library_form WHERE Status='Open' AND IdStatus='Held'");
$activeCount=(int)fetch_value("SELECT COUNT(*) FROM in_library_use WHERE Status='Active'");
require __DIR__.'/includes/layout_top.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3"><p class="text-muted mb-0">Use the same session controls here and in Library Desk. Take-home books are managed in <a href="returns.php">Returns</a>.</p><a class="btn btn-outline-secondary" href="desk_form.php">New session at Library Desk</a></div>
<div class="row g-3 mb-3"><div class="col-6"><div class="card card-body"><strong class="fs-4"><?=$activeCount?></strong><span class="text-muted">Books currently being read</span></div></div><div class="col-6"><div class="card card-body"><strong class="fs-4"><?=$heldCount?></strong><span class="text-muted">IDs in storage</span></div></div></div>
<form class="card card-body mb-3" method="get"><div class="row g-3 align-items-end"><div class="col-md-4"><label class="form-label" for="reading-search">Borrower name, ID or session</label><input class="form-control" id="reading-search" name="q" value="<?=h($q)?>" type="search"></div><div class="col-md-4"><label class="form-label" for="reading-book">Book title or accession</label><input class="form-control" id="reading-book" name="book" value="<?=h($bookQuery)?>" type="search"></div><div class="col-md-2"><label class="form-label" for="reading-type">Registration</label><select id="reading-type" name="type" class="form-select"><option value="">All borrowers</option><option value="Member" <?=$type==='Member'?'selected':''?>>Registered borrower</option><option value="Walk-in" <?=$type==='Walk-in'?'selected':''?>>Walk-in borrower</option></select></div><div class="col-md-2 d-flex gap-2"><button class="btn btn-primary">Search</button><a class="btn btn-outline-secondary" href="in_library_use.php">Clear</a></div></div></form>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3"><span class="small text-muted"><?=pagination_info($pg)?> reading sessions</span><a href="in_library_use_history.php">View reading history</a></div>
<div class="row g-3"><?php $readingOrigin='in_library_use.php';foreach($keys as$entry):$readingCard=reading_context($entry['SessionKey']);?><div class="col-xl-6"><?php require __DIR__.'/includes/reading_session_card.php';?></div><?php endforeach;?></div>
<?php if(!$keys):?><div class="card card-body text-center py-5"><h2 class="h6">No reading sessions found</h2><p class="text-muted mb-0">Try another search or start a session at Library Desk.</p></div><?php endif;?>
<?=pagination_links($pg,$_GET)?>
<?php require __DIR__.'/includes/reading_session_launcher.php';require __DIR__.'/includes/layout_bottom.php';?>
