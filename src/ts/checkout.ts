import {
  getDecodedToken,
  getEncodedTokenV4,
  getTokenMetadata,
  Wallet,
  Token,
  sumProofs,
  TokenMetadata,
  Proof,
  MeltProofsResponse,
  MeltQuoteBolt11Response,
  MintQuoteBaseResponse,
  MeltQuoteBaseResponse,
  MeltQuoteState,
  MintQuoteBolt11Response,
} from '@cashu/cashu-ts';
import {
  copyTextToClipboard,
  doConfettiBomb,
  delay,
  debounce,
  getErrorMessage,
} from './utils';
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
  state?: MeltQuoteState & 'EXPIRED';
  redirect?: string;
  message?: string;
  expiry?: number | null;
};

type RootData = {
  orderId: number;
  orderKey: string;
  returnUrl: string;
  expectedPaySats: number;
  quoteId: string;
  quoteExpiry: number;
  trustedMint: string;
};

// Clean up when leaving the page
const ac = new AbortController();
window.addEventListener('pagehide', () => ac.abort(), { once: true });
window.addEventListener('beforeunload', () => ac.abort(), { once: true });

// Trusted Mint Quote
let mintQuote: MintQuoteBolt11Response | null = null;

jQuery(async function ($) {
  const $root = $('#cashu-pay-root');
  const $qrcode = $('#cashu-qr');
  if ($root.length === 0) return;

  // Read the order data
  const data = readRootData($root);
  if (!data) {
    setStatus('Payment data incomplete, token payment may not work yet.');
    return;
  }

  // Get mint quote and create QR code
  const wallet = await getWalletWithUnit(data.trustedMint, 'sat');
  mintQuote = await wallet.createMintQuoteBolt11(data.expectedPaySats);
  renderQr();

  // Wire up the UI
  const $form = $('form.cashu-token');
  const $input = $('[data-cashu-token-input]');
  const $btn = $form.find('button[type="submit"]');

  // Add copy action
  $qrcode.on('click', () => {
    copyTextToClipboard(mintQuote!.request);
  });

  $input.on('paste', () => {
    window.setTimeout(() => {
      const token = String(($input as any).val() ?? '').trim();
      if (token) void startMeltFromToken(token, data, $btn, $input);
    }, 0);
  });

  $form.on('submit', (e) => {
    e.preventDefault();
    const token = String(($input as any).val() ?? '').trim();
    if (!token) {
      setStatus('Paste a Cashu token first.');
      return;
    }
    void startMeltFromToken(token, data, $btn, $input);
  });

  void listenForMintPaid(data, $btn, $input);
  void listenForMeltPaid(data);
  void confirmPaidAndMaybeRedirect(data);
});

function renderQr(): void {
  const el = document.getElementById('cashu-qr');
  if (!el || !mintQuote) return;

  // Clear anything left behind by a previous render
  el.innerHTML = '';

  // QRCode sometimes prefers a real element, not a jQuery wrapper
  // eslint-disable-next-line no-new
  new QRCode(el, {
    text: 'lightning:' + mintQuote.request,
    width: 360,
    height: 360,
    colorDark: '#000000',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.Q,
  });
}

/**
 * Reads all data-* attributes you output in receipt_page().
 */
function readRootData($root: JQuery<HTMLElement>): RootData | null {
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
    return null;
  }

  const ret = {
    orderId,
    orderKey,
    returnUrl,
    trustedMint,
    expectedPaySats,
    quoteId,
    quoteExpiry,
  };
  // console.log('ret', ret);
  return ret;
}

/**
 * Main “paste token -> melt proofs” flow.
 */
