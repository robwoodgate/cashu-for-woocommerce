import {
  getEncodedTokenV4,
  getTokenMetadata,
  Wallet,
  sumProofs,
  Proof,
  MeltProofsResponse,
  MeltQuoteState,
  MintQuoteBolt11Response,
  TokenMetadata,
} from '@cashu/cashu-ts';
import { copyTextToClipboard, doConfettiBomb, delay, getErrorMessage } from './utils';
import toastr from 'toastr';

type CashuWindow = Window & {
  cashu_wc?: {
    rest_root?: string;
    confirm_route?: string;
  };
};

declare const window: CashuWindow;
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
  quoteExpiry: number; // unix seconds, may be 0
  trustedMint: string;
};

type StoredMintQuote = {
  mint: string;
  amount: number;
  quote: string;
  request: string;
  expiry?: number | null;
};

// Set toastr options
toastr.options = { positionClass: 'toast-bottom-center' };

// Create AbortController for websocket management
const ac = new AbortController();
window.addEventListener('pagehide', () => ac.abort(), { once: true });
window.addEventListener('beforeunload', () => ac.abort(), { once: true });

window.addEventListener('unhandledrejection', (e) => {
  console.error('unhandledrejection', e.reason);
  // @ts-ignore
  if (typeof toastr !== 'undefined') toastr.error(String(e.reason?.message ?? e.reason));
});

// Cache wallets by mintUrl|unit
const walletCache = new Map<string, Promise<Wallet>>();

function getWalletCached(mintUrl: string, unit: CurrencyUnit = 'sat'): Promise<Wallet> {
  const key = `${String(mintUrl).replace(/\/+$/, '')}|${unit}`;
  const existing = walletCache.get(key);
  if (existing) return existing;
  // Start loading wallet (IIFE)
  const p = (async () => {
    const w = new Wallet(mintUrl, { unit });
    await w.loadMint();
    return w;
  })();
  // Cache unless load fails
  p.catch(() => walletCache.delete(key));
  walletCache.set(key, p);
  return p;
}

