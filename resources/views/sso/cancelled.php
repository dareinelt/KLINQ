<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
/** Hinweis, wenn die Kerberos-Aushandlung mit dem Browser nicht zustande kam. */
?>
<div class="login-page">
    <div class="login-card text-center">
        <h1 class="text-lg">Windows-Anmeldung nicht möglich</h1>
        <p class="text-muted">Ihr Browser hat kein gültiges Windows-Ticket übermittelt. Das ist kein Fehler – Sie können ohne automatische Erkennung fortfahren; bitte nennen Sie Ihren Namen dann in der Beschreibung.</p>
        <div class="cluster justify-center mt-4">
            <a class="btn btn-primary" href="<?= e($next ?? '/') ?>">Ohne Windows-Anmeldung fortfahren</a>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../partials/layout.php'; ?>
