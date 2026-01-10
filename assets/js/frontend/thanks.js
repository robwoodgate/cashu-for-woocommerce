(function () {
  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function mintHost(m) {
    try {
      return new URL(String(m || '')).hostname.replace(/^www\./i, '');
    } catch {
      return String(m || '');
    }
  }

  async function copyText(text) {
    if (!text) return Promise.resolve(false);

    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard
        .writeText(text)
        .then(() => true)
        .catch(() => false);
    }
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return Promise.resolve(!!ok);
    } catch {
      return Promise.resolve(false);
    }
  }

  // Bootstrap
  const root = document.getElementById('cashu-change-root');
  if (!root) return;
  const storageKey = 'cashu_wc_change';
  const orderId = root.getAttribute('data-order-id') || '';
  const orderKey = root.getAttribute('data-order-key') || '';
  if (!orderId || !orderKey) {
    root.remove();
    return;
  }

  // Get change from session storage
  let payload = null;
  try {
    const raw = sessionStorage.getItem(storageKey);
    payload = raw ? JSON.parse(raw) : null;
  } catch {
    payload = null;
  }
  if (!payload || !Array.isArray(payload.items) || payload.items.length === 0) {
    root.remove();
    return;
  }

  // TTL 60 mins
  if (payload.created && Date.now() - payload.created > 60 * 60 * 1000) {
    try {
      sessionStorage.removeItem(storageKey);
    } catch {}
    root.remove();
    return;
  }

  // Add change container
  root.classList.add('cashu-change');
  root.innerHTML = `
		<div class="cashu-change-head">
			<div class="cashu-change-title">Your change</div>
			<button type="button" class="cashu-change-dismiss">Dismiss</button>
		</div>
		<div class="cashu-change-lead">
			If you use a Cashu wallet, copy any change tokens below and paste them into your wallet.
		</div>
		<div class="cashu-change-list"></div>
		<div class="cashu-change-tip">
			Tip: paste the token into your Cashu wallet soon, tokens are bearer value.
		</div>
	`;

  // Add change items
  const list = $('.cashu-change-list', root);
  payload.items.forEach((it, idx) => {
    const item = document.createElement('div');
    item.className = 'cashu-change-item';
    item.setAttribute('data-idx', String(idx));
    item.innerHTML = `
			<div class="cashu-change-row">
				<div class="cashu-change-left">
					<div class="cashu-change-label"></div>
					<div class="cashu-change-meta"></div>
				</div>
				<div class="cashu-change-btns">
					<button type="button" class="cashu-change-btn cashu-change-copy" data-action="copy" data-idx="${idx}">Copy</button>
					<button type="button" class="cashu-change-btn cashu-change-toggle" data-action="toggle" data-idx="${idx}">Show</button>
				</div>
			</div>
			<pre class="cashu-change-token" hidden></pre>
		`;
    const labelEl = $('.cashu-change-label', item);
    labelEl.textContent = `Mint: ${mintHost(it && it.mint)}`;
    // Token description
    const metaEl = $('.cashu-change-meta', item);
    const amount = Number.isFinite(it && it.amount) ? it.amount : 0;
    metaEl.textContent = `Amount: ₿${amount}`;
    const tokenEl = $('.cashu-change-token', item);
    tokenEl.textContent = String(it && it.token ? it.token : '');
    list.appendChild(item);
  });

  // Global click listener
  root.addEventListener('click', async (ev) => {
    const btn = ev.target && ev.target.closest ? ev.target.closest('button') : null;
    if (!btn) return;
    // Dismiss
    if (btn.classList.contains('cashu-change-dismiss')) {
      try {
        sessionStorage.removeItem(storageKey);
      } catch {}
      root.remove();
      return;
    }
    // Item toggle
    const action = btn.getAttribute('data-action');
    const idxRaw = btn.getAttribute('data-idx');
    const idx = idxRaw ? parseInt(idxRaw, 10) : NaN;
    if (!Number.isFinite(idx)) return;
    const item = payload.items[idx];
    if (!item) return;
    if (action === 'toggle') {
      const card = btn.closest('.cashu-change-item');
      const pre = card ? $('.cashu-change-token', card) : null;
      if (!pre) return;
      const isHidden = pre.hasAttribute('hidden');
      if (isHidden) {
        pre.removeAttribute('hidden');
        btn.textContent = 'Hide';
      } else {
        pre.setAttribute('hidden', '');
        btn.textContent = 'Show';
      }
      return;
    }
    // Copy token
    if (action === 'copy') {
      const token = String(item.token || '');
      if (!token) return;
      const old = btn.textContent;
      btn.disabled = true;
      const ok = await copyText(token);
      btn.textContent = ok ? 'Copied' : 'Copy failed';
      setTimeout(() => {
        btn.textContent = old;
        btn.disabled = false;
      }, 1200);
    }
  });
})();
