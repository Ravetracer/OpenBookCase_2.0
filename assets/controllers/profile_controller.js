import { Controller } from '@hotwired/stimulus';

/*
 * Profile modal: update e-mail and delete the account. Lives on the permanent
 * <dialog id="profileModal"> whose body is server-rendered (not AJAX-injected),
 * so Stimulus actions/targets bind directly.
 */
export default class extends Controller {
    static targets = [
        'emailStatus',
        'channelToken', 'channelStatus',
        'languageToken',
        'homeStatus', 'homeRemove',
        'watchlist', 'watchlistEmpty', 'watchRowTemplate', 'wishlist',
        'apiApplyButton', 'apiApplyForm', 'apiStatus',
        'confirm', 'deleteButton', 'confirmCheck', 'confirmButton', 'deleteStatus', 'deleteToken',
    ];

    // Translated strings emitted on the dialog element (see base.html.twig).
    t(key, fallback) {
        return this.element.dataset[key] || fallback;
    }

    connect() {
        // Keep the watchlist in sync with the bookcase detail dialog (and vice
        // versa) without a reload. Remove is delegated so dynamically-added rows
        // work the same as server-rendered ones.
        this.onWatchlistChanged = (event) => this.applyWatchlistChange(event.detail);
        document.addEventListener('watchlist:changed', this.onWatchlistChanged);

        // A wish added/dropped/cancelled from a bookcase's wishlist modal — re-fetch
        // the profile's wishlist so it reflects the change without a page reload.
        this.onWishlistChanged = () => this.reloadWishlist();
        document.addEventListener('wishlist:changed', this.onWishlistChanged);

        // Keep the home-position fields in sync when home is set/removed elsewhere
        // (e.g. the map's "Set as home" popup), so the values show up instantly.
        this.onHomeChanged = (event) => this.applyHomeChange(event.detail || {});
        document.addEventListener('home:changed', this.onHomeChanged);

        this.onClick = (event) => {
            const button = event.target.closest('[data-watch-remove]');
            if (button && this.element.contains(button)) {
                event.preventDefault();
                this.removeWatch(button);
            }
        };
        this.element.addEventListener('click', this.onClick);
    }

    disconnect() {
        document.removeEventListener('watchlist:changed', this.onWatchlistChanged);
        document.removeEventListener('wishlist:changed', this.onWishlistChanged);
        document.removeEventListener('home:changed', this.onHomeChanged);
        this.element.removeEventListener('click', this.onClick);
    }

    // Reflect a home-position change (set/cleared elsewhere) in the form fields.
    applyHomeChange({ latitude, longitude, zoom, label, enabled, cleared } = {}) {
        if (!this.hasHomeRemoveTarget) return;
        const form = this.homeRemoveTarget.closest('form');
        if (!form) return;

        if (cleared) {
            form.elements.latitude.value = '';
            form.elements.longitude.value = '';
            form.elements.zoom.value = '';
            if (form.elements.label) form.elements.label.value = '';
            if (form.elements.enabled) form.elements.enabled.checked = false;
            this.homeRemoveTarget.classList.add('hidden');
            return;
        }

        if (latitude != null) form.elements.latitude.value = latitude;
        if (longitude != null) form.elements.longitude.value = longitude;
        if (zoom != null && zoom !== '') form.elements.zoom.value = zoom;
        if (form.elements.label && label !== undefined) form.elements.label.value = label || '';
        if (form.elements.enabled) form.elements.enabled.checked = !!enabled;
        this.homeRemoveTarget.classList.remove('hidden');
    }

    async updateEmail(event) {
        event.preventDefault();
        const status = this.emailStatusTarget;
        status.textContent = '';
        status.className = 'mt-1 text-sm';

        try {
            const response = await fetch('/profile/email', {
                method: 'POST',
                body: new FormData(event.currentTarget),
            });
            const json = await response.json().catch(() => ({}));
            if (response.ok) {
                status.textContent = this.t('transSaved', 'Saved');
                status.classList.add('text-success');
            } else {
                status.textContent = json.error || this.t('transCouldNotSave', 'Could not save.');
                status.classList.add('text-error');
            }
        } catch {
            status.textContent = this.t('transCouldNotSave', 'Could not save.');
            status.classList.add('text-error');
        }
    }

    // Save the chosen language, then reload so the server re-renders translated.
    async updateLanguage(event) {
        const locale = event.currentTarget.value;
        const body = new FormData();
        body.append('_token', this.languageTokenTarget.value);
        body.append('locale', locale);

        try {
            const response = await fetch('/profile/language', { method: 'POST', body });
            if (response.ok) {
                localStorage.setItem('locale', locale);
                window.location.reload();
            }
        } catch {
            /* keep the current language on failure */
        }
    }

