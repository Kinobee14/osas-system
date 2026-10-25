/* ===========================================================
   Vice Admin – single page app (hash router)
   =========================================================== */
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

/* ---------- helpers ---------- */
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmtDate = (d) => d ? new Date(d.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : '';
/** "14:30:00" → "2:30 PM". Parsed by hand: a bare time isn't a valid Date. */
const fmtTime = (t) => {
  if (!t) return '';
  const [h, m] = String(t).split(':').map(Number);
  if (Number.isNaN(h)) return t;
  const period = h >= 12 ? 'PM' : 'AM';
  return `${h % 12 === 0 ? 12 : h % 12}:${String(m || 0).padStart(2, '0')} ${period}`;
};
const badge = (s) => `<span class="badge badge--${esc(s)}">${esc(s)}</span>`;
const emptyRow = (cols, text = 'No records found.') => `<tr><td colspan="${cols}" class="empty">${text}</td></tr>`;

function toast(msg, isError = false) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'toast' + (isError ? ' toast--error' : '');
  t.hidden = false;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => (t.hidden = true), 2800);
}

/** size: undefined (default) | 'wide' | 'full' — controls the box width. */
function openModal(title, html, onMount, size) {
  $('#modalTitle').textContent = title;
  $('#modalBody').innerHTML = html;
  $('.modal__box').className = 'modal__box' + (size ? ` modal__box--${size}` : '');
  $('#modal').hidden = false;
  onMount?.($('#modalBody'));
}
const closeModal = () => ($('#modal').hidden = true);
$('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal' || e.target.dataset.close !== undefined) closeModal(); });
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  if (!$('#lightbox').hidden) return closeLightbox();
  if (!$('#modal').hidden) closeModal();
});

/* ---------- image lightbox (requirement pictures & event photos) ---------- */
function openLightbox(src, caption) {
  $('#lightboxImg').src = src;
  $('#lightboxCaption').textContent = caption || '';
  $('#lightbox').hidden = false;
}
function closeLightbox() {
  $('#lightbox').hidden = true;
  $('#lightboxImg').src = '';
}
$('#lightbox').addEventListener('click', (e) => {
  if (e.target.id !== 'lightboxImg') closeLightbox();
});

/* ---------- file helpers ---------- */
const isImageMime = (m) => String(m || '').startsWith('image/');
const fileGlyph = (mime) => {
  const m = String(mime || '');
  if (m.includes('pdf')) return '📕';
  if (m.includes('word') || m.includes('msword')) return '📘';
  if (m.includes('sheet') || m.includes('excel')) return '📗';
  if (m.startsWith('text/')) return '📄';
  return '📎';
};
const humanSize = (bytes) => {
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
  if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
  return bytes + ' B';
};

function confirmBox(message, okLabel = 'Confirm', danger = false) {
  return new Promise((resolve) => {
    openModal('Please confirm', `
      <p>${esc(message)}</p>
      <div class="form-actions">
        <button class="btn btn--ghost" data-close>Cancel</button>
        <button class="btn ${danger ? 'btn--danger-solid' : 'btn--primary'}" id="okBtn">${esc(okLabel)}</button>
      </div>`, (root) => {
      $('#okBtn', root).onclick = () => { closeModal(); resolve(true); };
      $$('[data-close]', root).forEach((b) => b.addEventListener('click', () => resolve(false)));
    });
  });
}

/** Asks the admin WHY they're declining. Resolves to the reason text, or null if cancelled. */
function declineReasonBox() {
  return new Promise((resolve) => {
    let done = false;
    const finish = (v) => { if (!done) { done = true; resolve(v); } };
    openModal('Decline reservation', `
      <p>Please tell the student why this reservation is being declined. They will see this reason.</p>
      <textarea id="declineReason" rows="4" maxlength="500" placeholder="Reason for declining (required)"></textarea>
      <p class="form-error" id="declineErr" style="display:none;color:#b3261e;margin:6px 0 0;">Please type a reason first.</p>
      <div class="form-actions">
        <button class="btn btn--ghost" data-close>Cancel</button>
        <button class="btn btn--danger-solid" id="declineOk">Decline</button>
      </div>`, (root) => {
      const box = $('#declineReason', root);
      box.focus();
      $('#declineOk', root).onclick = () => {
        const v = box.value.trim();
        if (!v) { $('#declineErr', root).style.display = 'block'; box.focus(); return; }
        finish(v);
        closeModal();
      };
      $$('[data-close]', root).forEach((b) => b.addEventListener('click', () => finish(null)));
    });
  });
}

/** Optional comment / instructions with an approval. Resolves '' (no comment), text, or null (cancelled). */
function approveCommentBox() {
  return new Promise((resolve) => {
    let done = false;
    const finish = (v) => { if (!done) { done = true; resolve(v); } };
    openModal('Approve reservation', `
      <p>Add an optional comment or additional instructions. The student can read it in their Message.</p>
      <textarea id="approveComment" rows="4" maxlength="500" placeholder="Comment / additional instructions (optional)"></textarea>
      <div class="form-actions">
        <button class="btn btn--ghost" data-close>Cancel</button>
        <button class="btn btn--primary" id="approveOk">Approve</button>
      </div>`, (root) => {
      const box = $('#approveComment', root);
      box.focus();
      $('#approveOk', root).onclick = () => { finish(box.value.trim()); closeModal(); };
      $$('[data-close]', root).forEach((b) => b.addEventListener('click', () => finish(null)));
    });
  });
}

/** Run an API action, show a toast, then refresh the view */
async function run(promise, refresh = true) {
  try {
    const res = await promise;
    toast(res.message || 'Done');
    if (refresh) { await render(); refreshCounts(); }
    return true;
  } catch (e) {
    toast(e.message, true);
    return false;
  }
}

