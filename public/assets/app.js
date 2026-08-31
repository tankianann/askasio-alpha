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

document.querySelectorAll('[data-split-button]').forEach((splitButton) => {
    const toggle = splitButton.querySelector('[data-split-button-toggle]');
    const menu = splitButton.querySelector('[data-split-button-menu]');

    if (!(toggle instanceof HTMLButtonElement) || !(menu instanceof HTMLElement)) {
        return;
    }

    const items = Array.from(menu.querySelectorAll('[role="menuitem"]'));

    function setSplitButtonOpen(open, focusIndex = null) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        menu.hidden = !open;

        if (open && typeof focusIndex === 'number' && items[focusIndex] instanceof HTMLElement) {
            items[focusIndex].focus();
        }
    }

    toggle.addEventListener('click', () => {
        setSplitButtonOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });

    toggle.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setSplitButtonOpen(true, event.key === 'ArrowDown' ? 0 : items.length - 1);
        }
    });

    items.forEach((item, index) => {
        item.addEventListener('keydown', (event) => {
            let nextIndex = null;

            if (event.key === 'ArrowDown') {
                nextIndex = (index + 1) % items.length;
            } else if (event.key === 'ArrowUp') {
                nextIndex = (index - 1 + items.length) % items.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = items.length - 1;
            } else if (event.key === 'Escape') {
                event.preventDefault();
                setSplitButtonOpen(false);
                toggle.focus();
                return;
            }

            if (nextIndex !== null) {
                event.preventDefault();
                items[nextIndex].focus();
            }
        });

        item.addEventListener('click', () => setSplitButtonOpen(false));
    });

    splitButton.addEventListener('focusout', (event) => {
        if (!(event.relatedTarget instanceof Node) || !splitButton.contains(event.relatedTarget)) {
            setSplitButtonOpen(false);
        }
    });

    document.addEventListener('click', (event) => {
        if (event.target instanceof Node && !splitButton.contains(event.target)) {
            setSplitButtonOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setSplitButtonOpen(false);
            toggle.focus();
        }
    });
});

document.querySelectorAll('[data-source-batch-form]').forEach((form) => {
    const selections = Array.from(form.querySelectorAll('[data-source-selection]'));
    const selectAll = form.querySelector('[data-select-all]');
    const summary = form.querySelector('[data-selection-summary]');

    function updateSourceSelectionSummary() {
        const selected = selections.filter((selection) => selection instanceof HTMLInputElement && selection.checked);

        if (summary instanceof HTMLElement) {
            summary.textContent = selected.length === 0
                ? 'No sources selected'
                : `${selected.length} ${selected.length === 1 ? 'source' : 'sources'} selected`;
        }

        if (selectAll instanceof HTMLInputElement) {
            selectAll.checked = selections.length > 0 && selected.length === selections.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < selections.length;
        }
    }

    if (selectAll instanceof HTMLInputElement) {
        selectAll.addEventListener('change', () => {
            selections.forEach((selection) => {
                if (selection instanceof HTMLInputElement) {
                    selection.checked = selectAll.checked;
                }
            });
            updateSourceSelectionSummary();
        });
    }

    selections.forEach((selection) => selection.addEventListener('change', updateSourceSelectionSummary));
    updateSourceSelectionSummary();
});

const bulkMarkdownForm = document.querySelector('[data-bulk-markdown-upload]');

