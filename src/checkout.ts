import { getDecodedToken, getEncodedTokenV4, Wallet } from '@cashu/cashu-ts';
import { decode } from '@gandlaf21/bolt11-decode';

type CashuWindow = Window & {
  cashu_wc?: {
    ajax_url: string;
    nonce_invoice: string;
    nonce_confirm: string;
    lightning_address: string;
    order_id: number | null;
  };
};

declare const window: CashuWindow;

async function payWithCashuToken(token: string) {
  setStatus('Decoding token…');

  const decoded = getDecodedToken(token);
  const mintUrl = decoded.mint;
  const unit = decoded.unit ?? 'sat';
  let proofs = decoded.proofs ?? [];

  // Reject P2PK locked proofs for now
  if (proofs.some((p) => typeof p.secret === 'string' && p.secret.includes('P2PK'))) {
    throw new Error(
      'This token is locked. Please unlock it in your wallet, then try again.',
    );
  }

  setStatus('Connecting to mint…');
  const wallet = new Wallet(mintUrl, { unit });
  await wallet.loadMint();

  setStatus('Checking token state…');
  const { unspent } = await wallet.groupProofsByState(proofs);
  if (!unspent.length) throw new Error('Token already spent.');
  if (unspent.length !== proofs.length) {
    proofs = unspent;
    const refreshed = getEncodedTokenV4({ mint: mintUrl, unit, proofs });
    tokenInput.value = refreshed;
    setStatus('Partially spent token detected, updated token applied.');
  }

  setStatus('Requesting order invoice…');
  const invoice = await generateInvoiceForOrder(); // server decides amount

  setStatus('Creating melt quote…');
  const meltQuote = await wallet.createMeltQuoteBolt11(invoice);
  const amountToSend = meltQuote.amount + meltQuote.fee_reserve;

  setStatus('Preparing proofs…');
  const { keep, send } = await wallet.send(amountToSend, proofs, { includeFees: true });

  setStatus('Paying invoice…');
  const meltRes = await wallet.meltProofsBolt11(meltQuote, send);

  const changeProofs = [...keep, ...(meltRes.change ?? [])];
  const changeToken = changeProofs.length
    ? getEncodedTokenV4({ mint: mintUrl, unit, proofs: changeProofs })
    : '';

  // Save local fallback immediately
  if (changeToken) localStorage.setItem(`cashu_change_${getOrderId()}`, changeToken);

  setStatus('Confirming order…');
  await confirmPayment({ changeToken }); // server verifies invoice paid, then stores _cashu_change

  setStatus('Paid, thank you.');
}

// jQuery(function ($) {
//   //
// });

let wallet: Wallet | null = null;
let proofs: any[] = [];
let tokenAmount = 0;
let unit = 'sat';
let mintUrl = '';

const tokenInput = document.getElementById(
  'cashu-token-input',
) as HTMLTextAreaElement | null;
const statusEl = document.getElementById('cashu-status') as HTMLDivElement | null;

