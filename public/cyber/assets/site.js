// CyberRide — site behavior: header scroll state, trailer modal, smooth nav, icons.
(function () {
  // Lucide icons
  if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();

  // Sticky header state
  var header = document.getElementById('siteHeader');
  var onScroll = function () {
    if (!header) return;
    header.classList.toggle('scrolled', window.scrollY > 12);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // Smooth in-page nav
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var id = a.getAttribute('href').slice(1);
      if (!id) return;
      var el = id === 'top' ? document.body : document.getElementById(id);
      if (el) {
        e.preventDefault();
        var y = id === 'top' ? 0 : el.getBoundingClientRect().top + window.scrollY - 60;
        window.scrollTo({ top: y, behavior: 'smooth' });
      }
    });
  });

  // Trailer modal
  var modal = document.getElementById('trailerModal');
  var open = document.getElementById('trailerOpen');
  var close = document.getElementById('trailerClose');
  function setOpen(v) {
    if (!modal) return;
    modal.classList.toggle('open', v);
    modal.setAttribute('aria-hidden', v ? 'false' : 'true');
  }
  if (open) open.addEventListener('click', function () { setOpen(true); });
  if (close) close.addEventListener('click', function () { setOpen(false); });
  if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) setOpen(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
})();
