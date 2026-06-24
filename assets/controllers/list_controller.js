import { Controller } from '@hotwired/stimulus';

/*
 * Drives the /list page: live search, clickable-header sort, pagination,
 * sort-by-distance, and a filter panel mirroring the map's filters — all by
 * fetching the server-rendered table fragment and swapping it in (same "Twig
 * fragment via fetch" pattern as the dialogs).
 *
 * The search box, filter panel and distance button live in the permanent shell
 * (Stimulus actions are fine there). The sort headers / pagination / per-page
 * select are inside the swapped fragment, so they carry plain `data-*` and are
 * handled via delegated listeners (the delegation rule).
 */
export default class extends Controller {
    static targets = [
        'search', 'results', 'distanceBtn',
        'filterPanel', 'filterButton', 'filterBadge',
        'exportFilteredCompressed', 'exportFilteredPlain',
    ];

    static values = {
        fragmenturl: String,
        q: String,
        sort: String,
        dir: String,
        page: Number,
        perpage: Number,
        userlat: String,
        userlon: String,
        geoerror: String,
        geodenied: String,
        geounsupported: String,
    };

    connect() {
        this.state = {
            q: this.qValue || '',
            sort: this.sortValue || 'title',
            dir: this.dirValue || 'asc',
            page: this.pageValue || 1,
            perPage: this.perpageValue || 25,
            userLat: this.userlatValue || '',
            userLon: this.userlonValue || '',
        };
        this.debounceTimer = null;

        // Delegated handlers for the injected fragment (sort headers, pagination, per-page).
        this.onClick = (event) => {
            const sortEl = event.target.closest('[data-sort]');
            if (sortEl && this.element.contains(sortEl)) {
                event.preventDefault();
                this.applySort(sortEl.dataset.sort);
                return;
            }
            const pageEl = event.target.closest('[data-page]');
            if (pageEl && this.element.contains(pageEl)) {
                event.preventDefault();
                this.state.page = parseInt(pageEl.dataset.page, 10) || 1;
                this.refresh();
            }
        };
        this.element.addEventListener('click', this.onClick);

        this.onChange = (event) => {
            const select = event.target.closest('select[name="perPage"]');
            if (select && this.element.contains(select)) {
                this.state.perPage = parseInt(select.value, 10) || 25;
                this.state.page = 1;
                this.refresh();
            }
        };
        this.element.addEventListener('change', this.onChange);

        // Seed the badge + export links from the server-rendered initial state.
        this.updateFilterUi();
    }

    disconnect() {
        this.element.removeEventListener('click', this.onClick);
        this.element.removeEventListener('change', this.onChange);
    }

    onSearch() {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(() => {
            this.state.q = this.searchTarget.value.trim();
            this.state.page = 1;
            this.refresh();
        }, 300);
    }

    applySort(key) {
        if (this.state.sort === key) {
            this.state.dir = this.state.dir === 'asc' ? 'desc' : 'asc';
        } else {
            this.state.sort = key;
            // "Added" (newest) is most useful newest-first; everything else asc.
            this.state.dir = key === 'newest' ? 'desc' : 'asc';
        }
        this.state.page = 1;
        this.refresh();
    }

    // ── Filters ──────────────────────────────────────────────────────────────

    onFilterChange() {
        this.state.page = 1;
        this.refresh();
    }

