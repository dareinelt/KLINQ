<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\CurrentUser;
use App\Security\WindowsIdentity;

/**
 * Windows-SSO: einmalige Kerberos-Aushandlung mit dem Browser.
 *
 * /sso/pruefung ist die einzige Kerberos-geschützte Route (Apache, mod_auth_gssapi).
 * Der Browser legt dort sein Dienstticket vor, Apache prüft es mit dem Keytab und setzt
 * REMOTE_USER. Das Ergebnis wird in der Sitzung gemerkt und der Besucher zur Ausgangsseite
 * zurückgeschickt. Scheitert die Aushandlung, ruft Apache /sso/abbruch auf – dort wird der
 * Versuch vermerkt, damit keine Schleife entsteht, und der Weg ohne SSO angeboten.
 */
final class SsoController extends BaseController
{
    public function __construct(View $view, CurrentUser $currentUser, private readonly WindowsIdentity $identity)
    {
        parent::__construct($view, $currentUser);
    }

    public function probe(Request $request): Response
    {
        $this->identity->remember($this->identity->fromServer($request));

        return $this->redirect(WindowsIdentity::safeNext($request->queryString('next'), '/'));
    }

    public function cancelled(Request $request): Response
    {
        $this->identity->remember(null);

        return $this->render('sso.cancelled', [
            'title' => 'Windows-Anmeldung nicht möglich',
            'next' => WindowsIdentity::safeNext($request->queryString('next'), '/'),
        ]);
    }
}