/* ---------- pages ---------- */
const APPR_STATUS_LABEL = { pending: 'Pending', approved: 'Approved', rejected: 'Rejected', revision: 'For Revision' };
function apprDetail(r, n) {
  const st = r[`approver${n}_status`] || 'pending';
  const dt = r[`approver${n}_date`];
  const note = r[`approver${n}_notation`];
  if (st === 'pending' && !dt && !note) return '';
  const when = dt ? new Date(dt + 'T00:00:00').toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' }) : '';
  return `<small class="pf-ap">Status: <b>${esc(APPR_STATUS_LABEL[st] || st)}</b>${when ? ` · Date: ${esc(when)}` : ''}</small>`
       + (note ? `<small class="pf-ap">Notation: ${esc(note)}</small>` : '');
}
const pages = {
  /* ===== Dashboard ===== */
  dashboard: {
    title: () => `Good day, Admin ${session.display_name}!`,
    async render() {
      const { stats, recent } = await api.get('dashboard.php');
      const cards = [['Upcoming Events', stats.upcoming], ['Total Event', stats.total_events], ['Total Users', stats.total_users], ['Pending Reservations', stats.pending], ['Pending Accounts', stats.pending_accounts], ['Awaiting Review', stats.pending_review]];
      return `
        <div class="stats">
          ${cards.map(([l, v]) => `<div class="card stat"><div class="stat__label">${l}</div><div class="stat__value">${v}</div></div>`).join('')}
        </div>
        <h2 class="section-title">Events Reservations</h2>
        <div class="card"><div class="table-wrap"><table>
          <thead><tr><th>Ref No.</th><th>Event</th><th>Venue</th><th>Date</th><th>Requirements</th><th>Status</th></tr></thead>
          <tbody>${recent.length ? recent.map((r) => `
            <tr><td>${esc(r.ref_no)}</td><td>${esc(r.event_name)}</td><td>${esc(r.venue)}</td><td>${fmtDate(r.event_date)}</td>
              <td>${filesButton(r)}</td><td>${statusCell(r)}</td></tr>`).join('') : emptyRow(6)}
          </tbody></table></div></div>`;
    },
    mount(root) { bindRowActions(root); },
  },

  /* ===== Pending Account Approvals ===== */
  accounts: {
    title: 'Pending Account Approvals',
    filter: 'pending',
    async render() {
      const rows = await api.get(`accounts.php?status=${this.filter}`);
      return `
        <div class="card">
          <div class="card__head">${tabs(['pending', 'active', 'rejected', 'all'], this.filter)}</div>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>First Name</th><th>Last Name</th><th>Email Address</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>${rows.length ? rows.map((r) => `
              <tr>
                <td>${fmtDate(r.created_at)}</td><td>${esc(r.first_name)}</td><td>${esc(r.last_name)}</td>
                <td>${esc(r.email)}</td><td>${badge(r.status)}</td>
                <td><div class="actions">${r.status === 'pending' ? `
                  <button class="btn btn--sm btn--primary" data-act="approve" data-id="${r.id}">Approve</button>
                  <button class="btn btn--sm btn--danger" data-act="reject" data-id="${r.id}">Reject</button>` : '<span class="muted">—</span>'}
                </div></td>
              </tr>`).join('') : emptyRow(6, 'No account requests here.')}
            </tbody></table></div>
        </div>`;
    },
    mount(root) {
      bindTabs(root, this);
      root.addEventListener('click', async (e) => {
        const b = e.target.closest('[data-act]');
        if (!b) return;
        const act = b.dataset.act;
        if (act === 'reject' && !(await confirmBox('Reject this account request?', 'Reject', true))) return;
        run(api.post(`accounts.php?action=${act}`, { id: b.dataset.id }));
      });
    },
  },

  /* ===== Approval Queue (majority vote) ===== */
  approvals: {
    title: 'Approval Queue',
    filter: 'pending',
    async render() {
      const { rows, approvals_needed, declines_needed } = await api.get(`reservations.php${this.filter === 'all' ? '' : '?status=' + this.filter}`);
      this.rows = rows;
      this.approvalsNeeded = approvals_needed;
      return `
        <div class="card">
          <div class="card__head">
            ${tabs(['pending', 'approved', 'declined', 'completed', 'cancelled', 'all'], this.filter)}
            <span class="vote-rule"><strong>${approvals_needed}</strong> approvals approve · <strong>${declines_needed}</strong> declines decline · you can undo your vote</span>
          </div>
          <div class="table-wrap"><table class="resp-table queue-table">
            <thead><tr><th>Request</th><th>Nature of Activity</th><th>Venue &amp; Schedule</th><th>Requested By</th><th>Form</th><th>Status</th><th>Votes</th><th>Your Vote</th></tr></thead>
            <tbody>${rows.length ? rows.map((r) => `
              <tr>
                <td class="q-title" data-label="Request">
                  <strong class="q-name" title="${esc(r.event_name)}">${esc(r.event_name)}</strong>
                  <div class="sub muted">${esc(r.ref_no)}</div>
                  ${r.created_at ? `<div class="sub muted">Submitted ${esc(fmtDate(r.created_at))}</div>` : ''}
                </td>
                <td class="cell-wrap" data-label="Nature of Activity" data-nature="${esc(csv(r.nature_of_activity).join('|'))}">${esc(natureText(r))}</td>
                <td data-label="Venue &amp; Schedule">
                  <div>${esc(r.venue || r.venue_name)}</div>
                  <div>${dateCell(r)}</div>
                  <div class="sub muted">${fmtTime(r.start_time)} – ${fmtTime(r.end_time)}</div>
                </td>
                <td data-label="Requested By">
                  <div class="q-who">${esc(r.requested_by)}</div>
                  <div class="sub muted q-org" title="${esc(dash(r.sponsoring_org))}">${esc(dash(r.sponsoring_org))}</div>
                  <div class="sub muted">${esc(dash(r.participants))} participants</div>
                </td>
                <td data-label="Form">${formButton(r)}</td>
                <td data-label="Status">${statusCell(r)}</td>
                <td data-label="Votes">${voteTally(r, approvals_needed, declines_needed)}${messageButton(r)}</td>
                <td class="q-actions" data-label="Your Vote"><div class="actions">${voteButtons(r)}</div></td>
              </tr>`).join('') : emptyRow(8, 'The queue is empty.')}
            </tbody></table></div>
        </div>`;
    },
    mount(root) {
      bindTabs(root, this);
      bindRowActions(root);
      root.addEventListener('click', (e) => {
        const m = e.target.closest('[data-msg]');
        if (m) openVoteMessages(this.rows.find((r) => String(r.id) === m.dataset.msg), this.approvalsNeeded);
      });
      root.addEventListener('click', async (e) => {
        const b = e.target.closest('[data-act]');
        if (!b || b.disabled) return;
        const act = b.dataset.act;
        let reason;
        if (act === 'decline') {
          reason = await declineReasonBox();
          if (!reason) return;
        } else if (act === 'approve') {
          reason = await approveCommentBox();
          if (reason === null) return; // cancelled – no vote is cast
        } else if (act === 'undo') {
          if (!(await confirmBox('Undo your vote on this reservation? If it was already approved or declined, it goes back to pending when the remaining votes no longer decide it.', 'Undo my vote'))) return;
        }
        run(api.post(`reservations.php?action=${act}`, { id: b.dataset.id, reason }));
      });
    },
  },

  /* ===== Reschedule Requests (student asks → admin decides) ===== */
  reschedules: {
    title: 'Reschedule Requests',
    filter: 'pending',
    async render() {
      const rows = await api.get(`reschedules.php${this.filter === 'all' ? '' : '?status=' + this.filter}`);
      const win = (d, s, e) => `${fmtDate(d)}<div class="sub muted">${fmtTime(s)} – ${fmtTime(e)}</div>`;
      return `
        <div class="card">
          <div class="card__head">
            ${tabs(['pending', 'suggested', 'approved', 'rejected', 'withdrawn', 'all'], this.filter)}
            <span class="vote-rule">You can <strong>Approve</strong>, <strong>Reject</strong> or <strong>Suggest</strong> another time. Nothing moves until you decide.</span>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th>Ref No.</th><th>Event</th><th>Requested By</th><th>Venue</th><th>Current / Original</th><th>Requested New Schedule</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>${rows.length ? rows.map((q) => {
              const open = q.status === 'pending' || q.status === 'suggested';
              const clash = open && q.conflicts && q.conflicts.length;
              return `
              <tr>
                <td>${esc(q.ref_no)}</td>
                <td><strong>${esc(q.event_name)}</strong></td>
                <td>${esc(q.requested_by)}</td>
                <td>${esc(q.venue_name)}</td>
                <td>${win(q.status === 'approved' ? q.orig_date : q.cur_date, q.status === 'approved' ? q.orig_start : q.cur_start, q.status === 'approved' ? q.orig_end : q.cur_end)}</td>
                <td>${win(q.new_date, q.new_start, q.new_end)}
                  ${clash ? `<div class="rs-flag rs-flag--bad" title="${esc(q.conflict_text)}">⚠ Slot taken: ${esc(q.conflict_text)}</div>`
                          : open ? '<div class="rs-flag rs-flag--ok">✓ Slot is free</div>' : ''}
                  ${q.suggested_date ? `<div class="rs-flag rs-flag--sug">Suggested: ${fmtDate(q.suggested_date)}, ${fmtTime(q.suggested_start)} – ${fmtTime(q.suggested_end)}</div>` : ''}</td>
                <td class="cell-wrap">${esc(q.reason)}${q.admin_note ? `<div class="sub muted"><strong>${esc(q.admin_label || 'Admin')}:</strong> ${esc(q.admin_note)}</div>` : ''}</td>
                <td>${badge(q.status === 'suggested' ? 'pending' : q.status).replace(/>[^<]*</, `>${esc(q.status === 'suggested' ? 'awaiting student' : q.status)}<`)}</td>
                <td><div class="actions">${open ? `
                  <button class="btn btn--sm btn--primary" data-rs="approve" data-id="${q.id}" ${clash ? 'disabled title="The requested time is taken. Suggest another time or reject."' : ''}>Approve</button>
                  <button class="btn btn--sm btn--outline" data-rs="suggest" data-id="${q.id}">Suggest</button>
                  <button class="btn btn--sm btn--danger" data-rs="reject" data-id="${q.id}">Reject</button>` : '<span class="muted">—</span>'}</div></td>
              </tr>`; }).join('') : emptyRow(9, 'No reschedule requests here.')}
            </tbody></table></div>
        </div>`;
    },
    mount(root) {
      bindTabs(root, this);
      root.addEventListener('click', async (e) => {
        const b = e.target.closest('[data-rs]');
        if (!b || b.disabled) return;
        const id = b.dataset.id;
        if (b.dataset.rs === 'approve') {
          if (!(await confirmBox('Approve this reschedule? The reservation moves to the new time and the original schedule is kept in its history.', 'Approve'))) return;
          run(api.post('reschedules.php?action=approve', { id }));
        } else if (b.dataset.rs === 'reject') {
          const note = await reasonBox('Reject reschedule request', 'Tell the student why this request is being rejected. They will see this.', 'Reject');
          if (!note) return;
          run(api.post('reschedules.php?action=reject', { id, note }));
        } else {
          openSuggestBox(id);
        }
      });
    },
  },

  /* ===== Schedules (calendar) ===== */
  schedules: {
    title: 'Schedules',
    month: null,
    venueId: '',
    async render() {
      if (!this.month) { const n = new Date(); this.month = new Date(n.getFullYear(), n.getMonth(), 1); }
      const y = this.month.getFullYear(), m = this.month.getMonth();
      const key = `${y}-${String(m + 1).padStart(2, '0')}`;
      const venueList = await api.get('venues.php');
      const events = (await api.get(`reservations.php?month=${key}`)).rows
        .filter((r) => r.status !== 'declined' && r.status !== 'cancelled')
        .filter((r) => !this.venueId || String(r.venue_id) === String(this.venueId));

      const byDay = {};
      events.forEach((ev) => { const d = +ev.event_date.slice(8, 10); (byDay[d] ||= []).push(ev); });

      const first = new Date(y, m, 1).getDay();
      const days = new Date(y, m + 1, 0).getDate();
      const today = new Date();
      let cells = '';
      for (let i = 0; i < first; i++) cells += '<div class="cal__day cal__day--blank"></div>';
      for (let d = 1; d <= days; d++) {
        const isToday = today.getFullYear() === y && today.getMonth() === m && today.getDate() === d;
        cells += `<div class="cal__day ${isToday ? 'cal__day--today' : ''}">
          <span class="cal__num">${d}</span>
          ${(byDay[d] || []).map((ev) => `<div class="cal__event cal__event--${ev.status}" title="${esc(ev.event_name)} · ${esc(ev.venue)} · ${fmtTime(ev.start_time)}–${fmtTime(ev.end_time)}${+ev.reschedule_count ? ' · rescheduled' : ''}">${+ev.reschedule_count ? '↻ ' : ''}${esc(ev.event_name)}</div>`).join('')}
        </div>`;
      }
      const label = this.month.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
      return `
        <div class="card cal">
          <div class="cal__head">
            <h2>Calendar – ${label}</h2>
            <div class="cal__nav">
              <select id="calVenue" class="cal__venue" title="Show one venue only">
                <option value="">All venues</option>
                ${venueList.map((v) => `<option value="${v.id}" ${String(v.id) === String(this.venueId) ? 'selected' : ''}>${esc(v.name)}</option>`).join('')}
              </select>
              <button class="btn btn--outline btn--sm" data-nav="-1">‹</button>
              <button class="btn btn--outline btn--sm" data-nav="0">TODAY</button>
              <button class="btn btn--outline btn--sm" data-nav="1">›</button>
            </div>
          </div>
          <div class="cal__grid">
            ${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((d) => `<div class="cal__dow">${d}</div>`).join('')}
            ${cells}
          </div>
          <div class="legend">
            <span style="--c:#8fd3a8">Approved (Booked)</span><span style="--c:#e3cf9f">Pending</span><span style="--c:#cfcfcb">Completed</span><span>↻ Rescheduled</span><span class="muted">A day with no events is Available.</span>
          </div>
        </div>`;
    },
    mount(root) {
      $('#calVenue', root)?.addEventListener('change', (e) => { this.venueId = e.target.value; render(); });
      root.addEventListener('click', (e) => {
        const b = e.target.closest('[data-nav]');
        if (!b) return;
        const n = +b.dataset.nav;
        const now = new Date();
        this.month = n === 0 ? new Date(now.getFullYear(), now.getMonth(), 1)
          : new Date(this.month.getFullYear(), this.month.getMonth() + n, 1);
        render();
      });
    },
  },

  /* ===== All Reservation ===== */
  reservations: {
    title: 'All Reservation',
    async render() {
      const { rows } = await api.get('reservations.php');
      const needsReview = rows.filter((r) => r.report_review_status === 'pending').length;
      return `
        ${needsReview ? `<div class="notice">
          <span class="notice__dot">!</span>
          <span><strong>${needsReview} post-event report${needsReview === 1 ? '' : 's'}</strong> submitted by students are waiting on your review. Look for the amber <span class="pe-inline">!</span> beside the Done status.</span>
        </div>` : ''}
        <div class="card"><div class="table-wrap"><table class="resp-table all-table">
          <thead><tr><th>Request</th><th>Nature of Activity</th><th>Venue &amp; Schedule</th><th>Requested By</th><th>Form</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>${rows.length ? rows.map((r) => `
            <tr>
              <td class="q-title" data-label="Request">
                <strong class="q-name" title="${esc(r.event_name)}">${esc(r.event_name)}</strong>
                <div class="sub muted">${esc(r.ref_no)}</div>
                ${r.created_at ? `<div class="sub muted">Submitted ${esc(fmtDate(r.created_at))}</div>` : ''}
              </td>
              <td class="cell-wrap" data-label="Nature of Activity" data-nature="${esc(csv(r.nature_of_activity).join('|'))}">${esc(natureText(r))}</td>
              <td data-label="Venue &amp; Schedule">
                <div>${esc(r.venue || r.venue_name)}</div>
                <div>${dateCell(r)}</div>
                <div class="sub muted">${fmtTime(r.start_time)} – ${fmtTime(r.end_time)}</div>
              </td>
              <td data-label="Requested By">
                <div class="q-who">${esc(r.requested_by)}</div>
                <div class="sub muted q-org" title="${esc(dash(r.sponsoring_org))}">${esc(dash(r.sponsoring_org))}</div>
                <div class="sub muted">${esc(dash(r.participants))} participants</div>
              </td>
              <td data-label="Form">${formButton(r)}</td>
              <td data-label="Status">${statusCell(r)}</td>
              <td class="q-actions" data-label="Action"><div class="actions">${reservationAction(r)}</div></td>
            </tr>`).join('') : emptyRow(7)}
          </tbody></table></div></div>`;
    },
    mount(root) {
      bindRowActions(root);
      root.addEventListener('click', (e) => {
        const b = e.target.closest('[data-act]');
        if (b) run(api.post(`reservations.php?action=${b.dataset.act}`, { id: b.dataset.id }));
      });
    },
  },

  /* ===== Venues (view + edit) ===== */
  venues: {
    title: () => (pages.venues.editing ? 'Edit Venues' : 'Venues'),
    editing: false,
    statuses: ['available', 'booked', 'reserved', 'maintenance'],
    async render() {
      const rows = await api.get('venues.php');
      if (!this.editing) {
        return `
          <div class="card"><div class="table-wrap"><table>
            <thead><tr><th>Venue</th><th>Campus</th><th>Capacity</th><th>Equipment</th><th>Status</th></tr></thead>
            <tbody>${rows.length ? rows.map((v) => `
              <tr><td>${esc(v.name)}</td><td>${esc(v.campus) || '<span class="muted">—</span>'}</td><td>${v.capacity}</td><td>${esc(v.equipment)}</td><td>${badge(v.status)}</td></tr>`).join('') : emptyRow(5)}
            </tbody></table></div></div>
          <div class="form-actions" style="margin-top:16px"><button class="btn btn--primary" id="editVenues">Edit Venues</button></div>`;
      }
      const opts = (sel) => this.statuses.map((s) => `<option value="${s}" ${s === sel ? 'selected' : ''}>${s[0].toUpperCase() + s.slice(1)}</option>`).join('');
      return `
        <div class="card">
          <div class="table-wrap"><table class="edit-table">
            <thead><tr><th>Venue</th><th>Campus</th><th style="width:110px">Capacity</th><th>Equipment</th><th style="width:150px">Status</th><th style="width:50px"></th></tr></thead>
            <tbody id="venueRows">${rows.map((v) => `
              <tr data-id="${v.id}">
                <td><input name="name" value="${esc(v.name)}"></td>
                <td><input name="campus" value="${esc(v.campus)}"></td>
                <td><input name="capacity" type="number" min="0" value="${v.capacity}"></td>
                <td><input name="equipment" value="${esc(v.equipment)}"></td>
                <td><select name="status">${opts(v.status)}</select></td>
                <td><button class="icon-btn" data-del="${v.id}" title="Delete">🗑</button></td>
              </tr>`).join('')}
            </tbody></table></div>
          <form class="add-row" id="addVenue">
            <label>Venue Name<input name="name" placeholder="e.g. Function Room" required></label>
            <label>Campus<input name="campus" placeholder="e.g. Main Campus"></label>
            <label>Capacity<input name="capacity" type="number" min="0" placeholder="0"></label>
            <label>Equipment<input name="equipment" placeholder="Add"></label>
            <label>Status<select name="status">${opts('available')}</select></label>
            <button class="btn btn--primary btn--sm" type="submit" style="height:34px">Add</button>
          </form>
          <div class="form-actions" style="padding:14px 30px 22px">
            <button class="btn btn--outline" id="cancelEdit">Cancel</button>
            <button class="btn btn--primary" id="saveVenues">Save Changes</button>
          </div>
        </div>`;
    },
    mount(root) {
      root.addEventListener('click', async (e) => {
        const t = e.target;
        if (t.id === 'editVenues') { this.editing = true; render(); }
        if (t.id === 'cancelEdit') { this.editing = false; render(); }
        if (t.id === 'saveVenues') {
          const list = $$('#venueRows tr', root).map((tr) => ({
            id: +tr.dataset.id,
            name: $('[name=name]', tr).value,
            campus: $('[name=campus]', tr).value,
            capacity: $('[name=capacity]', tr).value,
            equipment: $('[name=equipment]', tr).value,
            status: $('[name=status]', tr).value,
          }));
          if (await run(api.put('venues.php', list), false)) { this.editing = false; render(); }
        }
        const del = t.closest('[data-del]');
        if (del && (await confirmBox('Delete this venue? Reservations will keep their record without a venue.', 'Delete', true))) {
          run(api.del(`venues.php?id=${del.dataset.del}`));
        }
      });
      root.addEventListener('submit', (e) => {
        if (e.target.id !== 'addVenue') return;
        e.preventDefault();
        run(api.post('venues.php', Object.fromEntries(new FormData(e.target))));
      });
    },
  },

  /* ===== Inventory (borrowable resources: chairs, tables, fans, etc.) ===== */
  inventory: {
    title: 'Inventory',
    async render() {
      const rows = await api.get('resources.php');
      this.cache = rows;
      return `
        <div class="card">
          <div class="card__head">
            <p class="muted" style="margin:0">Resources listed here are what students can choose to borrow when making a reservation.</p>
            <button class="btn btn--primary btn--sm" id="addResource">+ Add Resource</button>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th>Resource Name</th><th>Stock</th><th>Action</th></tr></thead>
            <tbody>${rows.length ? rows.map((r) => `
              <tr>
                <td>${esc(r.name)}</td><td>${r.stock}</td>
                <td><div class="actions">
                  <button class="btn btn--sm btn--outline" data-edit="${r.id}">Edit</button>
                  <button class="btn btn--sm btn--danger" data-del="${r.id}">Delete</button>
                </div></td>
              </tr>`).join('') : emptyRow(3, 'No resources added yet.')}
            </tbody></table></div>
        </div>`;
    },
    form(r = {}) {
      return `
        <form class="form" id="resourceForm">
          <label>Resource Name<input name="name" value="${esc(r.name)}" placeholder="e.g. Chairs" required></label>
          <label>Stock (how many are available)<input name="stock" type="number" min="0" value="${r.stock ?? 0}" required></label>
          <div class="form-actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button><button class="btn btn--primary">Save</button></div>
        </form>`;
    },
    openForm(r) {
      openModal(r ? 'Edit Resource' : 'Add Resource', this.form(r), (root) => {
        $('#resourceForm', root).onsubmit = async (e) => {
          e.preventDefault();
          const data = Object.fromEntries(new FormData(e.target));
          const req = r ? api.put('resources.php', { ...data, id: r.id }) : api.post('resources.php', data);
          if (await run(req)) closeModal();
        };
      });
    },
    mount(root) {
      root.addEventListener('click', async (e) => {
        if (e.target.id === 'addResource') return this.openForm();
        const ed = e.target.closest('[data-edit]');
        if (ed) return this.openForm(this.cache.find((r) => r.id == ed.dataset.edit));
        const del = e.target.closest('[data-del]');
        if (del && (await confirmBox('Delete this resource? Students will no longer be able to borrow it.', 'Delete', true))) {
          run(api.del(`resources.php?id=${del.dataset.del}`));
        }
      });
    },
  },

  /* ===== Users ===== */
  users: {
    title: 'Users',
    q: '',
    roles: ['Student', 'Admin', 'Resource Keeper', 'Faculty'],
    async render() {
      const rows = await api.get(`users.php?q=${encodeURIComponent(this.q)}`);
      this.cache = rows;
      return `
        <div class="card">
          <div class="card__head">
            <input class="search" id="userSearch" placeholder="Search by name or organization" value="${esc(this.q)}">
            <button class="btn btn--primary btn--sm" id="addUser">+ Add User</button>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th>Name</th><th>Role</th><th>Organization/Office</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>${rows.length ? rows.map((u) => `
              <tr>
                <td>${esc(u.first_name)} ${esc(u.last_name)}</td><td>${esc(u.role)}</td><td>${esc(u.organization) || '<span class="muted">—</span>'}</td><td>${badge(u.status)}</td>
                <td><div class="actions">
                  <button class="btn btn--sm btn--outline" data-edit="${u.id}">Edit</button>
                  <button class="btn btn--sm ${u.status === 'active' ? 'btn--danger' : 'btn--outline'}" data-toggle="${u.id}">${u.status === 'active' ? 'Deactivate' : 'Activate'}</button>
                </div></td>
              </tr>`).join('') : emptyRow(5, 'No users match your search.')}
            </tbody></table></div>
        </div>`;
    },
    form(u = {}) {
      return `
        <form class="form" id="userForm">
          <label>First name<input name="firstName" value="${esc(u.first_name)}" required></label>
          <label>Last name<input name="lastName" value="${esc(u.last_name)}" required></label>
          <label>Email<input name="email" type="email" value="${esc(u.email)}"></label>
          <label>Role<select name="role">${this.roles.map((r) => `<option ${r === u.role ? 'selected' : ''}>${r}</option>`).join('')}</select></label>
          <label>Organization / Office<input name="organization" value="${esc(u.organization)}"></label>
          <div class="form-actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button><button class="btn btn--primary">Save</button></div>
        </form>`;
    },
    openForm(user) {
      openModal(user ? 'Edit User' : 'Add User', this.form(user), (root) => {
        $('#userForm', root).onsubmit = async (e) => {
          e.preventDefault();
          const data = Object.fromEntries(new FormData(e.target));
          const req = user ? api.put('users.php', { ...data, id: user.id }) : api.post('users.php', data);
          if (await run(req)) closeModal();
        };
      });
    },
    mount(root) {
      let timer;
      $('#userSearch', root).addEventListener('input', (e) => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
          this.q = e.target.value;
          await render();
          const s = $('#userSearch');
          s.focus();
          s.setSelectionRange(s.value.length, s.value.length);
        }, 300);
      });
      root.addEventListener('click', async (e) => {
        if (e.target.id === 'addUser') return this.openForm();
        const ed = e.target.closest('[data-edit]');
        if (ed) return this.openForm(this.cache.find((u) => u.id == ed.dataset.edit));
        const tg = e.target.closest('[data-toggle]');
        if (tg) run(api.post('users.php?action=toggle', { id: tg.dataset.toggle }));
      });
    },
  },

  /* ===== Reports — the post-event report register ===== */
  reports: {
    title: 'Post-Event Reports',
    days: 90,
    venue: 0,
    detail: false,
    async render() {
      const [venues, rep] = await Promise.all([
        api.get('venues.php'),
        api.get(`reports.php?days=${this.days}&venue_id=${this.venue}`),
      ]);
      const s = rep.summary;
      const max = Math.max(1, ...rep.by_venue.map((b) => +b.value));
      const ranges = [[30, 'Last 30 days'], [90, 'Last 90 days'], [365, 'Last 12 months'], [0, 'All time']];
      const condition = {
        all_returned: ['Returned', 'approved'],
        partially_returned: ['Partial', 'pending'],
        damaged: ['Damaged', 'declined'],
        not_applicable: ['N/A', 'completed'],
      };
      const venueCond = { good: ['Good', 'approved'], minor_damage: ['Minor', 'pending'], major_damage: ['Major', 'declined'] };
      const reviewTone = { pending: 'pending', approved: 'approved', needs_revision: 'declined' };
      const reviewLabel = { pending: 'Awaiting Review', approved: 'Approved', needs_revision: 'Needs Revision' };
      const pill = (map, key) => {
        const [label, tone] = map[key] || [key, 'completed'];
        return `<span class="badge badge--${tone}">${esc(label)}</span>`;
      };

      return `
        <div class="toolbar">
          <select id="fDays">${ranges.map(([v, l]) => `<option value="${v}" ${v == this.days ? 'selected' : ''}>${l}</option>`).join('')}</select>
          <select id="fVenue"><option value="0">All Venues</option>${venues.map((v) => `<option value="${v.id}" ${v.id == this.venue ? 'selected' : ''}>${esc(v.name)}</option>`).join('')}</select>
          <label class="check"><input type="checkbox" id="fDetail" ${this.detail ? 'checked' : ''}> Include full reports &amp; photos in the PDF</label>
          <button class="btn btn--primary btn--sm" id="exportPdf">⤓ Export PDF</button>
        </div>

        <div class="report-stats">
          ${[
            ['Approved', s.approved],
            ['Awaiting Review', s.pending_review],
            ['Needs Revision', s.needs_revision],
            ['Total Attendance', Number(s.attendees).toLocaleString()],
          ].map(([l, v]) => `<div class="card stat"><div class="stat__label">${l}</div><div class="stat__value">${v}</div></div>`).join('')}
        </div>

        <div class="card rate-card">
          <div class="rate-card__head">
            <div>
              <h3>Reporting completion</h3>
              <p class="muted">${s.approved} of ${s.finished_events} finished event${s.finished_events === 1 ? '' : 's'} have an approved report${s.venue_issues ? ` · ${s.venue_issues} flagged a venue issue` : ''}</p>
            </div>
            <div class="rate-card__pct">${s.completion_rate}%</div>
          </div>
          <div class="bar__track"><div class="bar__fill" style="width:${s.completion_rate}%"></div></div>
        </div>

        ${rep.awaiting_submission.length ? `
        <h2 class="section-title">Awaiting Student Submission <span class="count-pill">${rep.awaiting_submission.length}</span></h2>
        <div class="card"><div class="table-wrap"><table class="resp-table wait-table">
          <thead><tr><th>Ref No.</th><th>Event</th><th>Venue</th><th>Date Held</th><th>Waiting</th></tr></thead>
          <tbody>${rep.awaiting_submission.map((p) => `
            <tr>
              <td data-label="Ref No.">${esc(p.ref_no)}</td><td class="q-title" data-label="Event"><strong class="q-name" title="${esc(p.event_name)}">${esc(p.event_name)}</strong></td><td data-label="Venue">${esc(p.venue)}</td>
              <td data-label="Date Held">${fmtDate(p.event_date)}</td>
              <td data-label="Waiting"><span class="badge badge--${p.days_since > 7 ? 'declined' : 'pending'}">${p.days_since} day${p.days_since == 1 ? '' : 's'}</span></td>
            </tr>`).join('')}
          </tbody></table></div></div>
        <p class="muted" style="margin:-10px 0 20px;font-size:12px;">Nothing to do here yet — these are waiting on the student to submit, not on you.</p>` : ''}

        <h2 class="section-title">Reports by Venue</h2>
        <div class="card bars">${rep.by_venue.length ? rep.by_venue.map((b) => `
          <div class="bar"><span>${esc(b.label)}</span><div class="bar__track"><div class="bar__fill" style="width:${(b.value / max) * 100}%"></div></div><span class="bar__val">${b.value}</span></div>`).join('')
          : '<p class="empty">No reports in this period.</p>'}
        </div>

        <h2 class="section-title">Report Register</h2>
        <div class="card"><div class="table-wrap"><table class="resp-table rep-table">
          <thead><tr><th>Report</th><th>Venue &amp; Date Held</th><th>Attendance</th><th>Condition</th><th>Submitted By</th><th>Review</th><th>Action</th></tr></thead>
          <tbody>${rep.rows.length ? rep.rows.map((r) => `
            <tr>
              <td class="q-title" data-label="Report">
                <strong class="q-name" title="${esc(r.event_name)}">${esc(r.event_name)}</strong>
                <div class="sub muted">${esc(r.report_no)}</div>
                <div class="sub muted">${esc(r.ref_no)} · ${esc(r.requested_by)}</div>
              </td>
              <td data-label="Venue &amp; Date Held">
                <div>${esc(r.venue)}</div>
                <div>${fmtDate(r.event_date)}</div>
              </td>
              <td data-label="Attendance">
                <div>${Number(r.attendees_count).toLocaleString()}</div>
                <div class="sub muted">${r.photo_count ? `${r.photo_count} 📷` : 'No photos'}</div>
              </td>
              <td data-label="Condition">
                <div class="sub muted">Resources</div>${pill(condition, r.resources_condition)}
                <div class="sub muted">Venue</div>${pill(venueCond, r.venue_condition)}
              </td>
              <td data-label="Submitted By">${esc(r.prepared_by)}</td>
              <td data-label="Review"><span class="badge badge--${reviewTone[r.review_status] || 'completed'}">${esc(reviewLabel[r.review_status] || r.review_status)}</span></td>
              <td class="q-actions" data-label="Action"><div class="actions">
                <button class="btn btn--sm ${r.review_status === 'pending' ? 'btn--primary' : 'btn--outline'}" data-report="${r.id}">${r.review_status === 'pending' ? 'Review' : 'View'}</button>
                <a class="btn btn--sm btn--ghost" href="api/report_pdf.php?id=${r.id}" title="Download this report as PDF">PDF</a>
                ${r.review_status === 'approved' && session.can_clearance ? `<a class="btn btn--sm btn--ghost" href="api/clearance_pdf.php?report_id=${r.id}" title="Download the activity clearance">Clearance</a>` : ''}
              </div></td>
            </tr>`).join('') : emptyRow(7, 'No post-event reports have been submitted for this period yet.')}
          </tbody></table></div></div>`;
    },
    mount(root) {
      bindRowActions(root);
      $('#fDays', root).onchange = (e) => { this.days = +e.target.value; render(); };
      $('#fVenue', root).onchange = (e) => { this.venue = +e.target.value; render(); };
      $('#fDetail', root).onchange = (e) => { this.detail = e.target.checked; };
      // A plain navigation, not fetch: the session cookie goes along and the
      // browser handles the download with the filename the server sets.
      $('#exportPdf', root).onclick = () => {
        const url = `api/report_pdf.php?days=${this.days}&venue_id=${this.venue}${this.detail ? '&detail=1' : ''}`;
        toast('Building your PDF…');
        window.location.href = url;
      };
    },
  },

  /* ===== Settings ===== */
  settings: {
    title: 'Settings',
    async render() {
      const sg = await api.get('settings.php?action=signatories');
      return `
        <div class="settings-grid">
          <div class="card">
            <h3>Reservation Form Signatories</h3>
            <p class="muted" style="margin:-6px 0 12px">Shown on the student's reservation form and on the printed form. Students cannot edit these.</p>
            <form class="form" id="sigForm">
              <b>Approved by (1)</b>
              <label>Name<input name="approver1_name" value="${esc(sg.approver1_name)}" maxlength="150" required></label>
              <label>Position<input name="approver1_title" value="${esc(sg.approver1_title)}" maxlength="150" required></label>
              <b>Approved by (2)</b>
              <label>Name<input name="approver2_name" value="${esc(sg.approver2_name)}" maxlength="150" required></label>
              <label>Position<input name="approver2_title" value="${esc(sg.approver2_title)}" maxlength="150" required></label>
              <b>Noted by</b>
              <label>Name<input name="noted_name" value="${esc(sg.noted_name)}" maxlength="150" required></label>
              <label>Position<input name="noted_title" value="${esc(sg.noted_title)}" maxlength="150" required></label>
              <div class="form-actions"><button class="btn btn--primary">Save Signatories</button></div>
            </form>
          </div>
          <div class="card">
            <h3>Profile</h3>
            <form class="form" id="profileForm">
              <label>Display name<input name="display_name" value="${esc(session.display_name)}" required></label>
              <label>Office (e.g. Admin, Academic Affairs, OSAS)<input name="office" value="${esc(session.office)}" required></label>
              <div class="form-actions"><button class="btn btn--primary">Save Profile</button></div>
            </form>
          </div>
          <div class="card">
            <h3>Change Password</h3>
            <form class="form" id="pwForm">
              <label>Current password<input name="current_password" type="password" required></label>
              <label>New password<input name="new_password" type="password" minlength="6" required></label>
              <div class="form-actions"><button class="btn btn--primary">Update Password</button></div>
            </form>
          </div>
        </div>`;
    },
    mount(root) {
      $('#sigForm', root).onsubmit = async (e) => {
        e.preventDefault();
        try {
          const res = await api.put('settings.php?action=signatories', Object.fromEntries(new FormData(e.target)));
          toast(res.message);
        } catch (ex) { toast(ex.message, true); }
      };
      $('#profileForm', root).onsubmit = async (e) => {
        e.preventDefault();
        try {
          const res = await api.put('settings.php?action=profile', Object.fromEntries(new FormData(e.target)));
          Object.assign(session, res.data);
          paintSession();
          toast(res.message);
        } catch (ex) { toast(ex.message, true); }
      };
      $('#pwForm', root).onsubmit = async (e) => {
        e.preventDefault();
        if (await run(api.put('settings.php?action=password', Object.fromEntries(new FormData(e.target))), false)) e.target.reset();
      };
    },
  },
};


