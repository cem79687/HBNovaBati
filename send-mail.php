<?php
/**
 * HBNova Bâti — Traitement du formulaire de contact.
 *
 * Prérequis sur o2switch :
 * 1. Se connecter en SSH (ou utiliser le terminal cPanel) à la racine du site.
 * 2. Installer PHPMailer via Composer :
 *      composer require phpmailer/phpmailer
 *    -> crée le dossier /vendor et le fichier /vendor/autoload.php
 * 3. Remplir les constantes SMTP_* ci-dessous avec les identifiants de la boîte mail
 *    o2switch (Mutu > Comptes e-mail > contact@hbnovabati.fr), ou un service tiers
 *    (Brevo/SendinBlue, etc.) si tu préfères ne pas utiliser directement le SMTP o2switch.
 * 4. Créer un widget sur https://dash.cloudflare.com/ > Turnstile, et remplir :
 *      - TURNSTILE_SECRET_KEY ci-dessous (clé secrète, jamais exposée côté client)
 *      - le data-sitekey dans contact.html (clé publique, déjà en place, à remplacer
 *        "VOTRE_SITE_KEY_TURNSTILE" par la vraie clé site)
 */

header('Content-Type: application/json; charset=utf-8');

// ---- Configuration SMTP à compléter ----
define('SMTP_HOST', 'mail.hbnovabati.fr');       // à adapter selon o2switch
define('SMTP_USER', 'contact@hbnovabati.fr');    // adresse d'envoi
define('SMTP_PASS', 'A_COMPLETER');              // mot de passe de la boîte mail
define('SMTP_PORT', 587);                        // 587 (TLS) ou 465 (SSL)
define('MAIL_TO', 'contact@hbnovabati.fr');      // destinataire des demandes

// ---- Configuration Cloudflare Turnstile à compléter ----
define('TURNSTILE_SECRET_KEY', 'A_COMPLETER');   // clé secrète (dashboard Cloudflare > Turnstile)

// ---- Configuration pièce jointe ----
define('ATTACHMENT_MAX_SIZE', 5 * 1024 * 1024);  // 5 Mo
define('ATTACHMENT_ALLOWED_MIME', ['application/pdf', 'image/png', 'image/jpeg']);
define('ATTACHMENT_ALLOWED_EXT', ['pdf', 'png', 'jpg', 'jpeg']);
// -----------------------------------------

function respond($success, $message = '') {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Méthode non autorisée.');
}

// Piège à robots : si rempli, on répond "succès" sans envoyer de mail (ne pas alerter le bot)
if (!empty($_POST['site_web'])) {
    respond(true);
}

// ---- Vérification Cloudflare Turnstile ----
$turnstileToken = $_POST['cf-turnstile-response'] ?? '';
if ($turnstileToken === '') {
    respond(false, 'Merci de valider la vérification de sécurité.');
}

$verify = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
curl_setopt_array($verify, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'secret'   => TURNSTILE_SECRET_KEY,
        'response' => $turnstileToken,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]),
    CURLOPT_TIMEOUT => 10,
]);
$verifyResult = curl_exec($verify);
curl_close($verify);
$verifyData = json_decode($verifyResult, true);

if (empty($verifyData['success'])) {
    respond(false, 'Échec de la vérification de sécurité. Merci de réessayer.');
}

// Récupération et nettoyage des champs
$name    = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$email   = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
$phone   = trim(filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$budget  = trim(filter_input(INPUT_POST, 'budget', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$message = trim(filter_input(INPUT_POST, 'message', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');

// Validation serveur (indispensable : la validation JS peut être contournée)
if ($name === '' || $email === '' || $phone === '' || $message === '') {
    respond(false, 'Merci de remplir tous les champs obligatoires.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Adresse email invalide.');
}
if (!preg_match('/^(\+33|0)[1-9](\s?\d{2}){4}$/', $phone)) {
    respond(false, 'Numéro de téléphone invalide.');
}
if (empty($_POST['consent'])) {
    respond(false, 'Merci de cocher la case de consentement pour l\'utilisation de vos données.');
}

// ---- Validation de la pièce jointe (facultative) ----
$attachmentPath = null;
$attachmentName = null;

if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['attachment'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        respond(false, "Erreur lors de l'envoi du fichier. Merci de réessayer.");
    }
    if ($file['size'] > ATTACHMENT_MAX_SIZE) {
        respond(false, 'Le fichier joint dépasse la taille maximale (5 Mo).');
    }

    // On vérifie le vrai type du fichier (le nom/extension peut être falsifié), pas seulement
    // l'en-tête envoyé par le navigateur.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($realMime, ATTACHMENT_ALLOWED_MIME, true) || !in_array($ext, ATTACHMENT_ALLOWED_EXT, true)) {
        respond(false, 'Le fichier joint doit être un PDF, un PNG ou un JPG.');
    }

    $attachmentPath = $file['tmp_name'];
    // Nom de fichier assaini (on ne fait pas confiance au nom fourni par le client)
    $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $attachmentName = ($safeBase !== '' ? $safeBase : 'piece-jointe') . '.' . $ext;
}

require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_PORT === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(SMTP_USER, 'Site HBNova Bâti');
    $mail->addAddress(MAIL_TO);
    $mail->addReplyTo($email, $name);

    if ($attachmentPath !== null) {
        $mail->addAttachment($attachmentPath, $attachmentName);
    }

    $mail->isHTML(false);
    $mail->Subject = 'Nouvelle demande de devis — ' . $name;
    $mail->Body = "Nouvelle demande via le site hbnovabati.fr\n\n"
        . "Nom : {$name}\n"
        . "Email : {$email}\n"
        . "Téléphone : {$phone}\n"
        . "Type de projet : " . ($budget !== '' ? $budget : 'Non précisé') . "\n\n"
        . "Message :\n{$message}\n"
        . ($attachmentName !== null ? "\nPièce jointe : {$attachmentName}\n" : '');

    $mail->send();
    respond(true);
} catch (Exception $e) {
    error_log('Erreur envoi mail HBNova : ' . $mail->ErrorInfo);
    respond(false, "L'envoi a échoué. Merci de nous appeler directement au 07 83 38 85 72.");
}