if (tokenInput && statusEl && window.cashu_wc) {
  const setStatus = (msg: string) => {
    statusEl.textContent = msg;
  };

  /**
   * Extract amount in sats from a BOLT11 invoice.
   */
  const getSatsAmount = (lnInvoice: string): number => {
    const decoded = decode(lnInvoice);
    const amountSection = decoded.sections.find((section) => section.name === 'amount');
    if (!amountSection || !amountSection.value) {
      throw new Error('Amount not found in lightning invoice');
    }
    const millisats = parseInt(amountSection.value, 10);
    return Math.floor(millisats / 1000);
  };

  const getOrderId = (): number => {
    if (typeof window?.cashu_wc?.order_id === 'number' && window.cashu_wc.order_id > 0) {
      return window.cashu_wc.order_id;
    }
    const params = new URLSearchParams(window.location.search);
    const fromQuery = Number(params.get('order-pay') || 0);
    return fromQuery || 0;
  };

  const generateInvoice = async (sats: number): Promise<string> => {
    const orderId = getOrderId();
    if (!orderId) {
      throw new Error('No order ID found');
    }

    const res = await fetch(window.cashu_wc!.ajax_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'cashu_generate_invoice',
        amount: sats.toString(),
        order_id: orderId.toString(),
        nonce: window.cashu_wc!.nonce_invoice,
      }),
    });

    const json = await res.json();
    if (!json.success) {
      throw new Error(json.data || 'Invoice failed');
    }
    return json.data.invoice as string;
  };

  const confirmPayment = async (): Promise<void> => {
    const orderId = getOrderId();
    if (!orderId) {
      throw new Error('No order ID for confirmation');
    }

    const res = await fetch(window.cashu_wc!.ajax_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'cashu_confirm_payment',
        order_id: orderId.toString(),
        nonce: window.cashu_wc!.nonce_confirm,
      }),
    });

    const json = await res.json();
    if (json.success) {
      setStatus('Payment confirmed, order complete.');
      setTimeout(() => window.location.reload(), 3000);
    } else {
      setStatus('Payment failed to confirm.');
    }
  };

  const meltToken = async (token: string) => {
    try {
      setStatus('Decoding token…');

      const decoded = getDecodedToken(token);
      proofs = decoded.proofs || [];
      tokenAmount = proofs.reduce((sum, p) => sum + (p.amount || 0), 0);
      unit = (decoded.unit as string) || 'sat';
      mintUrl = decoded.mint as string;

      if (!proofs.length || tokenAmount <= 0) {
        throw new Error('Token contains no spendable proofs');
      }

      setStatus(`Token value, ${tokenAmount} ${unit}, initialising wallet…`);

      wallet = new Wallet(mintUrl);
      await wallet.loadMint();

      setStatus('Requesting lightning invoice…');
      const invoice = await generateInvoice(tokenAmount);

      setStatus('Creating melt quote…');
      const meltQuote = await wallet.createMeltQuoteBolt11(invoice);

      // Fee estimate as in your sample, but without trying to outsmart the invoice,
      // the wallet and melt will error if funds are genuinely insufficient.
      let estFeeSats = Math.ceil(Math.max(2, tokenAmount * 0.02));
      let estInvSats = tokenAmount - estFeeSats;

      if (unit !== 'sat') {
        const mintQuote = await wallet.createMintQuoteBolt11(tokenAmount);
        const sats = getSatsAmount(mintQuote.request);
        estFeeSats = Math.ceil(Math.max(2, sats * 0.02));
        estInvSats = sats - estFeeSats;
      }

      estInvSats -= wallet.getFeesForProofs(proofs);
      if (estInvSats <= 0) {
        throw new Error('Token too low once fees are included');
      }

      setStatus(`Sending ${meltQuote.amount} ${unit} plus fee reserve…`);

      const meltResponse = await wallet.meltProofsBolt11(meltQuote, proofs);

      if (!meltResponse.quote) {
        throw new Error('Melt failed');
      }

      setStatus('Payment successful.');

      if (meltResponse.change && meltResponse.change.length > 0) {
        setStatus('Preparing change token…');
        const newToken = getEncodedTokenV4({
          mint: mintUrl,
          unit,
          proofs: meltResponse.change,
        });
        await navigator.clipboard.writeText(newToken);
        setTimeout(() => setStatus('Change token copied to clipboard.'), 2000);
      }

      await confirmPayment();
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      console.error(msg);
      setStatus('Error, ' + msg);
    }
  };

  tokenInput.addEventListener('paste', (e: ClipboardEvent) => {
    const text = e.clipboardData?.getData('text') || '';
    if (text.startsWith('cashuB')) {
      e.preventDefault();
      const trimmed = text.trim();
      tokenInput.value = trimmed;
      setTimeout(() => {
        void meltToken(trimmed);
      }, 300);
    }
  });

  tokenInput.addEventListener('input', () => {
    const val = tokenInput.value.trim();
    if (val.startsWith('cashuB') && val.length > 100) {
      setStatus('Token looks valid, press Enter to pay or paste it again.');
    }
  });

  tokenInput.addEventListener('keydown', (e: KeyboardEvent) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const val = tokenInput.value.trim();
      if (val.startsWith('cashuB')) {
        void meltToken(val);
      }
    }
  });
}

