(function () {
    'use strict';
    const LIMIT = 2147483647n;
    function money(raw) {
        if (typeof raw !== 'string') return null;
        const m = raw.trim().replace(',', '.').match(/^(\d{1,8})(?:\.(\d{1,2}))?$/);
        if (!m) return null;
        const n = BigInt(m[1]) * 100n + BigInt((m[2] || '').padEnd(2, '0'));
        return n <= LIMIT ? n : null;
    }
    function quantity(raw) {
        if (typeof raw !== 'string') return null;
        const m = raw.trim().match(/^(\d{1,6})(?:[.,](\d{1,3}))?$/);
        if (!m) return null;
        const n = BigInt(m[1]) * 1000n + BigInt((m[2] || '').padEnd(3, '0'));
        return n > 0n ? n : null;
    }
    function calculate(rows, mode) {
        if (!Array.isArray(rows) || !rows.length || rows.length > 100 || !['standard','exempt','reverse_charge','small_business'].includes(mode)) return null;
        const items = [], groups = new Map(); let net = 0n, tax = 0n, gross = 0n;
        for (const row of rows) {
            const p = money(row.unit_price), q = quantity(row.quantity), rawRate = money(row.tax_rate);
            if (p === null || q === null || rawRate === null || rawRate > 10000n) return null;
            const rate = mode === 'standard' ? rawRate : 0n, line = (p * q + 500n) / 1000n;
            if (gross + line > LIMIT) return null;
            items.push(Number(line)); gross += line;
            groups.set(rate,(groups.get(rate)||0n)+line);
        }
        for(const [rate,total] of groups){const divisor=10000n+rate,n=(total*10000n+divisor/2n)/divisor;net+=n;tax+=total-n;}
        return {net:Number(net),tax:Number(tax),gross:Number(gross),items};
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = {money,quantity,calculate};
    if (typeof document === 'undefined') return;
    document.querySelectorAll('[data-billing-items]').forEach(root => {
        const list = root.querySelector('[data-item-list]'), form = root.closest('form');
        function rows() { return [...list.querySelectorAll('[data-item-row]')]; }
        function update() {
            const all = rows(), data = all.map((row,index) => {
                row.querySelector('[data-item-remove]').disabled = all.length === 1;
                row.querySelector('[data-item-up]').disabled = index === 0;
                row.querySelector('[data-item-down]').disabled = index === all.length - 1;
                const fields = {};
                row.querySelectorAll('[data-item-field]').forEach(input => { input.name = `items[${index}][${input.dataset.itemField}]`; fields[input.dataset.itemField] = input.value; });
                return fields;
            });
            root.querySelector('[data-add-item]').disabled = all.length >= 100;
            root.querySelector('[data-item-count]').textContent = `${all.length} / 100`;
            const totals = calculate(data, form.querySelector('[data-billing-tax-mode]').value), currency = form.querySelector('[data-billing-currency]').value.toUpperCase();
            const format = n => new Intl.NumberFormat(root.dataset.locale,{minimumFractionDigits:2,maximumFractionDigits:2}).format(n/100) + ' ' + currency;
            for (const field of ['net','tax','total']) root.querySelector('[data-items-'+field+']').textContent = totals ? format(totals[field === 'total' ? 'gross' : field]) : '—';
            all.forEach((row,i) => { row.querySelector('[data-item-total]').textContent = totals ? format(totals.items[i]) : '—'; });
        }
        root.addEventListener('click', event => {
            const button = event.target.closest('button'); if (!button || button.disabled) return;
            const row = button.closest('[data-item-row]');
            if (button.matches('[data-add-item]') && rows().length < 100) {
                const clone = root.querySelector('template').content.cloneNode(true); list.append(clone); update(); rows().at(-1).querySelector('textarea').focus(); return;
            }
            if (row && button.matches('[data-item-remove]') && rows().length > 1) { const next = row.nextElementSibling || row.previousElementSibling; row.remove(); next?.querySelector('textarea').focus(); }
            if (row && button.matches('[data-item-up]') && row.previousElementSibling) row.previousElementSibling.before(row);
            if (row && button.matches('[data-item-down]') && row.nextElementSibling) row.nextElementSibling.after(row);
            update();
        });
        form.addEventListener('input', update); form.addEventListener('change', update); update();
    });
})();
