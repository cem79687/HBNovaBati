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
 */

header('Content-Type: application/json; charset=utf-8');

// ---- Configuration SMTP à compléter ----
define('SMTP_HOST', 'mail.hbnovabati.fr');       // à adapter selon o2switch
define('SMTP_USER', 'contact@hbnovabati.fr');    // adresse d'envoi
define('SMTP_PASS', 'A_COMPLETER');              // mot de passe de la boîte mail
define('SMTP_PORT', 587);                        // 587 (TLS) ou 465 (SSL)
define('MAIL_TO', 'contact@hbnovabati.fr');      // destinataire des demandes
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

    $mail->isHTML(false);
    $mail->Subject = 'Nouvelle demande de devis — ' . $name;
    $mail->Body = "Nouvelle demande via le site hbnovabati.fr\n\n"
        . "Nom : {$name}\n"
        . "Email : {$email}\n"
        . "Téléphone : {$phone}\n"
        . "Type de projet : " . ($budget !== '' ? $budget : 'Non précisé') . "\n\n"
        . "Message :\n{$message}\n";

    $mail->send();
    respond(true);
} catch (Exception $e) {
    error_log('Erreur envoi mail HBNova : ' . $mail->ErrorInfo);
    respond(false, "L'envoi a échoué. Merci de nous appeler directement au 07 83 38 85 72.");
}
