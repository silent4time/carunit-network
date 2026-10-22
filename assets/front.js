(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-cn-copy]');
    if (!btn) {
      return;
    }
    var el = document.querySelector(btn.getAttribute('data-cn-copy'));
    if (!el) {
      return;
    }
    var text = el.textContent;
    var done = function () {
      var old = btn.textContent;
      btn.textContent = 'کپی شد';
      btn.classList.add('is-done');
      setTimeout(function () {
        btn.textContent = old;
        btn.classList.remove('is-done');
      }, 1500);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done);
      return;
    }
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (err) {}
    document.body.removeChild(ta);
  });
})();