/* ===========================================================
   Permission to Conduct Activity / Program — the student's form,
   shown to the admin exactly as it is on paper (read-only).
   =========================================================== */
const NATURE_LIST = ['Seminar / Convention', 'Educational Tour', 'Immersion', 'Community Extension', 'Academic Requirements'];
const ATTACH_LIST = ['List of Students', 'Project Proposal', 'Budget Proposal', "Instructor's Acceptance of Responsibility", 'Proposed Program / Invitation', 'Parent Consent'];
const csv = (v) => String(v || '').split(',').map((x) => x.trim()).filter(Boolean);

/** "Seminar / Convention, Others: Orientation" for table cells. */
function natureText(r) {
  const list = csv(r.nature_of_activity).map((n) => (n === 'Others' ? `Others${r.nature_other ? ': ' + r.nature_other : ''}` : n));
  return list.length ? list.join(', ') : '—';
}
const dash = (v) => (v === null || v === undefined || v === '' ? '—' : v);

/** Small button that opens the full form for a reservation. */
function formButton(r) {
  const n = r.file_count ? ` · ${r.file_count} 📎` : '';
  return `<button class="btn btn--sm btn--outline" data-files="${r.id}" title="View the full Permission to Conduct Activity form">📄 View Form${n}</button>`;
}