    async updateNotifications(event) {
        const radio = event.currentTarget;
        const status = this.channelStatusTarget;
        status.textContent = '';
        status.className = 'mt-1 text-sm';

        // Reflect the new selection on the join buttons.
        this.element.querySelectorAll('input[name="notification-channel"]').forEach((input) => {
            const label = input.closest('label');
            if (!label) return;
            label.classList.toggle('btn-primary', input.checked);
            label.classList.toggle('btn-outline', !input.checked);
        });

        const body = new FormData();
        body.append('_token', this.channelTokenTarget.value);
        body.append('channel', radio.value);

        try {
            const response = await fetch('/profile/notifications', { method: 'POST', body });
            const json = await response.json().catch(() => ({}));
            if (response.ok) {
                status.textContent = this.t('transSaved', 'Saved');
                status.classList.add('text-success');
                setTimeout(() => { status.textContent = ''; }, 1500);
            } else {
                status.textContent = json.error || this.t('transCouldNotSave', 'Could not save.');
                status.classList.add('text-error');
            }
        } catch {
            status.textContent = this.t('transCouldNotSave', 'Could not save.');
            status.classList.add('text-error');
        }
    }

    // Save the home map location + opt-in flag.
    async updateHome(event) {
        event.preventDefault();
        const status = this.homeStatusTarget;
        status.textContent = '';
        status.className = 'text-sm';

        try {
            const form = event.currentTarget;
            const response = await fetch('/profile/home', { method: 'POST', body: new FormData(form) });
            const json = await response.json().catch(() => ({}));
            if (response.ok) {
                status.textContent = this.t('transSaved', 'Saved');
                status.classList.add('text-success');
                setTimeout(() => { status.textContent = ''; }, 1500);
                if (this.hasHomeRemoveTarget) this.homeRemoveTarget.classList.remove('hidden');
                // Reflect the new home on the map (marker + centering values) live.
                document.dispatchEvent(new CustomEvent('home:changed', { detail: {
                    latitude: form.elements.latitude.value,
                    longitude: form.elements.longitude.value,
                    zoom: form.elements.zoom.value,
                    label: form.elements.label ? form.elements.label.value : '',
                    enabled: form.elements.enabled.checked,
                } }));
            } else {
                status.textContent = json.error || this.t('transCouldNotSave', 'Could not save.');
                status.classList.add('text-error');
            }
        } catch {
            status.textContent = this.t('transCouldNotSave', 'Could not save.');
            status.classList.add('text-error');
        }
    }

    // Remove the home position entirely: clear the saved data and the map marker.
    async removeHome() {
        if (!confirm(this.t('transConfirmRemoveHome', 'Remove your saved home position?'))) return;

        const status = this.homeStatusTarget;
        status.textContent = '';
        status.className = 'text-sm';

        const homeForm = this.homeRemoveTarget.closest('form');
        const body = new FormData();
        body.append('_token', homeForm.elements._token.value);
        body.append('clear', '1');

        try {
            const response = await fetch('/profile/home', { method: 'POST', body });
            if (response.ok) {
                // Clear the inputs + toggle, hide the remove button.
                homeForm.elements.latitude.value = '';
                homeForm.elements.longitude.value = '';
                homeForm.elements.zoom.value = '';
                homeForm.elements.enabled.checked = false;
                this.homeRemoveTarget.classList.add('hidden');
                status.textContent = this.t('transSaved', 'Saved');
                status.classList.add('text-success');
                setTimeout(() => { status.textContent = ''; }, 1500);
                // Tell the map to drop the home marker.
                document.dispatchEvent(new CustomEvent('home:changed', { detail: { cleared: true } }));
            } else {
                status.textContent = this.t('transCouldNotSave', 'Could not save.');
                status.classList.add('text-error');
            }
        } catch {
            status.textContent = this.t('transCouldNotSave', 'Could not save.');
            status.classList.add('text-error');
        }
    }

    async removeWatch(button) {
        const id = button.dataset.bcId;
        if (!confirm(this.t('transConfirmRemoveWatch', 'Remove this bookcase from your watchlist?'))) return;

        button.disabled = true;
        try {
            const response = await fetch(`/api/bookcase/${id}/watch`, { method: 'DELETE' });
            if (response.ok) {
                this.removeWatchRow(id);
                // Reflect the removal on the detail dialog's Watch button if it's open.
                document.dispatchEvent(new CustomEvent('watchlist:changed', { detail: { id, watching: false } }));
            } else {
                button.disabled = false;
            }
        } catch {
            button.disabled = false;
        }
    }