    // Reset every control to its default (everything shown) and re-apply.
    resetFilters() {
        if (!this.hasFilterPanelTarget) return;
        const panel = this.filterPanelTarget;

        panel.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
            // Category checkboxes default to checked; the toggles default to off.
            cb.checked = !['f-wishlist', 'f-bookcrossing', 'f-watching'].includes(cb.name);
        });
        const osmWith = panel.querySelector('input[name="f-osm"][value="with"]');
        if (osmWith) osmWith.checked = true;
        const ratingEl = panel.querySelector('select[name="f-rating"]');
        if (ratingEl) ratingEl.value = '0';

        this.state.page = 1;
        this.refresh();
    }

    // Read the panel's current state into a structured object (or null if absent).
    readFilters() {
        if (!this.hasFilterPanelTarget) return null;
        const panel = this.filterPanelTarget;

        const checked = (name) =>
            Array.from(panel.querySelectorAll(`input[name="${name}"]:checked`)).map((i) => i.value);
        const total = (name) => panel.querySelectorAll(`input[name="${name}"]`).length;
        const ratingEl = panel.querySelector('select[name="f-rating"]');

        return {
            accessibility: checked('f-accessibility'),
            status: checked('f-status'),
            type: checked('f-type'),
            mobility: checked('f-mobility'),
            minRating: ratingEl ? parseInt(ratingEl.value, 10) || 0 : 0,
            wishlist: !!panel.querySelector('input[name="f-wishlist"]:checked'),
            bookcrossing: !!panel.querySelector('input[name="f-bookcrossing"]:checked'),
            watching: !!panel.querySelector('input[name="f-watching"]:checked'),
            osm: panel.querySelector('input[name="f-osm"]:checked')?.value || 'with',
            totals: {
                accessibility: total('f-accessibility'),
                status: total('f-status'),
                type: total('f-type'),
                mobility: total('f-mobility'),
            },
        };
    }

    // Append the active filter dimensions to a URLSearchParams. A dimension at its
    // default ("all" / off) is omitted so the URL stays minimal; an empty category
    // (user unchecked everything) is sent as an empty value so the server shows none.
    appendFilterParams(p) {
        const f = this.readFilters();
        if (!f) return;

        const csv = (key, vals, totalCount) => {
            if (vals.length === totalCount) return; // full selection → no restriction
            p.set(key, vals.join(','));
        };
        csv('acc', f.accessibility, f.totals.accessibility);
        csv('status', f.status, f.totals.status);
        csv('type', f.type, f.totals.type);
        csv('mob', f.mobility, f.totals.mobility);

        if (f.minRating > 0) p.set('minRating', String(f.minRating));
        if (f.wishlist) p.set('wishes', '1');
        if (f.bookcrossing) p.set('bcz', '1');
        if (f.watching) p.set('watch', '1');
        if (f.osm && f.osm !== 'with') p.set('osm', f.osm);
    }

    // Refresh the filter badge/button state and the "export with filters" links.
    updateFilterUi() {
        const f = this.readFilters();

        let n = 0;
        if (f) {
            if (f.accessibility.length !== f.totals.accessibility) n += 1;
            if (f.status.length !== f.totals.status) n += 1;
            if (f.type.length !== f.totals.type) n += 1;
            if (f.mobility.length !== f.totals.mobility) n += 1;
            if (f.minRating > 0) n += 1;
            if (f.wishlist) n += 1;
            if (f.bookcrossing) n += 1;
            if (f.watching) n += 1;
            if (f.osm && f.osm !== 'with') n += 1;
        }

        if (this.hasFilterBadgeTarget) {
            this.filterBadgeTarget.textContent = String(n);
            this.filterBadgeTarget.classList.toggle('hidden', n === 0);
        }
        if (this.hasFilterButtonTarget) {
            this.filterButtonTarget.classList.toggle('btn-primary', n > 0);
            this.filterButtonTarget.classList.toggle('btn-outline', n === 0);
        }

        this.syncExportLinks();
    }

    // Point the "export with current filters & sorting" links at the live query.
    syncExportLinks() {
        const build = (target, gzip) => {
            if (!target) return;
            const base = new URL(target.href, window.location.origin);
            const p = new URLSearchParams();
            if (this.state.q) p.set('q', this.state.q);
            p.set('sort', this.state.sort);
            p.set('dir', this.state.dir);
            if (this.state.userLat !== '' && this.state.userLon !== '') {
                p.set('userLat', this.state.userLat);
                p.set('userLon', this.state.userLon);
            }
            this.appendFilterParams(p);
            p.set('filtered', '1');
            if (gzip) p.set('gzip', '1');
            target.href = `${base.pathname}?${p.toString()}`;
        };
        if (this.hasExportFilteredCompressedTarget) build(this.exportFilteredCompressedTarget, true);
        if (this.hasExportFilteredPlainTarget) build(this.exportFilteredPlainTarget, false);
    }

    // ── Distance ───────────────────────────────────────────────────────────────

    sortByDistance() {
        if (!('geolocation' in navigator)) {
            window.alert(this.geounsupportedValue);
            return;
        }
        this.distanceBtnTarget.classList.add('opacity-50');

        const onSuccess = (pos) => {
            this.distanceBtnTarget.classList.remove('opacity-50');
            this.state.userLat = String(pos.coords.latitude);
            this.state.userLon = String(pos.coords.longitude);
            this.state.sort = 'distance';
            this.state.dir = 'asc';
            this.state.page = 1;
            this.distanceBtnTarget.classList.replace('btn-outline', 'btn-primary');
            this.refresh();
        };

        const fail = (err) => {
            this.distanceBtnTarget.classList.remove('opacity-50');
            // code 1 = permission denied → tell the user to fix it in Settings
            // (on iOS an installed PWA needs its own permission, separate from Safari).
            const denied = err && err.code === 1;
            window.alert(denied ? this.geodeniedValue : this.geoerrorValue);
        };

        // iOS home-screen PWAs often fail the high-accuracy (GPS) path even when the
        // coarse network location works, so fall back to low accuracy before giving up.
        // A permission denial (code 1) won't change on a retry, so don't bother.
        const onError = (err) => {
            if (err && err.code === 1) {
                fail(err);
                return;
            }
            navigator.geolocation.getCurrentPosition(onSuccess, fail, {
                enableHighAccuracy: false,
                timeout: 20000,
                maximumAge: 300000,
            });
        };

        navigator.geolocation.getCurrentPosition(onSuccess, onError, {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 60000,
        });
    }

    params() {
        const p = new URLSearchParams();
        if (this.state.q) p.set('q', this.state.q);
        p.set('sort', this.state.sort);
        p.set('dir', this.state.dir);
        p.set('page', String(this.state.page));
        p.set('perPage', String(this.state.perPage));
        if (this.state.userLat !== '' && this.state.userLon !== '') {
            p.set('userLat', this.state.userLat);
            p.set('userLon', this.state.userLon);
        }
        this.appendFilterParams(p);
        return p;
    }

    async refresh() {
        const query = this.params().toString();
        // Keep the badge + export links current even before the fetch resolves.
        this.updateFilterUi();
        try {
            const response = await fetch(`${this.fragmenturlValue}?${query}`);
            if (!response.ok) return;
            this.resultsTarget.innerHTML = await response.text();
            // Keep the URL shareable / reloadable without a navigation.
            history.replaceState(null, '', `${window.location.pathname}?${query}`);
        } catch {
            /* leave the current table in place on a transient failure */
        }
    }
}
