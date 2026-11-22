// Website navigation only. No form, account, application or transfer state is touched.
(() => {
  const header = document.querySelector('[data-omnigo-prestige]');
  if (!header) return;
  const menus = [...header.querySelectorAll('details')];
  const close = (menu, restoreFocus = false) => {
    if (!menu.open) return;
    menu.open = false;
    if (restoreFocus) menu.querySelector('summary')?.focus({preventScroll: true});
  };
  for (const menu of menus) {
    menu.addEventListener('toggle', () => {
      if (menu.open) for (const other of menus) if (other !== menu) close(other);
    });
  }
  document.addEventListener('pointerdown', event => {
    for (const menu of menus) if (!menu.contains(event.target)) close(menu);
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const menu = menus.find(item => item.open);
    if (menu) {event.preventDefault(); close(menu, true);}
  });
  header.addEventListener('click', event => {
    if (event.target.closest('a[href]')) for (const menu of menus) close(menu);
  });
})();
