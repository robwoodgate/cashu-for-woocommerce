import { getDecodedToken, getEncodedTokenV4, Wallet } from '@cashu/cashu-ts';
import { copyTextToClipboard, doConfettiBomb, delay, debounce } from './utils';

type CashuWindow = Window & {
	cashu_wc?: {
		rest_root?: string;
		confirm_route?: string;
		// you might later add a nonce here if your REST endpoint requires it
		// wp_nonce?: string;
	};
};

declare const window: CashuWindow;
declare const QRCode: any;

type CurrencyUnit = 'btc' | 'sat' | 'msat' | string;

type MeltQuoteBolt11 = {
	quote: string;
	amount: number;
	fee_reserve: number;
	unit: 'sat';
	expiry?: number;
	request?: string; // bolt11 invoice (some mints echo this)
};

type ConfirmPaidResponse = {
	paid?: boolean;
	success?: boolean;
	redirect_url?: string;
	message?: string;
};

type RootData = {
	orderId: number;
	orderKey: string;
	returnUrl: string;
	trustedMint: string;
	invoiceBolt11: string;
	invoiceAmountSats: number;
	feeReserveSats: number;
	expectedPaySats: number;
	quoteId: string;
	quoteExpiry: number;
};

jQuery(function ($) {
	const $root = $('#cashu-pay-root');
	const $qrcode = $('#cashu-qr');
	if ($root.length === 0) return;

	// Render QR first, even if other metadata is missing
	const bolt11Attr =
		String($root.attr('data-invoice-bolt11') ?? '').trim() ||
		String(($root as any).data('invoice-bolt11') ?? '').trim() ||
		String(($root as any).data('invoiceBolt11') ?? '').trim();

	if (bolt11Attr) {
		renderQr(bolt11Attr);

		// Add copy action
		$qrcode.on('click', () => {
			copyTextToClipboard(bolt11Attr);
		});
	} else {
		setStatus('Missing invoice, please refresh the page.');
		return;
	}

	// Now read the rest of the data for melt and confirm logic
	const data = readRootData($root);
	if (!data) {
		// Do not kill the QR, just stop the rest of the flow
		setStatus('Payment data incomplete, token payment may not work yet.');
		return;
	}

	// Wire up the rest as before...
	const $form = $('form.cashu-token');
	const $input = $('[data-cashu-token-input]');
	const $btn = $form.find('button[type="submit"]');

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

	void listenForMeltPaid(data);
	void confirmPaidAndMaybeRedirect(data);

	setStatus('Ready.');
});

