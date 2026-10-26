<?php
/** Small factual summary of the currently displayed groups, not global totals. */
function circulation_overview(array $metrics): void { ?>
<section class="circulation-overview" aria-label="Current results summary">
  <div class="overview-caption"><i class="bi bi-grid-1x2" aria-hidden="true"></i><span>Current results</span></div>
  <?php foreach($metrics as $label=>$value):?><div class="overview-metric"><strong><?= h($value) ?></strong><span><?= h($label) ?></span></div><?php endforeach;?>
</section>
<?php }

/** Retain list filters without accepting a redirect destination from the client. */
function circulation_list_query(?array $source=null): string {
    $source ??= $_GET;
    $query=[];
    foreach(['q','tab','status','from','to','page','member_id','view_loan'] as $key) {
        if(isset($source[$key]) && is_scalar($source[$key])) $query[$key]=mb_substr((string)$source[$key],0,100);
    }
    return http_build_query($query);
}
function circulation_post_query(): string {
    $source=[];
    parse_str(is_string($_POST['list_query']??null)?substr($_POST['list_query'],0,2000):'', $source);
    return circulation_list_query($source);
}
function circulation_due_summary(int $overdue,?string $due): void { ?>
  <?php if($overdue):?><span class="due-alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> <?= $overdue ?> overdue</span><?php endif;?>
  <?php if($due):?><span>Earliest due <?= fmt_date($due) ?></span><?php else:?><span>All books returned</span><?php endif;?>
<?php }
