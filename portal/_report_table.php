<div class="table-wrap">
 <table>
 <thead>
 <tr><th>#</th><th>Title</th><th>Category</th><th>Status</th><th>Location</th><th>Reported By</th><th>Date</th><th>Actions</th></tr>
 </thead>
 <tbody>
 <?php foreach($reports as $r):
 $escalated = !empty($r['escalated_to_lgu']) ? 1 : 0;
 $rep_brgy = trim((string)($r['barangay_name'] ?? ''));
 $rep_addr = trim((string)($r['resident_address'] ?? ''));
 $residence_full = $rep_brgy !== ''
  ? ('Brgy ' . $rep_brgy . ($rep_addr !== '' ? ', ' . $rep_addr : ''))
  : '';
 $responder_pending = $r['status'] === 'dangerous' && !empty($r['assigned_to']) && empty($r['resolved_at']);
 ?>
 <tr data-status="<?= htmlspecialchars($r['status']) ?>"<?= $responder_pending ? ' data-responder-pending="1"' : '' ?>>
 <td style="color:var(--muted);font-size:0.74rem;">#<?= $r['id'] ?></td>
 <td style="font-weight:600;max-width:200px;">
 <?= htmlspecialchars(mb_strimwidth($r['title'],0,55,'…')) ?>
 <?php if($escalated): ?>
 <span title="Escalated to LGU" style="margin-left:4px;display:inline-flex;align-items:center;gap:3px;font-size:0.65rem;font-weight:700;color:#92400e;background:#fef3c7;padding:1px 6px;border-radius:4px;border:1px solid #fde68a;vertical-align:middle;"><i class="fas fa-arrow-up-from-bracket"></i> LGU</span>
 <?php endif; ?>
 </td>
 <td><span class="cat-chip"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i> <?= ucfirst($r['category']) ?></span></td>
 <td><span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span><?php if($responder_pending): ?><span class="responder-pending-chip" title="A first responder has been dispatched and must resolve this incident" style="margin-left:5px;display:inline-flex;align-items:center;gap:3px;font-size:0.64rem;font-weight:700;color:#92400e;background:#fffbeb;padding:1px 6px;border-radius:4px;border:1px solid #fde68a;white-space:nowrap;"><i class="fas fa-hourglass-half"></i> Responder pending</span><?php endif; ?></td>
 <td style="font-size:0.78rem;"><?= htmlspecialchars($r['barangay'] ?? $r['city'] ?? '') ?></td>
 <td style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?><?php if($residence_full !== ''): ?><div style="font-size:0.7rem;color:#94a3b8;margin-top:2px;"><i class="fas fa-house-user" style="margin-right:3px;"></i><?= htmlspecialchars($residence_full) ?></div><?php endif; ?></td>
 <td style="font-size:0.74rem;color:var(--muted);white-space:nowrap;"><?= date('M j, Y g:i A',strtotime($r['created_at'])) ?></td>
 <td>
 <div style="display:flex;gap:5px;align-items:center;">
 <?php
 $r_title_js = htmlspecialchars(json_encode($r['title'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_cat_js = htmlspecialchars(json_encode($r['category'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_stat_js = htmlspecialchars(json_encode($r['status'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_loc_js = htmlspecialchars(json_encode($r['barangay'] ?? $r['city'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_user_js = htmlspecialchars(json_encode(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), ENT_QUOTES, 'UTF-8');
 $r_date_js = htmlspecialchars(json_encode(date('M j, Y g:i A', strtotime($r['created_at']))), ENT_QUOTES, 'UTF-8');
 $r_desc_js = htmlspecialchars(json_encode($r['description'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_res_js = htmlspecialchars(json_encode($residence_full), ENT_QUOTES, 'UTF-8');
 ?>
 <button class="btn-icon btn-view" title="View Details"
 onclick="viewReport(<?= $r['id'] ?>, <?= $r_title_js ?>, <?= $r_cat_js ?>, <?= $r_stat_js ?>, <?= $r_loc_js ?>, <?= $r_user_js ?>, <?= $r_date_js ?>, <?= $r_desc_js ?>, <?= $escalated ?>, <?= $r_res_js ?>, <?= $responder_pending ? 1 : 0 ?>)">
 <i class="fas fa-eye"></i>
 </button>
 <?php if($r['status'] !== 'safe'): ?>
 <?php if($r['status'] === 'dangerous'): ?>
 <button class="btn-icon btn-resolve-pending" type="button" disabled title="<?= $responder_pending ? 'A first responder has been dispatched and must resolve this incident' : 'Dangerous incidents must be resolved by the LGU or the assigned responder' ?>" style="background:#fffbeb;color:#92400e;opacity:.75;cursor:not-allowed;">
 <i class="fas fa-hourglass-half"></i>
 </button>
 <?php else: ?>
 <button class="btn-icon btn-resolve" title="Mark Resolved"
 onclick="quickResolve(<?= $r['id'] ?>,this)">
 <i class="fas fa-check"></i>
 </button>
 <?php endif; ?>
 <?php if(!$escalated): ?>
 <button class="btn-icon btn-escalate" title="Escalate to LGU"
 onclick="quickEscalate(<?= $r['id'] ?>,this)">
 <i class="fas fa-arrow-up-from-bracket"></i>
 </button>
 <?php else: ?>
 <button class="btn-icon" title="Already Escalated to LGU" disabled
 style="background:#fef3c7;color:#92400e;opacity:0.7;cursor:default;">
 <i class="fas fa-check"></i>
 </button>
 <?php endif; ?>
 <?php endif; ?>
 </div>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
</div>