function activityFormHtml(r, sg = {}) {
  const natures = csv(r.nature_of_activity);
  const attached = csv(r.attachments_checklist);
  const box = (on) => `<span class="pf-box ${on ? 'pf-box--on' : ''}">${on ? '✓' : ''}</span>`;
  const legacy = !r.nature_of_activity && !r.participants && !r.sponsoring_org; // reservation made before the new form
  return `
    <div class="pf">
      <div class="pf-head">
        <img src="../public/pictures/UCC.png" alt="" onerror="this.style.display='none'">
        <div>
          <div class="pf-uni">UNIVERSITY OF CALOOCAN CITY</div>
          <div class="pf-addr">Biglang-Awa St., 12<sup>th</sup> Avenue East, Caloocan City<br>Telefax 310-6855</div>
          <div class="pf-office">OFFICE OF THE STUDENT AFFAIRS AND SERVICES</div>
        </div>
      </div>
      <div class="pf-row pf-title">
        <span>PERMISSION TO CONDUCT ACTIVITY / PROGRAM</span>
        <span>ON CAMPUS ${box(r.activity_scope === 'on')} AND OFF-CAMPUS ${box(r.activity_scope === 'off')}</span>
      </div>
      <div class="pf-row">
        <span><b>CAMPUS:</b> <u>${esc(dash(r.campus))}</u></span>
        <span><b>Date Prepared:</b> <u>${esc(r.date_prepared ? fmtDate(r.date_prepared) : fmtDate(r.created_at))}</u></span>
      </div>
      <div class="pf-pad"><b>Nature of Activity:</b> (Please Check)</div>
      <div class="pf-nature">
        <div>${NATURE_LIST.map((n) => `<div class="pf-opt">${box(natures.includes(n))} ${esc(n)}</div>`).join('')}</div>
        <div>
          <div class="pf-opt">${box(natures.includes('Others'))} Others: (Please specify)</div>
          <div class="pf-fill">${esc(natures.includes('Others') ? r.nature_other || '' : '')}</div>
        </div>
      </div>
      <div class="pf-grid">
        <div class="pf-cell pf-full"><b>Title of Activity:</b><div class="pf-val">${esc(r.event_name)}</div></div>
        <div class="pf-cell"><b>Venue:</b><div class="pf-val">${esc(r.venue_name)}</div></div>
        <div class="pf-cell pf-r"><b>Date:</b> ${esc(fmtDate(r.event_date))}<br><b>Time:</b> ${esc(fmtTime(r.start_time))} – ${esc(fmtTime(r.end_time))}</div>
        <div class="pf-cell"><b>No. of Participants:</b><div class="pf-val">${esc(dash(r.participants))}</div></div>
        <div class="pf-cell pf-r"><b>Sponsoring Organization / Class:</b><div class="pf-val">${esc(dash(r.sponsoring_org))}</div></div>
        <div class="pf-cell pf-full"><b>Source of Fund:</b><div class="pf-val">${esc(dash(r.source_of_fund))}</div></div>
        <div class="pf-cell pf-full pf-nb"><b>Objectives of the Activity:</b><div class="pf-val pf-pre">${esc(dash(r.purpose))}</div></div>
      </div>
      <div class="pf-sign">
        <div class="pf-left">
          <p><b>I HEREBY CERTIFY THAT THIS ACTIVITY</b> is necessary to provide students new avenue of knowledge and experiences. Report of this activity and liquidation of expenses (if any) shall be made available after the conduct of activity.</p>
          <div class="pf-sigline"><u>${esc(r.adviser_name || '')}</u><small>Signature over printed name<br>CLUB / ORGANIZATION / ADVISER</small></div>
          <p style="margin-top:14px"><b>Recommending Approval:</b></p>
          <div class="pf-sigline"><u>${esc(r.recommending_name || '')}</u><small>Signature over printed name<br>Program Coordinator / Program Chairman / Dean</small></div>
          <p style="margin-top:12px">Remarks: ${r.remarks ? `<u>${esc(r.remarks)}</u>` : ''}</p>
        </div>
        <div>
          <div class="pf-pad"><b>Approved by:</b>
            <div class="pf-nm"><b>${esc(sg.approver1_name)}</b><small>${esc(sg.approver1_title)}</small>${apprDetail(r, 1)}</div>
            <div class="pf-nm"><b>${esc(sg.approver2_name)}</b><small>${esc(sg.approver2_title)}</small>${apprDetail(r, 2)}</div>
          </div>
          <div class="pf-pad pf-top"><b>Noted by:</b>
            <div class="pf-nm" style="margin-top:30px"><b>${esc(sg.noted_name)}</b><small>${esc(sg.noted_title)}</small></div>
          </div>
        </div>
      </div>
      <div class="pf-att"><b>Attachments:</b>
        <div class="pf-attcols">${ATTACH_LIST.map((a) => `<div class="pf-opt">${box(attached.includes(a))} ${esc(a)}</div>`).join('')}</div>
      </div>
    </div>
    ${legacy ? '<p class="muted" style="margin:8px 0 0">This reservation was submitted before the new form existed, so some fields are blank.</p>' : ''}`;
}

