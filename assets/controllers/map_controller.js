import { Controller } from '@hotwired/stimulus';
import axios from 'axios';
import { searchPhoton, placeLabel, parseCoords } from '../geocode.js';

export default class extends Controller {
    static targets = ['searchInput', 'searchResults', 'searchClear', 'filterContainer', 'filterPanel', 'filterBadge'];

    static values = {
        detailstrans: String,
        wishesopen: String,
        inactive: String,
        searchbookcases: String,
        searchplaces: String,
        searchnoresults: String,
        searchcoordinate: String,
        geoerror: String,
        geounsupported: String,
        geolocate: String,
        canadd: Boolean,
        addhere: String,
        moveconfirm: String,
        movefailed: String,
        initialId: String,
        initialLat: String,
        initialLon: String,
        // Per-user home location (set in the profile / via the map popup).
        homeEnabled: Boolean,
        homeLat: String,
        homeLon: String,
        homeZoom: String,
        homeToken: String,
        homelabel: String,
        homecustomlabel: String,
        sethere: String,
        sethomeconfirm: String,
        sethomesaved: String,
        sethomefailed: String,
        // Bookcase ids the current user watches — seeds the "only watched" filter.
        watchedids: Array,
    };

    initialize() {
        super.initialize();

        // Centre priority: deep-link entry → user's home location (if enabled) → default.
        const lat = parseFloat(this.initialLatValue);
        const lon = parseFloat(this.initialLonValue);
        const hasInitial = this.initialIdValue !== '' && !isNaN(lat) && !isNaN(lon);

        const homeLat = parseFloat(this.homeLatValue);
        const homeLon = parseFloat(this.homeLonValue);
        const homeZoom = parseInt(this.homeZoomValue, 10);
        const hasHome = this.homeEnabledValue && !isNaN(homeLat) && !isNaN(homeLon);

        // Default for anonymous visitors / no stored home: centre of Germany, zoomed out.
        let center = [51.1657, 10.4515], zoom = 10;
        if (hasInitial) {
            center = [lat, lon];
            zoom = 17;
        } else if (hasHome) {
            center = [homeLat, homeLon];
            zoom = isNaN(homeZoom) ? 13 : homeZoom;
        }

        this.map = L.map('map').setView(center, zoom);
        this.markerCluster = L.markerClusterGroup();
        this.loadedMarkers = [];
        // id → { marker, item }, so dialog changes can refresh a marker's icon/popup live.
        this.markersById = {};
        this.spinner = document.getElementById('loaderSpinner');

        // Search state
        this.searchSeq = 0;
        this.searchItems = [];
        this.activeIndex = -1;
        this.debounceTimer = null;

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(this.map);

        this.addGeolocateControl();

        const bounds = this.getBoundingCoordinates(this.map.getBounds());

        this.latMin = bounds.latMin;
        this.latMax = bounds.latMax;
        this.lngMin = bounds.lngMin;
        this.lngMax = bounds.lngMax;

        this.map.addLayer(this.markerCluster);

        // Drop the home marker if the user has a home location set.
        this.showHomeMarker();

        this.loadEntries();
    }