async function startMeltFromToken(
  token: string,
  data: RootData,
  $btn: JQuery<HTMLElement>,
  $input: JQuery<HTMLElement>,
): Promise<void> {
  lockUi($btn, $input, true);
  setStatus('Checking token...');

  // Validate and get token mint
  const tokenMint = getTokenMint(token, $btn, $input);
  if (!tokenMint) {
    return;
  }

  setStatus('Connecting to mint...');

  let tokenWallet: Wallet;
  let trustedWallet: Wallet;
  let proofs: Proof[];
  try {
    // Instantiate wallet and properly decode token proofs
    trustedWallet = await getWalletWithUnit(data.trustedMint, 'sat');
    tokenWallet = await getWalletWithUnit(tokenMint, 'sat');
    const decoded = tokenWallet.decodeToken(token);
    proofs = decoded.proofs;
  } catch (e) {
    lockUi($btn, $input, false);
    setStatus('Could not connect to mint.');
    return;
  }

  try {
    let fees = tokenWallet.getFeesForProofs(proofs);
    let amount = sumProofs(proofs);
    let change: string[] = [];
    mintQuote =
      mintQuote ?? (await trustedWallet.createMintQuoteBolt11(data.expectedPaySats));

    // if proofs are from another mint, create a mint quote at our trusted mint
    // and melt quote at the untrusted mint to pay for the trusted proofs.
    if (!sameMint(tokenMint, data.trustedMint)) {
      setStatus(`Calculating melt fees for ${tokenMint} token...`);
      const utMeltQuote = await tokenWallet.createMeltQuoteBolt11(mintQuote.request);
      const required = utMeltQuote.amount + utMeltQuote.fee_reserve + fees;
      const meltFees = utMeltQuote.fee_reserve + fees;
      if (amount < required) {
        lockUi($btn, $input, false);
        setStatus(
          `Token amount (${amount}) is too small. Please paste a token of at least ${required} to cover your mint's fees: ${meltFees}`,
        );
        return;
      }

      // Execute the melt of untrusted proofs and get trusted ones.
      setStatus('Melting token...');
      const utMeltRes = await tokenWallet.meltProofsBolt11(utMeltQuote, proofs);
      await untilMintQuotePaid(trustedWallet, mintQuote);
      setStatus('Paying invoice...');
      proofs = await trustedWallet.mintProofsBolt11(data.expectedPaySats, mintQuote);
      fees = trustedWallet.getFeesForProofs(proofs);
      amount = sumProofs(proofs);
      change.push(getChangeToken(utMeltRes, tokenWallet.mint.mintUrl));
      $input.val(change[0]);
    }

    // Check we have enough to pay our trusted mint's melt invoice.
    if (amount - fees < data.expectedPaySats) {
      lockUi($btn, $input, false);
      setStatus(
        `Token amount (${amount}) is too small after your mint's fees: ${fees}. Please paste a larger token.`,
      );
      return;
    }

    // Melt proofs to pay vendor invoice
    setStatus('Paying invoice...');
    const quote = await trustedWallet.checkMeltQuoteBolt11(data.quoteId);
    const meltRes = await trustedWallet.meltProofsBolt11(quote, proofs);

    // If there is change, hand them back to the user as a new token.
    change.push(getChangeToken(meltRes, trustedWallet.mint.mintUrl));

    // Now confirm with Woo and redirect if the server agrees it is paid.
    setStatus('Confirming payment...');
    await confirmPaidAndMaybeRedirect(data, change.join(', '));

    // If confirm says not paid yet (rare), the melt paid listener should still catch up.
    setStatus('Waiting for confirmation...');
  } catch (e: any) {
    lockUi($btn, $input, false);

    const msg = (e as Error)?.message ? String((e as Error).message) : '';
    if (msg) {
      setStatus('Payment failed, ' + msg);
      return;
    }
    setStatus('Payment failed.');
  }
}

/**
 * Validates a token and returns the token mint
 * @param  token  Token
 * @param  $btn   Button element
 * @param  $input Input element
 * @return Token mint url or null
 */
function getTokenMint(
  token: string,
  $btn: JQuery<HTMLElement>,
  $input: JQuery<HTMLElement>,
): string | null {
  lockUi($btn, $input, true);
  setStatus('Checking token...');

  // Get token metadata
  let meta: TokenMetadata;
  try {
    meta = getTokenMetadata(token);
  } catch (e) {
    const message = getErrorMessage(e);
    console.error(message);
    toastr.error(message);
    lockUi($btn, $input, false);
    setStatus('That token does not look valid.');
    return null;
  }

  // Validate metadata
  const tokenMint = meta.mint;
  const tokenUnit = meta.unit ?? 'sat';
  if (!tokenMint || meta.amount === 0) {
    lockUi($btn, $input, false);
    setStatus('Token has no spendable proofs.');
    return null;
  }
  if (tokenUnit !== 'sat') {
    lockUi($btn, $input, false);
    setStatus('This checkout expects sat denominated tokens.');
    return null;
  }

  // Seems ok
  return tokenMint;
}

/**
 * Returns a token from melt response change
 * @param  meltResponse The melt response from mint
 * @param  mintUrl      The mint URL
 * @return THe token, or an empty string.
 */
function getChangeToken(meltResponse: MeltProofsResponse, mintUrl: string): string {
  const change = Array.isArray(meltResponse?.change) ? meltResponse.change : [];
  let changeToken = '';
  if (change.length > 0) {
    changeToken = getEncodedTokenV4({
      mint: mintUrl,
      proofs: change,
      unit: 'sat',
    });
  }
  return changeToken;
}

/**
 * Listen for melt quote being marked PAID at the mint.
 * This supports the QR flow where the user pays in their own wallet.
 */