/* ===========================================================
   Requirements: what the student attached to their request
   =========================================================== */

/** The little "N files" button that opens the viewer. */
function filesButton(r) {
  if (!r.file_count) return '<span class="muted">—</span>';
  const label = r.image_count
    ? `${r.file_count} file${r.file_count === 1 ? '' : 's'} · ${r.image_count} 📷`
    : `${r.file_count} file${r.file_count === 1 ? '' : 's'}`;
  return `<button class="btn btn--sm btn--outline" data-files="${r.id}" title="View attached requirements">${label}</button>`;
}

async function openRequirements(reservationId) {
  openModal('Permission to Conduct Activity', '<p class="muted">Loading form…</p>', null, 'wide');
  try {
    const { reservation, resources, files, signatories } = await api.get(`requirements.php?reservation_id=${reservationId}`);
    const images = files.filter((f) => f.kind === 'image');
    const docs = files.filter((f) => f.kind !== 'image');

    const html = `
      <div class="req-head">
        <dl class="kv">${[
          ['Reference No.', reservation.ref_no],
          ['Requested by', reservation.requested_by + (reservation.organization ? ` (${reservation.organization})` : '')],
          ['Email', reservation.student_email || '—'],
          ['Status', reservation.status],
        ].map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('')}</dl>
      </div>
      ${activityFormHtml(reservation, signatories)}
      ${resources.length ? `<p class="req-resources"><strong>Borrowing:</strong> ${resources.map((x) => `${esc(x.resource_name)} ×${x.quantity}`).join(', ')}</p>` : ''}

      <h5 class="req-section">Pictures (${images.length})</h5>
      ${images.length ? `<div class="thumb-grid">${images.map((f) => `
        <figure class="thumb" data-view="${esc(f.url)}" data-caption="${esc(f.label || f.name)}">
          <img src="${esc(f.url)}" alt="${esc(f.label || f.name)}" loading="lazy">
          <figcaption>${esc(f.label || f.name)}<span>${esc(f.size)}</span></figcaption>
        </figure>`).join('')}</div>`
      : '<p class="muted req-empty">No pictures were attached.</p>'}

      <h5 class="req-section">Documents (${docs.length})</h5>
      ${docs.length ? `<ul class="file-list">${docs.map((f) => `
        <li>
          <span class="file-list__icon">${fileGlyph(f.mime)}</span>
          <span class="file-list__body">
            <strong>${esc(f.label || f.name)}</strong>
            <span class="muted">${esc(f.name)} · ${esc(f.size)}</span>
          </span>
          <a class="btn btn--sm btn--outline" href="${esc(f.url)}" target="_blank" rel="noopener">Open</a>
        </li>`).join('')}</ul>`
      : '<p class="muted req-empty">No documents were attached.</p>'}

      <div class="form-actions"><button class="btn btn--ghost" data-close>Close</button></div>`;

    openModal('Permission to Conduct Activity', html, (root) => {
      $$('[data-view]', root).forEach((fig) => {
        fig.onclick = () => openLightbox(fig.dataset.view, fig.dataset.caption);
      });
    }, 'wide');
  } catch (e) {
    openModal('Permission to Conduct Activity', `<p class="form-error">${esc(e.message)}</p>
      <div class="form-actions"><button class="btn btn--ghost" data-close>Close</button></div>`);
  }
}

