/* Zadania·AP — render kodu QR z otpauth:// (bez inline, pod CSP script-src 'self').
   Wymaga wcześniej załadowanego assets/qr.js (window.qrcode). */
(function () {
  var el = document.querySelector('.qr-box[data-otp]');
  if (!el || typeof qrcode === 'undefined') { return; }
  try {
    var q = qrcode(0, 'M');
    q.addData(el.getAttribute('data-otp'));
    q.make();
    el.innerHTML = q.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
    var s = el.querySelector('svg');
    if (s) {
      s.removeAttribute('width');
      s.removeAttribute('height');
      s.style.width = '100%';
      s.style.height = 'auto';
      s.style.display = 'block';
    }
  } catch (e) {}
})();