async function listenForMintPaid(data: RootData, $btn, $input): Promise<void> {
  try {
    const wallet = await getWalletWithUnit(data.trustedMint, 'sat');
    if (!mintQuote) return;

    // Wait for PAID
    await wallet.on.onceMintPaid(mintQuote.quote, {
      signal: ac.signal,
      timeoutMs: Math.max(900_000, mintQuote.expiry),
    });

    // Once paid, start melt.
    setStatus('Payment detected, finalising...');
    const proofs = await wallet.mintProofsBolt11(data.expectedPaySats, mintQuote);
    const token = getEncodedTokenV4({
      mint: data.trustedMint,
      proofs,
      unit: 'sat',
    });
    await startMeltFromToken(token, data, $btn, $input);
  } catch (e) {
    // Timeout or abort is fine, user may refresh or re-try.
    // You could optionally restart the listener if you refresh quotes.
  }
}

/**
 * Listen for melt quote being marked PAID at the mint.
 * This supports the QR flow where the user pays in their own wallet.
 */
async function listenForMeltPaid(data: RootData): Promise<void> {
  try {
    const wallet = await getWalletWithUnit(data.trustedMint, 'sat');

    // If quote expiry is known, set timeout a bit past it, otherwise a sensible default.
    const nowSec = Math.floor(Date.now() / 1000);
    const msUntil =
      data.quoteExpiry && data.quoteExpiry > nowSec
        ? (data.quoteExpiry - nowSec + 30) * 1000
        : 15 * 60 * 1000;

    // Wait for PAID
    await wallet.on.onceMeltPaid(data.quoteId, {
      signal: ac.signal,
      timeoutMs: msUntil,
    });

    // Once paid, confirm with Woo, then redirect.
    setStatus('Payment detected, finalising...');
    await confirmPaidAndMaybeRedirect(data);
  } catch (e) {
    // Timeout or abort is fine, user may refresh or re-try.
    // You could optionally restart the listener if you refresh quotes.
  }
}

// Helper to wait until mint quote is paid
async function untilMintQuotePaid(wallet: Wallet, quote: MintQuoteBaseResponse) {
  try {
    await wallet.on.onceMintPaid(quote.quote, {
      signal: ac.signal,
      timeoutMs: 900_000,
    });
  } catch {
    toastr.error(`Mint quote not paid in time or aborted: ${quote.quote}`);
  }
}

/**
 * Calls your REST endpoint to confirm the quote has been paid for this order.
 * If paid, redirects to the order return URL (or a redirect_url if your endpoint returns one).
 */
async function confirmPaidAndMaybeRedirect(
  data: RootData,
  changeToken: string = '',
): Promise<void> {
  const restRoot = String(window.cashu_wc?.rest_root ?? '');
  const route = String(window.cashu_wc?.confirm_route ?? '');

  if (!restRoot || !route) {
    // If localisation is missing, fall back to return URL only when you have another signal.
    toastr.error('restRoot not set properly!');
    return;
  }

  const endpoint = restRoot.replace(/\/?$/, '/') + route.replace(/^\//, '');

  const payload = {
    order_id: data.orderId,
    order_key: data.orderKey,
    quote_id: data.quoteId,
    change_token: changeToken,
  };

  let res: Response;
  try {
    res = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        // 'X-WP-Nonce': String(window.cashu_wc?.nonce_confirm ?? ''),
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });
  } catch {
    return;
  }

  let json: ConfirmPaidResponse | null = null;
  try {
    json = (await res.json()) as ConfirmPaidResponse;
  } catch {
    return;
  }

  if (!json || json.state !== 'PAID') return;

  doConfettiBomb();
  await delay(2000);
  const redirectUrl = String(json?.redirect ?? data.returnUrl);
  if (redirectUrl) {
    window.location.assign(redirectUrl);
  }
}

/**
 * Instantiates a Cashu wallet for a specified mint and unit
 */
async function getWalletWithUnit(
  mintUrl: string,
  unit: CurrencyUnit = 'sat',
): Promise<Wallet> {
  const wallet = new Wallet(mintUrl, { unit });
  await wallet.loadMint();
  return wallet;
}

/**
 * Lightweight mint URL comparison.
 * Strips training slashes.
 */
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
 * Temporary status UI, replace with whatever you prefer.
 */
function setStatus(msg: string): void {
  jQuery('.cashu-foot').text(msg);
}

function lockUi(
  $btn: JQuery<HTMLElement>,
  $input: JQuery<HTMLElement>,
  locked: boolean,
): void {
  $btn.prop('disabled', locked);
  $input.prop('disabled', locked);
}

/**
 * Optional, show a refund token if keep/change proofs exist.
 * You can style this properly later, this is just a sketch.
 */
function showRefundToken(token: string): void {
  const $existing = jQuery('#cashu-refund-token');
  if ($existing.length) {
    $existing.text(token);
    return;
  }

  const $el = jQuery(
    `<div class="cashu-refund">
				<p class="cashu-refund-title">Leftover Token (Copy This)</p>
				<textarea id="cashu-refund-token" class="cashu-refund-box" readonly></textarea>
		 </div>`,
  );

  $el.find('#cashu-refund-token').text(token);

  // Append beneath the form
  jQuery('form.cashu-token').after($el);
}