/* ===========================================================
   Post-event report — the student submits it, OSAS reviews it
   =========================================================== */

/**
 * Status cell for a reservation row: the badge, plus — once the event is
 * over — whatever OSAS's side of the report workflow looks like right now.
 * No report yet is purely informational (there's nothing to click; it's
 * the student's move). A report awaiting review gets an amber pulsing "!".
 * Approved/needs-revision get a quiet, non-pulsing indicator that still
 * opens the read-only view.
 */
function statusCell(r) {
  let extra = '';
  if (r.status === 'completed') {
    if (!r.report_id) {
      extra = `<span class="pe-wait" title="Waiting on the student to submit a post-event report">…</span>`;
    } else if (r.report_review_status === 'pending') {
      extra = `<button class="pe-btn pe-btn--review" data-report="${r.report_id}" title="Report submitted — needs your review"><span>!</span></button>`;
    } else if (r.report_review_status === 'approved') {
      extra = `<button class="pe-btn pe-btn--done" data-report="${r.report_id}" title="Report ${esc(r.report_no || '')} — approved">✓</button>`;
    } else if (r.report_review_status === 'needs_revision') {
      extra = `<button class="pe-btn pe-btn--revision" data-report="${r.report_id}" title="Sent back for revision — waiting on the student">↺</button>`;
    }
  }
  return `<div class="status-cell">${badge(r.status)}${extra}</div>`;
}

/** Read-only(ish) view of a submitted report: full write-up, photo gallery,
 * PDF link, and — only while it's awaiting review — Approve / Request
 * Revision actions for the Vice Admin looking at it. */
async function openReportView(reportId) {
  openModal('Post-Event Report', '<p class="muted">Loading report…</p>', null, 'wide');
  let r;
  try {
    r = await api.get(`post_events.php?id=${reportId}`);
  } catch (e) {
    return openModal('Post-Event Report', `<p class="form-error">${esc(e.message)}</p>
      <div class="form-actions"><button class="btn btn--ghost" data-close>Close</button></div>`);
  }

  const statusTone = { pending: 'pending', approved: 'approved', needs_revision: 'declined' }[r.review_status] || 'completed';

  const meta = [
    ['Report No.', r.report_no],
    ['Reservation', r.ref_no],
    ['Venue', `${r.venue_name}${r.campus ? ' · ' + r.campus : ''}`],
    ['Date held', `${fmtDate(r.event_date)} · ${fmtTime(r.start_time)} – ${fmtTime(r.end_time)}`],
    ['Requested by', r.requested_by],
    ['Attendance', Number(r.attendees_count).toLocaleString()],
    ['Resources', r.resource_condition_label],
    ['Venue condition', r.venue_condition_label],
    ['Submitted by', r.prepared_by + ' (student)'],
    ['Submitted on', fmtDate(r.created_at)],
  ];
  if (r.reviewed_at) {
    meta.push(['Reviewed by', r.reviewer_office && r.reviewer_office !== 'Admin' ? `Vice ${r.reviewer_office}` : (r.reviewer_name || '—')]);
    meta.push(['Reviewed on', fmtDate(r.reviewed_at)]);
  }

  const block = (label, text) => `
    <h5 class="req-section">${esc(label)}</h5>
    <p class="report-text">${text ? esc(text) : '<span class="muted">Not provided.</span>'}</p>`;

  const remarksBlock = r.review_remarks
    ? `<div class="notice notice--warn"><span class="notice__dot notice__dot--warn">↺</span>
        <span><strong>Sent back for revision:</strong> ${esc(r.review_remarks)}</span></div>`
    : '';

  const reviewActions = r.review_status === 'pending' ? `
    <div class="review-actions">
      <div class="review-actions__head">This report is awaiting your review.</div>
      <textarea id="reviewRemarks" rows="2" placeholder="Remarks for the student (required only if sending it back)"></textarea>
      <div class="form-actions">
        <button class="btn btn--danger" id="btnRequestRevision">Request Revision</button>
        <button class="btn btn--primary" id="btnApprove">Approve Report</button>
      </div>
    </div>` : '';

  openModal('Post-Event Report', `
    <div class="req-head">
      <h4>${esc(r.event_name)} <span class="badge badge--${statusTone}" style="vertical-align:2px;">${esc(r.review_status_label)}</span></h4>
      <dl class="kv">${meta.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('')}</dl>
      ${r.resources.length ? `<p class="req-resources"><strong>Borrowed:</strong> ${r.resources.map((x) => `${esc(x.resource_name)} ×${x.quantity}`).join(', ')}</p>` : ''}
    </div>
    ${remarksBlock}
    ${block('Summary', r.summary)}
    ${block('Issues encountered', r.issues)}
    ${block('Recommendations', r.recommendations)}
    <h5 class="req-section">Event pictures (${r.photos.length})</h5>
    ${r.photos.length ? `<div class="thumb-grid">${r.photos.map((p) => `
      <figure class="thumb" data-view="${esc(p.url)}" data-caption="${esc(p.caption || p.name)}">
        <img src="${esc(p.url)}" alt="${esc(p.caption || p.name)}" loading="lazy">
        <figcaption>${esc(p.caption || p.name)}<span>${esc(p.size)}</span></figcaption>
      </figure>`).join('')}</div>` : '<p class="muted req-empty">No pictures attached.</p>'}
    ${reviewActions}
    <div class="form-actions">
      <button class="btn btn--ghost" data-close>Close</button>
      <a class="btn btn--primary" href="api/report_pdf.php?id=${r.id}">Download PDF</a>
      ${r.review_status === 'approved' && session.can_clearance ? `<a class="btn btn--primary" href="api/clearance_pdf.php?report_id=${r.id}">Download Clearance</a>` : ''}
    </div>`, (root) => {
    $$('[data-view]', root).forEach((fig) => {
      fig.onclick = () => openLightbox(fig.dataset.view, fig.dataset.caption);
    });

    const approveBtn = $('#btnApprove', root);
    const revisionBtn = $('#btnRequestRevision', root);
    if (approveBtn) {
      approveBtn.onclick = async () => {
        if (!(await confirmBox('Approve this post-event report? It can no longer be edited afterward.', 'Approve'))) return;
        try {
          const res = await api.post(`post_events.php?action=approve`, { id: reportId }, { silent: true });
          closeModal();
          toast(res.message || 'Report approved.');
          await render();
          refreshCounts();
        } catch (e) { toast(e.message, true); }
      };
    }
    if (revisionBtn) {
      revisionBtn.onclick = async () => {
        const remarks = $('#reviewRemarks', root).value.trim();
        if (!remarks) { toast('Please explain what needs to change before sending it back.', true); return; }
        try {
          const res = await api.post(`post_events.php?action=request_revision`, { id: reportId, remarks }, { silent: true });
          closeModal();
          toast(res.message || 'Sent back to the student.');
          await render();
          refreshCounts();
        } catch (e) { toast(e.message, true); }
      };
    }
  }, 'wide');
}

