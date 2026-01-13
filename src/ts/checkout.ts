import {
  getEncodedTokenV4,
  getTokenMetadata,
  Wallet,
  sumProofs,
  Proof,
  MeltQuoteState,
  TokenMetadata,
  MeltQuoteBolt11Response,
  MeltProofsResponse,
  ConsoleLogger,
} from '@cashu/cashu-ts';
import { copyTextToClipboard, doConfettiBomb, delay, getErrorMessage } from './utils';

// ------------------------------
// Types
// ------------------------------

type CashuWindow = Window & {
  cashu_wc?: {
    rest_root?: string;
    confirm_route?: string;
    symbol: string;
    i18n?: Record<string, string>;
  };
};
declare const window: CashuWindow;

// The 'wp-i18n' dependency.
declare const wp: { i18n: { sprintf: (format: string, ...args: any[]) => string } };

declare const QRCode: any;

type CurrencyUnit = 'btc' | 'sat' | 'msat' | string;

type ConfirmPaidResponse = {
  ok?: boolean;
  state?: MeltQuoteState | 'EXPIRED';
  redirect?: string;
  message?: string;
  expiry?: number | null;
};

type RootData = {
  orderId: number;
  orderKey: string;
  returnUrl: string;
  expectedPaySats: number; // headline amount user must cover (invoice + fee_reserve)
  quoteId: string; // melt quote id (vendor payment)
  quoteExpiryMs: number; // milliseconds, may be 0
  trustedMint: string;
};

type StoredMintQuote = {
  mint: string;
  amount: number;
  quote: string;
  request: string;
  expiry?: number | null;
};

type ChangeItem = {
  mint: string;
  token: string;
  amount: number;
  kind: string;
  dust: boolean;
};

type ChangePayload = {
  v: 1;
  created: number;
  items: ChangeItem[];
};

// ------------------------------
// Helpers
// ------------------------------

// Create AbortController for websocket management
const ac = new AbortController();
window.addEventListener('pagehide', () => ac.abort(), { once: true });
window.addEventListener('beforeunload', () => ac.abort(), { once: true });

// Cache wallets by mintUrl|unit
const walletCache = new Map<string, Promise<Wallet>>();

function getWalletCached(mintUrl: string, unit: CurrencyUnit = 'sat'): Promise<Wallet> {
  const key = `${String(mintUrl).replace(/\/+$/, '')}|${unit}`;
  const existing = walletCache.get(key);
  if (existing) return existing;
  // Start loading wallet (IIFE)
  const p = (async () => {
    const w = new Wallet(mintUrl, { unit, logger: new ConsoleLogger('debug') });
    await w.loadMint();
    return w;
  })();
  // Cache unless load fails
  p.catch(() => walletCache.delete(key));
  walletCache.set(key, p);
  return p;
}

/**
 * Read order data-* attributes on gateway receipt_page().
 */
function readRootData($root: JQuery<HTMLElement>): RootData {
  const orderId = Number($root.data('order-id'));
  const orderKey = String($root.data('order-key') ?? '');
  const returnUrl = String($root.data('return-url') ?? '');
  const expectedPaySats = Number($root.data('pay-amount-sats') ?? 0);
  const quoteId = String($root.data('melt-quote-id') ?? '');
  const quoteExpiryMs = Number($root.data('spot-quote-expiry') ?? 0) * 1000;
  const trustedMint = String($root.data('trusted-mint') ?? '');

  if (
    !Number.isFinite(orderId) ||
    orderId <= 0 ||
    !orderKey ||
    !returnUrl ||
    !trustedMint ||
    !Number.isFinite(expectedPaySats) ||
    expectedPaySats <= 0 ||
    !quoteId
  ) {
    throw new Error('Bad order data');
  }

  return {
    orderId,
    orderKey,
    returnUrl,
    expectedPaySats,
    quoteId,
    quoteExpiryMs,
    trustedMint,
  };
}

function sameMint(a: string, b: string): boolean {
  try {
    const ua = new URL(a);
    const ub = new URL(b);
    const normA = ua.origin + ua.pathname.replace(/\/+$/, '');
    const normB = ub.origin + ub.pathname.replace(/\/+$/, '');
    return normA === normB;
  } catch {
    return a.replace(/\/+$/, '') === b.replace(/\/+$/, '');
  }
}