    connect() {
        this.map.on('moveend', this.loadEntries.bind(this));

        // Close the suggestion list when clicking anywhere outside the search box,
        // and the filter panel when clicking outside the filter control.
        this.outsideClick = (e) => {
            if (!this.element.contains(e.target)) {
                this.hideResults();
            }
            if (this.hasFilterContainerTarget && this.hasFilterPanelTarget
                && !this.filterContainerTarget.contains(e.target)) {
                this.filterPanelTarget.classList.add('hidden');
            }
        };
        document.addEventListener('click', this.outsideClick);

        // A freshly created entry: drop its marker immediately (no reload).
        this.onCreated = (e) => this.addCreatedMarker(e.detail);
        document.addEventListener('bc:created', this.onCreated);

        // Keep an open marker tooltip in sync with changes made in the dialog —
        // wishlist count, rating, and edits all update the popup (and icon) live.
        this.onWishlistChanged = (e) => this.updateMarker(e.detail.id, { openWishlistCount: e.detail.openCount });
        document.addEventListener('wishlist:changed', this.onWishlistChanged);

        // Watched-entry set for the "only watched" filter; kept live as the user
        // watches/unwatches from the detail dialog or profile modal.
        this.watchedIds = new Set(this.watchedidsValue || []);
        this.onWatchlistChanged = (e) => {
            if (!e.detail) return;
            if (e.detail.watching) this.watchedIds.add(e.detail.id);
            else this.watchedIds.delete(e.detail.id);
            this.applyFilters();
        };
        document.addEventListener('watchlist:changed', this.onWatchlistChanged);

        // Read the filter panel's initial (default) state so freshly-loaded
        // markers are gated by it. The panel DOM exists at this point.
        this.currentFilters = this.readFilters();

        this.onRatingChanged = (e) => this.updateMarker(e.detail.id, {
            ratingCount: e.detail.count,
            ratingAverage: e.detail.average,
        });
        document.addEventListener('bc:rating', this.onRatingChanged);

        this.onUpdated = (e) => this.updateMarker(e.detail.id, e.detail);
        document.addEventListener('bc:updated', this.onUpdated);

        // Home position set/changed/removed from the profile modal.
        this.onHomeChanged = (e) => this.applyHomeChange(e.detail || {});
        document.addEventListener('home:changed', this.onHomeChanged);

        // Logged-in users can add an entry at any point on the map: right-click
        // (desktop) or long-press (touch) drops an "Add bookcase here" popup.
        if (this.canaddValue) {
            this.map.on('contextmenu', (e) => this.showAddHerePopup(e.latlng));
            this.bindLongPress();
        }

        // Shared deep link (/bookcase/{id}): open its detail dialog once everything
        // is connected. Deferred a tick so the bookcase-modal controller is listening.
        if (this.initialIdValue !== '') {
            setTimeout(() => {
                document.dispatchEvent(new CustomEvent('bc:open', { detail: { id: this.initialIdValue } }));
            }, 0);
        }
    }

    disconnect() {
        document.removeEventListener('click', this.outsideClick);
        document.removeEventListener('bc:created', this.onCreated);
        document.removeEventListener('wishlist:changed', this.onWishlistChanged);
        document.removeEventListener('watchlist:changed', this.onWatchlistChanged);
        document.removeEventListener('bc:rating', this.onRatingChanged);
        document.removeEventListener('bc:updated', this.onUpdated);
        document.removeEventListener('home:changed', this.onHomeChanged);
    }

    // ── Add a new entry from the map ─────────────────────────────────────────

    // Emulate a context-menu on touch devices: a stationary ~550ms press.
    bindLongPress() {
        const el = this.map.getContainer();
        let timer = null;
        const cancel = () => { if (timer) { clearTimeout(timer); timer = null; } };

        el.addEventListener('touchstart', (e) => {
            if (e.touches.length !== 1) { cancel(); return; }
            const touch = e.touches[0];
            const point = this.map.mouseEventToLatLng(touch);
            timer = setTimeout(() => {
                timer = null;
                this.showAddHerePopup(point);
            }, 550);
        }, { passive: true });
        el.addEventListener('touchmove', cancel, { passive: true });
        el.addEventListener('touchend', cancel);
        el.addEventListener('touchcancel', cancel);
    }

    // A transient popup at `latlng`: the primary "Add bookcase here" button, plus
    // a separated, low-emphasis "Set as home" link (confirmed) for logged-in users.
    showAddHerePopup(latlng) {
        const wrapper = document.createElement('div');
        wrapper.className = 'flex flex-col items-stretch text-center';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-primary btn-sm';
        button.textContent = this.addhereValue;
        button.addEventListener('click', () => {
            this.map.closePopup();
            document.dispatchEvent(new CustomEvent('bc:create', {
                detail: { lat: latlng.lat, lon: latlng.lng, editable: false },
            }));
        });
        wrapper.append(button);

        // Set-home is separated below a divider + confirmed, so it can't be hit by
        // accident when the user just meant to add a bookcase.
        if (this.canaddValue && this.homeTokenValue) {
            const divider = document.createElement('div');
            divider.className = 'divider my-1';
            wrapper.append(divider);

            const homeBtn = document.createElement('button');
            homeBtn.type = 'button';
            homeBtn.className = 'btn btn-ghost btn-xs';
            homeBtn.textContent = this.sethereValue;
            homeBtn.addEventListener('click', () => this.setHome(latlng));
            wrapper.append(homeBtn);
        }

        L.popup({ offset: [0, -4] }).setLatLng(latlng).setContent(wrapper).openOn(this.map);
    }

