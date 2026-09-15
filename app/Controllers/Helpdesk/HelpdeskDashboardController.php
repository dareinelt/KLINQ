<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\TicketRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\TicketReportService;
use App\Services\Helpdesk\TicketService;

/** Help-Desk-Dashboard: Kennzahlen, eigene Tickets, offene Warteschlange, SLA-Überblick. */
final class HelpdeskDashboardController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TicketReportService $reports,
        private readonly TicketRepository $tickets
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $data = $this->reports->dashboard();

        return $this->render('helpdesk.dashboard', [
            'title' => 'Help Desk',
            'activeNav' => 'helpdesk',
            'areaLabel' => 'Help Desk',
            'data' => $data,
            'views' => TicketService::views(),
            'viewCounts' => $this->tickets->viewCounts((int) $this->currentUser->id()),
            'now' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'scripts' => ['/js/helpdesk.js'],
        ]);
    }
}
