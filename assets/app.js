document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('table').forEach(t => {
    t.addEventListener('dblclick', e => {
      const tr = e.target.closest('tr');
      if (!tr) return;
      tr.classList.toggle('row-highlight');
    });
  });
});
