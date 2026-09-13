<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\CurrentUser;
use App\Services\DashboardService;

final class DashboardController extends BaseController
{
    public function __construct(View $view, CurrentUser $currentUser, private readonly DashboardService $dashboard)
    {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        return $this->render('dashboard.index', [
            'title' => 'Dashboard',
            'activeNav' => 'dashboard',
            'stats' => $this->dashboard->stats(),
            'recentMovements' => $this->currentUser->can('movements.view') ? $this->dashboard->recentMovements(10) : [],
            'recentHistory' => $this->currentUser->can('assets.view') ? $this->dashboard->recentAssetHistory(10) : [],
            'returnsDueSoon' => $this->currentUser->can('movements.view') ? $this->dashboard->returnsDueSoon(10) : [],
        ]);
    }
}
