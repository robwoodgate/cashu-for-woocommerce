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