/**
 * Translation function for i18n
 */
function t(key: string, ...args: any[]): string {
  const dict = window.cashu_wc?.i18n ?? {};
  const raw = dict[key] ?? key;
  if (!args.length) return raw;
  try {
    return wp.i18n.sprintf(raw, ...args);
  } catch {
    return raw; // safe fallback
  }
}

// ------------------------------
// LocalStorage helpers
// ------------------------------

function loadJson<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return null;
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}

function saveJson(key: string, val: any): void {
  try {
    localStorage.setItem(key, JSON.stringify(val));
  } catch {
    // ignore
  }
}

function deleteJson(key: string): void {
  try {
    localStorage.removeItem(key);
  } catch {
    // ignore
  }
}

function loadChangePayload(key: string): ChangePayload {
  try {
    const parsed = loadJson<ChangePayload>(key);
    if (
      !parsed ||
      !Array.isArray(parsed.items) ||
      Date.now() - parsed.created > 60 * 60 * 1000 // older than 1 hour
    ) {
      return { v: 1, created: Date.now(), items: [] };
    }
    return parsed;
  } catch {
    return { v: 1, created: Date.now(), items: [] };
  }
}

// ------------------------------
// Bootstrap checkout
// ------------------------------

/**
 * We support two checkout flows: QR Code & Token
 *
 * QR Code pays a MINT quote at trusted mint via lightning Network (LN).
 * If the customer pays from trusted mint, they save LN fees.
 * Whichever mint they use, we get proofs at our trusted mint.
 *
 * Token can be from any mint. If from an untrusted mint, we melt them to
 * pay the MINT quote at trusted mint via lightning Network (LN).
 *
 * Once we have trusted proofs we MELT them to pay the vendor LN invoice.
 * We then ask the WooCommerce store to confirm the melt is paid.
 */

