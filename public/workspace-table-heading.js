import {companyLogoSource, exportCompanyName} from './company-export-brand.js';
import {text} from './i18n.js';
import {esc} from './ui.js';
import {workspaceExportCompany} from './workspace-export-company.js';

export function workspaceTableHeading(state, content, enabled = true) {
 const company = enabled ? workspaceExportCompany(state,state.company) : null;
 return tableHeadingTitle(content, company);
}

export function tableHeadingTitle(content, company, titleClass = '') {
 const id = Number(company?.id);
 const hasCompany = Number.isSafeInteger(id) && id > 0;
 const source = hasCompany && company.hasLogo ? companyLogoSource(company, {payment:true}) + '&v=' + (Number(company.brandingVersion) || 0) : '';
 return `<div class="${titleClass ? esc(titleClass) + ' ' : ''}table-heading-title">
  ${hasCompany ? `<span class="upcoming-payments-company-logo table-heading-company-logo" data-company-logo="${id}"${source ? '' : ' hidden'}><img${source ? ' src="' + esc(source) + '"' : ''} width="600" height="240" alt="${esc(text`${exportCompanyName(company)} logosu`)}"></span>` : ''}
  <div class="table-heading-copy">${content}</div>
 </div>`;
}
