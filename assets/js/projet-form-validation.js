(function () {
    const projetForm = document.getElementById('projet-form');

    if (!projetForm) {
        return;
    }

    const searchInput = document.getElementById('member-search');
    const list = document.getElementById('member-checkbox-list');
    const emptyState = document.getElementById('member-search-empty');

    const fields = {
        nom: document.querySelector('[data-field-id="nom"]'),
        description: document.querySelector('[data-field-id="description"]'),
        dateDebut: document.querySelector('[data-field-id="date_debut"]'),
        dateFinPrevue: document.querySelector('[data-field-id="date_fin_prevue"]'),
        dateFinReelle: document.querySelector('[data-field-id="date_fin_reelle"]'),
        responsable: document.querySelector('[data-field-id="responsable"]'),
        statut: document.querySelector('[data-field-id="statut"]'),
        priorite: document.querySelector('[data-field-id="priorite"]'),
    };

    const errors = {
        nom: document.getElementById('nom-live-error'),
        description: document.getElementById('description-live-error'),
        dateDebut: document.getElementById('date-debut-live-error'),
        dateFinPrevue: document.getElementById('date-fin-prevue-live-error'),
        dateFinReelle: document.getElementById('date-fin-reelle-live-error'),
        responsable: document.getElementById('responsable-live-error'),
        statut: document.getElementById('statut-live-error'),
        priorite: document.getElementById('priorite-live-error'),
    };

    const memberCheckboxes = Array.from(document.querySelectorAll('.member-checkbox'));

    function setFieldError(input, errorContainer, message) {
        if (!input || !errorContainer) {
            return;
        }

        if (message) {
            input.classList.add('is-invalid');
            errorContainer.textContent = message;
            errorContainer.style.display = 'block';
            return;
        }

        input.classList.remove('is-invalid');
        errorContainer.textContent = '';
        errorContainer.style.display = 'none';
    }

    function normalizeSpaces(value) {
        return value.replace(/\s+/g, ' ').trim();
    }

    function validateNom() {
        const input = fields.nom;

        if (!input) {
            return true;
        }

        const normalized = normalizeSpaces(input.value);

        if (input.value !== normalized && input.value.trim() !== '') {
            input.value = normalized;
        }

        if (normalized.length === 0) {
            setFieldError(input, errors.nom, 'Le nom du projet est obligatoire.');
            return false;
        }

        if (normalized.length < 3) {
            setFieldError(input, errors.nom, 'Le nom doit contenir au moins 3 caracteres.');
            return false;
        }

        if (normalized.length > 100) {
            setFieldError(input, errors.nom, 'Le nom ne peut pas depasser 100 caracteres.');
            return false;
        }

        setFieldError(input, errors.nom, '');
        return true;
    }

    function validateDescription() {
        const input = fields.description;

        if (!input) {
            return true;
        }

        const trimmed = input.value.trim();

        if (trimmed.length === 0) {
            setFieldError(input, errors.description, 'La description est obligatoire.');
            return false;
        }

        if (trimmed.length < 10) {
            setFieldError(input, errors.description, 'La description doit contenir au moins 10 caracteres.');
            return false;
        }

        if (trimmed.length > 1000) {
            setFieldError(input, errors.description, 'La description ne peut pas depasser 1000 caracteres.');
            return false;
        }

        setFieldError(input, errors.description, '');
        return true;
    }

    function validateDates() {
        let isValid = true;

        if (fields.dateDebut) {
            if (fields.dateDebut.value === '') {
                setFieldError(fields.dateDebut, errors.dateDebut, 'La date de debut est obligatoire.');
                isValid = false;
            } else {
                setFieldError(fields.dateDebut, errors.dateDebut, '');
            }
        }

        if (fields.dateFinPrevue) {
            if (fields.dateFinPrevue.value === '') {
                setFieldError(fields.dateFinPrevue, errors.dateFinPrevue, 'La date de fin prevue est obligatoire.');
                isValid = false;
            } else if (fields.dateDebut && fields.dateDebut.value !== '' && fields.dateFinPrevue.value < fields.dateDebut.value) {
                setFieldError(fields.dateFinPrevue, errors.dateFinPrevue, 'La date de fin prevue doit etre superieure ou egale a la date de debut.');
                isValid = false;
            } else {
                setFieldError(fields.dateFinPrevue, errors.dateFinPrevue, '');
            }
        }

        if (fields.dateFinReelle) {
            if (fields.dateFinReelle.value !== '' && fields.dateDebut && fields.dateDebut.value !== '' && fields.dateFinReelle.value < fields.dateDebut.value) {
                setFieldError(fields.dateFinReelle, errors.dateFinReelle, 'La date de fin reelle doit etre superieure ou egale a la date de debut.');
                isValid = false;
            } else if (fields.statut && fields.statut.value === 'TERMINE' && fields.dateFinReelle.value === '') {
                setFieldError(fields.dateFinReelle, errors.dateFinReelle, 'Renseignez la date de fin reelle pour un projet termine.');
                isValid = false;
            } else if (fields.dateFinReelle.value !== '' && fields.statut && !['TERMINE', 'ANNULE'].includes(fields.statut.value)) {
                setFieldError(fields.dateFinReelle, errors.dateFinReelle, 'La date de fin reelle ne peut etre renseignee que pour un projet termine ou annule.');
                isValid = false;
            } else {
                setFieldError(fields.dateFinReelle, errors.dateFinReelle, '');
            }
        }

        return isValid;
    }

    function validateSelects() {
        let isValid = true;

        if (fields.responsable) {
            if (fields.responsable.value === '') {
                setFieldError(fields.responsable, errors.responsable, 'Veuillez choisir un responsable.');
                isValid = false;
            } else {
                setFieldError(fields.responsable, errors.responsable, '');
            }
        }

        if (fields.statut) {
            if (fields.statut.value === '') {
                setFieldError(fields.statut, errors.statut, 'Veuillez choisir un statut.');
                isValid = false;
            } else {
                setFieldError(fields.statut, errors.statut, '');
            }
        }

        if (fields.priorite) {
            if (fields.priorite.value === '') {
                setFieldError(fields.priorite, errors.priorite, 'Veuillez choisir une priorite.');
                isValid = false;
            } else {
                setFieldError(fields.priorite, errors.priorite, '');
            }
        }

        return isValid;
    }

    function syncResponsableWithEquipe() {
        const responsableValue = fields.responsable ? fields.responsable.value : '';

        if (!responsableValue) {
            return;
        }

        memberCheckboxes.forEach(function (checkbox) {
            if (checkbox.value === responsableValue) {
                checkbox.checked = true;
            }
        });
    }

    if (searchInput && list) {
        const options = Array.from(list.querySelectorAll('.member-option'));

        searchInput.addEventListener('input', function () {
            const term = searchInput.value.trim().toLowerCase();
            let visibleCount = 0;

            options.forEach(function (option) {
                const label = option.getAttribute('data-member-label') || '';
                const visible = label.includes(term);
                option.style.display = visible ? '' : 'none';

                if (visible) {
                    visibleCount += 1;
                }
            });

            if (emptyState) {
                emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
            }
        });
    }

    if (fields.nom) {
        fields.nom.addEventListener('input', validateNom);
        fields.nom.addEventListener('blur', validateNom);
    }

    if (fields.description) {
        fields.description.addEventListener('input', validateDescription);
        fields.description.addEventListener('blur', validateDescription);
    }

    ['dateDebut', 'dateFinPrevue', 'dateFinReelle'].forEach(function (key) {
        if (fields[key]) {
            fields[key].addEventListener('input', validateDates);
            fields[key].addEventListener('change', validateDates);
            fields[key].addEventListener('blur', validateDates);
        }
    });

    if (fields.responsable) {
        fields.responsable.addEventListener('change', function () {
            syncResponsableWithEquipe();
            validateSelects();
        });
        fields.responsable.addEventListener('blur', validateSelects);
    }

    if (fields.statut) {
        fields.statut.addEventListener('change', function () {
            validateSelects();
            validateDates();
        });
        fields.statut.addEventListener('blur', function () {
            validateSelects();
            validateDates();
        });
    }

    if (fields.priorite) {
        fields.priorite.addEventListener('change', validateSelects);
        fields.priorite.addEventListener('blur', validateSelects);
    }

    syncResponsableWithEquipe();
    validateNom();
    validateDescription();
    validateDates();
    validateSelects();

    projetForm.addEventListener('submit', function (event) {
        syncResponsableWithEquipe();

        const isValid = [
            validateNom(),
            validateDescription(),
            validateDates(),
            validateSelects(),
        ].every(Boolean);

        if (!isValid) {
            event.preventDefault();

            const firstInvalid = projetForm.querySelector('.is-invalid');
            if (firstInvalid) {
                firstInvalid.focus();
            }
        }
    });
})();
