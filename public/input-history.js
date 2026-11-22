// Business fields must not offer saved browser entries. Login credentials keep
// their own, explicit Beni Hatırla policy; values and native pickers are untouched.
const installed = new WeakSet();
const loginSelector = '#loginForm,[data-login-form]';
const controls = 'input,textarea';
const excludedTypes = new Set(['hidden','password','checkbox','radio','file','submit','reset','button','image']);

function isLogin(element) {
 return Boolean(element.matches?.(loginSelector) || element.closest?.(loginSelector));
}

export function suppressInputHistory(root) {
 if (!root?.querySelectorAll) return;
 const forms = [...(root.matches?.('form') ? [root] : []), ...root.querySelectorAll('form')];
 for (const form of forms) if (!isLogin(form) && form.getAttribute('autocomplete') !== 'off') form.setAttribute('autocomplete','off');
 const fields = [...(root.matches?.(controls) ? [root] : []), ...root.querySelectorAll(controls)];
 for (const field of fields) {
  if (isLogin(field) || excludedTypes.has(field.type) || field.autocomplete === 'one-time-code') continue;
  if (field.getAttribute('autocomplete') !== 'off') field.setAttribute('autocomplete','off');
  field.setAttribute('data-lpignore','true');
  field.setAttribute('data-1p-ignore','');
  if (field.type === 'search') {
   field.setAttribute('results','0');
   field.removeAttribute('autosave');
  }
 }
}

export function installInputHistoryPolicy(doc = globalThis.document) {
 if (!doc?.documentElement || installed.has(doc)) return;
 installed.add(doc);
 suppressInputHistory(doc);
 // Inspect inserted subtrees only; live clocks and table refreshes never trigger
 // a full-document scan, rerender, value change or focus/scroll adjustment.
 const Observer = doc.defaultView?.MutationObserver;
 if (Observer) new Observer(records => {
  for (const record of records) for (const node of record.addedNodes) {
   if (node.nodeType === 1) suppressInputHistory(node);
  }
 }).observe(doc.documentElement,{childList:true,subtree:true});
 doc.addEventListener('focusin',event => suppressInputHistory(event.target),true);
}

installInputHistoryPolicy();
