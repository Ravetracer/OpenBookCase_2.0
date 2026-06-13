import { Controller } from '@hotwired/stimulus';

/*
 * Drives the /list page: live search, clickable-header sort, pagination and
 * sort-by-distance — all by fetching the server-rendered table fragment and
 * swapping it in (same "Twig fragment via fetch" pattern as the dialogs).
 *
 * The search box + distance button live in the permanent shell (Stimulus
 * actions are fine there). The sort headers / pagination / per-page select are
 * inside the swapped fragment, so they carry plain `data-*` and are handled
 * via delegated listeners (the delegation rule).
 */
export default class extends Controller {
    static targets = ['search', 'results', 'distanceBtn'];

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
            this.state.dir = 'asc';
        }
        this.state.page = 1;
        this.refresh();
    }

    sortByDistance() {
        if (!('geolocation' in navigator)) {
            window.alert(this.geounsupportedValue);
            return;
        }
        this.distanceBtnTarget.classList.add('opacity-50');
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                this.distanceBtnTarget.classList.remove('opacity-50');
                this.state.userLat = String(pos.coords.latitude);
                this.state.userLon = String(pos.coords.longitude);
                this.state.sort = 'distance';
                this.state.dir = 'asc';
                this.state.page = 1;
                this.distanceBtnTarget.classList.replace('btn-outline', 'btn-primary');
                this.refresh();
            },
            () => {
                this.distanceBtnTarget.classList.remove('opacity-50');
                window.alert(this.geoerrorValue);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
        );
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
        return p;
    }

    async refresh() {
        const query = this.params().toString();
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