/**
 * Wires the requirements / report-view buttons on any page that renders
 * reservation rows, so the three pages don't each repeat this.
 */
function bindRowActions(root) {
  root.addEventListener('click', (e) => {
    const files = e.target.closest('[data-files]');
    if (files) return openRequirements(files.dataset.files);
    const rep = e.target.closest('[data-report]');
    if (rep) return openReportView(rep.dataset.report);
  });
}

/** Date cell; if the reservation was rescheduled, also shows where it started. */
function dateCell(r) {
  const n = +r.reschedule_count || 0;
  if (!n || !r.orig_date) return fmtDate(r.event_date);
  return `${fmtDate(r.event_date)}
    <div class="sub resched-tag" title="Rescheduled ${n} time${n === 1 ? '' : 's'}">↻ Rescheduled</div>
    <div class="sub muted">Originally ${fmtDate(r.orig_date)}, ${fmtTime(r.orig_start)} – ${fmtTime(r.orig_end)}</div>`;
}

/** Asks for a required text (used for rejecting). Resolves to the text or null. */
function reasonBox(title, intro, okLabel) {
  return new Promise((resolve) => {
    let done = false;
    const finish = (v) => { if (!done) { done = true; resolve(v); } };
    openModal(title, `
      <p>${esc(intro)}</p>
      <textarea id="rsReason" rows="4" maxlength="500" placeholder="Required"></textarea>
      <p id="rsReasonErr" style="display:none;color:#b3261e;margin:6px 0 0;">Please type something first.</p>
      <div class="form-actions">
        <button class="btn btn--ghost" data-close>Cancel</button>
        <button class="btn btn--danger-solid" id="rsReasonOk">${esc(okLabel)}</button>
      </div>`, (root) => {
      const box = $('#rsReason', root); box.focus();
      $('#rsReasonOk', root).onclick = () => {
        const v = box.value.trim();
        if (!v) { $('#rsReasonErr', root).style.display = 'block'; return; }
        finish(v); closeModal();
      };
      $$('[data-close]', root).forEach((b) => b.addEventListener('click', () => finish(null)));
    });
  });
}

/** Suggest another schedule: shows automatic free times, or the admin can type their own. */
async function openSuggestBox(id) {
  let sug = [];
  try { sug = await api.get(`reschedules.php?action=suggest&id=${id}`); } catch (e) { toast(e.message, true); return; }
  const label = (s) => `${fmtDate(s.date)} · ${fmtTime(s.start)} – ${fmtTime(s.end)}`;
  openModal('Suggest another schedule', `
    <p>Pick a free time the student can accept, or enter your own. The student must accept it — nothing changes until they do.</p>
    <div class="sug-list">${sug.length ? sug.map((s, i) => `<button type="button" class="sug-btn" data-i="${i}">${esc(label(s))}<small>${s.type === 'same_day' ? 'Same day' : 'Same time, other day'}</small></button>`).join('')
      : '<p class="muted">No automatic suggestions found nearby — enter a time below.</p>'}</div>
    <div class="sug-fields">
      <label>Date <input type="date" id="sgDate"></label>
      <label>Start <input type="time" id="sgStart"></label>
      <label>End <input type="time" id="sgEnd"></label>
    </div>
    <textarea id="sgNote" rows="2" maxlength="500" placeholder="Note to the student (optional)"></textarea>
    <div class="form-actions">
      <button class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" id="sgOk">Send suggestion</button>
    </div>`, (root) => {
    const fill = (s) => { $('#sgDate', root).value = s.date; $('#sgStart', root).value = s.start; $('#sgEnd', root).value = s.end; };
    $$('.sug-btn', root).forEach((b) => (b.onclick = () => {
      $$('.sug-btn', root).forEach((x) => x.classList.remove('is-on')); b.classList.add('is-on'); fill(sug[+b.dataset.i]);
    }));
    if (sug[0]) { fill(sug[0]); $('.sug-btn', root)?.classList.add('is-on'); }
    $('#sgOk', root).onclick = async () => {
      const date = $('#sgDate', root).value, start = $('#sgStart', root).value, end = $('#sgEnd', root).value;
      if (!date || !start || !end) return toast('Choose a date, start and end time.', true);
      closeModal();
      run(api.post('reschedules.php?action=suggest', { id, date, start, end, note: $('#sgNote', root).value.trim() }));
    };
  });
}

/* ---------- shared UI bits ---------- */
function tabs(list, active) {
  return `<div class="tabs">${list.map((t) => `<button class="tab ${t === active ? 'active' : ''}" data-tab="${t}">${t[0].toUpperCase() + t.slice(1)}</button>`).join('')}</div>`;
}
function bindTabs(root, page) {
  $$('[data-tab]', root).forEach((b) => (b.onclick = () => { page.filter = b.dataset.tab; render(); }));
}
function voteTally(r, needed, declineNeeded) {
  const who = r.voters.map((v) => `${v.name}: ${v.vote}${v.vote === 'decline' && v.reason ? ' — ' + v.reason : ''}`).join('\n') || 'No votes yet';
  const pct = (n) => Math.min(100, (n / needed) * 100);
  return `<div class="tally" title="${esc(who)}">
    <div class="tally__row"><span class="tally__lbl ok">✓ ${r.approve_count}</span><div class="tally__track"><div class="tally__fill ok" style="width:${pct(r.approve_count)}%"></div></div></div>
    <div class="tally__row"><span class="tally__lbl no">✗ ${r.decline_count}</span><div class="tally__track"><div class="tally__fill no" style="width:${Math.min(100, (r.decline_count / (declineNeeded || needed)) * 100)}%"></div></div></div>
  </div>`;
}
/**
 * "Mark done" is now only an early override — an event whose end time has
 * passed flips to Done by itself (shared/events.php). Once it's done the
 * action becomes filing or reading the post-event report.
 */
/**
 * "Mark done" is still an early override — an event whose end time has
 * passed flips to Done by itself (shared/events.php). Once it's done, the
 * report itself is the student's job to submit; OSAS only reviews it, so
 * the action column reflects wherever that review is up to.
 */
function reservationAction(r) {
  if (r.status === 'approved') {
    return `<button class="btn btn--sm btn--outline" data-act="complete" data-id="${r.id}" title="End this event early and mark it done now">Mark done</button>`;
  }
  if (r.status === 'completed') {
    if (!r.report_id) return '<span class="muted">Awaiting student submission</span>';
    if (r.report_review_status === 'pending') {
      return `<button class="btn btn--sm btn--primary" data-report="${r.report_id}">Review report</button>`;
    }
    if (r.report_review_status === 'needs_revision') {
      return `<button class="btn btn--sm btn--outline" data-report="${r.report_id}">View (sent back)</button>`;
    }
    return `<button class="btn btn--sm btn--outline" data-report="${r.report_id}">View report</button>`;
  }
  return '<span class="muted">—</span>';
}

/** "✉ Message (n)" button under the tally: opens each admin's name, comment and date in a popup. */
function messageButton(r) {
  const n = (r.voters || []).length;
  return `<div style="margin-top:6px"><button class="btn btn--sm btn--outline" data-msg="${r.id}" title="Read the approvers' comments">✉ Message${n ? ` (${n})` : ''}</button></div>`;
}

/** Popup: one card per admin who voted — name, Approved/Declined, comment, date and time. */
function openVoteMessages(r, needed) {
  if (!r) return;
  const votes = r.voters || [];
  const approvals = votes.filter((v) => v.vote === 'approve').length;
  const body = votes.length
    ? `<div class="vmsg-list">${votes.map((v) => `
        <div class="vmsg ${v.vote === 'approve' ? 'vmsg--ok' : 'vmsg--no'}">
          <div class="vmsg__top"><strong>${esc(v.name)}</strong>
            <span class="badge badge--${v.vote === 'approve' ? 'approved' : 'declined'}">${v.vote === 'approve' ? 'Approved' : 'Declined'}</span></div>
          <div class="vmsg__text">${v.reason ? esc(v.reason).replace(/\n/g, '<br>') : '<span class="muted">No comment.</span>'}</div>
          <div class="vmsg__when">${esc(v.at || '')}</div>
        </div>`).join('')}</div>`
    : '<p class="muted">No admin has voted on this reservation yet.</p>';
  openModal('Messages', `
    <p style="margin:0 0 4px"><strong>${esc(r.event_name)}</strong> <span class="muted">· ${esc(r.ref_no)}</span> ${badge(r.status)}</p>
    <p class="muted" style="margin:0 0 12px">${approvals} of ${needed} approvals</p>
    ${body}
    <div class="form-actions"><button class="btn btn--ghost" data-close>Close</button></div>`);
}

