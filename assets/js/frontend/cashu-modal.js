import { getDecodedToken, getEncodedTokenV4, Wallet } from '@cashu/cashu-ts';
import QRCode from 'qrcode';

class CashuModalCheckout {
  constructor({ amountSats, lightningAddress, orderId, onSuccess, onError }) {
    this.amountSats = amountSats;
    this.lightningAddress = lightningAddress;
    this.orderId = orderId;
    this.onSuccess = onSuccess;
    this.onError = onError || console.error;
    this.wallet = null;
    this.mintUrl = null;
  }

  async open() {
    this.renderModal();
  }

  renderModal() {
    const modal = document.createElement('div');
    modal.id = 'cashu-modal';
    modal.innerHTML = `
      <div class="cashu-backdrop">
        <div class="cashu-content">
          <h2>Pay with Cashu Token</h2>
          <p>Amount: <strong>${this.amountSats.toLocaleString()} sats</strong></p>
          <textarea id="cashu-token" placeholder="cashuA..."></textarea>
          <input id="cashu-privkey" type="text" placeholder="P2PK private key (if locked)" style="display:none;">
          <button id="cashu-melt" disabled>Melt Token & Pay</button>
          <div id="cashu-qr" style="margin:20px auto;"></div>
          <p id="cashu-status">Paste token to start...</p>
          <div id="cashu-change"></div>
          <button class="cashu-close">Close</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    const tokenInput = modal.querySelector('#cashu-token');
    const privkeyInput = modal.querySelector('#cashu-privkey');
    const meltBtn = modal.querySelector('#cashu-melt');
    const status = modal.querySelector('#cashu-status');

    tokenInput.addEventListener('input', () => {
      this.validateToken(tokenInput.value, status, privkeyInput, meltBtn);
    });

    meltBtn.addEventListener('click', () =>
      this.meltAndPay(tokenInput.value, privkeyInput.value, status),
    );

    modal.querySelector('.cashu-close').addEventListener('click', () => this.close());
  }

  async validateToken(token, status, privkeyInput, meltBtn) {
    status.textContent = 'Validating...';
    meltBtn.disabled = true;

    try {
      const decoded = getDecodedToken(token.trim());
      const proofs = decoded.token[0].proofs;
      const amount = proofs.reduce((sum, p) => sum + p.amount, 0);
      if (amount < this.amountSats) throw new Error('Insufficient funds');

      this.mintUrl = decoded.token[0].mint;
      this.wallet = new Wallet(this.mintUrl);
      await this.wallet.loadMint();

      const locked = proofs.some((p) => p.secret.includes('P2PK'));
      privkeyInput.style.display = locked ? 'block' : 'none';

      status.textContent = `Valid token (${amount.toLocaleString()} sats). ${locked ? 'Enter privkey.' : 'Ready!'}`;
      meltBtn.disabled = false;
    } catch (err) {
      status.textContent = err.message;
    }
  }

  async meltAndPay(token, privkey, status) {
    status.textContent = 'Getting invoice...';

    try {
      const invoice = await this.getInvoice();

      QRCode.toCanvas(document.getElementById('cashu-qr'), invoice, { width: 256 });

      status.textContent = 'Melting token...';

      const decoded = getDecodedToken(token);
      const proofs = decoded.token[0].proofs;

      const meltQuote = await this.wallet.createMeltQuoteBolt11(invoice);

      const amountToSend = meltQuote.amount + meltQuote.fee_reserve;

      const ops = this.wallet.ops.send(amountToSend, proofs).includeFees(true);

      if (privkey) {
        ops.asP2PK({ privkey });
      }

      const { send: proofsToSend } = await ops.run();

      const meltResult = await this.wallet.meltProofs(meltQuote, proofsToSend);

      if (!meltResult.paid) throw new Error('Melt failed');

      status.textContent = 'Paid!';

      if (meltResult.change.length > 0) {
        const changeToken = getEncodedTokenV4({
          mint: this.mintUrl,
          proofs: meltResult.change,
        });
        document.getElementById('cashu-change').innerHTML = `
          <p>Change token:</p>
          <textarea readonly>${changeToken}</textarea>
          <button onclick="navigator.clipboard.writeText('${changeToken}')">Copy</button>
        `;
      }

      await this.notifySuccess();
      setTimeout(() => this.close(), 3000);
    } catch (err) {
      status.textContent = 'Error: ' + err.message;
      this.onError(err);
    }
  }

  async getInvoice() {
    const res = await fetch(ajaxurl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'cashu_get_invoice',
        amount: this.amountSats,
        order_id: this.orderId,
        nonce: cashuCheckout.nonce,
      }),
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.data || 'Failed to get invoice');
    return data.data.invoice;
  }

  async notifySuccess() {
    const res = await fetch(ajaxurl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'cashu_payment_success',
        order_id: this.orderId,
        nonce: cashuCheckout.nonce,
      }),
    });
    const data = await res.json();
    if (!data.success) throw new Error('Failed to mark order paid');
  }

  close() {
    document.getElementById('cashu-modal')?.remove();
  }
}

window.CashuModalCheckout = CashuModalCheckout;