//=======

import {
  Wallet,
  MintQuoteState,
  getDecodedToken,
  getEncodedTokenV4,
  Proof,
  ConsoleLogger,
  LogLevel,
} from '@cashu/cashu-ts';

declare const cashu_wc: {
  restBase: string; // e.g. "/wp-json/cashu-wc/v1"
  orderId: number;
  orderKey: string;
  expectedAmountSats: number;
  unit: 'sat';
  trustedMintUrl: string;

  // policy knobs, keep aligned with your Numo style logic
  maxFeeReserveRatio: number; // 0.05
  minFeeOverheadRatio: number; // 0.005

  // optional
  debug?: boolean;
  quoteTimeoutMs?: number; // e.g. 120000
};

type MeltQuoteBolt11 = {
  quote: string;
  request: string; // bolt11
  amount: number;
  unit: string;
  fee_reserve: number;
  state?: string;
  expiry?: number;
  payment_preimage?: string | null;
};

type SwapFrame = {
  unknownMintUrl: string;
  receivedAmountFromUnknownMint: number;
  maxFeeReserveRatio: number;
  minFeeOverheadRatio: number;
  feeReserveEstimate: number;
  minOverhead: number;
  initialLightningAmount: number;
  finalLightningAmount: number;
  trustedMintQuoteIdInitial: string;
  trustedMintQuoteIdFinal: string;
  unknownMeltQuoteIdInitial: string;
  unknownMeltQuoteIdFinal: string;
  startedAt: number;
  finishedAt?: number;
};

const $ = (window as any).jQuery as JQueryStatic;

const qs = <T extends HTMLElement>(sel: string) =>
  document.querySelector(sel) as T | null;

const setStatus = (msg: string) => {
  const el = qs<HTMLDivElement>('#cashu_status');
  if (el) el.textContent = msg;
};

const setInvoice = (bolt11: string) => {
  const el = qs<HTMLTextAreaElement>('#cashu_invoice');
  if (el) el.value = bolt11;
};