    // Show (or move) a distinct, non-clustered marker at the user's home location.
    // Kept off the marker cluster so it never merges into a bookcase cluster.
    showHomeMarker() {
        const lat = parseFloat(this.homeLatValue);
        const lon = parseFloat(this.homeLonValue);
        if (isNaN(lat) || isNaN(lon)) return;

        if (this.homeMarker) {
            this.homeMarker.setLatLng([lat, lon]);
            return;
        }

        this.homeMarker = L.marker([lat, lon], {
            icon: L.icon({
                iconUrl: '/build/images/marker-icon-home.png',
                iconRetinaUrl: '/build/images/marker-icon-2x-home.png',
                iconSize: [30, 30],
                iconAnchor: [15, 15],
                popupAnchor: [0, -15],
            }),
            zIndexOffset: 1000,
            keyboard: false,
        }).addTo(this.map);

        // Popup label: the user's custom name (e.g. "Office") if set, else the
        // generic "Home" label. Bound as a function so it reflects label edits.
        this.homeMarker.bindPopup(() => this.homecustomlabelValue || this.homelabelValue);
    }

    removeHomeMarker() {
        if (this.homeMarker) {
            this.homeMarker.remove();
            this.homeMarker = null;
        }
    }

    // React to a home position set/removed in the profile modal (same page).
    applyHomeChange({ latitude, longitude, zoom, label, enabled, cleared } = {}) {
        if (cleared) {
            this.homeLatValue = '';
            this.homeLonValue = '';
            this.homeZoomValue = '';
            this.homecustomlabelValue = '';
            this.homeEnabledValue = false;
            this.removeHomeMarker();
            return;
        }

        this.homeLatValue = String(latitude);
        this.homeLonValue = String(longitude);
        if (zoom != null && zoom !== '') this.homeZoomValue = String(zoom);
        if (label !== undefined) this.homecustomlabelValue = label || '';
        this.homeEnabledValue = !!enabled;
        this.showHomeMarker();
        // Refresh an already-open popup so a label edit shows immediately.
        if (this.homeMarker && this.homeMarker.isPopupOpen()) this.homeMarker.getPopup().update();
    }

    // Save the clicked point (+ current zoom) as the user's home location and
    // enable the feature, after a confirmation.
    async setHome(latlng) {
        if (!window.confirm(this.sethomeconfirmValue)) return;

        const body = new FormData();
        body.append('_token', this.homeTokenValue);
        body.append('latitude', latlng.lat);
        body.append('longitude', latlng.lng);
        body.append('zoom', this.map.getZoom());
        body.append('enabled', '1');

        try {
            const response = await fetch('/profile/home', { method: 'POST', body });
            this.map.closePopup();
            if (response.ok) {
                // Broadcast so this map (marker/values) and the profile form fields
                // both update instantly. applyHomeChange handles the map side.
                document.dispatchEvent(new CustomEvent('home:changed', { detail: {
                    latitude: latlng.lat,
                    longitude: latlng.lng,
                    zoom: this.map.getZoom(),
                    enabled: true,
                } }));
                window.alert(this.sethomesavedValue);
            } else {
                window.alert(this.sethomefailedValue);
            }
        } catch {
            this.map.closePopup();
            window.alert(this.sethomefailedValue);
        }
    }

    addCreatedMarker({ id, latitude, longitude, title, entryType, mapSymbol } = {}) {
        if (!id || this.loadedMarkers.includes(id)) return;
        this.loadedMarkers.push(id);
        this.buildAndAddMarker({
            id,
            title,
            entryType,
            mapSymbol,
            position: { latitude, longitude },
            openWishlistCount: 0,
        });
    }

    showSpinner(visible) {
        if (this.spinner) this.spinner.hidden = !visible;
    }

    // ── Geolocation ────────────────────────────────────────────────────────

