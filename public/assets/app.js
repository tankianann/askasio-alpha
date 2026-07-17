document.querySelectorAll('[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const message = form.getAttribute('data-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
});

const sourceTypeInputs = document.querySelectorAll('input[name="source_type"]');
const sourceFields = document.querySelectorAll('[data-source-fields]');

function updateSourceFields() {
    const selected = document.querySelector('input[name="source_type"]:checked');

    sourceFields.forEach((section) => {
        const active = selected && section.getAttribute('data-source-fields') === selected.value;
        section.hidden = !active;
        section.querySelectorAll('input').forEach((input) => {
            input.disabled = !active;
        });
    });
}

if (sourceTypeInputs.length > 0) {
    sourceTypeInputs.forEach((input) => input.addEventListener('change', updateSourceFields));
    updateSourceFields();
}

document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const targetId = button.getAttribute('data-copy-target');
        const target = targetId ? document.getElementById(targetId) : null;

        if (!(target instanceof HTMLInputElement)) {
            return;
        }

        try {
            await navigator.clipboard.writeText(target.value);
            button.textContent = 'Copied';
        } catch {
            target.select();
        }
    });
});
