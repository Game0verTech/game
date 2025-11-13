<?php
require_login();
require_role('admin');

$pageTitle = 'Store';
$bodyClass = 'store-app';
$mainClass = 'store-main';
$extraStylesheets = ['/assets/css/store.css'];
$deferScripts = ['/assets/js/store.js'];
$csrfToken = csrf_token();

require __DIR__ . '/../../templates/header.php';
?>
<div id="store-app" class="store-app-shell" data-csrf="<?= sanitize($csrfToken) ?>">
    <section class="store-toolbar">
        <div class="store-status-block">
            <span class="status-label">Store is <strong id="store-status-label">Closed</strong></span>
            <button id="store-toggle" class="store-toggle" type="button">
                <span class="toggle-indicator"></span>
                <span class="toggle-text">Open Store</span>
            </button>
        </div>
        <div class="store-toolbar-actions">
            <button type="button" class="store-action" id="store-products-btn">Products</button>
            <button type="button" class="store-action" id="store-reports-btn">Reports</button>
        </div>
    </section>

    <section class="store-session-banner" id="store-session-banner" hidden>
        <div>
            <h2>Active Session</h2>
            <p class="session-meta" id="store-session-meta"></p>
        </div>
        <div class="session-financials">
            <div>
                <span class="label">Starting Cash</span>
                <span class="value" id="store-session-starting">$0.00</span>
            </div>
            <div>
                <span class="label">Opened By</span>
                <span class="value" id="store-session-user"></span>
            </div>
        </div>
    </section>

    <section class="store-layout">
        <div class="store-products-panel">
            <header class="panel-header">
                <h2>Products</h2>
                <p class="panel-subtitle">Tap any item to add it to the ticket.</p>
            </header>
            <div class="store-products-grid" id="store-products-grid"></div>
            <div class="empty-products" id="store-empty-products" hidden>
                <p>No products added yet. Use the Products button to start building your catalog.</p>
            </div>
        </div>
        <div class="store-transaction-panel">
            <header class="panel-header">
                <h2>Current Transaction</h2>
                <p class="panel-subtitle">Manage the ticket and finalize the sale.</p>
            </header>
            <div class="transaction-card">
                <ul class="transaction-items" id="store-transaction-items"></ul>
                <div class="transaction-empty" id="store-transaction-empty">
                    <p>Add items from the catalog to build the order.</p>
                </div>
                <div class="transaction-summary">
                    <div class="summary-row">
                        <span>Items</span>
                        <span id="store-transaction-count">0</span>
                    </div>
                    <div class="summary-row total">
                        <span>Total</span>
                        <span id="store-transaction-total">$0.00</span>
                    </div>
                </div>
                <div class="transaction-actions">
                    <button type="button" class="ghost" id="store-clear-transaction">Cancel Sale</button>
                    <button type="button" class="primary" id="store-complete-transaction" disabled>Complete Sale</button>
                </div>
            </div>
            <section class="recent-transactions" id="store-recent-transactions" hidden>
                <h3>Recent Sales</h3>
                <ul id="store-recent-list"></ul>
            </section>
        </div>
    </section>
</div>

<div class="store-modal" id="store-open-modal" hidden>
    <div class="store-modal__dialog">
        <button type="button" class="store-modal__close" data-close>&times;</button>
        <h2>Open Store</h2>
        <p>Confirm your credentials and starting cash to begin a new session for this station.</p>
        <form id="store-open-form">
            <label>Starting Cash
                <input type="number" step="0.01" min="0" name="starting_cash" required>
            </label>
            <label>Password
                <input type="password" name="password" required>
            </label>
            <div class="form-actions">
                <button type="button" class="ghost" data-close>Cancel</button>
                <button type="submit" class="primary">Open Session</button>
            </div>
        </form>
        <p class="form-error" id="store-open-error" role="alert"></p>
    </div>
</div>

