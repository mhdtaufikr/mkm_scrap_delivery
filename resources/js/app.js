import './bootstrap';

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.textContent = visible ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(visible));
    });
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') document.querySelectorAll('details[open]').forEach((menu) => menu.removeAttribute('open'));
});
document.addEventListener('click', (event) => {
    document.querySelectorAll('details[data-menu][open]').forEach((menu) => {
        if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
});
document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));
document.querySelectorAll('form[data-submit-once]').forEach((form) => {
    form.addEventListener('submit', () => {
        // Keep the clicked submit button's action value in the form payload.
        setTimeout(() => form.querySelectorAll('button[type="submit"]').forEach((button) => button.disabled = true), 0);
    });
});

// Only show controls relevant to the selected assignment method in the approval editor.
// Without JavaScript, every field remains visible and the server still validates the selected mode.
document.querySelectorAll('[data-approval-step]').forEach((form) => {
    const mode = form.querySelector('[name="actor_mode"]');
    const update = () => {
        form.querySelectorAll('[data-actor-fields]').forEach((group) => {
            const visible = group.dataset.actorFields === mode.value;
            group.hidden = !visible;
            group.querySelectorAll('input, select').forEach((input) => input.disabled = !visible);
        });
    };
    mode.addEventListener('change', update);
    update();
});


document.querySelectorAll('[data-goods-form]').forEach((form) => {
    const rows = form.querySelector('[data-goods-rows]');
    const template = form.querySelector('[data-goods-template]');
    const add = form.querySelector('[data-add-goods]');
    const renumber = () => {
        rows.querySelectorAll('[data-goods-row]').forEach((row, index) => {
            row.querySelector('[data-row-number]').textContent = index + 1;
            row.querySelectorAll('input').forEach((input) => {
                input.name = input.name.replace(/items\[[^\]]+\]/, `items[${index}]`);
                const field = input.name.match(/\[([^\]]+)\]$/)[1];
                input.setAttribute('aria-label', `${field} for item ${index + 1}`);
            });
            row.querySelector('[data-remove-goods]').disabled = rows.children.length <= 1;
        });
        add.disabled = rows.children.length >= 50;
    };
    add.addEventListener('click', () => {
        if (rows.children.length >= 50) return;
        rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', rows.children.length));
        renumber();
        rows.lastElementChild.querySelector('input').focus();
    });
    rows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-goods]');
        if (!button || rows.children.length <= 1) return;
        button.closest('[data-goods-row]').remove();
        renumber();
    });
    renumber();
});


// Keep inactive type fields out of browser validation and submitted form data.
document.querySelectorAll('[data-goods-form]').forEach((form) => {
    const type = form.querySelector('#type');
    const syncType = () => {
        form.querySelectorAll('[data-type-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.typePanel !== type.value;
            panel.disabled = panel.hidden;
        });
        form.querySelectorAll('[data-type-field]').forEach((group) => {
            group.hidden = group.dataset.typeField !== type.value;
            group.querySelectorAll('input, select, textarea').forEach((input) => input.disabled = group.hidden);
        });
        form.querySelector('[data-type-hint]').textContent = type.value === 'DISPOSAL'
            ? 'Disposal is draft-only until its approval workflow is integrated.'
            : 'Scrap follows the configured Procurement, HRD, handover and Security approval workflow.';
    };
    type.addEventListener('change', syncType);
    syncType();
});

import './pwa';
