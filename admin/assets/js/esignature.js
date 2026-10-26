/* ===========================================================
   E-signatures (OSAS / Academic Affairs / Admin accounts)
   Loaded AFTER app.js. It only adds to the existing pages:
     - "My E-Signature" card on Settings
     - signature pad ("Add Signature")
     - Sign / Remove signature bar + the signature printed on the
       Permission to Conduct Activity form ("View Form")
   Nothing in app.js is edited; the hooks below wrap its functions.
   =========================================================== */
(() => {
  const SLOT_ORDER = ['approver1', 'approver2', 'noted'];
  let mine = null; // { signature, slot, slot_label, can_sign, slot_signature }
  let slotSigs = null; // { approver1, approver2, noted } drawn in Settings; null if the table isn't imported
  const SLOT_NAMES = { approver1: 'Approved by (1)', approver2: 'Approved by (2)', noted: 'Noted by' };

  const loadMine = async () => {
    try { mine = await api.get('esignature.php?action=mine', { silent: true }); }
    catch { mine = null; }
    return mine;
  };

  /* ---------- signature pad ---------- */
  /** Opens the pad. Resolves with the saved PNG data URL, or null if cancelled. */
  function openPad(title = 'Add Signature', slot = null) {
    return new Promise((resolve) => {
      let done = false;
      const finish = (v) => { if (done) return; done = true; closeModal(); resolve(v); };
      openModal(title, `
        <canvas class="esig-pad" id="esigPad"></canvas>
        <p class="muted esig-hint">Draw your signature above using your mouse, finger or stylus.</p>
        <div class="form-actions">
          <button class="btn btn--ghost" type="button" id="esigCancel">Cancel</button>
          <button class="btn btn--outline" type="button" id="esigClear">Clear</button>
          <button class="btn btn--primary" type="button" id="esigSave">Save</button>
        </div>`, (root) => {
        const cv = $('#esigPad', root);
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const rect = cv.getBoundingClientRect();
        cv.width = Math.round(rect.width * ratio);
        cv.height = Math.round(rect.height * ratio);
        const ctx = cv.getContext('2d');
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
        let drawing = false, dirty = false, last = null;
        const pos = (e) => { const r = cv.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
        cv.addEventListener('pointerdown', (e) => {
          drawing = true; last = pos(e); cv.setPointerCapture(e.pointerId);
          ctx.beginPath(); ctx.arc(last.x, last.y, 1.2, 0, Math.PI * 2); ctx.fillStyle = '#111'; ctx.fill(); dirty = true;
        });
        cv.addEventListener('pointermove', (e) => {
          if (!drawing) return;
          const p = pos(e);
          ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
          last = p;
        });
        const stop = () => { drawing = false; };
        cv.addEventListener('pointerup', stop); cv.addEventListener('pointercancel', stop); cv.addEventListener('pointerleave', stop);

        $('#esigClear', root).onclick = () => { ctx.clearRect(0, 0, cv.width, cv.height); dirty = false; };
        $('#esigCancel', root).onclick = () => finish(null);
        $('#esigSave', root).onclick = async () => {
          if (!dirty) return toast('Please draw your signature first.', true);
          const png = cropToPng(cv);
          if (!png) return toast('Please draw your signature first.', true);
          try {
            await api.post(slot ? 'esignature.php?action=save_slot' : 'esignature.php?action=save', slot ? { slot, signature: png } : { signature: png });
            toast('E-signature saved.');
            finish(png);
          } catch (ex) { toast(ex.message, true); }
        };
      });
      // closing the modal any other way (X / outside click / Esc) = cancelled
      const mo = new MutationObserver(() => { if ($('#modal').hidden) { mo.disconnect(); finish(null); } });
      mo.observe($('#modal'), { attributes: true, attributeFilter: ['hidden'] });
    });
  }

  /** Crops the empty margin and scales the drawing down to at most 480px wide, as a transparent PNG. */
  function cropToPng(cv) {
    const { width: w, height: h } = cv;
    const data = cv.getContext('2d').getImageData(0, 0, w, h).data;
    let x0 = w, y0 = h, x1 = -1, y1 = -1;
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      if (data[(y * w + x) * 4 + 3] > 8) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    }
    if (x1 < 0) return null;
    const pad = 6;
    x0 = Math.max(0, x0 - pad); y0 = Math.max(0, y0 - pad); x1 = Math.min(w - 1, x1 + pad); y1 = Math.min(h - 1, y1 + pad);
    const cw = x1 - x0 + 1, ch = y1 - y0 + 1;
    const scale = Math.min(1, 480 / cw);
    const out = document.createElement('canvas');
    out.width = Math.max(10, Math.round(cw * scale)); out.height = Math.max(10, Math.round(ch * scale));
    out.getContext('2d').drawImage(cv, x0, y0, cw, ch, 0, 0, out.width, out.height);
    return out.toDataURL('image/png');
  }

  /** A "Draw E-Signature" block under each signatory in Reservation Form Signatories. */
  function slotBlock(slot) {
    const sig = slotSigs && slotSigs[slot];
    return `<div class="esig-slot">
      <div class="esig-preview">${sig ? `<img src="${esc(sig)}" alt="${esc(SLOT_NAMES[slot])} e-signature">` : '<span class="muted">No e-signature yet.</span>'}</div>
      <div class="form-actions" style="margin-top:8px">
        ${sig ? `<button class="btn btn--sm btn--ghost" type="button" data-slot-remove="${slot}">Remove</button>` : ''}
        <button class="btn btn--sm btn--outline" type="button" data-slot-draw="${slot}">${sig ? 'Redraw' : 'Draw'} E-Signature</button>
      </div></div>`;
  }
  function addSlotButtons(root) {
    const form = $('#sigForm', root);
    if (!form) return;
    slotSigs = slotSigs || {};
    const heads = $$(':scope > b', form);
    const end = $('.form-actions', form);
    // "Noted by" can only be changed by OSAS; the other two lines are open to Administration / Academic Affairs.
    SLOT_ORDER.forEach((slot, i) => {
      if (slot === 'noted' && !session.is_osas) return (heads[i + 1] || end).insertAdjacentHTML('beforebegin', `<p class="muted" style="margin:6px 0 10px">The “Noted by” e-signature can only be changed by OSAS.</p>`);
      (heads[i + 1] || end).insertAdjacentHTML('beforebegin', slotBlock(slot));
    });
    $$('[data-slot-draw]', form).forEach((b) => (b.onclick = async () => {
      if (await openPad(`E-Signature - ${SLOT_NAMES[b.dataset.slotDraw]}`, b.dataset.slotDraw)) render();
    }));
    $$('[data-slot-remove]', form).forEach((b) => (b.onclick = async () => {
      if (!(await confirmBox(`Remove the ${SLOT_NAMES[b.dataset.slotRemove]} e-signature?`, 'Remove'))) return;
      try { await api.post('esignature.php?action=remove_slot', { slot: b.dataset.slotRemove }); toast('E-signature removed.'); render(); }
      catch (ex) { toast(ex.message, true); }
    }));
  }

  const settings = pages.settings;
  const origRender = settings.render.bind(settings);
  const origMount = settings.mount ? settings.mount.bind(settings) : null;
  settings.render = async function () {
    const html = await origRender();
    try { const r = await api.get('esignature.php?action=slots', { silent: true }); slotSigs = r.ready ? r.slots : null; } catch { slotSigs = null; }
    return html;
  };
  settings.mount = function (root) {
    origMount?.(root);
    addSlotButtons(root);
  };

  /* ---------- Permission form: show signatures + Sign button ---------- */
  const origOpen = window.openRequirements;
  window.openRequirements = async function (reservationId) {
    let info = null;
    try { info = await api.get(`esignature.php?action=form&reservation_id=${encodeURIComponent(reservationId)}`, { silent: true }); } catch { /* not set up: plain form */ }
    const result = await origOpen(reservationId);
    if (!info) return result;

    const root = $('#modalBody');
    const pf = root && $('.pf', root);
    if (!pf) return result;

    // 1) the signatures themselves, above each printed name
    const names = $$('.pf-nm', pf);
    SLOT_ORDER.forEach((slot, i) => {
      const s = info.signatures[slot];
      if (!s || !names[i]) return;
      names[i].insertAdjacentHTML('afterbegin', `<div class="pf-esig"><img src="${esc(s.signature)}" alt="E-signature"></div>`);
      names[i].insertAdjacentHTML('beforeend', `<small class="pf-esig-meta">E-SIGNED ${esc(s.date)}</small>`);
    });

    return result;
  };
})();