function renderQr(bolt11: string): void {
	const el = document.getElementById('cashu-qr');
	if (!el) return;

	// Clear anything left behind by a previous render
	el.innerHTML = '';

	// QRCode sometimes prefers a real element, not a jQuery wrapper
	// eslint-disable-next-line no-new
	new QRCode(el, {
		text: 'bitcoin:?lightning=' + bolt11,
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
	const trustedMint = String($root.data('trusted-mint') ?? '');
	const invoiceBolt11 = String($root.data('invoice-bolt11') ?? '');
	const invoiceAmountSats = Number($root.data('invoice-amount-sats') ?? 0);
	const feeReserveSats = Number($root.data('fee-reserve-sats') ?? 0);
	const expectedPaySats = Number($root.data('pay-amount-sats') ?? 0);
	const quoteId = String($root.data('melt-quote-id') ?? '');
	const quoteExpiry = Number($root.data('melt-quote-expiry') ?? 0);

	if (
		!Number.isFinite(orderId) ||
		orderId <= 0 ||
		!orderKey ||
		!returnUrl ||
		!trustedMint ||
		!invoiceBolt11 ||
		!Number.isFinite(invoiceAmountSats) ||
		invoiceAmountSats <= 0 ||
		!Number.isFinite(feeReserveSats) ||
		feeReserveSats < 0 ||
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
		invoiceBolt11,
		invoiceAmountSats,
		feeReserveSats,
		expectedPaySats,
		quoteId,
		quoteExpiry,
	};
	// console.log('ret', ret);
	return ret;
}

/**
 * Main “paste token -> melt proofs” flow.
 *
 * Uses the docs pattern:
 * - decode token (getDecodedToken)
 * - wallet.loadMint()
 * - wallet.send(amountToSend, proofs, { includeFees: true })
 * - wallet.meltProofs(meltQuote, proofsToSend)
 */
async function startMeltFromToken(
	token: string,
	data: RootData,
	$btn: JQuery<HTMLElement>,
	$input: JQuery<HTMLElement>,
): Promise<void> {
	lockUi($btn, $input, true);
	setStatus('Checking token…');

	let decoded: ReturnType<typeof getDecodedToken>;
	try {
		decoded = getDecodedToken(token);
	} catch {
		lockUi($btn, $input, false);
		setStatus('That token does not look valid.');
		return;
	}

	const tokenMint = String((decoded as any).mint ?? '');
	const tokenUnit = String((decoded as any).unit ?? '');
	const proofs = Array.isArray((decoded as any).proofs) ? (decoded as any).proofs : [];

	if (!tokenMint || proofs.length === 0) {
		lockUi($btn, $input, false);
		setStatus('Token has no spendable proofs.');
		return;
	}

	// This checkout is built around a “trusted mint” quote, so keep it strict.
	if (!sameMint(tokenMint, data.trustedMint)) {
		lockUi($btn, $input, false);
		setStatus(
			'That token is from a different mint. Use the mint shown on this page for lowest fees.',
		);
		return;
	}

	if (tokenUnit && tokenUnit !== 'sat') {
		lockUi($btn, $input, false);
		setStatus('This checkout expects sat denominated tokens.');
		return;
	}

	setStatus('Connecting to mint…');

	let wallet: Wallet;
	try {
		wallet = await getWalletWithUnit(data.trustedMint, 'sat');
	} catch (e) {
		lockUi($btn, $input, false);
		setStatus('Could not connect to mint.');
		return;
	}

	// Build a meltQuote object from the server-provided quote context,
	// so we melt against the exact quote id stored on the order.
	const meltQuote: MeltQuoteBolt11 = {
		quote: data.quoteId,
		amount: data.invoiceAmountSats,
		fee_reserve: data.feeReserveSats,
		unit: 'sat',
		expiry: data.quoteExpiry || undefined,
		request: data.invoiceBolt11,
	};

	const amountToSend = meltQuote.amount + meltQuote.fee_reserve;

	// Guard, your PHP stores expectedPaySats = amount + fee_reserve
	if (data.expectedPaySats !== amountToSend) {
		// Not necessarily fatal, but worth being cautious.
		// If you refresh quotes client-side later, this is one place to reconcile.
	}

	try {
		setStatus('Selecting proofs…');

		const { keep: proofsToKeep, send: proofsToSend } = await wallet.send(
			amountToSend,
			proofs,
			{
				includeFees: true,
			},
		);

		setStatus('Paying invoice…');

		// meltProofs returns change, if any.
		const meltRes: any = await wallet.meltProofsBolt11(meltQuote as any, proofsToSend);

		// If there are leftovers, hand them back to the user as a new token.
		const change = Array.isArray(meltRes?.change) ? meltRes.change : [];
		const leftovers = [...proofsToKeep, ...change];

		if (leftovers.length > 0) {
			const refundToken = getEncodedTokenV4({
				mint: data.trustedMint,
				proofs: leftovers,
			});
			showRefundToken(refundToken);
		}

		// Now confirm with Woo and redirect if the server agrees it is paid.
		setStatus('Confirming payment…');
		await confirmPaidAndMaybeRedirect(data);

		// If confirm says not paid yet (rare), the melt paid listener should still catch up.
		setStatus('Waiting for confirmation…');
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
 * Listen for melt quote being marked PAID at the mint.
 * This supports the QR flow where the user pays in their own wallet.
 *
 * Uses WalletEvents one-shot helper from your docs:
 * wallet.on.onceMeltPaid(id, { timeoutMs, signal })
 */
async function listenForMeltPaid(data: RootData): Promise<void> {
	const ac = new AbortController();

	// Clean up when leaving the page
	window.addEventListener('pagehide', () => ac.abort(), { once: true });
	window.addEventListener('beforeunload', () => ac.abort(), { once: true });

	try {
		const wallet = await getWalletWithUnit(data.trustedMint, 'sat');

		// If quote expiry is known, set timeout a bit past it, otherwise a sensible default.
		const nowSec = Math.floor(Date.now() / 1000);
		const msUntilExpiry =
			data.quoteExpiry && data.quoteExpiry > nowSec
				? (data.quoteExpiry - nowSec + 30) * 1000
				: 5 * 60 * 1000;

		// Wait for PAID
		await wallet.on.onceMeltPaid(data.quoteId, {
			signal: ac.signal,
			timeoutMs: Math.max(10_000, msUntilExpiry),
		});

		// Once paid, confirm with Woo, then redirect.
		setStatus('Payment detected, finalising…');
		await confirmPaidAndMaybeRedirect(data);
	} catch (e) {
		// Timeout or abort is fine, user may refresh or re-try.
		// You could optionally restart the listener if you refresh quotes.
	}
}

/**
 * Calls your REST endpoint to confirm the quote has been paid for this order.
 * If paid, redirects to the order return URL (or a redirect_url if your endpoint returns one).
 */
async function confirmPaidAndMaybeRedirect(data: RootData): Promise<void> {
	const restRoot = String(window.cashu_wc?.rest_root ?? '');
	const route = String(window.cashu_wc?.confirm_route ?? '');

	if (!restRoot || !route) {
		// If localisation is missing, fall back to return URL only when you have another signal.
		return;
	}

	const endpoint = restRoot.replace(/\/?$/, '/') + route.replace(/^\//, '');

	const payload = {
		order_id: data.orderId,
		order_key: data.orderKey,
		quote_id: data.quoteId,
	};

	let res: Response;
	try {
		res = await fetch(endpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				// If your REST route is protected by WP nonce, add it here:
				// 'X-WP-Nonce': String(window.cashu_wc?.wp_nonce ?? ''),
			},
			credentials: 'same-origin',
			body: JSON.stringify(payload),
		});
	} catch {
		return;
	}

	if (!res.ok) return;

	let json: ConfirmPaidResponse | null = null;
	try {
		json = (await res.json()) as ConfirmPaidResponse;
	} catch {
		json = null;
	}

	const paid = Boolean(json?.paid ?? json?.success);
	if (!paid) return;

	doConfettiBomb();
	const redirectUrl = String(json?.redirect_url ?? data.returnUrl);
	if (redirectUrl) {
		window.location.assign(redirectUrl);
	}
}

/**
 * Instantiates a Cashu wallet for a specified mint and unit, per docs.
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
 * Very lightweight mint URL comparison.
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