if (bulkMarkdownForm instanceof HTMLFormElement) {
    const fileInput = bulkMarkdownForm.querySelector('[data-bulk-markdown-files]');
    const submitButton = bulkMarkdownForm.querySelector('[data-bulk-markdown-submit]');
    const dropzone = bulkMarkdownForm.querySelector('[data-bulk-markdown-dropzone]');
    const selection = bulkMarkdownForm.querySelector('[data-bulk-markdown-selection]');
    const results = document.querySelector('[data-bulk-markdown-results]');
    const rows = document.querySelector('[data-bulk-markdown-rows]');
    const summary = document.querySelector('[data-bulk-markdown-summary]');
    const progress = document.querySelector('[data-bulk-markdown-progress]');
    const csrf = bulkMarkdownForm.querySelector('input[name="_csrf"]');
    let selectedFiles = [];

    function updateBulkMarkdownSelection(files) {
        selectedFiles = Array.from(files);

        if (selection instanceof HTMLElement) {
            selection.textContent = selectedFiles.length === 0
                ? 'No files selected.'
                : `${selectedFiles.length} ${selectedFiles.length === 1 ? 'file' : 'files'} selected.`;
        }
    }

    if (fileInput instanceof HTMLInputElement) {
        fileInput.addEventListener('change', () => updateBulkMarkdownSelection(fileInput.files || []));
    }

    if (dropzone instanceof HTMLElement) {
        ['dragenter', 'dragover'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropzone.classList.add('is-dragging');
            });
        });

        ['dragleave', 'drop'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropzone.classList.remove('is-dragging');
            });
        });

        dropzone.addEventListener('drop', (event) => {
            if (event instanceof DragEvent && event.dataTransfer) {
                updateBulkMarkdownSelection(event.dataTransfer.files);
            }
        });
    }

    bulkMarkdownForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!(fileInput instanceof HTMLInputElement)
            || !(submitButton instanceof HTMLButtonElement)
            || !(results instanceof HTMLElement)
            || !(rows instanceof HTMLElement)
            || !(summary instanceof HTMLElement)
            || !(progress instanceof HTMLElement)
            || !(csrf instanceof HTMLInputElement)) {
            return;
        }

        const files = selectedFiles;

        if (files.length === 0) {
            if (selection instanceof HTMLElement) {
                selection.textContent = 'Choose at least one Markdown file.';
                selection.classList.add('bulk-upload-error');
            }
            fileInput.focus();
            return;
        }

        selection?.classList.remove('bulk-upload-error');

        fileInput.disabled = true;
        submitButton.disabled = true;
        results.hidden = false;
        rows.replaceChildren();

        const uploads = files.map((file) => {
            const row = document.createElement('tr');
            const filename = document.createElement('td');
            const documentName = document.createElement('td');
            const status = document.createElement('td');
            const badge = document.createElement('span');

            filename.textContent = file.name;
            documentName.textContent = '—';
            badge.className = 'badge badge-pending';
            badge.textContent = 'Waiting';
            status.appendChild(badge);
            row.append(filename, documentName, status);
            rows.appendChild(row);

            return { file, documentName, status, badge };
        });

        let succeeded = 0;
        let failed = 0;
        summary.textContent = `${files.length} files selected`;

        for (let index = 0; index < uploads.length; index += 1) {
            const upload = uploads[index];
            upload.badge.className = 'badge badge-processing';
            upload.badge.textContent = 'Uploading';
            progress.textContent = `${index + 1} of ${uploads.length}`;

            const body = new FormData();
            body.append('_csrf', csrf.value);
            body.append('markdown_file', upload.file, upload.file.name);

            try {
                const response = await fetch(bulkMarkdownForm.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    body,
                });
                const contentType = response.headers.get('content-type') || '';
                const payload = contentType.includes('application/json') ? await response.json() : null;

                if (!response.ok || !payload || !payload.source) {
                    const message = payload && payload.error && typeof payload.error.message === 'string'
                        ? payload.error.message
                        : 'The upload could not be completed.';
                    throw new Error(message);
                }

                const link = document.createElement('a');
                link.className = 'table-link';
                link.href = payload.source.url;
                link.textContent = payload.source.name;
                upload.documentName.replaceChildren(link);
                upload.badge.className = 'badge badge-pending';
                upload.badge.textContent = 'Queued';
                succeeded += 1;
            } catch (error) {
                upload.badge.className = 'badge badge-failed';
                upload.badge.textContent = 'Rejected';
                const detail = document.createElement('span');
                detail.className = 'table-subtitle bulk-upload-error';
                detail.textContent = error instanceof Error ? error.message : 'The upload could not be completed.';
                upload.status.appendChild(detail);
                failed += 1;
            }

            summary.textContent = `${succeeded} imported, ${failed} rejected`;
        }

        progress.textContent = 'Complete';
        fileInput.disabled = false;
        fileInput.value = '';
        updateBulkMarkdownSelection([]);
        submitButton.disabled = false;
    });
}

document.querySelectorAll('[data-widget-installation]').forEach((installation) => {
    const selectId = installation.getAttribute('data-layout-select');
    const layoutSelect = selectId ? document.getElementById(selectId) : null;
    const copyButton = installation.querySelector('[data-widget-copy-button]');
    const snippets = installation.querySelectorAll('[data-widget-snippet]');

    if (!(layoutSelect instanceof HTMLSelectElement) || !(copyButton instanceof HTMLButtonElement)) {
        return;
    }

    const updateSnippet = () => {
        const layout = layoutSelect.value === 'inline_fullscreen' ? 'inline_fullscreen' : 'floating';

        snippets.forEach((snippet) => {
            snippet.hidden = snippet.getAttribute('data-widget-snippet') !== layout;
        });
        copyButton.setAttribute('data-copy-target', layout === 'inline_fullscreen' ? 'inline-embed-code' : 'floating-embed-code');
        copyButton.textContent = 'Copy embed code';
    };

    layoutSelect.addEventListener('change', updateSnippet);
    updateSnippet();
});

document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const targetId = button.getAttribute('data-copy-target');
        const target = targetId ? document.getElementById(targetId) : null;

        if (!(target instanceof HTMLInputElement) && !(target instanceof HTMLTextAreaElement)) {
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