    // Re-fetch the user's wishlist fragment after any wishlist mutation elsewhere.
    async reloadWishlist() {
        if (!this.hasWishlistTarget) return;
        try {
            const response = await fetch('/profile/wishlist');
            if (response.ok) this.wishlistTarget.innerHTML = await response.text();
        } catch {
            /* leave the current list in place on failure */
        }
    }

    // React to a watch toggle made elsewhere (the bookcase detail dialog).
    applyWatchlistChange({ id, title, watching } = {}) {
        if (!id) return;
        if (watching) {
            this.addWatchRow(id, title);
        } else {
            this.removeWatchRow(id);
        }
    }

    addWatchRow(id, title) {
        if (this.watchlistTarget.querySelector(`[data-watch-row][data-bc-id="${id}"]`)) return;

        const row = this.watchRowTemplateTarget.content.firstElementChild.cloneNode(true);
        row.dataset.bcId = id;
        const link = row.querySelector('[data-watch-link]');
        link.href = `/bookcase/${id}`;
        link.textContent = title || 'Bookcase';
        row.querySelector('[data-watch-remove]').dataset.bcId = id;

        this.watchlistTarget.appendChild(row);
        this.refreshWatchlistEmptyState();
    }

    removeWatchRow(id) {
        this.watchlistTarget.querySelector(`[data-watch-row][data-bc-id="${id}"]`)?.remove();
        this.refreshWatchlistEmptyState();
    }

    refreshWatchlistEmptyState() {
        const hasRows = this.watchlistTarget.children.length > 0;
        this.watchlistTarget.classList.toggle('hidden', !hasRows);
        this.watchlistEmptyTarget.classList.toggle('hidden', hasRows);
    }

    // Reveal the (initially hidden) API-access application form.
    showApiApply() {
        if (this.hasApiApplyFormTarget) this.apiApplyFormTarget.classList.remove('hidden');
        if (this.hasApiApplyButtonTarget) this.apiApplyButtonTarget.classList.add('hidden');
    }

    // Submit a new API-access application; reload so the section reflects "pending".
    async applyApi(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.checkValidity()) { form.reportValidity(); return; }
        await this.postApiForm(form);
    }

    // Post a reply inside a pending application's conversation thread.
    async replyApi(event) {
        event.preventDefault();
        await this.postApiForm(event.currentTarget);
    }

    async postApiForm(form) {
        const status = this.hasApiStatusTarget ? this.apiStatusTarget : null;
        if (status) { status.textContent = ''; status.className = 'mt-1 text-sm'; }
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
            const json = await response.json().catch(() => ({}));
            if (response.ok) {
                window.location.reload();
            } else if (status) {
                status.textContent = json.error || this.t('transCouldNotSave', 'Could not save.');
                status.classList.add('text-error');
            }
        } catch {
            if (status) {
                status.textContent = this.t('transCouldNotSave', 'Could not save.');
                status.classList.add('text-error');
            }
        }
    }

    showConfirm() {
        this.confirmTarget.classList.remove('hidden');
        this.deleteButtonTarget.classList.add('hidden');
    }

    cancelConfirm() {
        this.confirmTarget.classList.add('hidden');
        this.deleteButtonTarget.classList.remove('hidden');
        this.confirmCheckTarget.checked = false;
        this.confirmButtonTarget.disabled = true;
        this.deleteStatusTarget.textContent = '';
    }

    toggleConfirm() {
        this.confirmButtonTarget.disabled = !this.confirmCheckTarget.checked;
    }

    async deleteAccount() {
        this.confirmButtonTarget.disabled = true;
        this.deleteStatusTarget.textContent = '';

        const body = new FormData();
        body.append('_token', this.deleteTokenTarget.value);

        try {
            const response = await fetch('/profile/delete', { method: 'POST', body });
            const json = await response.json().catch(() => ({}));
            if (response.ok) {
                window.location = json.redirect || '/';
            } else {
                this.deleteStatusTarget.textContent = json.error || this.t('transCouldNotDelete', 'Could not delete account.');
                this.confirmButtonTarget.disabled = false;
            }
        } catch {
            this.deleteStatusTarget.textContent = this.t('transCouldNotDelete', 'Could not delete account.');
            this.confirmButtonTarget.disabled = false;
        }
    }
}
