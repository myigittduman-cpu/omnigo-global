import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
const paths={
 truck:'M3 6h11v11H3zM14 10h4l3 4v3h-7M5 17a2 2 0 1 0 4 0M16 17a2 2 0 1 0 4 0',
 warning:'M12 3 2 21h20L12 3zM12 9v5M12 17h.01',
 excel:'M14 3H5v18h14V8zM14 3v5h5M8 12l6 6M14 12l-6 6',
 pdf:'M14 3H5v18h14V8zM14 3v5h5M8 12h8M8 16h5',
 refresh:'M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 6M4 12l2 6a7 7 0 0 0 12-1'
};
export const workspaceIcon=name=>i18nHtml`<svg class="workspace-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${paths[name]||paths.truck}"/></svg>`;
