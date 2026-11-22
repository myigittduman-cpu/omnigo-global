(() => {
  'use strict';
  const gallery = document.querySelector('.ogp-references');
  if (!gallery || typeof HTMLDialogElement === 'undefined') return;
  const dialog = document.createElement('dialog');
  dialog.className = 'ogp-lightbox';
  dialog.setAttribute('aria-label', 'Referans görseli önizlemesi');
  const panel = document.createElement('div');
  panel.className = 'ogp-lightbox-panel';
  const closeButton = document.createElement('button');
  closeButton.type = 'button';
  closeButton.className = 'ogp-lightbox-close';
  closeButton.textContent = 'Kapat ×';
  const image = document.createElement('img');
  image.className = 'ogp-lightbox-image';
  const caption = document.createElement('p');
  caption.id = 'ogp-lightbox-caption';
  dialog.setAttribute('aria-describedby', caption.id);
  panel.append(closeButton, image, caption);
  dialog.append(panel);
  document.body.append(dialog);
  let opener = null;
  let scrollTop = 0;
  let oldBodyStyle = null;
  function close() { dialog.close(); }
  closeButton.addEventListener('click', close);
  let backdropStart = false;
  dialog.addEventListener('pointerdown', event => { backdropStart = event.target === dialog; });
  dialog.addEventListener('click', event => {
    if (backdropStart && event.target === dialog) close();
    backdropStart = false;
  });
  dialog.addEventListener('close', () => {
    if (oldBodyStyle === null) document.body.removeAttribute('style');
    else document.body.setAttribute('style', oldBodyStyle);
    window.scrollTo({top: scrollTop, behavior: 'instant'});
    image.removeAttribute('src');
    opener?.focus({preventScroll: true});
  });
  gallery.querySelectorAll('img').forEach(source => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'ogp-image-open';
    button.setAttribute('aria-label', source.alt + ' — büyük görüntüle');
    button.setAttribute('aria-haspopup', 'dialog');
    source.replaceWith(button);
    button.append(source);
    button.addEventListener('click', () => {
      opener = button;
      image.src = source.currentSrc || source.src;
      image.alt = source.alt;
      caption.textContent = source.closest('figure')?.querySelector('figcaption')?.textContent || source.alt;
      scrollTop = window.scrollY;
      oldBodyStyle = document.body.getAttribute('style');
      dialog.showModal();
      Object.assign(document.body.style, {position: 'fixed', top: `-${scrollTop}px`, left: '0', right: '0', overflow: 'hidden'});
      closeButton.focus({preventScroll: true});
    });
  });
})();