const postJson = async <T>(path: string, body: unknown): Promise<T> => {
  const res = await fetch(`${cashu_wc.restBase}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    credentials: 'same-origin',
  });
  const json = await res.json();
  if (!res.ok || (json && json.ok === false)) {
    const msg = json?.message || `HTTP ${res.status}`;
    throw new Error(msg);
  }
  return json as T;
};

const sumProofs = (proofs: Proof[]) =>
  proofs.reduce((acc, p) => acc + (p.amount ?? 0), 0);

(async function init() {
  const logger = cashu_wc.debug ? new ConsoleLogger('debug' as LogLevel) : undefined;

  const trustedWallet = new Wallet(
    cashu_wc.trustedMintUrl,
    logger ? { logger } : undefined,
  );
  await trustedWallet.loadMint();

  let proofs: Proof[] = [];
  let mintQuoteId = '';
  let mintInvoice = '';
  let mintedAmountSats = 0;
  let changeToken = '';
  let changeAmountSats = 0;
  let swapFrame: SwapFrame | null = null;

  const mintFromLightning = async () => {
    setStatus('Creating Lightning invoice...');
    const mq = await trustedWallet.createMintQuoteBolt11(cashu_wc.expectedAmountSats);
    mintQuoteId = mq.quote;
    mintInvoice = mq.request;

    setInvoice(mintInvoice);
    setStatus('Waiting for payment...');

    const timeoutMs = cashu_wc.quoteTimeoutMs ?? 120_000;
    const paid = await trustedWallet.on.onceMintPaid(mq.quote, { timeoutMs });

    if (paid.state !== MintQuoteState.PAID && paid.state !== MintQuoteState.ISSUED) {
      throw new Error(`Mint quote not paid, state=${paid.state}`);
    }

    setStatus('Minting proofs...');
    proofs = await trustedWallet.mintProofs(cashu_wc.expectedAmountSats, mq.quote);
    mintedAmountSats = sumProofs(proofs);

    setStatus(
      `Received ${mintedAmountSats} sats on trusted mint, preparing settlement...`,
    );
  };

  const swapUnknownTokenToTrusted = async (tokenStr: string) => {
    const decoded = getDecodedToken(tokenStr);
    if (!decoded.mint || !decoded.proofs?.length) throw new Error('Invalid token');

    const unknownMintUrl = decoded.mint;
    const unknownProofs = decoded.proofs as Proof[];
    const receivedAmount = sumProofs(unknownProofs);

    swapFrame = {
      unknownMintUrl,
      receivedAmountFromUnknownMint: receivedAmount,
      maxFeeReserveRatio: cashu_wc.maxFeeReserveRatio,
      minFeeOverheadRatio: cashu_wc.minFeeOverheadRatio,
      feeReserveEstimate: 0,
      minOverhead: 0,
      initialLightningAmount: 0,
      finalLightningAmount: 0,
      trustedMintQuoteIdInitial: '',
      trustedMintQuoteIdFinal: '',
      unknownMeltQuoteIdInitial: '',
      unknownMeltQuoteIdFinal: '',
      startedAt: Math.floor(Date.now() / 1000),
    };

    const unknownWallet = new Wallet(unknownMintUrl, logger ? { logger } : undefined);
    await unknownWallet.loadMint();

    // 1) initial buffer and initial trusted quote, used to estimate fee reserve
    const feeBuffer = Math.ceil(receivedAmount * cashu_wc.maxFeeReserveRatio);
    let lightningAmount = receivedAmount - feeBuffer;
    if (lightningAmount <= 0) throw new Error('Amount too small after fee buffer');

    swapFrame.initialLightningAmount = lightningAmount;

    const initialTrustedQuote =
      await trustedWallet.createMintQuoteBolt11(lightningAmount);
    swapFrame.trustedMintQuoteIdInitial = initialTrustedQuote.quote;

    const initialUnknownMeltQuote = await unknownWallet.createMeltQuoteBolt11(
      initialTrustedQuote.request,
    );
    swapFrame.unknownMeltQuoteIdInitial = initialUnknownMeltQuote.quote;

    const feeReserveEstimate = initialUnknownMeltQuote.fee_reserve;
    swapFrame.feeReserveEstimate = feeReserveEstimate;

    if (feeReserveEstimate > feeBuffer) {
      throw new Error(
        `Unknown mint fee reserve too high, ${feeReserveEstimate} > ${feeBuffer}`,
      );
    }

    // 2) final trusted quote amount
    const minOverhead = Math.ceil(receivedAmount * cashu_wc.minFeeOverheadRatio);
    swapFrame.minOverhead = minOverhead;

    lightningAmount = receivedAmount - minOverhead - feeReserveEstimate;
    if (lightningAmount <= 0)
      throw new Error('Amount too small after overhead and fee reserve');

    swapFrame.finalLightningAmount = lightningAmount;

    const finalTrustedQuote = await trustedWallet.createMintQuoteBolt11(lightningAmount);
    swapFrame.trustedMintQuoteIdFinal = finalTrustedQuote.quote;

    const finalUnknownMeltQuote = await unknownWallet.createMeltQuoteBolt11(
      finalTrustedQuote.request,
    );
    swapFrame.unknownMeltQuoteIdFinal = finalUnknownMeltQuote.quote;

    // 3) melt all unknown proofs, let change come back if any
    setStatus('Swapping token from unknown mint...');
    const meltRes = await unknownWallet.meltProofsBolt11(
      finalUnknownMeltQuote,
      unknownProofs,
    );

    if (!meltRes.quote || meltRes.quote.state !== 'PAID') {
      throw new Error(`Unknown mint melt not paid, state=${meltRes.quote?.state}`);
    }

    // 4) wait for trusted quote to be paid, then mint proofs
    await trustedWallet.on.onceMintPaid(finalTrustedQuote.quote, {
      timeoutMs: cashu_wc.quoteTimeoutMs ?? 120_000,
    });
    proofs = await trustedWallet.mintProofs(lightningAmount, finalTrustedQuote.quote);
    mintedAmountSats = sumProofs(proofs);

    swapFrame.finishedAt = Math.floor(Date.now() / 1000);

    // record mint quote info for persistence later
    mintQuoteId = finalTrustedQuote.quote;
    mintInvoice = finalTrustedQuote.request;
  };

  const receiveTrustedToken = async (tokenStr: string) => {
    setStatus('Receiving token...');
    proofs = await trustedWallet.receive(tokenStr);
    mintedAmountSats = sumProofs(proofs);
    setStatus(
      `Received ${mintedAmountSats} sats on trusted mint, preparing settlement...`,
    );
  };

  const settleToVendor = async () => {
    setStatus('Requesting melt quote from server...');
    const mqRes = await postJson<{ ok: true; melt_quote: MeltQuoteBolt11 }>(
      '/melt-quote',
      {
        order_id: cashu_wc.orderId,
        order_key: cashu_wc.orderKey,
        unit: cashu_wc.unit,
        amount_sats: cashu_wc.expectedAmountSats,
      },
    );

    const meltQuote = mqRes.melt_quote;
    const required = meltQuote.amount + meltQuote.fee_reserve;

    if (sumProofs(proofs) < required) {
      throw new Error(
        `Insufficient proofs to melt, need ${required}, have ${sumProofs(proofs)}`,
      );
    }

    setStatus('Melting proofs to vendor invoice...');
    const meltRes = await trustedWallet.ops.meltBolt11(meltQuote as any, proofs).run();

    // meltRes.change are change proofs (NUT-08), encode and persist
    if (meltRes.change?.length) {
      changeAmountSats = sumProofs(meltRes.change);
      changeToken = getEncodedTokenV4({
        mint: cashu_wc.trustedMintUrl,
        unit: cashu_wc.unit,
        proofs: meltRes.change,
      });
    }

    setStatus('Confirming settlement with server...');
    const confirm = await postJson<{ ok: true; state: string; redirect: string }>(
      '/confirm-melt',
      {
        order_id: cashu_wc.orderId,
        order_key: cashu_wc.orderKey,
        mint_url: cashu_wc.trustedMintUrl,
        melt_quote_id: meltRes.quote.quote,
        mint_quote_id: mintQuoteId,
        mint_invoice: mintInvoice,
        minted_amount_sats: mintedAmountSats,
        change_token: changeToken || null,
        change_amount_sats: changeAmountSats || 0,
        swap_frame: swapFrame ? JSON.stringify(swapFrame) : null,
      },
    );

    if (confirm.redirect) {
      window.location.href = confirm.redirect;
    }
  };

  // UI wiring
  $('#cashu_ln_btn').on('click', async (e) => {
    e.preventDefault();
    try {
      await mintFromLightning();
      await settleToVendor();
    } catch (err: any) {
      setStatus(`Failed, ${err?.message || String(err)}`);
    }
  });

  $('#cashu_token_btn').on('click', async (e) => {
    e.preventDefault();
    try {
      const tokenStr = String(
        qs<HTMLInputElement>('#cashu_token_input')?.value || '',
      ).trim();
      if (!tokenStr) throw new Error('Paste a Cashu token first');

      const decoded = getDecodedToken(tokenStr);
      if (decoded.mint === cashu_wc.trustedMintUrl) {
        await receiveTrustedToken(tokenStr);
      } else {
        await swapUnknownTokenToTrusted(tokenStr);
      }

      await settleToVendor();
    } catch (err: any) {
      setStatus(`Failed, ${err?.message || String(err)}`);
    }
  });

  setStatus('Ready.');
})().catch((e) => {
  console.error(e);
  setStatus(`Init failed, ${(e as Error).message}`);
});