    addGeolocateControl() {
        const controller = this;
        const GeoControl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd() {
                const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                const link = L.DomUtil.create('a', '', container);
                link.href = '#';
                link.role = 'button';
                link.title = controller.geolocateValue;
                link.setAttribute('aria-label', controller.geolocateValue);
                // Center the icon within the (26px desktop / 30px touch) Leaflet button
                // so the white background fills the whole frame evenly.
                link.style.display = 'flex';
                link.style.alignItems = 'center';
                link.style.justifyContent = 'center';
                link.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px"><circle cx="12" cy="12" r="3.5"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>';
                L.DomEvent.on(link, 'click', (e) => {
                    L.DomEvent.preventDefault(e);
                    L.DomEvent.stopPropagation(e);
                    controller.locateUser(link);
                });
                L.DomEvent.disableClickPropagation(container);
                return container;
            },
        });
        this.map.addControl(new GeoControl());
    }

    locateUser(link) {
        if (!('geolocation' in navigator)) {
            window.alert(this.geounsupportedValue);
            return;
        }

        if (link) link.classList.add('opacity-50');
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                if (link) link.classList.remove('opacity-50');
                const latlng = [pos.coords.latitude, pos.coords.longitude];
                this.showUserLocation(latlng, pos.coords.accuracy);
                this.map.setView(latlng, 16);
            },
            () => {
                if (link) link.classList.remove('opacity-50');
                window.alert(this.geoerrorValue);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
        );
    }

    // A distinct blue dot (+ accuracy halo) for the user's own position, kept
    // off the marker cluster so it never merges with bookcase pins. Re-used on
    // repeated locates rather than stacking new layers.
    showUserLocation(latlng, accuracy) {
        // Remember the located position so bookcase popups can show the distance.
        this.userLat = latlng[0] ?? latlng.lat;
        this.userLng = latlng[1] ?? latlng.lng;

        if (this.userMarker) {
            this.userMarker.setLatLng(latlng);
            this.userAccuracy.setLatLng(latlng).setRadius(accuracy || 0);
            this.userMarker.setPopupContent(this.userLocationPopup(latlng));
            this.userMarker.openPopup();
            return;
        }

        this.userAccuracy = L.circle(latlng, {
            radius: accuracy || 0,
            color: '#2563eb',
            weight: 1,
            fillColor: '#3b82f6',
            fillOpacity: 0.15,
            interactive: false,
        }).addTo(this.map);

        this.userMarker = L.circleMarker(latlng, {
            radius: 8,
            color: '#ffffff',
            weight: 3,
            fillColor: '#2563eb',
            fillOpacity: 1,
        }).addTo(this.map);
        this.userMarker.bindPopup(this.userLocationPopup(latlng));
        this.userMarker.openPopup();
    }

    // Popup for the user's location dot: the label, plus a subtle "Add bookcase
    // here" link for logged-in users (kept low-key — locating is mainly used to
    // find nearby bookcases, not to add one).
    userLocationPopup(latlng) {
        const wrapper = document.createElement('div');
        wrapper.className = 'text-center';

        const label = document.createElement('div');
        label.className = 'font-medium';
        label.textContent = this.geolocateValue;
        wrapper.append(label);

        if (this.canaddValue) {
            const link = document.createElement('button');
            link.type = 'button';
            link.className = 'btn btn-ghost btn-xs mt-1 text-primary';
            link.textContent = this.addhereValue;
            link.addEventListener('click', () => {
                this.map.closePopup();
                document.dispatchEvent(new CustomEvent('bc:create', {
                    detail: { lat: latlng.lat ?? latlng[0], lon: latlng.lng ?? latlng[1], editable: false },
                }));
            });
            wrapper.append(link);
        }

        return wrapper;
    }

    // ── Search ─────────────────────────────────────────────────────────────

    onSearchInput() {
        const query = this.searchInputTarget.value.trim();
        this.searchClearTarget.classList.toggle('hidden', query === '');

        clearTimeout(this.debounceTimer);
        if (query.length < 2) {
            this.hideResults();
            return;
        }
        this.debounceTimer = setTimeout(() => this.runSearch(query), 300);
    }

    clearSearch() {
        this.searchInputTarget.value = '';
        this.searchClearTarget.classList.add('hidden');
        this.hideResults();
        this.searchInputTarget.focus();
    }

    runSearch(query) {
        const seq = ++this.searchSeq;
        const items = [];

        // 1) Direct coordinate jump, when the query looks like "lat, lon".
        const coord = parseCoords(query);
        if (coord) {
            items.push({
                type: 'coordinate',
                label: `${this.searchcoordinateValue}: ${coord.lat}, ${coord.lon}`,
                lat: coord.lat,
                lon: coord.lon,
            });
        }

        Promise.allSettled([
            axios.get(`/api/bookcase/search?q=${encodeURIComponent(query)}`),
            searchPhoton(query, this.map.getCenter()),
        ]).then(([bookcases, places]) => {
            if (seq !== this.searchSeq) return; // a newer query already fired

            const bcResults = bookcases.status === 'fulfilled' ? bookcases.value.data : [];
            bcResults.forEach((bc) => items.push({
                type: 'bookcase',
                group: this.searchbookcasesValue,
                label: bc.title,
                id: bc.id,
                lat: bc.latitude,
                lon: bc.longitude,
            }));

            const features = places.status === 'fulfilled' ? places.value : [];
            features.forEach((f) => {
                const label = placeLabel(f.properties);
                if (!label) return;
                items.push({
                    type: 'place',
                    group: this.searchplacesValue,
                    label,
                    lat: f.geometry.coordinates[1],
                    lon: f.geometry.coordinates[0],
                });
            });

            this.renderResults(items);
        });
    }

    renderResults(items) {
        this.searchItems = items;
        this.activeIndex = -1;
        const list = this.searchResultsTarget;
        list.innerHTML = '';

        if (items.length === 0) {
            const li = document.createElement('li');
            li.className = 'menu-disabled';
            const span = document.createElement('span');
            span.className = 'opacity-60';
            span.textContent = this.searchnoresultsValue;
            li.append(span);
            list.append(li);
            this.showResults();
            return;
        }

        let lastGroup = null;
        items.forEach((item, index) => {
            if (item.group && item.group !== lastGroup) {
                lastGroup = item.group;
                const title = document.createElement('li');
                title.className = 'menu-title';
                title.textContent = item.group;
                list.append(title);
            }

            const li = document.createElement('li');
            const a = document.createElement('a');
            a.textContent = item.label;
            a.dataset.index = index;
            a.addEventListener('click', (e) => {
                e.preventDefault();
                this.selectResult(item);
            });
            li.append(a);
            list.append(li);
        });

        this.showResults();
    }

    onSearchKeydown(event) {
        const selectable = this.searchItems.length;

        switch (event.key) {
            case 'ArrowDown':
                if (!selectable) return;
                event.preventDefault();
                this.moveActive(1);
                break;
            case 'ArrowUp':
                if (!selectable) return;
                event.preventDefault();
                this.moveActive(-1);
                break;
            case 'Enter':
                if (this.activeIndex >= 0 && this.searchItems[this.activeIndex]) {
                    event.preventDefault();
                    this.selectResult(this.searchItems[this.activeIndex]);
                } else if (selectable === 1) {
                    event.preventDefault();
                    this.selectResult(this.searchItems[0]);
                }
                break;
            case 'Escape':
                this.hideResults();
                break;
        }
    }

    moveActive(delta) {
        const count = this.searchItems.length;
        this.activeIndex = (this.activeIndex + delta + count) % count;

        this.searchResultsTarget.querySelectorAll('a[data-index]').forEach((a) => {
            const isActive = Number(a.dataset.index) === this.activeIndex;
            a.classList.toggle('menu-active', isActive);
            if (isActive) a.scrollIntoView({ block: 'nearest' });
        });
    }

    selectResult(item) {
        this.hideResults();

        if (item.type === 'bookcase') {
            this.searchInputTarget.value = item.label;
            this.map.setView([item.lat, item.lon], 17);
            document.dispatchEvent(new CustomEvent('bc:open', { detail: { id: item.id } }));
            return;
        }

        // place / coordinate
        this.searchInputTarget.value = item.type === 'place' ? item.label : this.searchInputTarget.value;
        this.map.setView([item.lat, item.lon], 16);
    }

    showResults() {
        this.searchResultsTarget.classList.remove('hidden');
    }

    hideResults() {
        this.searchResultsTarget.classList.add('hidden');
        this.activeIndex = -1;
    }

    // ── Markers ──────────────────────────────────────────────────────────────

    loadEntries() {
        const bounds = this.getBoundingCoordinates(this.map.getBounds());

        if (!this.loadRequired(bounds) && this.loadedMarkers.length > 0) {
            return;
        }

        this.showSpinner(true);
        axios.get(`/api/bookcase?latMin=${bounds.latMin}&latMax=${bounds.latMax}&lonMin=${bounds.lngMin}&lonMax=${bounds.lngMax}`)
            .then((response) => {
                response.data.forEach((item) => {
                    if (this.loadedMarkers.includes(item.id)) {
                        return;
                    }
                    this.loadedMarkers.push(item.id);
                    this.buildAndAddMarker(item);
                });
                this.showSpinner(false);
            })
            .catch(() => this.showSpinner(false));
    }

    // Pick the marker icon. An inactive (currently unavailable) entry always gets
    // the dedicated inactive badge — availability is the most important signal.
    // Otherwise tardis (a rare, manually-assigned DB symbol) wins; then the base
    // pin is chosen by the entry type (givebox vs bookcase), and — when the entry
    // has an accessibility level — its red/yellow/green variant is used.
    markerIcon(item) {
        if ('inactive' === item.status) {
            return L.icon({
                iconUrl: '/build/images/marker-icon-inactive.png',
                iconRetinaUrl: '/build/images/marker-icon-2x-inactive.png',
                iconSize: [32, 32],
                iconAnchor: [16, 16],
                popupAnchor: [0, -16],
            });
        }

        if ('tardis' === item.mapSymbol) {
            return L.icon({
                iconUrl: '/build/images/marker-icon-tardis.png',
                iconRetinaUrl: '/build/images/marker-icon-tardis-2x.png',
                iconSize: [25, 38],
                iconAnchor: [8, 38],
            });
        }

        // Accessibility colour suffix, shared by both base pins:
        // '' / '-accessibility-green' / '-accessibility-red' / '-accessibility-yellow'.
        const color = item.accessibility ? `-accessibility-${item.accessibility}` : '';

        // Giveboxes use a square badge (1:1) rather than the teardrop pin, so they
        // get square sizing centred on the point and no drop shadow.
        if ('givebox' === item.entryType) {
            return L.icon({
                iconUrl: `/build/images/marker-icon-givebox${color}.png`,
                iconRetinaUrl: `/build/images/marker-icon-2x-givebox${color}.png`,
                iconSize: [30, 30],
                iconAnchor: [15, 15],
                popupAnchor: [0, -15],
            });
        }

        return L.icon({
            iconUrl: `/build/images/marker-icon${color}.png`,
            iconRetinaUrl: `/build/images/marker-icon-2x${color}.png`,
            shadowUrl: '/build/images/marker-shadow.png',
            iconSize: [25, 41],
            iconAnchor: [12, 41],
        });
    }

    // Build one marker (with its detail popup) and add it to the cluster.
    buildAndAddMarker(item) {
        const newMarker = L.marker(
            [item.position.latitude, item.position.longitude],
            { icon: this.markerIcon(item), draggable: this.canaddValue }
        );

        // Bind the content as a function so it's rebuilt on every open — the
        // distance-to-user line then reflects the latest geolocation result even
        // when the user locates themselves after the markers were drawn.
        newMarker.bindPopup(() => this.popupContent(item), { offset: [0, -20] });

        // Logged-in users can correct a misplaced entry by dragging its marker.
        if (this.canaddValue) {
            newMarker.on('dragstart', (e) => { e.target.closePopup(); e.target._preDragLatLng = e.target.getLatLng(); });
            newMarker.on('dragend', (e) => this.onMarkerMoved(e.target, item.id));
        }

        // Keep a handle on the marker + its (mutable) data so dialog actions can
        // refresh it live. popupContent reads this same object, so mutating it is
        // enough for the next open; an open popup is refreshed explicitly below.
        this.markersById[item.id] = { marker: newMarker, item };

        // Only show it if it passes the active filters (kept off the cluster
        // otherwise; applyFilters() can add it back when filters change).
        if (this.passesFilters(item, this.currentFilters)) {
            this.markerCluster.addLayer(newMarker);
        }
    }

    // ── Marker filters ───────────────────────────────────────────────────────

    // Show/hide the filter panel (and keep aria state in sync).
    toggleFilterPanel() {
        if (!this.hasFilterPanelTarget) return;
        this.filterPanelTarget.classList.toggle('hidden');
    }

    // Read the panel's current state into a plain object. Queried by attribute
    // (not a Stimulus target) so it also works during initialize(), before the
    // first marker load. Returns null when the panel isn't present (no filtering).
    readFilters() {
        const panel = this.element.querySelector('[data-map-target="filterPanel"]');
        if (!panel) return null;

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
            totals: {
                accessibility: total('f-accessibility'),
                status: total('f-status'),
                type: total('f-type'),
                mobility: total('f-mobility'),
            },
        };
    }

    // Does a marker's data satisfy the given filter state?
    passesFilters(item, f) {
        if (!f) return true;

        // null accessibility (no level set) is its own bucket: 'unset'.
        const accToken = item.accessibility || 'unset';
        if (!f.accessibility.includes(accToken)) return false;
        if (!f.status.includes(item.status)) return false;
        if (!f.type.includes(item.entryType)) return false;
        if (!f.mobility.includes(item.isMobile ? 'mobile' : 'fixed')) return false;

        if (f.minRating > 0 && !(item.ratingAverage != null && item.ratingAverage >= f.minRating)) return false;
        if (f.wishlist && !(item.openWishlistCount > 0)) return false;
        if (f.bookcrossing && !item.isBookcrossingZone) return false;
        if (f.watching && !this.watchedIds.has(item.id)) return false;

        return true;
    }

    // Re-evaluate every loaded marker against the (possibly changed) filters and
    // add/remove it from the cluster in a single batch.
    applyFilters() {
        this.currentFilters = this.readFilters();

        const toAdd = [];
        const toRemove = [];
        Object.values(this.markersById).forEach(({ marker, item }) => {
            const pass = this.passesFilters(item, this.currentFilters);
            const onMap = this.markerCluster.hasLayer(marker);
            if (pass && !onMap) toAdd.push(marker);
            else if (!pass && onMap) toRemove.push(marker);
        });

        if (toRemove.length) this.markerCluster.removeLayers(toRemove);
        if (toAdd.length) this.markerCluster.addLayers(toAdd);

        this.updateFilterBadge(this.currentFilters);
    }

    // Reset every control to its default (everything visible) and re-apply.
    resetFilters() {
        const panel = this.element.querySelector('[data-map-target="filterPanel"]');
        if (!panel) return;

        panel.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
            // Category checkboxes default to checked; the wishlist/bookcrossing/
            // watching toggles default to off.
            cb.checked = !['f-wishlist', 'f-bookcrossing', 'f-watching'].includes(cb.name);
        });
        const ratingEl = panel.querySelector('select[name="f-rating"]');
        if (ratingEl) ratingEl.value = '0';

        this.applyFilters();
    }

    // Badge = number of filter dimensions that deviate from "show everything".
    updateFilterBadge(f) {
        if (!this.hasFilterBadgeTarget) return;

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
        }

        this.filterBadgeTarget.textContent = String(n);
        this.filterBadgeTarget.classList.toggle('hidden', n === 0);
    }

    // Merge new data into a marker and refresh its icon + open popup in place.
    // Called from dialog-driven events (wishlist, rating, edit save).
    updateMarker(id, patch = {}) {
        const entry = this.markersById[id];
        if (!entry) return;

        Object.assign(entry.item, patch);

        // An edit may have moved the entry — reposition the marker if so.
        const pos = entry.item.position;
        if (patch.position && pos && pos.latitude != null && pos.longitude != null) {
            entry.marker.setLatLng([pos.latitude, pos.longitude]);
        }

        entry.marker.setIcon(this.markerIcon(entry.item));
        if (entry.marker.isPopupOpen()) entry.marker.getPopup().update();

        // The change (rating, wishlist count, mobility, …) may flip whether the
        // entry still matches the active filters — reconcile its membership.
        const pass = this.passesFilters(entry.item, this.currentFilters);
        const onMap = this.markerCluster.hasLayer(entry.marker);
        if (pass && !onMap) {
            this.markerCluster.addLayer(entry.marker);
        } else if (!pass && onMap) {
            this.markerCluster.removeLayer(entry.marker);
        } else if (onMap) {
            this.markerCluster.refreshClusters(entry.marker);
        }
    }

    // After a drag: confirm the move was intentional, then persist it. On
    // cancel or failure, snap the marker back to where it started.
    onMarkerMoved(marker, id) {
        const pos = marker.getLatLng();
        const original = marker._preDragLatLng;

        if (!window.confirm(this.moveconfirmValue)) {
            if (original) { marker.setLatLng(original); this.markerCluster.refreshClusters(marker); }
            return;
        }

        fetch(`/api/bookcase/${id}/position`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `latitude=${encodeURIComponent(pos.lat)}&longitude=${encodeURIComponent(pos.lng)}`,
        }).then((response) => {
            if (!response.ok) throw new Error('save failed');
            // Keep the new position; refresh so clustering reflects the move.
            this.markerCluster.refreshClusters(marker);
        }).catch(() => {
            if (original) marker.setLatLng(original);
            this.markerCluster.refreshClusters(marker);
            window.alert(this.movefailedValue);
        });
    }

    popupContent(item) {
        const wrapper = document.createElement('div');
        wrapper.className = 'text-center';

        const title = document.createElement('h6');
        title.className = 'mb-2 font-semibold';
        title.textContent = item.title;

        wrapper.append(title);

        // Inactive entries: flag "currently unavailable" + show the disclaimer
        // right in the popup so users don't have to open the detail view.
        if ('inactive' === item.status) {
            const badge = document.createElement('div');
            badge.className = 'badge badge-error badge-sm mb-2';
            badge.textContent = this.inactiveValue || 'Currently unavailable';
            wrapper.append(badge);

            if (item.statusDescription) {
                const note = document.createElement('p');
                note.className = 'mb-2 text-xs text-base-content/70';
                note.textContent = item.statusDescription;
                wrapper.append(note);
            }
        }

        // Flag open wishes so passers-by know a book is wanted here.
        if (item.openWishlistCount > 0) {
            const badge = document.createElement('div');
            badge.className = 'badge badge-secondary badge-sm mb-2';
            badge.textContent = (this.wishesopenValue || '%count% wishes open').replace('%count%', item.openWishlistCount);
            wrapper.append(badge);
        }

        // Average rating (when at least one rating exists) and — once the user has
        // located themselves — the distance to this bookcase.
        const meta = [];
        if (item.ratingCount > 0 && item.ratingAverage != null) {
            meta.push(`♥ ${item.ratingAverage.toFixed(1)} (${item.ratingCount})`);
        }
        if (this.userLat != null && this.userLng != null) {
            meta.push(this.formatDistance(
                this.distanceKm(this.userLat, this.userLng, item.position.latitude, item.position.longitude)
            ));
        }
        if (meta.length) {
            const info = document.createElement('div');
            info.className = 'mb-2 text-xs text-base-content/70';
            info.textContent = meta.join(' · ');
            wrapper.append(info);
        }

        const button = document.createElement('button');
        button.className = 'btn btn-primary btn-sm';
        button.textContent = this.detailstransValue;
        // Picked up by the permanent bookcase-modal controller via event delegation.
        button.dataset.bcOpen = item.id;

        wrapper.append(button);
        return wrapper;
    }

    // Great-circle distance between two points in kilometres (haversine).
    distanceKm(lat1, lon1, lat2, lon2) {
        const R = 6371;
        const toRad = (d) => (d * Math.PI) / 180;
        const dLat = toRad(lat2 - lat1);
        const dLon = toRad(lon2 - lon1);
        const a = Math.sin(dLat / 2) ** 2 +
            Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    // Human-readable distance: metres under 1 km, otherwise one decimal of km.
    formatDistance(km) {
        return km < 1 ? `${Math.round(km * 1000)} m` : `${km.toFixed(1)} km`;
    }

    loadRequired(bounds) {
        if (bounds.latMin < this.latMin ||
            bounds.latMax > this.latMax ||
            bounds.lngMin < this.lngMin ||
            bounds.lngMax > this.lngMax) {

            this.lngMin = Math.min(bounds.lngMin, this.lngMin);
            this.lngMax = Math.max(bounds.lngMax, this.lngMax);
            this.latMin = Math.min(bounds.latMin, this.latMin);
            this.latMax = Math.max(bounds.latMax, this.latMax);

            return true;
        }

        return false;
    }

    getBoundingCoordinates(bounds) {
        const ne = bounds.getNorthEast();
        const sw = bounds.getSouthWest();

        return {
            latMin: sw.lat,
            latMax: ne.lat,
            lngMin: sw.lng,
            lngMax: ne.lng
        };
    }
}
