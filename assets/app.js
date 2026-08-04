/* Zadania·AP — skrypty UI (bez inline, pod CSP script-src 'self') */
(function () {
  // Edycja inline w tabeli (index.php): auto-submit po zmianie; ukryj przyciski „✓".
  document.querySelectorAll('.cell-save').forEach(function (b) { b.style.display = 'none'; });
  document.querySelectorAll('.cellf .cell-input').forEach(function (el) {
    el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
  });

  // Potwierdzenie akcji (np. usunięcie zadania w task.php): formularze z atrybutem data-confirm.
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
    });
  });
})();