function voteButtons(r) {
  const undo = r.can_undo
    ? `<button class="btn btn--sm btn--outline" data-act="undo" data-id="${r.id}" title="Take back your vote">↶ Undo</button>` : '';
  if (r.my_vote) {
    return `<span class="muted">${r.my_vote === 'approve' ? '✓ You approved' : '✗ You declined'}</span> ${undo}`;
  }
  if (r.status !== 'pending') return '<span class="muted">Closed</span>';
  return `<button class="btn btn--sm btn--primary" data-act="approve" data-id="${r.id}">Approve</button>
          <button class="btn btn--sm btn--danger" data-act="decline" data-id="${r.id}">Decline</button>`;
}

/* ---------- table search + pagination (5 rows per page) ---------- */
const PAGE_SIZE = 5;
/* pages whose tables get paging; `search: false` where the page already has its own search box */
const PAGED_PAGES = { dashboard: {}, accounts: {}, approvals: { nature: true }, reschedules: {}, reservations: { nature: true }, inventory: {}, users: { search: false } };
const tableState = {}; // remembers search text + page across refreshes (e.g. after approving a row)

function enhanceTables(root, name, page) {
  const cfg = PAGED_PAGES[name];
  if (!cfg) return;
  $$('.card > .table-wrap > table:not(.edit-table)', root).forEach((table, i) => {
    const body = table.tBodies[0];
    if (!body) return;
    const items = [...body.rows].filter((r) => !r.querySelector('td.empty')).map((row) => ({
      row,
      // search the data cells only, not button labels like "Approve"
      text: [...row.cells].filter((c) => !c.querySelector('.actions')).map((c) => c.textContent).join(' ').toLowerCase(),
    }));
    // Nature of Activity: filter dropdown + sortable column header (approvals and all-reservations only)
    let sortBtn = null;   // "Sort by nature" button, shown only where the column header is hidden (card layout)
    const natIdx = cfg.nature ? [...table.tHead.rows[0].cells].findIndex((th) => th.textContent.trim() === 'Nature of Activity') : -1;
    items.forEach((it, n) => {
      it.order = n;
      it.natures = natIdx >= 0 ? (it.row.cells[natIdx].dataset.nature || '').split('|').filter(Boolean) : [];
      it.natureKey = natIdx >= 0 ? it.row.cells[natIdx].textContent.trim().toLowerCase() : '';
    });
    if (!items.length) return;

    const wrap = table.parentElement;
    const card = wrap.parentElement;
    const st = tableState[`${name}:${page.filter || ''}:${i}`] ||= { q: '', page: 1, nature: '', sort: '' };

    if (cfg.search !== false) {
      const tools = document.createElement('div');
      tools.className = 'tbl-tools';
      tools.innerHTML = `<input class="search" type="search" placeholder="Search this list…" aria-label="Search this list" value="${esc(st.q)}">`;
      card.insertBefore(tools, wrap);
      $('input', tools).addEventListener('input', (e) => { st.q = e.target.value; st.page = 1; draw(); });
      if (natIdx >= 0) {
        const sel = document.createElement('select');
        sel.className = 'nature-filter';
        sel.setAttribute('aria-label', 'Filter by nature of activity');
        sel.innerHTML = `<option value="">All natures of activity</option>${[...NATURE_LIST, 'Others'].map((n) => `<option value="${esc(n)}"${st.nature === n ? ' selected' : ''}>${esc(n)}</option>`).join('')}`;
        sel.addEventListener('change', () => { st.nature = sel.value; st.page = 1; draw(); });
        tools.appendChild(sel);
        sortBtn = document.createElement('button');
        sortBtn.type = 'button';
        sortBtn.className = 'btn btn--sm btn--outline nature-sort';
        sortBtn.addEventListener('click', () => { st.sort = st.sort === '' ? 'asc' : st.sort === 'asc' ? 'desc' : ''; st.page = 1; draw(); });
        tools.appendChild(sortBtn);
      }
    }
    const sortTh = natIdx >= 0 ? table.tHead.rows[0].cells[natIdx] : null;
    if (sortTh) {
      sortTh.classList.add('th-sort');
      sortTh.tabIndex = 0;
      sortTh.setAttribute('role', 'button');
      sortTh.title = 'Click to sort A–Z / Z–A';
      const cycle = () => { st.sort = st.sort === '' ? 'asc' : st.sort === 'asc' ? 'desc' : ''; st.page = 1; draw(); };
      sortTh.addEventListener('click', cycle);
      sortTh.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cycle(); } });
    }

    const noMatch = document.createElement('tr');
    noMatch.innerHTML = `<td colspan="${table.tHead.rows[0].cells.length}" class="empty">No results match your search.</td>`;
    noMatch.hidden = true;
    body.appendChild(noMatch);

    const pager = document.createElement('div');
    pager.className = 'pager';
    wrap.after(pager);
    pager.addEventListener('click', (e) => {
      const b = e.target.closest('[data-p]');
      if (!b || b.disabled) return;
      st.page = Number(b.dataset.p);
      draw();
    });

    function draw() {
      const q = st.q.trim().toLowerCase();
      const hits = items.filter((it) => (!q || it.text.includes(q)) && (!st.nature || it.natures.includes(st.nature)));
      if (sortTh) {
        const dir = st.sort === 'desc' ? -1 : 1;
        hits.sort((a, b) => (st.sort ? dir * a.natureKey.localeCompare(b.natureKey) : 0) || a.order - b.order);
        hits.forEach((it) => body.insertBefore(it.row, noMatch));
        if (st.sort) sortTh.dataset.dir = st.sort; else delete sortTh.dataset.dir;
        if (sortBtn) sortBtn.textContent = st.sort === 'asc' ? 'Sort by nature: A–Z ▲' : st.sort === 'desc' ? 'Sort by nature: Z–A ▼' : 'Sort by nature';
      }
      const pages = Math.max(1, Math.ceil(hits.length / PAGE_SIZE));
      st.page = Math.min(Math.max(1, st.page), pages);
      const start = (st.page - 1) * PAGE_SIZE;
      const shown = new Set(hits.slice(start, start + PAGE_SIZE).map((it) => it.row));
      items.forEach((it) => { it.row.hidden = !shown.has(it.row); });
      noMatch.hidden = hits.length > 0;

      if (!hits.length) { pager.innerHTML = ''; return; }
      let nums = '';
      let prev = 0;
      for (let p = 1; p <= pages; p++) {
        if (p !== 1 && p !== pages && Math.abs(p - st.page) > 1) continue;
        if (p - prev > 1) nums += '<span class="pager__gap">…</span>';
        nums += `<button class="pager__btn${p === st.page ? ' is-current' : ''}" data-p="${p}" ${p === st.page ? 'aria-current="page"' : ''}>${p}</button>`;
        prev = p;
      }
      pager.innerHTML = `
        <span class="pager__info">Showing ${start + 1}–${Math.min(start + PAGE_SIZE, hits.length)} of ${hits.length}</span>
        ${pages > 1 ? `<div class="pager__nav">
          <button class="pager__btn" data-p="${st.page - 1}" ${st.page === 1 ? 'disabled' : ''}>‹ Prev</button>
          ${nums}
          <button class="pager__btn" data-p="${st.page + 1}" ${st.page === pages ? 'disabled' : ''}>Next ›</button>
        </div>` : ''}`;
    }
    draw();
  });
}

/* ---------- router ---------- */
let session = null;
let renderToken = 0;

async function render() {
  const name = location.hash.slice(1) || 'dashboard';
  const page = pages[name] || pages.dashboard;
  const token = ++renderToken;

  $$('.nav a[data-page]').forEach((a) => a.classList.toggle('active', a.dataset.page === name));
  $('#pageTitle').textContent = typeof page.title === 'function' ? page.title() : page.title;
  $('#sidebar').classList.remove('open');

  try {
    const html = await page.render();
    if (token !== renderToken) return; // user navigated away meanwhile
    // Replace node so old event listeners are dropped
    const current = $('#app');
    const fresh = current.cloneNode(false);
    fresh.innerHTML = html;
    current.replaceWith(fresh);
    page.mount?.(fresh);
    enhanceTables(fresh, name, page);
  } catch (e) {
    $('#app').innerHTML = `<div class="card" style="padding:24px"><strong>Could not load this page.</strong><p class="muted">${esc(e.message)}</p></div>`;
  }
}

async function refreshCounts() {
  try {
    const [acc, res] = await Promise.all([api.get('accounts.php?status=pending'), api.get('reservations.php?status=pending')]);
    api.get('reschedules.php?status=pending', { silent: true }).then((rs) => {
      const el = $('#rsCount'); el.textContent = rs.length; el.hidden = !rs.length;
    }).catch(() => {});
    const set = (id, n) => { const el = $(id); el.textContent = n; el.hidden = !n; };
    set('#accCount', acc.length);
    set('#resCount', res.rows.filter((r) => !r.my_vote).length);
  } catch { /* ignore */ }
}

function paintSession() {
  // The brand name is always SPARK (set in index.html); the small line under it is whoever is logged in.
  $('#brandRole').textContent = session.office && session.office !== 'Admin'
    ? `Vice ${session.office}`
    : (session.display_name && session.display_name !== 'Vice' ? session.display_name : 'Admin');
}

/* ---------- boot ---------- */
(async function boot() {
  $('#today').textContent = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
  try {
    session = await api.get('auth.php?action=me');
  } catch {
    return; // api.js redirects to login
  }
  paintSession();

  $('#menuBtn').onclick = () => $('#sidebar').classList.toggle('open');
  $('#logoutBtn').onclick = async (e) => {
    e.preventDefault();
    if (!(await confirmBox('Log out of the admin panel?', 'Log Out'))) return;
    await api.post('auth.php?action=logout').catch(() => {});
    location.replace('login.html');
  };

  window.addEventListener('hashchange', render);
  render();
  refreshCounts();
})();