<div class="store-modal" id="store-close-modal" hidden>
    <div class="store-modal__dialog">
        <button type="button" class="store-modal__close" data-close>&times;</button>
        <h2>Close Store</h2>
        <p>Enter the final cash total and confirm with your password to close this session.</p>
        <form id="store-close-form">
            <label>Ending Cash
                <input type="number" step="0.01" min="0" name="closing_cash" required>
            </label>
            <label>Password
                <input type="password" name="password" required>
            </label>
            <div class="form-actions">
                <button type="button" class="ghost" data-close>Cancel</button>
                <button type="submit" class="primary">Close Session</button>
            </div>
        </form>
        <p class="form-error" id="store-close-error" role="alert"></p>
    </div>
</div>

<div class="store-modal" id="store-products-modal" hidden>
    <div class="store-modal__dialog large">
        <button type="button" class="store-modal__close" data-close>&times;</button>
        <header class="modal-header">
            <h2>Products</h2>
            <button type="button" class="primary" id="store-add-product">Add Product</button>
        </header>
        <p class="modal-description">Create and maintain the global list of items available for sale. Upload PNG or JPEG icons to personalise each button.</p>
        <div class="product-editor" id="store-product-editor"></div>
        <template id="store-product-form-template">
            <form class="product-form" data-product-id="">
                <div class="product-form__preview">
                    <img src="/assets/store/icons/default-product.svg" alt="Product icon" class="product-icon-preview">
                    <label class="upload-button">Change Icon
                        <input type="file" name="icon" accept="image/png,image/jpeg">
                    </label>
                </div>
                <div class="product-form__fields">
                    <label>Name
                        <input type="text" name="name" maxlength="150" required>
                    </label>
                    <div class="field-grid">
                        <label>Cost
                            <input type="number" step="0.01" min="0" name="cost" required>
                        </label>
                        <label>Price
                            <input type="number" step="0.01" min="0" name="price" required>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="primary">Save</button>
                        <button type="button" class="ghost" data-delete>Delete</button>
                    </div>
                    <p class="form-error" role="alert"></p>
                </div>
            </form>
        </template>
    </div>
</div>

<div class="store-modal" id="store-reports-modal" hidden>
    <div class="store-modal__dialog large">
        <button type="button" class="store-modal__close" data-close>&times;</button>
        <header class="modal-header">
            <h2>Reports</h2>
            <div class="report-controls">
                <label>Date
                    <input type="date" id="store-report-date">
                </label>
                <button type="button" class="ghost" id="store-refresh-reports">Refresh</button>
                <button type="button" class="primary" id="store-print-reports">Print / Save PDF</button>
            </div>
        </header>
        <section class="reports-content" id="store-reports-content">
            <div class="reports-summary">
                <div>
                    <span class="label">Sessions</span>
                    <span class="value" data-summary="sessions">0</span>
                </div>
                <div>
                    <span class="label">Transactions</span>
                    <span class="value" data-summary="transactions">0</span>
                </div>
                <div>
                    <span class="label">Revenue</span>
                    <span class="value" data-summary="revenue">$0.00</span>
                </div>
                <div>
                    <span class="label">Costs</span>
                    <span class="value" data-summary="costs">$0.00</span>
                </div>
                <div>
                    <span class="label">Profit</span>
                    <span class="value" data-summary="profit">$0.00</span>
                </div>
            </div>
            <div class="reports-table-wrapper">
                <table class="reports-table">
                    <thead>
                        <tr>
                            <th>Session</th>
                            <th>Terminal</th>
                            <th>Opened By</th>
                            <th>Opened</th>
                            <th>Closed By</th>
                            <th>Closed</th>
                            <th>Starting Cash</th>
                            <th>Closing Cash</th>
                            <th>Transactions</th>
                            <th>Sales</th>
                        </tr>
                    </thead>
                    <tbody id="store-reports-body">
                        <tr><td colspan="10">No data for the selected date.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="store-toast" id="store-toast" hidden></div>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
