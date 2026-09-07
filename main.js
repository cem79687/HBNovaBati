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
    function isValid() {
        var name = form.querySelector('#name');
        var email = form.querySelector('#email');
        var phone = form.querySelector('#phone');
        var message = form.querySelector('#message');

        if (!name.value.trim() || !email.value.trim() || !phone.value.trim() || !message.value.trim()) {
            return false;
        }
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(email.value.trim())) {
            return false;
        }
        return true;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        hideMessages();

        if (!isValid()) {
            showWarning('Merci de vérifier les champs obligatoires (nom, email, téléphone, message).');
            return;
        }

        // Piège à robots : si rempli, on abandonne silencieusement (comportement normal côté utilisateur)
        var honeypot = form.querySelector('#site_web');
        if (honeypot && honeypot.value.trim() !== '') {
            showSuccess();
            form.reset();
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
