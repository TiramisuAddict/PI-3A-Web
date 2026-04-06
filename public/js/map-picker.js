class MapPickerDialog {
    constructor(options = {}) {
        this.map = null;
        this.marker = null;
        this.selected = null;
        this.callback = null;
        this.readOnly = false;

        this.modalElement = document.getElementById('mapPickerModal');
        this.mapElement = document.getElementById('mapPicker');
        this.infoElement = document.getElementById('mapPickerInfo');
        this.searchInput = document.getElementById('mapSearchInput');
        this.searchButton = document.getElementById('mapSearchButton');
        this.confirmButton = document.getElementById('mapConfirmButton');

        this.modal = new bootstrap.Modal(this.modalElement);

        this.searchUrl = options.searchUrl || '/map/search';
        this.reverseUrl = options.reverseUrl || '/map/reverse';

        this.defaultLat = options.defaultLat || 34.0;
        this.defaultLon = options.defaultLon || 9.0;
        this.defaultZoom = options.defaultZoom || 6;

        this._bindEvents();
    }

    _bindEvents() {
        this.modalElement.addEventListener('shown.bs.modal', () => {
            this._initMap();
            setTimeout(() => {
                this.map.invalidateSize();
            }, 150);
        });

        this.searchButton.addEventListener('click', () => {
            this.searchCity(this.searchInput.value);
        });

        this.searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.searchCity(this.searchInput.value);
            }
        });

        this.confirmButton.addEventListener('click', () => {
            if (this.selected && this.callback) {
                this.callback(this.selected);
                this.modal.hide();
            }
        });
    }

    _initMap() {
        if (!this.map) {
            this.map = L.map(this.mapElement).setView([this.defaultLat, this.defaultLon], this.defaultZoom);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: 'OpenStreetMap'
            }).addTo(this.map);

            this.map.on('click', (e) => {
                if (this.readOnly) return;
                this.reverseGeocode(e.latlng.lat, e.latlng.lng);
            });
        } else {
            this.map.setView([this.defaultLat, this.defaultLon], this.defaultZoom);
        }
    }

    open(callback = null) {
        this.callback = callback;
        this.readOnly = false;
        this.selected = null;
        this.confirmButton.disabled = true;
        this.confirmButton.style.display = '';
        this.infoElement.textContent = 'Cliquez pour choisir un lieu';
        this.searchInput.value = '';

        if (this.marker) {
            this.map?.removeLayer(this.marker);
            this.marker = null;
        }

        this.modal.show();
    }

    openReadOnly(lat, lon, locationName = 'Localisation') {
        this.callback = null;
        this.readOnly = true;
        this.selected = {
            cityName: locationName,
            lat: lat,
            lon: lon
        };

        this.confirmButton.disabled = true;
        this.confirmButton.style.display = 'none';
        this.infoElement.textContent = '📍 ' + locationName;
        this.searchInput.value = locationName;

        this.modal.show();

        setTimeout(() => {
            this._initMap();
            this.map.setView([lat, lon], 14);
            this._setMarker(lat, lon, locationName);
        }, 200);
    }

    _setMarker(lat, lon, label = null) {
        if (this.marker) {
            this.map.removeLayer(this.marker);
        }

        this.marker = L.marker([lat, lon]).addTo(this.map);

        if (label) {
            this.marker.bindPopup(label).openPopup();
        }
    }

    async reverseGeocode(lat, lon) {
        this.infoElement.textContent = 'Recherche en cours...';

        try {
            const response = await fetch(`${this.reverseUrl}?lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lon)}`);
            const data = await response.json();

            const city =
                data.address?.city ||
                data.address?.town ||
                data.address?.village ||
                data.address?.municipality ||
                data.address?.county ||
                data.address?.state ||
                data.display_name ||
                'Lieu inconnu';

            this.selected = {
                cityName: city,
                lat: lat,
                lon: lon,
                displayName: data.display_name || city
            };

            this.infoElement.textContent = 'Destination: ' + city;
            this.confirmButton.disabled = false;

            this._setMarker(lat, lon, data.display_name || city);
            this.map.setView([lat, lon], Math.max(this.map.getZoom(), 12));
        } catch (e) {
            this.infoElement.textContent = 'Erreur reseau';
        }
    }

    async searchCity(query) {
        if (!query || !query.trim()) return;

        this.infoElement.textContent = 'Recherche: ' + query + '...';

        try {
            const response = await fetch(`${this.searchUrl}?q=${encodeURIComponent(query.trim())}`);
            const results = await response.json();

            if (results && results.length > 0) {
                const result = results[0];
                const lat = parseFloat(result.lat);
                const lon = parseFloat(result.lon);

                this.map.setView([lat, lon], 12);
                await this.reverseGeocode(lat, lon);
            } else {
                this.infoElement.textContent = 'Ville non trouvee: ' + query;
            }
        } catch (e) {
            this.infoElement.textContent = 'Erreur de recherche';
        }
    }
}

window.MapPickerDialog = MapPickerDialog;