import axios from 'axios';

// Free, key-less OSM-based geocoder built for type-ahead (better suited to
// per-keystroke autocomplete than Nominatim, whose usage policy discourages it).
const PHOTON_URL = 'https://photon.komoot.io/api/';
const PHOTON_LANGS = ['en', 'de', 'fr'];

// "50.1, 8.8" / "50.1 8.8" — a raw coordinate the user can jump to / enter directly.
const COORD_RE = /^\s*(-?\d{1,3}(?:\.\d+)?)\s*[,\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/;
// Wikipedia "geo:" URI — e.g. "geo:50.19,8.8".
const GEO_RE = /geo:\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)/i;

// Photon's supported languages are limited; fall back to no `lang` otherwise.
export function photonLang() {
    const lang = (document.documentElement.lang || '').slice(0, 2).toLowerCase();
    return PHOTON_LANGS.includes(lang) ? lang : null;
}

// Query Photon, biased to `center` ({lat, lng}). Resolves to its GeoJSON features.
export async function searchPhoton(query, center, limit = 5) {
    const params = new URLSearchParams({ q: query, limit: String(limit) });
    if (center) {
        params.set('lat', center.lat);
        params.set('lon', center.lng);
    }
    const lang = photonLang();
    if (lang) params.set('lang', lang);

    const response = await axios.get(`${PHOTON_URL}?${params.toString()}`);
    return response.data.features || [];
}

// Build a human-readable one-line label from a Photon feature's properties.
export function placeLabel(props) {
    const main = props.name || [props.street, props.housenumber].filter(Boolean).join(' ');
    const locality = [props.postcode, props.city || props.county || props.state].filter(Boolean).join(' ');
    return [main, locality, props.country].filter(Boolean).join(', ');
}

// Parse "lat, lon" / "lat lon" → {lat, lon} (validated), or null.
export function parseCoords(str) {
    const m = String(str).match(COORD_RE);
    if (!m) return null;
    const lat = parseFloat(m[1]);
    const lon = parseFloat(m[2]);
    if (lat < -90 || lat > 90 || lon < -180 || lon > 180) return null;
    return { lat, lon };
}

// Parse a "geo:lat,lon" URI (anywhere in the string) → {lat, lon} (validated), or null.
export function parseGeo(str) {
    const m = String(str).match(GEO_RE);
    if (!m) return null;
    const lat = parseFloat(m[1]);
    const lon = parseFloat(m[2]);
    if (lat < -90 || lat > 90 || lon < -180 || lon > 180) return null;
    return { lat, lon };
}