/**
 * Bootstrap checkout
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

  // Load checkout data
  let data: RootData;
  try {
    data = readRootData($root);
  } catch (_e) {
    $status.text('Payment data incomplete, please refresh and try again.');
    return;
  }

  // Helpers
  const status = (msg: string) => $status.text(msg);
  const lock = (locked: boolean) => {
    $btn.prop('disabled', locked);
    $input.prop('disabled', locked);
  };
  const getToken = () => String($input.val() ?? '').trim();
  const ls = {
    mq: `cashu_wc_mq_${data.orderId}`,
    change: `cashu_wc_change_${data.orderId}`,
    recovery: `cashu_wc_recovery_${data.orderId}`, // keep as invisible safety
  };

  // Init vars
  const trustedWalletP = getWalletCached(data.trustedMint, 'sat');
  let mintHandled = false;
  let mqP: Promise<StoredMintQuote> | null = null;
  let pending = 0;
  let chain: Promise<any> = Promise.resolve();
  let userPending = 0;

  function run<T>(
    fn: () => Promise<T>,
    opts: { user?: boolean } = {},
  ): Promise<T | undefined> {
    const isUser = !!opts.user;

    if (isUser && userPending > 0) {
      toastr.error('Payment already in progress');
      return Promise.resolve(undefined);
    }

    if (isUser) {
      userPending++;
      if (userPending === 1) lock(true);
    }

    const p = chain.then(fn).catch((e) => {
      const msg = getErrorMessage(e);
      status(msg);
      if (isUser) toastr.error(msg);
      return undefined as unknown as T;
    });

    // keep the chain alive regardless
    chain = p.then(() => undefined);

    return p.finally(() => {
      if (isUser) {
        userPending--;
        if (userPending === 0) lock(false);
      }
    });
  }

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

  function rememberChangeTokens(tokens: string[]): void {
    const clean = tokens.map((t) => String(t).trim()).filter(Boolean);
    if (clean.length === 0) return;

    const existing = loadJson<string[]>(ls.change) ?? [];
    const merged = Array.from(new Set([...existing, ...clean])).slice(-10);
    saveJson(ls.change, merged);
  }

  async function classifyChangeToken(
    token: string,
  ): Promise<'spendable' | 'dust' | 'unknown'> {
    try {
      const meta = getTokenMetadata(token);
      if (!meta.mint) return 'unknown';
      const w = await getWalletCached(String(meta.mint), 'sat');
      const decoded = w.decodeToken(token);
      const amt = sumProofs(decoded.proofs);
      const fees = w.getFeesForProofs(decoded.proofs);
      return amt > fees ? 'spendable' : 'dust';
    } catch {
      return 'unknown';
    }
  }

  function getChangeToken(meltResponse: MeltProofsResponse, mintUrl: string): string {
    const change = Array.isArray(meltResponse?.change) ? meltResponse.change : [];
    if (change.length === 0) return '';
    return getEncodedTokenV4({ mint: mintUrl, proofs: change, unit: 'sat' });
  }

  function renderQr(text: string): void {
    const el = $qr.get(0) as HTMLElement | undefined;
    if (!el || typeof QRCode === 'undefined') return;
    el.innerHTML = '';
    // eslint-disable-next-line no-new
    new QRCode(el, {
      text,
      width: 360,
      height: 360,
      colorDark: '#000000',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.Q,
    });
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

  async function confirmOnce(
    extraChangeTokens: string[] = [],
  ): Promise<ConfirmPaidResponse | null> {
    const restRoot = String(window.cashu_wc?.rest_root ?? '');
    const route = String(window.cashu_wc?.confirm_route ?? '');
    if (!restRoot || !route) return null;

    const endpoint = restRoot.replace(/\/?$/, '/') + route.replace(/^\//, '');

    const stored = loadJson<string[]>(ls.change) ?? [];
    const allChange = Array.from(
      new Set([...stored, ...extraChangeTokens.map(String)]),
    ).filter(Boolean);

    const payload: any = {
      order_id: data.orderId,
      order_key: data.orderKey,
      quote_id: data.quoteId,
    };
    if (allChange.length > 0) payload.change_tokens = allChange;

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
      }

      if (json?.state === 'EXPIRED') {
        status('This payment quote has expired.');
        toastr.error('This payment quote has expired.');
        await delay(2000);
        window.location.assign(String(data.returnUrl)); // order received page
      }

      return json ?? null;
    } catch {
      return null;
    }
  }

  async function confirmUntilPaid(attempts: number, waitMs: number): Promise<void> {
    for (let i = 0; i < attempts; i++) {
      await delay(waitMs);
      toastr.info('Confirming melt - poll #' + i);
      const r = await confirmOnce();
      if (r?.state === 'PAID' || r?.state === 'EXPIRED') return;
    }
  }

  async function meltTrustedProofsToVendor(
    proofs: Proof[],
    trustedWallet: Wallet,
  ): Promise<void> {
    const amount = sumProofs(proofs);
    const fees = trustedWallet.getFeesForProofs(proofs);

    // Safety: backup proofs before melt as they may have been minted by us
    // in the QR Code or untrusted mint payment flows
    try {
      const rec = getEncodedTokenV4({ mint: data.trustedMint, proofs, unit: 'sat' });
      localStorage.setItem(ls.recovery, rec);
    } catch {
      // ignore
    }

    status('Paying invoice...');

    const w = trustedWallet;
    const quote = await w.checkMeltQuoteBolt11(data.quoteId);
    const meltRes = await w.meltProofsBolt11(quote, proofs);
    toastr.info('Melted proofs to pay invoice.');

    // Spent, so clear recovery
    try {
      localStorage.removeItem(ls.recovery);
    } catch {
      // ignore
    }

    const change = getChangeToken(meltRes, w.mint.mintUrl);
    if (change) {
      rememberChangeTokens([change]);
      toastr.info('Confirming melt + saving change!');
      void run(() => confirmOnce([change]));
    }

    status('Confirming payment...');
    void run(() => confirmUntilPaid(12, 1200));
  }

  let mintHandleP: Promise<void> | null = null;

  async function handleMintQuotePaid(mq: StoredMintQuote): Promise<void> {
    if (mintHandleP) return mintHandleP;

    mintHandleP = (async () => {
      status('Payment detected, finalising...');
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

  async function startMintQuoteWatcher(): Promise<void> {
    const mq = await ensureMintQuote();
    renderQr('lightning:' + mq.request);

    $qr.off('click').on('click', () => copyTextToClipboard(mq.request));

    const wallet = await trustedWalletP;

    // quick state check
    const q = await wallet.checkMintQuoteBolt11(mq.quote);
    if (q.state === 'PAID') {
      void run(() => handleMintQuotePaid(mq));
      return;
    }

    // wait, with fallback
    await waitMintQuotePaid(wallet, mq.quote, mq.expiry);
    void run(() => handleMintQuotePaid(mq));
  }

  async function waitMintQuotePaid(
    wallet: Wallet,
    quoteId: string,
    expiry: number | null | undefined,
  ): Promise<void> {
    const deadline = Date.now() + msUntilUnixExpiry(expiry);

    // Poll every 3s until paid or time runs out
    const poll = async () => {
      while (!ac.signal.aborted && Date.now() < deadline) {
        const q = await wallet.checkMintQuoteBolt11(quoteId);
        if (q.state === 'PAID') return;
        await delay(3000);
      }
      throw new Error('Mint quote timed out or was aborted.');
    };

    // Websocket wait, may throw
    const ws = async () => {
      const timeoutMs = Math.max(10_000, deadline - Date.now());
      await wallet.on.onceMintPaid(quoteId, { signal: ac.signal, timeoutMs });
    };

    try {
      await ws();
    } catch {
      // websocket path failed, just poll quietly
      await poll();
    }
  }

  async function startMeltPaidWatcher(): Promise<void> {
    try {
      const w = await trustedWalletP;
      const timeoutMs = msUntilUnixExpiry(data.quoteExpiry);

      await w.on.onceMeltPaid(data.quoteId, { signal: ac.signal, timeoutMs });
      status('Payment detected, finalising...');
      void run(() => confirmOnce());
    } catch {
      // ignore timeout/abort
    }
  }

  async function payFromToken(token: string): Promise<void> {
    status('Checking token...');

    let meta: TokenMetadata;
    try {
      meta = getTokenMetadata(token);
    } catch (e) {
      toastr.error(getErrorMessage(e));
      status('That token does not look valid.');
      return;
    }

    const tokenMint = String(meta.mint ?? '').trim();
    const tokenUnit = String(meta.unit ?? 'sat');
    if (!tokenMint || meta.amount === 0) {
      status('Token has no spendable proofs.');
      return;
    }
    if (tokenUnit !== 'sat') {
      status('This checkout expects sat denominated tokens.');
      return;
    }

    status('Connecting to mint...');

    const tokenWallet = await getWalletCached(tokenMint, 'sat');
    const decoded = tokenWallet.decodeToken(token);
    let proofs = decoded.proofs;

    if (!Array.isArray(proofs) || proofs.length === 0) {
      status('Token has no usable proofs.');
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

    status('Calculating your mint’s fees...');
    const utMeltQuote = await tokenWallet.createMeltQuoteBolt11(mq.request);

    const required = utMeltQuote.amount + utMeltQuote.fee_reserve + fees;
    const meltFees = utMeltQuote.fee_reserve + fees;

    if (amount < required) {
      status(
        `Token amount (${amount}) is too small. Please paste a token of at least ${required} sats to cover your mint’s fees (${meltFees}).`,
      );
      return;
    }

    status('Sending payment...');
    const utMeltRes = await tokenWallet.meltProofsBolt11(utMeltQuote, proofs);

    const change = getChangeToken(utMeltRes, tokenWallet.mint.mintUrl);
    if (change) {
      rememberChangeTokens([change]);
      void run(() => confirmOnce([change]));
    }

    // Important: do NOT sit here waiting while holding the UI lock.
    // If the mint paid it, our mint-quote watcher will continue automatically.
    status('Waiting for payment confirmation...');
  }

  // Wire UI
  lock(false);
  // status('Preparing payment...');

  $form.off('submit').on('submit', (e) => {
    e.preventDefault();
    const token = getToken();
    if (!token) {
      status('Paste a Cashu token first.');
      return;
    }
    void run(() => payFromToken(token), { user: true });
  });

  // $input.off('paste').on('paste', () => {
  //   window.setTimeout(() => {
  //     const token = getToken();
  //     void run(() => payFromToken(token), { user: true });
  //   }, 0);
  // });

  // Start async processes (don’t block UI)
  void startMintQuoteWatcher().catch(() => {
    status('Could not prepare the invoice, please refresh and try again.');
  });

  void startMeltPaidWatcher();
  void run(() => confirmOnce());
});

/**
 * Reads order data-* attributes on gateway receipt_page().
 */
function readRootData($root: JQuery<HTMLElement>): RootData {
  const orderId = Number($root.data('order-id'));
  const orderKey = String($root.data('order-key') ?? '');
  const returnUrl = String($root.data('return-url') ?? '');
  const expectedPaySats = Number($root.data('pay-amount-sats') ?? 0);
  const quoteId = String($root.data('melt-quote-id') ?? '');
  const quoteExpiry = Number($root.data('melt-quote-expiry') ?? 0);
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
    throw new Error('bad');
  }

  return {
    orderId,
    orderKey,
    returnUrl,
    expectedPaySats,
    quoteId,
    quoteExpiry,
    trustedMint,
  };
}

function msUntilUnixExpiry(expirySec: number | null | undefined): number {
  const nowSec = Math.floor(Date.now() / 1000);
  if (typeof expirySec === 'number' && expirySec > nowSec) {
    return Math.max(10_000, (expirySec - nowSec + 30) * 1000);
  }
  return 15 * 60 * 1000;
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
