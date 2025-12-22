import { getDecodedToken, getEncodedTokenV4, Wallet } from '@cashu/cashu-ts';
import confetti from 'canvas-confetti';
import toastr from 'toastr';

/**
 * Copies text to clipboard, with fallback for localhost operation
 * @param {string} text Text to copy
 */
export function copyTextToClipboard(text: string) {
  if (!navigator.clipboard) {
    fallbackCopyTextToClipboard(text);
    return;
  }
  navigator.clipboard.writeText(text).then(
    function () {
      toastr.info('copied!');
    },
    function (err) {
      console.error('Async: Could not copy text: ', err);
    },
  );
}

function fallbackCopyTextToClipboard(text: string) {
  var textArea = document.createElement('textarea');
  textArea.value = text;

  // Avoid scrolling to bottom
  textArea.style.top = '0';
  textArea.style.left = '0';
  textArea.style.position = 'fixed';

  document.body.appendChild(textArea);
  textArea.focus();
  textArea.select();

  try {
    var successful = document.execCommand('copy');
    if (successful) {
      toastr.info('copied!');
    }
  } catch (err) {
    console.error('Fallback: Oops, unable to copy', err);
  }

  document.body.removeChild(textArea);
}

/**
 * Activates the confetti bomb effect
 */
export function doConfettiBomb() {
  // Do the confetti bomb
  var duration = 0.25 * 1000; //secs
  var end = Date.now() + duration;

  (function frame() {
    // launch a few confetti from the left edge
    confetti({
      particleCount: 7,
      angle: 60,
      spread: 55,
      origin: {
        x: 0,
      },
    });
    // and launch a few from the right edge
    confetti({
      particleCount: 7,
      angle: 120,
      spread: 55,
      origin: {
        x: 1,
      },
    });

    // keep going until we are out of time
    if (Date.now() < end) {
      requestAnimationFrame(frame);
    }
  })();
  confetti.reset();
}

/**
 * Returns apromise to create a delay
 * @param delay time in ms
 * @example await delay(1000); // waits 1 second
 */
export const delay = (ms: number) => new Promise((res) => setTimeout(res, ms));

/**
 * Debounces a function for delay milliseconds to prevent excessive calls.
 *
 * @param func - Function to debounce.
 * @param delay - Delay in milliseconds.
 * @returns Debounced function with the same parameters as `func`.
 */
export const debounce = <T extends (...args: any[]) => void>(
  func: T,
  delay: number,
): ((...args: Parameters<T>) => void) => {
  let timeoutId: ReturnType<typeof setTimeout> | undefined;
  return (...args: Parameters<T>) => {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => func(...args), delay);
  };
};
