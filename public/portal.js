const floatingFields = document.querySelectorAll('.vault-login-field');
const syncFloatingLabels = () => floatingFields.forEach(field => {
    field.classList.toggle('has-value', field.querySelector('input').value.length > 0);
});
floatingFields.forEach(field => {
    const input = field.querySelector('input');
    input.addEventListener('input', syncFloatingLabels);
    input.addEventListener('change', syncFloatingLabels);
    input.addEventListener('focus', syncFloatingLabels);
    input.addEventListener('blur', syncFloatingLabels);
});
syncFloatingLabels();
window.addEventListener('pageshow', syncFloatingLabels);

document.querySelectorAll('[data-demo]').forEach(button => button.addEventListener('click', () => {
    document.querySelector('[name=email]').value = button.dataset.demo;
    document.querySelector('[name=password]').value = 'FineworthDemo!27';
}));
document.querySelector('.password-toggle')?.addEventListener('click', event => {
    const input = document.querySelector('#password');
    input.type = input.type === 'password' ? 'text' : 'password';
    event.currentTarget.setAttribute('aria-label', input.type === 'password' ? 'Show password' : 'Hide password');
    event.currentTarget.setAttribute('aria-pressed', String(input.type !== 'password'));
});
document.querySelector('.mobile-menu')?.addEventListener('click', event => {
    const open = document.querySelector('.sidebar').classList.toggle('is-open');
    event.currentTarget.setAttribute('aria-expanded', String(open));
});
document.querySelectorAll('[data-open]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.open).showModal()));
document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); }));
const previousInput = JSON.parse(document.body.dataset.old || '{}');
if (previousInput._form) {
    const dialog = document.getElementById(previousInput._form);
    if (dialog) {
        Object.entries(previousInput).forEach(([name, value]) => {
            if (['_token', '_form', 'password'].includes(name)) return;
            dialog.querySelectorAll('input, select, textarea').forEach(input => {
                if (input.type === 'file' || input.type === 'password') return;
                if (input.name === name && input.type !== 'checkbox') input.value = value ?? '';
                if (input.name === name + '[]' && input.type === 'checkbox') input.checked = (value || []).map(String).includes(input.value);
            });
        });
        dialog.showModal();
    }
}
document.querySelectorAll('[data-search]').forEach(input => input.addEventListener('input', () => {
    const rows = document.querySelectorAll('[data-search-row]');
    let visible = 0;
    rows.forEach(row => { row.hidden = !row.textContent.toLowerCase().includes(input.value.toLowerCase()); if (!row.hidden) visible++; });
    const empty = document.querySelector('[data-search-empty]');
    if (empty) empty.hidden = visible > 0;
}));
const holdingType = document.querySelector('[name=type][data-holding-type]');
if (holdingType) {
    const form = holdingType.closest('form');
    const quantity = form.querySelector('[name=quantity]');
    const refreshMeasurements = () => {
        const whisky = holdingType.value === 'whisky';
        form.querySelectorAll('[data-for-type]').forEach(group => {
            group.hidden = group.dataset.forType !== holdingType.value;
            group.querySelectorAll('input, select').forEach(input => input.disabled = group.hidden);
        });
        quantity.readOnly = whisky;
        quantity.max = whisky ? '1' : '100000';
        if (whisky) quantity.value = '1';
        form.querySelector('[data-quantity-label]').textContent = whisky ? 'Quantity (casks)' : 'Quantity (bottles)';
        form.querySelector('[data-quantity-help]').textContent = whisky ? 'One individually referenced cask per holding.' : 'Enter total bottles, not the number of cases.';
        const raw = form.querySelector(whisky ? '[name=litres]' : '[name=bottle_ml]').value;
        const litres = whisky ? Number(raw) : Number(raw) * Number(quantity.value) / 1000;
        form.querySelector('[data-measurement-preview]').textContent = (quantity.value || 0) + (whisky ? ' cask' : ' bottles') + ' · ' + (raw === '' ? 'Volume not recorded' : litres.toLocaleString(undefined, {maximumFractionDigits: 2}) + ' L ' + (whisky ? 'recorded bulk whisky' : 'total wine'));
    };
    holdingType.addEventListener('change', refreshMeasurements);
    form.addEventListener('input', refreshMeasurements);
    refreshMeasurements();
}
document.querySelectorAll('form[data-document-form]').forEach(form => {
    const client = form.querySelector('[name=client_id]');
    const holding = form.querySelector('[name=holding_id]');
    const filter = () => {
        if (!holding) return;
        [...holding.options].forEach(option => { option.hidden = !!option.dataset.client && option.dataset.client !== client.value; option.disabled = option.hidden; });
        if (holding.selectedOptions[0]?.disabled) holding.value = '';
        const replaces = form.querySelector('[name=replaces_id]');
        if (replaces) [...replaces.options].forEach(option => { option.hidden = !!option.dataset.client && (option.dataset.client !== client.value || option.dataset.holding !== holding.value); option.disabled = option.hidden; });
        if (replaces?.selectedOptions[0]?.disabled) replaces.value = '';
    };
    client?.addEventListener('change', filter); holding?.addEventListener('change', filter); filter();
});
const requestForm = document.querySelector('#request-modal form');
if (requestForm) {
    const holding = requestForm.querySelector('[name=holding_id]');
    const service = requestForm.querySelector('[name=type]');
    const refresh = () => {
        const option = [...service.options].find(item => item.value === 'Regauge');
        option.disabled = holding.selectedOptions[0]?.dataset.type !== 'whisky';
        if (option.disabled && service.value === 'Regauge') service.value = 'Evaluation';
    };
    holding.addEventListener('change', refresh); refresh();
}

const dealForm = document.querySelector('[data-deal-form]');
if (dealForm) {
    const type = dealForm.querySelector('[data-deal-type]');
    const refreshDeal = () => {
        const whisky = type.value === 'whisky';
        dealForm.querySelectorAll('[data-deal-fields]').forEach(group => {
            group.hidden = group.dataset.dealFields !== type.value;
            group.querySelectorAll('input').forEach(input => input.disabled = group.hidden);
        });
        const quantity = dealForm.elements.quantity;
        quantity.max = whisky ? '1' : '100000';
        quantity.readOnly = whisky;
        if (whisky) quantity.value = '1';
        dealForm.querySelector('[data-deal-quantity]').textContent = whisky ? 'Quantity (one referenced cask)' : 'Quantity (total bottles)';
        const stock = Math.round(Number(dealForm.elements.unit_price.value) * 100) * Number(quantity.value);
        const fees = ['markup','management','brokerage'].map(key => Math.round(stock * Number(dealForm.elements[key+'_rate'].value) / 100));
        const total = stock + fees.reduce((a,b) => a+b,0);
        const balance = total - Math.round(Number(dealForm.elements.deposit.value) * 100);
        const money = pennies => new Intl.NumberFormat('en-GB', {style:'currency',currency:'GBP'}).format(pennies/100);
        dealForm.querySelector('[data-deal-preview]').textContent = `Preview: stock ${money(stock)} + fees ${money(total-stock)} = ${money(total)}. Balance after deposit: ${money(balance)}. Final amounts are calculated on the server.`;
    };
    dealForm.addEventListener('input', refreshDeal);
    dealForm.addEventListener('change', refreshDeal);
    refreshDeal();
}