jQuery(function ($) {
  // Init UI
  const $root = $('#cashu-pay-root');
  if (!$root.length) return;
  const $scope = $root.next('section.cashu-checkout');
  if (!$scope.length) return;
  const $form = $scope.find('form.cashu-token');
  const $input = $scope.find('[data-cashu-token-input]');
  const $btn = $form.find('button[type="submit"]');
  const $status = $scope.find('.cashu-status');
  const $qr = $scope.find('[data-cashu-qr]');
  const setStatus = (msg: string, isError: boolean = false) => {
    const color = isError ? 'var(--cashu-warning)' : 'var(--cashu-status)';
    $status.text(msg).css('background-color', color);
  };
  const lock = (locked: boolean) => {
    $btn.prop('disabled', locked);
    $input.prop('disabled', locked);
  };
  lock(false);
  $form.off('submit').on('submit', (e) => {
    e.preventDefault();
    const token = getToken();
    if (!token) {
      setStatus(t('paste_token_first'), true);
      return;
    }
    void run(() => payFromToken(token), { user: true });
  });

  // Load checkout data
  let data: RootData;
  try {
    data = readRootData($root);
  } catch (_e) {
    $status.text(t('data_incomplete'));
    return;
  }

  // Init vars
  let mqP: Promise<StoredMintQuote> | null = null;
  let chain: Promise<any> = Promise.resolve();
  let mintHandleP: Promise<void> | null = null;
  let userPending = 0;
  const trustedWalletP = getWalletCached(data.trustedMint, 'sat');
  const getToken = () => String($input.val() ?? '').trim();
  const ls = {
    mq: 'cashu_wc_mq',
    change: 'cashu_wc_change',
    recovery: 'cashu_wc_recovery',
  };

  // Clear old change
  try {
    deleteJson(ls.change);
  } catch {}

  // Start async processes (don’t block UI)
  void startAsyncProcesses().catch(() => {
    setStatus(t('invoice_failed'), true);
  });

  // ------------------------------
  // Checkout Helpers
  // ------------------------------

  async function startAsyncProcesses(): Promise<void> {
    void renderQr();
    void pollOrderStatus();
    void startMintQuoteWatcher();
    const token = localStorage.getItem(ls.recovery);
    if (token) {
      payFromToken(token).catch((e) => {
        console.error(e);
        setStatus(t('recovery_failed'), true);
        $input.val(token);
        localStorage.removeItem(ls.recovery);
      });
    }
  }

  async function renderQr(): Promise<void> {
    const mq = await ensureMintQuote();
    const el = $qr.get(0) as HTMLElement | undefined;
    if (!el || typeof QRCode === 'undefined') return;
    el.innerHTML = '';
    new QRCode(el, {
      text: 'lightning:' + mq.request,
      width: 360,
      height: 360,
      colorDark: '#000000',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.Q,
    });
    // Copy on click
    const $qrWrap = $qr.parent(); // allows logo click
    $qrWrap.off('click').on('click', async () => {
      copyTextToClipboard(mq.request);
      setStatus(t('copied'));
      await delay(500);
      setStatus(t('waiting_for_payment'));
    });
  }

  async function run<T>(
    fn: () => Promise<T>,
    opts: { user?: boolean } = {},
  ): Promise<T | undefined> {
    const isUser = !!opts.user;

    if (isUser && userPending > 0) {
      setStatus(t('payment_in_progress'), true);
      return Promise.resolve(undefined);
    }

    if (isUser) {
      userPending++;
      if (userPending === 1) lock(true);
    }

    const p = chain.then(fn).catch((e) => {
      const msg = getErrorMessage(e);
      setStatus(msg, true);
      return undefined as unknown as T;
    });

    // keep the chain alive regardless
    chain = p.then(() => undefined);

    try {
      return await p;
    } finally {
      if (isUser) {
        userPending--;
        if (userPending === 0) lock(false);
      }
    }
  }

  async function saveProofs(changeProofs: Proof[], wallet: Wallet): Promise<void> {
    if (changeProofs.length < 1) {
      return;
    }
    const changeAmt = sumProofs(changeProofs);
    const changeFees = wallet.getFeesForProofs(changeProofs);
    const tokenStr = getEncodedTokenV4({
      mint: wallet.mint.mintUrl,
      proofs: changeProofs,
      unit: 'sat',
    });
    const kind = sameMint(wallet.mint.mintUrl, data.trustedMint)
      ? t('change_from_network')
      : t('change_from_token');
    rememberChangeItem({
      mint: wallet.mint.mintUrl,
      token: tokenStr,
      amount: changeAmt,
      kind,
      dust: changeAmt <= changeFees,
    });
  }

  function rememberChangeItem(item: ChangeItem): void {
    const payload = loadChangePayload(ls.change);
    // de-dupe by token string
    const exists = payload.items.some((x) => x.token === item.token);
    if (!exists) payload.items.push(item);
    // cap to 5 items
    payload.items = payload.items.slice(-5);
    saveJson(ls.change, payload);
  }

  // ------------------------------
  // Pay By Token
  // ------------------------------

  async function payFromToken(token: string): Promise<void> {
    setStatus(t('checking_token'));
    await delay(500);
    let meta: TokenMetadata;
    try {
      meta = getTokenMetadata(token);
    } catch (e) {
      console.error(getErrorMessage(e));
      setStatus(t('invalid_token'), true);
      return;
    }

    const tokenMint = String(meta.mint ?? '').trim();
    const tokenUnit = String(meta.unit ?? 'sat');
    if (!tokenMint || meta.amount === 0) {
      setStatus(t('no_spendable_proofs'), true);
      return;
    }
    if (tokenUnit !== 'sat') {
      setStatus(t('not_sat_denom'), true);
      return;
    }

    setStatus(t('connecting_to_mint'));
    await delay(500);
    const tokenWallet = await getWalletCached(tokenMint, 'sat');
    const decoded = tokenWallet.decodeToken(token);
    let proofs = decoded.proofs;

    if (!Array.isArray(proofs) || proofs.length === 0) {
      setStatus(t('no_usable_proofs'), true);
      return;
    }

    // Trusted mint token, pay vendor directly
    if (sameMint(tokenMint, data.trustedMint)) {
      const trustedWallet = await trustedWalletP;
      await meltTrustedProofsToVendor(proofs, trustedWallet);
      return;
    }

    // Untrusted: melt at untrusted mint to pay the trusted mint quote invoice
    const mq = await ensureMintQuote();

    const amount = sumProofs(proofs);
    const fees = tokenWallet.getFeesForProofs(proofs);

    setStatus(t('calculating_fees'));
    await delay(500);
    const utMeltQuote = await tokenWallet.createMeltQuoteBolt11(mq.request);
    const required = utMeltQuote.amount + utMeltQuote.fee_reserve + fees;
    const meltFees = utMeltQuote.fee_reserve + fees;

    if (amount < required) {
      const symbol = window.cashu_wc?.symbol ?? '₿';
      setStatus(t('token_too_small', symbol, amount, required, meltFees), true);
      return;
    }

    setStatus(t('sending_payment'));
    await delay(500);
    const utMeltRes = await tokenWallet.meltProofsBolt11(utMeltQuote, proofs);

    const changeProofs = Array.isArray(utMeltRes?.change) ? utMeltRes.change : [];
    void saveProofs(changeProofs, tokenWallet);
    setStatus(t('waiting_confirmation'));
  }

  // ------------------------------
  // Mint Quote - For QR Code or
  // tokens from untrusted mints.
  // Returns proofs at trusted mint
  // ------------------------------

  async function ensureMintQuote(): Promise<StoredMintQuote> {
    if (mqP) return mqP;

    mqP = (async () => {
      const cached = loadJson<StoredMintQuote>(ls.mq);
      if (storedMintQuoteLooksUsable(cached)) return cached;

      const wallet = await trustedWalletP;
      const mq = await wallet.createMintQuoteBolt11(data.expectedPaySats);

      const store: StoredMintQuote = {
        mint: data.trustedMint,
        amount: data.expectedPaySats,
        quote: mq.quote,
        request: mq.request,
        expiry: mq.expiry ?? null,
      };
      saveJson(ls.mq, store);
      return store;
    })();

    mqP.catch(() => {
      mqP = null;
    });

    return mqP;
  }

  function storedMintQuoteLooksUsable(mq: StoredMintQuote | null): mq is StoredMintQuote {
    if (!mq) return false;
    if (!mq.quote || !mq.request || !mq.mint) return false;
    if (mq.amount !== data.expectedPaySats) return false;
    if (!sameMint(mq.mint, data.trustedMint)) return false;

    const exp = typeof mq.expiry === 'number' ? mq.expiry : 0;
    const now = Math.floor(Date.now() / 1000);
    if (exp > 0 && exp <= now) return false;
    return true;
  }

  async function startMintQuoteWatcher(): Promise<void> {
    const mq = await ensureMintQuote();
    const wallet = await trustedWalletP;

    // Websocket is primary listener
    const wsPaid = async (): Promise<boolean> => {
      const expiryMs = data.quoteExpiryMs - Date.now();
      if (expiryMs <= 0 || ac.signal.aborted) return false;

      // Keep ws open min 10s, but stop waiting at quote expiry.
      const timeoutMs = Math.max(10_000, expiryMs);
      try {
        await Promise.race([
          wallet.on.onceMintPaid(mq.quote, { signal: ac.signal, timeoutMs }),
          delay(expiryMs).then(() => {
            throw new Error('Quote expired');
          }),
        ]);
        return true;
      } catch {
        return false;
      }
    };

    // Fallback polling
    const pollPaid = async (): Promise<boolean> => {
      try {
        while (!ac.signal.aborted && Date.now() < data.quoteExpiryMs) {
          const q = await wallet.checkMintQuoteBolt11(mq.quote);
          if (q.state === 'PAID') return true;
          await delay(3000);
        }
        return false;
      } catch {
        return false;
      }
    };

    const paid = (await wsPaid()) || (!ac.signal.aborted && (await pollPaid()));
    if (!paid) return;

    // success only
    void run(() => handleMintQuotePaid(mq));
  }

  async function handleMintQuotePaid(mq: StoredMintQuote): Promise<void> {
    if (mintHandleP) return mintHandleP;

    mintHandleP = (async () => {
      setStatus(t('payment_received'));
      await delay(500);
      const wallet = await trustedWalletP;
      const mintedProofs = await wallet.mintProofsBolt11(data.expectedPaySats, mq.quote);
      deleteJson(ls.mq);
      mqP = null;
      await meltTrustedProofsToVendor(mintedProofs, wallet);
    })();

    try {
      await mintHandleP;
    } catch (e) {
      // allow retry if it failed
      mintHandleP = null;
      throw e;
    }
  }

  // ------------------------------
  // Melt Quote - To pay vendor's
  // lightning invoice and settle
  // using proofs from trusted mint
  // ------------------------------

  async function meltTrustedProofsToVendor(
    proofs: Proof[],
    trustedWallet: Wallet,
  ): Promise<void> {
    // Backup proofs as a token before melt as they may have been minted
    // by us in the QR Code or untrusted mint payment flows
    const token = getEncodedTokenV4({ mint: data.trustedMint, proofs, unit: 'sat' });
    let meltRes: MeltProofsResponse<MeltQuoteBolt11Response> | undefined;

    setStatus(t('paying_invoice'));

    try {
      localStorage.setItem(ls.recovery, token);
      const quote = await trustedWallet.checkMeltQuoteBolt11(data.quoteId);
      meltRes = await trustedWallet.meltProofsBolt11(quote, proofs);
    } catch (e) {
      $input.val(token);
      setStatus(getErrorMessage(e), true);
      return;
    } finally {
      localStorage.removeItem(ls.recovery);
    }

    const changeProofs = Array.isArray(meltRes?.change) ? meltRes.change : [];
    void saveProofs(changeProofs, trustedWallet);

    setStatus(t('confirming_payment'));
  }

  // ------------------------------
  // Order Status - Check WooCommerce
  // has confirmed lightning payment
  // ------------------------------

  async function checkOrderStatus(): Promise<ConfirmPaidResponse | null> {
    const restRoot = String(window.cashu_wc?.rest_root ?? '');
    const route = String(window.cashu_wc?.confirm_route ?? '');
    if (!restRoot || !route) return null;

    const endpoint = restRoot.replace(/\/?$/, '/') + route.replace(/^\//, '');

    const payload: any = {
      order_id: data.orderId,
      order_key: data.orderKey,
      quote_id: data.quoteId,
    };

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });
      const json = (await res.json()) as ConfirmPaidResponse;

      if (json?.state === 'PAID') {
        doConfettiBomb();
        await delay(2000);
        window.location.assign(String(json.redirect ?? data.returnUrl));
        return json;
      }

      if (json?.state === 'EXPIRED') {
        setStatus(t('invoice_expired'), true);
        await delay(2000);
        window.location.assign(String(data.returnUrl)); // order received page
        return json;
      }
      if (json?.expiry) {
        const msg = t('invoice_expires_in', formatCountdown(json.expiry));
        const seconds = json.expiry - Date.now() / 1000;
        if (seconds < 300) {
          setStatus(msg, seconds < 60);
        }
      }

      return json ?? null;
    } catch {
      return null;
    }
  }

  async function pollOrderStatus(): Promise<void> {
    // Already expired?
    if (ac.signal.aborted || Date.now() > data.quoteExpiryMs) {
      window.location.assign(String(data.returnUrl)); // order received page
      return;
    }
    // Start polling
    while (!ac.signal.aborted && Date.now() <= data.quoteExpiryMs) {
      await delay(3000);
      const r = await run(() => checkOrderStatus());
      if (r?.state === 'PAID' || r?.state === 'EXPIRED') return;
    }
    // Final check for redirect
    await delay(500);
    await run(() => checkOrderStatus());
  }

  /**
   * Returns "MM:SS" remaining until a target Unix timestamp (in seconds).
   * If the time has passed, returns "00:00".
   */
  function formatCountdown(
    targetUnixSeconds: number,
    nowMs: number = Date.now(),
  ): string {
    const remainingMs = targetUnixSeconds * 1000 - nowMs;
    const totalSeconds = Math.max(0, Math.floor(remainingMs / 1000));

    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    const mm = String(minutes).padStart(2, '0');
    const ss = String(seconds).padStart(2, '0');

    return `${mm}:${ss}`;
  }
});
