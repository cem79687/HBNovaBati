/**
 * HBNova Bâti — Traitement du formulaire de contact.
 * Aucune dépendance externe (jQuery retiré, inutile ici).
 */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('contactForm');
    if (!form) return;

    var successBox = document.getElementById('form-message-success');
    var warningBox = document.getElementById('form-message-warning');
    var submitBtn = form.querySelector('button[type="submit"]');
    var submitBtnDefaultHTML = submitBtn ? submitBtn.innerHTML : '';

    function hideMessages() {
        if (successBox) successBox.style.display = 'none';
        if (warningBox) warningBox.style.display = 'none';
    }

    function showSuccess() {
        hideMessages();
        if (successBox) successBox.style.display = 'block';
        successBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function showWarning(message) {
        hideMessages();
        if (warningBox) {
            if (message) warningBox.textContent = message;
            warningBox.style.display = 'block';
        }
        warningBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // Validation basique côté client (en plus des attributs HTML5 required/pattern déjà en place)
    // Retourne null si tout est valide, ou un message d'erreur précis sinon.
    function getValidationError() {
        var name = form.querySelector('#name');
        var email = form.querySelector('#email');
        var phone = form.querySelector('#phone');
        var message = form.querySelector('#message');

        if (!name.value.trim() || !email.value.trim() || !phone.value.trim() || !message.value.trim()) {
            return 'Merci de vérifier les champs obligatoires (nom, email, téléphone, message).';
        }
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(email.value.trim())) {
            return 'Merci de vérifier les champs obligatoires (nom, email, téléphone, message).';
        }

        // Pièce jointe (facultative) : on vérifie type et taille si un fichier est fourni
        var attachment = form.querySelector('#attachment');
        if (attachment && attachment.files && attachment.files.length > 0) {
            var file = attachment.files[0];
            var allowedTypes = ['application/pdf', 'image/png', 'image/jpeg'];
            var maxSize = 5 * 1024 * 1024; // 5 Mo
            if (allowedTypes.indexOf(file.type) === -1) {
                return 'Le fichier joint doit être un PDF, un PNG ou un JPG.';
            }
            if (file.size > maxSize) {
                return 'Le fichier joint dépasse la taille maximale (5 Mo).';
            }
        }

        // Consentement RGPD obligatoire
        var consent = form.querySelector('#consent');
        if (consent && !consent.checked) {
            return 'Merci de cocher la case de consentement pour l\'utilisation de vos données.';
        }

        return null;
    }

    // Zone de dépôt de fichier : affichage du nom choisi + glisser-déposer
    var uploadZone = document.getElementById('uploadZone');
    var attachmentInput = document.getElementById('attachment');
    var uploadZoneContent = document.getElementById('uploadZoneContent');
    var uploadZoneDefaultHTML = uploadZoneContent ? uploadZoneContent.innerHTML : '';

    function updateUploadZoneLabel() {
        if (!attachmentInput || !uploadZoneContent) return;
        if (attachmentInput.files && attachmentInput.files.length > 0) {
            uploadZoneContent.innerHTML = '<i class="fa fa-check-circle upload-zone-icon" style="color:#2e7d32;"></i>' +
                '<span>Fichier sélectionné</span>' +
                '<span class="upload-zone-filename">' + attachmentInput.files[0].name + '</span>';
        } else {
            uploadZoneContent.innerHTML = uploadZoneDefaultHTML;
        }
    }

    if (attachmentInput) {
        attachmentInput.addEventListener('change', updateUploadZoneLabel);
    }

    if (uploadZone && attachmentInput) {
        ['dragover', 'dragenter'].forEach(function (evt) {
            uploadZone.addEventListener(evt, function (e) {
                e.preventDefault();
                uploadZone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'dragend'].forEach(function (evt) {
            uploadZone.addEventListener(evt, function () {
                uploadZone.classList.remove('is-dragover');
            });
        });
        uploadZone.addEventListener('drop', function (e) {
            e.preventDefault();
            uploadZone.classList.remove('is-dragover');
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                attachmentInput.files = e.dataTransfer.files;
                updateUploadZoneLabel();
            }
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        hideMessages();

        var validationError = getValidationError();
        if (validationError) {
            showWarning(validationError);
            return;
        }

        // Vérifie que le widget Cloudflare Turnstile a bien été validé
        var turnstileResponse = form.querySelector('[name="cf-turnstile-response"]');
        if (!turnstileResponse || !turnstileResponse.value) {
            showWarning('Merci de valider la vérification de sécurité avant d\'envoyer.');
            return;
        }

        // Piège à robots : si rempli, on abandonne silencieusement (comportement normal côté utilisateur)
        var honeypot = form.querySelector('#site_web');
        if (honeypot && honeypot.value.trim() !== '') {
            showSuccess();
            form.reset();
            updateUploadZoneLabel();
            return;
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i> Envoi en cours...';
        }

        var formData = new FormData(form);

        fetch(form.getAttribute('action'), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
            .then(function (response) {
                return response.json().catch(function () {
                    // Réponse non-JSON (erreur serveur inattendue, page d'erreur, etc.)
                    throw new Error('invalid_response');
                });
            })
            .then(function (data) {
                if (data && data.success) {
                    showSuccess();
                    form.reset();
                    updateUploadZoneLabel();
                } else {
                    showWarning(data && data.message ? data.message : null);
                }
            })
            .catch(function () {
                showWarning('Une erreur est survenue. Vous pouvez aussi nous appeler directement au 07 83 38 85 72.');
            })
            .finally(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = submitBtnDefaultHTML;
                }
            });
    });
});
