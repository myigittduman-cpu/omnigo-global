(() => {
  'use strict';
  const form = document.querySelector('#srfm-form-2940');
  if (!form) return;
  const fields = [...form.querySelectorAll('input[data-required="true"],textarea[data-required="true"]')];
  let attempted = false;
  fields.forEach(field => {
    field.required = true;
    field.setAttribute('aria-required', 'true');
    const error = field.closest('.srfm-block')?.querySelector('[data-srfm-id]');
    if (error) {
      error.id = error.dataset.srfmId;
      field.setAttribute('aria-describedby', error.id);
    }
    field.addEventListener('input', () => {
      if (attempted) field.setAttribute('aria-invalid', String(!field.validity.valid));
    });
  });
  const errors = [...form.querySelectorAll('[id="srfm-error-message"]')];
  errors.forEach((error, index) => {
    if (index > 0) error.id = `srfm-error-message-${index}`;
    error.setAttribute('role', 'alert');
  });
  function validate() {
    attempted = true;
    fields.forEach(field => field.setAttribute('aria-invalid', String(!field.validity.valid)));
    const invalid = fields.find(field => !field.validity.valid);
    if (invalid) requestAnimationFrame(() => invalid.focus());
  }
  form.addEventListener('submit', validate, true);
  form.querySelector('.srfm-submit-button,[type="submit"]')?.addEventListener('click', validate, true);
  const choices = {
    pazaryeri: 'Pazaryeri yönetimi',
    'e-ticaret': 'E-ticaret sitesi',
    mobil: 'Mobil satış uygulaması',
    kurumsal: 'Kurumsal web sitesi'
  };
  const label = document.createElement('label');
  label.className = 'og-inquiry-service';
  label.textContent = 'Görüşmek istediğiniz hizmet';
  const select = document.createElement('select');
  select.id = 'og-inquiry-service';
  label.htmlFor = select.id;
  select.add(new Option('Hizmet seçin (isteğe bağlı)', ''));
  Object.entries(choices).forEach(([value, text]) => select.add(new Option(text, value)));
  const hint = document.createElement('small');
  hint.textContent = 'Seçiminiz ihtiyaç açıklamanıza eklenir. Birden fazla hizmeti açıklamada belirtebilirsiniz.';
  label.append(select, hint);
  form.querySelector('.srfm-block')?.before(label);
  const description = form.querySelector('textarea');
  let prefix = '';
  function applyChoice() {
    if (!description) return;
    const body = prefix && description.value.startsWith(prefix) ? description.value.slice(prefix.length) : description.value;
    prefix = choices[select.value] ? `Hizmet: ${choices[select.value]}\n` : '';
    description.value = prefix + body;
    description.dispatchEvent(new Event('input', {bubbles: true}));
  }
  select.addEventListener('change', applyChoice);
  const initial = new URLSearchParams(location.search).get('hizmet');
  if (Object.hasOwn(choices, initial)) { select.value = initial; applyChoice(); }
})();
