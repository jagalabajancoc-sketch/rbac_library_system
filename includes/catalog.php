<?php
/** Server-side catalog. Only one bounded page of book records leaves the server. */
require_once __DIR__.'/functions.php';
function catalog_text($value, int $length=120): string { return is_scalar($value) ? mb_substr(trim((string)$value),0,$length) : ''; }
function catalog_picks($value): array {
    return array_slice(array_values(array_unique(array_filter(array_map('catalog_text',is_array($value)?$value:[])))),0,20);
}
function catalog_like(string $value): string { return '%'.str_replace(['=','%','_'],['==','=%','=_'],$value).'%'; }
function catalog_page(array $input, int $memberId): array {
    $q=catalog_text($input['q'] ?? '');
    $page=max(1,min(1000000,(int)catalog_text($input['page'] ?? '1'))); $perPage=12;
    $where=['b.IsArchived=0']; $params=[];
    $from='FROM book b LEFT JOIN category c ON c.CategoryID=b.CategoryID LEFT JOIN section sec ON sec.SectionID=b.SectionID
        LEFT JOIN publisher p ON p.PublisherID=b.PublisherID LEFT JOIN policy pol ON pol.PolicyID=b.PolicyID';
    foreach (array_slice(preg_split('/\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY) ?: [],0,8) as $term) {
        $where[]="(b.Title LIKE ? ESCAPE '=' OR REPLACE(REPLACE(b.ISBN,'-',''),' ','') LIKE ? ESCAPE '=' OR p.PublisherName LIKE ? ESCAPE '='
            OR EXISTS(SELECT 1 FROM book_author ba JOIN author a ON a.AuthorID=ba.AuthorID WHERE ba.BookID=b.BookID AND CONCAT_WS(' ',a.FirstName,a.LastName) LIKE ? ESCAPE '='))";
        array_push($params,catalog_like($term),catalog_like(str_replace('-','',$term)),catalog_like($term),catalog_like($term));
    }
    foreach(['section'=>'b.SectionID','category'=>'b.CategoryID'] as $key=>$column) {
        $ids=array_values(array_filter(catalog_picks($input[$key] ?? []),'ctype_digit'));
        if($ids) { $where[]=$column.' IN ('.implode(',',array_fill(0,count($ids),'?')).')'; array_push($params,...$ids); }
    }
    $authors=catalog_picks($input['author'] ?? []);
    if($authors) {
        $where[]="EXISTS(SELECT 1 FROM book_author ba JOIN author a ON a.AuthorID=ba.AuthorID WHERE ba.BookID=b.BookID AND CONCAT_WS(' ',a.FirstName,a.LastName) IN (".implode(',',array_fill(0,count($authors),'?')).'))';
        array_push($params,...$authors);
    }
    $shelf=sql_available_copies('b');
    $onLoan="(SELECT COUNT(*) FROM loan_detail ld JOIN book_copy bc ON bc.CopyID=ld.CopyID LEFT JOIN return_detail rd ON rd.LoanDetailID=ld.LoanDetailID WHERE bc.BookID=b.BookID AND rd.ReturnDetailID IS NULL)";
    $overdue="(SELECT COUNT(*) FROM loan_detail ld JOIN book_copy bc ON bc.CopyID=ld.CopyID LEFT JOIN return_detail rd ON rd.LoanDetailID=ld.LoanDetailID WHERE bc.BookID=b.BookID AND rd.ReturnDetailID IS NULL AND ld.DueDate<CURDATE())";
    $statuses=[];
    foreach(catalog_picks($input['status'] ?? []) as $status) {
        if($status==='available') $statuses[]='(pol.IsBorrowable=1 AND '.$shelf.'>'.borrow_keep_copies().')';
        elseif($status==='onloan') $statuses[]=$onLoan.'>0';
        elseif($status==='overdue') $statuses[]=$overdue.'>0';
        elseif($status==='reference') $statuses[]='pol.IsBorrowable=0';
    }
    if($statuses) $where[]='('.implode(' OR ',$statuses).')';
    $filter=' WHERE '.implode(' AND ',$where);
    $total=(int)fetch_value('SELECT COUNT(*) '.$from.$filter,$params);
    $pages=max(1,(int)ceil($total/$perPage)); $page=min($page,$pages); $offset=($page-1)*$perPage;
    $order=match(catalog_text($input['sort'] ?? 'relevant')) {
        'author'=>'Authors, b.Title, b.BookID', 'publisher'=>'p.PublisherName DESC, b.Title, b.BookID', default=>'b.Title, b.BookID'
    };
    if(($input['sort'] ?? 'relevant')==='relevant' && $q!=='') {
        $order="CASE WHEN b.Title LIKE ? ESCAPE '=' THEN 0 ELSE 1 END, b.Title, b.BookID";
        $params[]=substr(catalog_like($q),1);
    }
    $rows=fetch_all("SELECT b.BookID,b.Title,b.ISBN,b.Edition,b.PublicationYear,b.CategoryID,b.SectionID,c.CategoryName,sec.SectionName,p.PublisherName,pol.IsBorrowable,pol.LoanDays,
        (SELECT GROUP_CONCAT(CONCAT_WS(' ',a.FirstName,a.LastName) ORDER BY a.LastName,a.FirstName SEPARATOR '|') FROM book_author ba JOIN author a ON a.AuthorID=ba.AuthorID WHERE ba.BookID=b.BookID) AS Authors,
        $shelf AS AvailableCopies, ".sql_total_copies('b')." AS TotalCopies, $onLoan AS OnLoanCopies, $overdue AS OverdueCopies,
        (SELECT GROUP_CONCAT(DISTINCT CONCAT_WS(' / ',s.SectionName,sh.ShelfCode) ORDER BY sh.ShelfCode SEPARATOR '; ') FROM book_copy bc JOIN shelf sh ON sh.ShelfID=bc.ShelfID LEFT JOIN section s ON s.SectionID=sh.SectionID WHERE bc.BookID=b.BookID AND bc.IsArchived=0) AS Shelves
        $from $filter ORDER BY $order LIMIT $perPage OFFSET $offset",$params);
    $requested=array_map('intval',array_column(fetch_all("SELECT DISTINCT d.BookID FROM borrow_request r JOIN borrow_request_detail d ON d.RequestID=r.RequestID WHERE r.UserID=? AND r.ApprovalStatus IN ('Pending','Approved')",[$memberId]),'BookID'));
    $holding=array_map('intval',array_column(fetch_all('SELECT DISTINCT bc.BookID FROM loan_detail ld JOIN loan l ON l.LoanID=ld.LoanID JOIN book_copy bc ON bc.CopyID=ld.CopyID LEFT JOIN return_detail rd ON rd.LoanDetailID=ld.LoanDetailID WHERE l.BorrowerUserID=? AND rd.ReturnDetailID IS NULL',[$memberId]),'BookID'));
    $books=[];
    foreach($rows as $r) {
        $books[]=['id'=>(int)$r['BookID'],'title'=>$r['Title'],'authors'=>$r['Authors']?explode('|',$r['Authors']):[],
            'publisher'=>$r['PublisherName']??'Unknown publisher','year'=>(string)($r['PublicationYear']??''),'isbn'=>$r['ISBN']??'', 'edition'=>$r['Edition']??'',
            'categoryId'=>(string)$r['CategoryID'],'category'=>$r['CategoryName']??'Uncategorized', 'sectionId'=>(string)$r['SectionID'],'section'=>$r['SectionName']??'Unassigned section',
            'locations'=>$r['Shelves']?:'Shelf location not assigned — ask the librarian.',
            'borrowable'=>(int)$r['IsBorrowable']===1,
            'loanDays'=>(int)$r['LoanDays'] > 0 ? min(MAX_LOAN_DAYS, (int)$r['LoanDays']) : DEFAULT_LOAN_DAYS,
            'avail'=>max(0,(int)$r['AvailableCopies']-borrow_keep_copies()),'shelf'=>(int)$r['AvailableCopies'],'total'=>(int)$r['TotalCopies'],
            'onLoan'=>(int)$r['OnLoanCopies'],'overdue'=>(int)$r['OverdueCopies'],
            'mine'=>in_array((int)$r['BookID'],$holding,true)?'holding':(in_array((int)$r['BookID'],$requested,true)?'requested':'')];
    }
    $facets=[];
    foreach(['section'=>['section','SectionID','SectionName'],'category'=>['category','CategoryID','CategoryName']] as $key=>[$table,$id,$name]) {
        $facets[$key]=fetch_all("SELECT CAST(t.$id AS CHAR) AS value,t.$name AS label,COUNT(*) AS n FROM $table t JOIN book b ON b.$id=t.$id WHERE b.IsArchived=0 GROUP BY t.$id,t.$name ORDER BY t.$name");
    }
    $authorQ=catalog_like(catalog_text($input['author_q']??''));
    $facets['authors']=fetch_all("SELECT CONCAT_WS(' ',a.FirstName,a.LastName) AS label,COUNT(DISTINCT b.BookID) AS n FROM author a JOIN book_author ba ON ba.AuthorID=a.AuthorID JOIN book b ON b.BookID=ba.BookID WHERE b.IsArchived=0 AND CONCAT_WS(' ',a.FirstName,a.LastName) LIKE ? ESCAPE '=' GROUP BY label ORDER BY label LIMIT 20",[$authorQ]);
    return ['books'=>$books,'total'=>$total,'page'=>$page,'pages'=>$pages,'perPage'=>$perPage,'facets'=>$facets];
}
