/** Align leading detail arrows using the current table column's widest value. */
export function alignTableDisclosureLabels(root, lineSelector) {
  const lines = [...root.querySelectorAll(lineSelector)];
  if (!lines.length) return;

  const widths = new Map();
  root.setAttribute('data-measure-table-disclosures', '');
  const measured = lines.map(line => {
    const column = line.closest('td,th').cellIndex;
    const label = line.lastElementChild;
    const pixels = label.getBoundingClientRect().width;
    const fontSize = Number.parseFloat(root.ownerDocument.defaultView.getComputedStyle(label).fontSize);
    widths.set(column, Math.max(widths.get(column) ?? 0, pixels / fontSize));
    return {line, column};
  });
  root.removeAttribute('data-measure-table-disclosures');

  for (const {line, column} of measured) {
    line.style.setProperty('--table-disclosure-label-width', widths.get(column) + 'em');
  }
}
