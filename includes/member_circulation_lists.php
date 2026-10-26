<?php
/** Member pagination keeps every outstanding book for a member together. */
function return_member_groups(string $search, int $page, int $memberId=0, int $transactionId=0): array
{
    $where=[];$params=[];
    if($memberId){$where[]='l.BorrowerUserID=?';$params[]=$memberId;}
    if($transactionId){$where[]='l.LoanID=?';$params[]=$transactionId;}
    if($search!==''){
        $where[]="(CAST(l.LoanID AS CHAR) LIKE ? OR CONCAT(m.FirstName,' ',m.LastName) LIKE ? OR m.MembershipNumber LIKE ? OR EXISTS(SELECT 1 FROM loan_detail d JOIN book_copy c ON c.CopyID=d.CopyID JOIN book b ON b.BookID=c.BookID WHERE d.LoanID=l.LoanID AND (b.Title LIKE ? OR c.AccessionNumber LIKE ?)))";
        array_push($params,...array_fill(0,5,'%'.$search.'%'));
    }
    $where[]='EXISTS(SELECT 1 FROM loan_detail d WHERE d.LoanID=l.LoanID AND NOT EXISTS(SELECT 1 FROM return_detail r WHERE r.LoanDetailID=d.LoanDetailID))';
    $base='FROM loan l JOIN user_account m ON m.UserID=l.BorrowerUserID WHERE '.implode(' AND ',$where);
    $pg=pagination_calc((int)fetch_value('SELECT COUNT(DISTINCT m.UserID) '.$base,$params),10,$page);
    $ids=array_column(fetch_all("SELECT m.UserID,MAX(l.LoanID) AS Latest $base GROUP BY m.UserID ORDER BY Latest DESC,m.UserID LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",$params),'UserID');
    $groups=[];foreach($ids as $id)$groups[(int)$id]=['items'=>[],'transactions'=>[]];
    if($ids){
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $items=fetch_all("SELECT d.*,l.LoanDate,l.BorrowerUserID,CONCAT(m.FirstName,' ',m.LastName) AS Member,m.MembershipNumber,b.Title,c.AccessionNumber
            FROM loan_detail d JOIN loan l ON l.LoanID=d.LoanID JOIN user_account m ON m.UserID=l.BorrowerUserID
            JOIN book_copy c ON c.CopyID=d.CopyID JOIN book b ON b.BookID=c.BookID
            WHERE m.UserID IN ($marks) AND NOT EXISTS(SELECT 1 FROM return_detail r WHERE r.LoanDetailID=d.LoanDetailID)
            ORDER BY l.LoanID DESC,d.LoanDetailID",$ids);
        foreach($items as $item){$id=(int)$item['BorrowerUserID'];$groups[$id]['member']=$item;$groups[$id]['items'][]=$item;$groups[$id]['transactions'][(int)$item['LoanID']]=true;}
    }
    return ['pagination'=>$pg,'groups'=>$groups];
}
/** Group review/receipt rows by identity, never by a potentially shared name. */
function return_rows_by_member(array $rows): array
{
    $groups=[];foreach($rows as $row)$groups[(int)$row['BorrowerUserID']][]=$row;
    return $groups;
}
/** Apply request filters before paging members, then keep all matching submissions together. */
function request_member_groups(string $base,array $params,int $page): array
{
    $pg=pagination_calc((int)fetch_value('SELECT COUNT(DISTINCT t.UserID) '.$base,$params),10,$page);
    $ids=array_column(fetch_all("SELECT t.UserID,MAX(t.TransactionID) AS Latest $base GROUP BY t.UserID ORDER BY Latest DESC,t.UserID LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",$params),'UserID');
    $groups=[];foreach($ids as $id)$groups[(int)$id]=['transactions'=>[]];
    if($ids){
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $rows=fetch_all("SELECT t.*,m.FirstName,m.LastName,m.MembershipNumber $base AND t.UserID IN ($marks) ORDER BY t.TransactionID DESC",array_merge($params,$ids));
        foreach($rows as $row){$id=(int)$row['UserID'];$groups[$id]['member']=$row;$groups[$id]['transactions'][]=$row;}
    }
    return ['pagination'=>$pg,'groups'=>$groups];
}
