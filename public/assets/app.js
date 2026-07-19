document.querySelectorAll('[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const message = form.getAttribute('data-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
});

const sidebarOpenButton = document.querySelector('[data-sidebar-open]');
const sidebarCloseButtons = document.querySelectorAll('[data-sidebar-close]');
const adminSidebar = document.getElementById('admin-sidebar');
const desktopSidebarMedia = window.matchMedia('(min-width: 62.01rem)');

function setSidebarOpen(open) {
    document.body.classList.toggle('sidebar-open', open);

    if (sidebarOpenButton instanceof HTMLButtonElement) {
        sidebarOpenButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (adminSidebar instanceof HTMLElement) {
        const hiddenDrawer = !desktopSidebarMedia.matches && !open;
        adminSidebar.inert = hiddenDrawer;
        adminSidebar.setAttribute('aria-hidden', hiddenDrawer ? 'true' : 'false');
    }
}

if (sidebarOpenButton instanceof HTMLButtonElement) {
    sidebarOpenButton.addEventListener('click', () => {
        setSidebarOpen(true);
        const sidebarCloseButton = document.querySelector('.sidebar-close');

        if (sidebarCloseButton instanceof HTMLButtonElement) {
            sidebarCloseButton.focus();
        }
    });
}

sidebarCloseButtons.forEach((button) => {
    button.addEventListener('click', () => {
        setSidebarOpen(false);

        if (sidebarOpenButton instanceof HTMLButtonElement) {
            sidebarOpenButton.focus();
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
        setSidebarOpen(false);

        if (sidebarOpenButton instanceof HTMLButtonElement) {
            sidebarOpenButton.focus();
        }
    }
});

desktopSidebarMedia.addEventListener('change', (event) => {
    if (event.matches) {
        setSidebarOpen(false);
    } else {
        setSidebarOpen(document.body.classList.contains('sidebar-open'));
    }
});

setSidebarOpen(false);

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
