<?php

/** Staff worklist: one selection across a borrower's matching submissions. */
/** @var array<int, array{member: array, transactions: array}> $requestGroups Provided by request_transactions_view.php. */
foreach ($requestGroups as $borrowerId => $group):
    $person = $group['member'];
    $txIds = array_map('intval', array_column($group['transactions'], 'TransactionID'));
    $marks = implode(',', array_fill(0, count($txIds), '?'));
    $items = fetch_all("SELECT r.*,t.SubmittedAt,c.AccessionNumber,
        (SELECT GROUP_CONCAT(b.Title SEPARATOR ', ') FROM borrow_request_detail d JOIN book b ON b.BookID=d.BookID WHERE d.RequestID=r.RequestID) AS Books
        FROM borrow_request r JOIN request_transaction t ON t.TransactionID=r.TransactionID
        LEFT JOIN book_copy c ON c.CopyID=r.AssignedCopyID
        WHERE r.TransactionID IN ($marks) ORDER BY FIELD(r.ApprovalStatus,'Pending','Approved','Fulfilled','Rejected','Cancelled','Expired'),r.RequestDate,r.RequestID", $txIds);
    $pending = count(array_filter($items, fn($it) => $it['ApprovalStatus'] === 'Pending'));
    $ready = count(array_filter($items, fn($it) => $it['ApprovalStatus'] === 'Approved'));
?>
    <details class="card transaction-card borrower-group mb-4" data-member-id="<?= (int)$borrowerId ?>" aria-labelledby="borrowerRequests<?= (int)$borrowerId ?>" <?= ($pending || $ready) ? 'open' : '' ?>>
        <summary class="p-3">
            <span class="borrower-heading"><strong id="borrowerRequests<?= (int)$borrowerId ?>"><?= h($person['FirstName'] . ' ' . $person['LastName']) ?></strong><span class="small text-muted">Borrower <?= h($person['MembershipNumber'] ?: '#' . $borrowerId) ?></span></span>
            <span class="borrower-totals"><span><?= $pending ?> pending · <?= $ready ?> ready for pickup · <?= count($items) ?> listed</span></span>
            <span class="small text-decoration-underline">View requests<span class="visually-hidden"> for <?= h($person['FirstName'] . ' ' . $person['LastName']) ?></span></span>
        </summary>
        <form method="post" class="request-action-form px-3 pb-3">
            <?= csrf_field() ?><input type="hidden" name="borrower_id" value="<?= (int)$borrowerId ?>"><input type="hidden" name="list_query" value="<?= h(circulation_list_query()) ?>">
            <?php if ($pending || $ready): ?><div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <?php if ($pending): ?><button type="button" class="btn btn-outline-primary btn-sm" data-select-status="Pending">Select all pending (<?= $pending ?>)</button><?php endif; ?>
                    <?php if ($ready): ?><button type="button" class="btn btn-outline-success btn-sm" data-select-status="Approved">Select all approved (<?= $ready ?>)</button><?php endif; ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-select-status="">Clear selection</button>
                    <span class="small text-muted">Or select individual books below.</span>
                </div><?php endif; ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Select</th>
                            <th>Book / copy</th>
                            <th>Submission</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): $open = in_array($it['ApprovalStatus'], ['Pending', 'Approved'], true); ?>
                            <tr>
                                <td><?php if ($open): ?><input type="checkbox" class="form-check-input" name="request_ids[]" data-request-status="<?= h($it['ApprovalStatus']) ?>" value="<?= (int)$it['RequestID'] ?>" aria-label="Select <?= h($it['Books'] . ' — ' . request_transaction_ref((int)$it['TransactionID'])) ?>"><?php else: ?>—<?php endif; ?></td>
                                <td><strong><?= h($it['Books']) ?></strong>
                                    <div class="small text-muted"><?= h($it['AccessionNumber'] ?: 'Copy assigned on approval') ?></div>
                                </td>
                                <td class="small"><?= h(request_transaction_ref((int)$it['TransactionID'])) ?><div class="text-muted"><?= fmt_datetime($it['SubmittedAt']) ?></div>
                                </td>
                                <td class="text-nowrap"><?= request_type_badge($it['RequestType']) ?></td>
                                <td class="text-nowrap"><?= status_badge(request_display_status($it)) ?></td>
                                <td class="small"><?php if ($it['ReleasedLoanID']): ?>Transaction #<?= (int)$it['ReleasedLoanID'] ?><?php elseif ($it['ApprovalStatus'] === 'Approved'): ?>Held until <?= fmt_datetime($it['ExpiresAt']) ?><?php elseif ($it['RequestType'] === 'Reservation' && $it['ApprovalStatus'] === 'Pending'): ?>Queue #<?= reservation_queue_position((int)$it['RequestID']) ?><?php endif; ?><div><?= h($it['DecisionReason'] ?? '') ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pending || $ready): ?><div class="request-actions">
                    <strong class="selection-count" data-selected-count aria-live="polite">0 books selected</strong><span class="small text-muted action-hint" data-action-hint>Select pending books to approve them together.</span>
                    <label class="small flex-grow-1" data-request-reason><span data-reason-label>Reason (required for rejection)</span><input class="form-control" name="reason" maxlength="255"></label>
                    <?php if (has_permission('approve_requests')): ?><button class="btn btn-primary" name="action" value="approve">Approve selected</button><button class="btn btn-outline-danger" name="action" value="reject">Reject selected</button><?php endif; ?>
                    <?php if (has_permission('process_loans')): ?><button class="btn btn-success" name="action" value="release">Release selected together</button><?php endif; ?>
                    <?php if (has_permission('approve_requests')): ?><button class="btn btn-outline-secondary" name="action" value="cancel">Cancel selected</button><?php endif; ?>
                </div><?php endif; ?>
        </form>
    </details>
<?php endforeach; ?>