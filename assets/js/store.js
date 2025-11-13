(function () {
    const app = document.getElementById('store-app');
    if (!app) {
        return;
    }

    const csrf = app.dataset.csrf;
    const toastEl = document.getElementById('store-toast');
    const productsGrid = document.getElementById('store-products-grid');
    const emptyProductsEl = document.getElementById('store-empty-products');
    const transactionItemsEl = document.getElementById('store-transaction-items');
    const transactionEmptyEl = document.getElementById('store-transaction-empty');
    const transactionCountEl = document.getElementById('store-transaction-count');
    const transactionTotalEl = document.getElementById('store-transaction-total');
    const completeSaleBtn = document.getElementById('store-complete-transaction');
    const cancelSaleBtn = document.getElementById('store-clear-transaction');
    const toggleBtn = document.getElementById('store-toggle');
    const statusLabel = document.getElementById('store-status-label');
    const sessionBanner = document.getElementById('store-session-banner');
    const sessionMeta = document.getElementById('store-session-meta');
    const sessionStarting = document.getElementById('store-session-starting');
    const sessionUser = document.getElementById('store-session-user');
    const recentSection = document.getElementById('store-recent-transactions');
    const recentList = document.getElementById('store-recent-list');
    const productsModal = document.getElementById('store-products-modal');
    const reportsModal = document.getElementById('store-reports-modal');
    const openModal = document.getElementById('store-open-modal');
    const closeModal = document.getElementById('store-close-modal');
    const productEditor = document.getElementById('store-product-editor');
    const productTemplate = document.getElementById('store-product-form-template');
    const reportsBody = document.getElementById('store-reports-body');
    const reportDateInput = document.getElementById('store-report-date');
    const reportSummary = document.querySelectorAll('[data-summary]');

    const state = {
        terminalKey: ensureTerminalKey(),
        session: null,
        products: [],
        cart: [],
        recent: [],
    };

    function ensureTerminalKey() {
        try {
            const storageKey = 'storeTerminalKey';
            let value = localStorage.getItem(storageKey);
            if (!value) {
                value = crypto.randomUUID ? crypto.randomUUID() : Math.random().toString(36).slice(2) + Date.now();
                localStorage.setItem(storageKey, value);
            }
            return value;
        } catch (err) {
            console.error('Unable to access localStorage for terminal key', err);
            return Math.random().toString(36).slice(2) + Date.now();
        }
    }

    function showToast(message) {
        if (!toastEl) {
            return;
        }
        toastEl.textContent = message;
        toastEl.hidden = false;
        setTimeout(() => {
            toastEl.hidden = true;
        }, 4000);
    }

    function closeModalElement(modal) {
        if (modal) {
            modal.setAttribute('hidden', 'hidden');
        }
    }

    function openModalElement(modal) {
        if (modal) {
            modal.removeAttribute('hidden');
        }
    }

    function buildFormData(action, data = {}, files) {
        const form = new FormData();
        form.append('action', action);
        form.append('_token', csrf);
        Object.entries(data).forEach(([key, value]) => {
            if (value !== undefined && value !== null) {
                form.append(key, value);
            }
        });
        if (files) {
            Object.entries(files).forEach(([key, value]) => {
                if (value) {
                    form.append(key, value);
                }
            });
        }
        return form;
    }

    async function apiRequest(action, data = {}, options = {}) {
        const formData = buildFormData(action, { ...data, terminal_key: state.terminalKey });
        const response = await fetch('/api/store.php', {
            method: 'POST',
            body: formData,
        });
        if (!response.ok) {
            throw new Error('Request failed with status ' + response.status);
        }
        const payload = await response.json();
        if (!payload.success) {
            throw new Error(payload.error || 'An unexpected error occurred.');
        }
        return payload;
    }

    function renderProducts() {
        productsGrid.innerHTML = '';
        if (!state.products.length) {
            emptyProductsEl.hidden = false;
            return;
        }
        emptyProductsEl.hidden = true;
        const fragment = document.createDocumentFragment();
        state.products.forEach((product) => {
            const button = document.createElement('button');
            button.className = 'product-button';
            button.type = 'button';
            button.dataset.id = product.id;
            const img = document.createElement('img');
            img.src = product.image_path;
            img.alt = product.name;
            const name = document.createElement('span');
            name.className = 'product-name';
            name.textContent = product.name;
            const price = document.createElement('span');
            price.className = 'product-price';
            price.textContent = formatCurrency(product.price);
            button.appendChild(img);
            button.appendChild(name);
            button.appendChild(price);
            button.addEventListener('click', () => addProductToCart(product.id));
            fragment.appendChild(button);
        });
        productsGrid.appendChild(fragment);
    }

    function addProductToCart(productId) {
        const product = state.products.find((item) => item.id === productId);
        if (!product) {
            return;
        }
        const existing = state.cart.find((item) => item.id === productId);
        if (existing) {
            existing.quantity += 1;
        } else {
            state.cart.push({
                id: product.id,
                name: product.name,
                price: parseFloat(product.price),
                cost: parseFloat(product.cost),
                quantity: 1,
            });
        }
        updateCartUI();
    }

    function updateCartUI() {
        transactionItemsEl.innerHTML = '';
        if (!state.cart.length) {
            transactionEmptyEl.hidden = false;
            completeSaleBtn.disabled = true;
            transactionCountEl.textContent = '0';
            transactionTotalEl.textContent = '$0.00';
            return;
        }
        transactionEmptyEl.hidden = true;
        const fragment = document.createDocumentFragment();
        let totalItems = 0;
        let totalAmount = 0;
        state.cart.forEach((item) => {
            totalItems += item.quantity;
            totalAmount += item.price * item.quantity;
            const li = document.createElement('li');
            li.className = 'transaction-item';
            const info = document.createElement('div');
            info.className = 'item-info';
            const name = document.createElement('span');
            name.className = 'item-name';
            name.textContent = item.name;
            const meta = document.createElement('span');
            meta.className = 'item-meta';
            meta.textContent = `${formatCurrency(item.price)} × ${item.quantity}`;
            info.appendChild(name);
            info.appendChild(meta);
            li.appendChild(info);
            const controls = document.createElement('div');
            controls.className = 'item-controls';
            const decBtn = document.createElement('button');
            decBtn.type = 'button';
            decBtn.dataset.action = 'decrease';
            decBtn.setAttribute('aria-label', 'Decrease');
            decBtn.textContent = '−';
            const qtySpan = document.createElement('span');
            qtySpan.textContent = String(item.quantity);
            const incBtn = document.createElement('button');
            incBtn.type = 'button';
            incBtn.dataset.action = 'increase';
            incBtn.setAttribute('aria-label', 'Increase');
            incBtn.textContent = '+';
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.dataset.action = 'remove';
            removeBtn.setAttribute('aria-label', 'Remove');
            removeBtn.textContent = '✕';
            [decBtn, qtySpan, incBtn, removeBtn].forEach((node) => controls.appendChild(node));
            [decBtn, incBtn, removeBtn].forEach((btn) => {
                btn.addEventListener('click', () => updateCartItem(item.id, btn.dataset.action));
            });
            li.appendChild(controls);
            fragment.appendChild(li);
        });
        transactionItemsEl.appendChild(fragment);
        transactionCountEl.textContent = String(totalItems);
        transactionTotalEl.textContent = `$${totalAmount.toFixed(2)}`;
        completeSaleBtn.disabled = !state.session;
    }

    function updateCartItem(productId, action) {
        const item = state.cart.find((entry) => entry.id === productId);
        if (!item) {
            return;
        }
        switch (action) {
            case 'increase':
                item.quantity += 1;
                break;
            case 'decrease':
                item.quantity -= 1;
                if (item.quantity <= 0) {
                    state.cart = state.cart.filter((entry) => entry.id !== productId);
                }
                break;
            case 'remove':
                state.cart = state.cart.filter((entry) => entry.id !== productId);
                break;
        }
        updateCartUI();
    }

    function clearCart() {
        state.cart = [];
        updateCartUI();
    }

    function updateSessionUI() {
        if (state.session) {
            toggleBtn.classList.add('active');
            toggleBtn.querySelector('.toggle-text').textContent = 'Close Store';
            statusLabel.textContent = 'Open';
            sessionBanner.hidden = false;
            sessionMeta.textContent = `Session #${state.session.id} • Opened ${formatDateTime(state.session.opened_at)}`;
            sessionStarting.textContent = formatCurrency(state.session.starting_cash);
            sessionUser.textContent = state.session.opened_by_username || '';
        } else {
            toggleBtn.classList.remove('active');
            toggleBtn.querySelector('.toggle-text').textContent = 'Open Store';
            statusLabel.textContent = 'Closed';
            sessionBanner.hidden = true;
            sessionMeta.textContent = '';
            sessionStarting.textContent = '$0.00';
            sessionUser.textContent = '';
            completeSaleBtn.disabled = true;
        }
    }

    function formatCurrency(value) {
        const amount = Number(value || 0);
        return `$${amount.toFixed(2)}`;
    }

    function formatDateTime(value) {
        if (!value) {
            return '';
        }
        const date = new Date(value.replace(' ', 'T'));
        return date.toLocaleString();
    }

    function renderRecent() {
        recentList.innerHTML = '';
        if (!state.recent.length) {
            recentSection.hidden = true;
            return;
        }
        recentSection.hidden = false;
        const fragment = document.createDocumentFragment();
        state.recent.forEach((transaction) => {
            const li = document.createElement('li');
            li.className = 'recent-transaction';
            const header = document.createElement('header');
            const idSpan = document.createElement('span');
            idSpan.textContent = `Sale #${transaction.id}`;
            const totalSpan = document.createElement('span');
            totalSpan.textContent = formatCurrency(transaction.total);
            header.appendChild(idSpan);
            header.appendChild(totalSpan);
            li.appendChild(header);
            const meta = document.createElement('div');
            meta.className = 'recent-meta';
            meta.textContent = `${formatDateTime(transaction.created_at)} • ${transaction.user || 'Unknown'}`;
            li.appendChild(meta);
            const itemsList = document.createElement('ul');
            transaction.items.forEach((item) => {
                const itemLi = document.createElement('li');
                itemLi.textContent = `${item.product_name} – ${item.quantity} × ${formatCurrency(item.product_price)} (${formatCurrency(item.line_total)})`;
                itemsList.appendChild(itemLi);
            });
            li.appendChild(itemsList);
            fragment.appendChild(li);
        });
        recentList.appendChild(fragment);
    }

    function bindModalCloseButtons() {
        document.querySelectorAll('.store-modal').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModalElement(modal);
                }
            });
        });
        document.querySelectorAll('[data-close]').forEach((button) => {
            button.addEventListener('click', () => {
                const modal = button.closest('.store-modal');
                closeModalElement(modal);
            });
        });
    }

    function setupToolbar() {
        toggleBtn.addEventListener('click', () => {
            if (state.session) {
                openModalElement(closeModal);
            } else {
                openModalElement(openModal);
            }
        });
        document.getElementById('store-products-btn').addEventListener('click', () => {
            renderProductEditor();
            openModalElement(productsModal);
        });
        document.getElementById('store-reports-btn').addEventListener('click', () => {
            if (!reportDateInput.value) {
                reportDateInput.value = new Date().toISOString().split('T')[0];
            }
            fetchReports();
            openModalElement(reportsModal);
        });
    }

    function setupForms() {
        const openForm = document.getElementById('store-open-form');
        openForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const errorEl = document.getElementById('store-open-error');
            errorEl.textContent = '';
            const data = new FormData(openForm);
            try {
                const payload = await apiRequest('open_session', {
                    starting_cash: data.get('starting_cash'),
                    password: data.get('password'),
                });
                state.session = payload.session;
                state.recent = payload.recent || [];
                updateSessionUI();
                renderRecent();
                closeModalElement(openModal);
                openForm.reset();
                showToast('Store session opened.');
                updateCartUI();
            } catch (error) {
                errorEl.textContent = error.message;
            }
        });

        const closeForm = document.getElementById('store-close-form');
        closeForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const errorEl = document.getElementById('store-close-error');
            errorEl.textContent = '';
            if (!state.session) {
                errorEl.textContent = 'No session is currently open.';
                return;
            }
            const data = new FormData(closeForm);
            try {
                await apiRequest('close_session', {
                    session_id: state.session.id,
                    closing_cash: data.get('closing_cash'),
                    password: data.get('password'),
                });
                state.session = null;
                closeModalElement(closeModal);
                closeForm.reset();
                showToast('Store session closed.');
                updateSessionUI();
                updateCartUI();
            } catch (error) {
                errorEl.textContent = error.message;
            }
        });

        cancelSaleBtn.addEventListener('click', () => {
            clearCart();
        });

        completeSaleBtn.addEventListener('click', async () => {
            if (!state.session || !state.cart.length) {
                return;
            }
            completeSaleBtn.disabled = true;
            try {
                const items = state.cart.map((item) => ({ product_id: item.id, quantity: item.quantity }));
                const payload = await apiRequest('record_transaction', {
                    session_id: state.session.id,
                    items: JSON.stringify(items),
                });
                state.recent = payload.recent || state.recent;
                showToast('Sale recorded successfully.');
                clearCart();
                renderRecent();
            } catch (error) {
                showToast(error.message);
            } finally {
                completeSaleBtn.disabled = !state.session;
            }
        });

        document.getElementById('store-add-product').addEventListener('click', () => {
            addProductForm();
        });

        document.getElementById('store-refresh-reports').addEventListener('click', fetchReports);
        document.getElementById('store-print-reports').addEventListener('click', () => window.print());
    }

    function addProductForm(product) {
        if (!productTemplate) {
            return;
        }
        const clone = productTemplate.content.firstElementChild.cloneNode(true);
        const form = clone;
        const previewImg = form.querySelector('.product-icon-preview');
        const iconInput = form.querySelector('input[name="icon"]');
        const nameInput = form.querySelector('input[name="name"]');
        const costInput = form.querySelector('input[name="cost"]');
        const priceInput = form.querySelector('input[name="price"]');
        const deleteBtn = form.querySelector('[data-delete]');
        const errorEl = form.querySelector('.form-error');

        if (product) {
            form.dataset.productId = product.id;
            previewImg.src = product.image_path;
            previewImg.alt = product.name;
            nameInput.value = product.name;
            costInput.value = Number(product.cost).toFixed(2);
            priceInput.value = Number(product.price).toFixed(2);
        } else {
            form.dataset.productId = '';
        }

        iconInput.addEventListener('change', () => {
            const file = iconInput.files && iconInput.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (event) => {
                    previewImg.src = event.target.result;
                };
                reader.readAsDataURL(file);
            }
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            errorEl.textContent = '';
            const productId = form.dataset.productId;
            const payloadData = {
                name: nameInput.value,
                cost: costInput.value,
                price: priceInput.value,
            };
            if (productId) {
                payloadData.product_id = productId;
            }
            const files = iconInput.files && iconInput.files[0] ? { icon: iconInput.files[0] } : null;
            try {
                const formData = buildFormData(productId ? 'update_product' : 'create_product', payloadData, files);
                const response = await fetch('/api/store.php', { method: 'POST', body: formData });
                if (!response.ok) {
                    throw new Error('Failed to save product');
                }
                const result = await response.json();
                if (!result.success) {
                    throw new Error(result.error || 'Unable to save product');
                }
                state.products = result.products;
                renderProducts();
                renderProductEditor();
                showToast('Product saved.');
            } catch (error) {
                errorEl.textContent = error.message;
            }
        });

        deleteBtn.addEventListener('click', async () => {
            errorEl.textContent = '';
            const productId = form.dataset.productId;
            if (!productId) {
                form.remove();
                return;
            }
            if (!confirm('Delete this product?')) {
                return;
            }
            try {
                await apiRequest('delete_product', { product_id: productId });
                state.products = state.products.filter((item) => item.id !== Number(productId));
                renderProducts();
                form.remove();
                showToast('Product removed.');
            } catch (error) {
                errorEl.textContent = error.message;
            }
        });

        productEditor.appendChild(form);
    }

    function renderProductEditor() {
        productEditor.innerHTML = '';
        if (!state.products.length) {
            addProductForm();
            return;
        }
        state.products.forEach((product) => addProductForm(product));
    }

    async function fetchReports() {
        const dateValue = reportDateInput.value;
        if (!dateValue) {
            return;
        }
        try {
            const payload = await apiRequest('report', { report_date: dateValue });
            reportSummary.forEach((node) => {
                const key = node.getAttribute('data-summary');
                if (key && payload.summary[key] !== undefined) {
                    if (['revenue', 'costs', 'profit'].includes(key)) {
                        node.textContent = formatCurrency(payload.summary[key]);
                    } else {
                        node.textContent = payload.summary[key];
                    }
                }
            });
            reportsBody.innerHTML = '';
            if (!payload.sessions.length) {
                const row = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = 10;
                cell.textContent = 'No data for the selected date.';
                row.appendChild(cell);
                reportsBody.appendChild(row);
                return;
            }
            payload.sessions.forEach((session) => {
                const row = document.createElement('tr');
                const cells = [
                    `#${session.id}`,
                    session.terminal_key,
                    session.opened_by_username || '',
                    formatDateTime(session.opened_at),
                    session.closed_by_username || '-',
                    session.closed_at ? formatDateTime(session.closed_at) : '-',
                    formatCurrency(session.starting_cash),
                    session.closing_cash ? formatCurrency(session.closing_cash) : '-',
                    session.transaction_count,
                    formatCurrency(session.sales_total),
                ];
                cells.forEach((value) => {
                    const cell = document.createElement('td');
                    cell.textContent = String(value);
                    row.appendChild(cell);
                });
                reportsBody.appendChild(row);
            });
        } catch (error) {
            showToast(error.message);
        }
    }

    async function loadState() {
        try {
            const payload = await apiRequest('state');
            state.session = payload.session;
            state.products = payload.products;
            state.recent = payload.recent || [];
            renderProducts();
            updateSessionUI();
            renderRecent();
            updateCartUI();
        } catch (error) {
            console.error(error);
            showToast('Unable to load store data.');
        }
    }

    bindModalCloseButtons();
    setupToolbar();
    setupForms();
    loadState();
})();
