<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\MovementRepository;
use App\Security\CurrentUser;
use App\Services\DocumentService;
use App\Services\LocationService;
use App\Services\MovementService;
use App\Support\Paginator;

/** Desktop: Bewegungsliste/Tagesübersicht, offene Vorgänge (Arbeitsliste) und Nachbearbeitung. */
final class MovementController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly MovementRepository $movements,
        private readonly MovementService $service,
        private readonly DocumentRepository $documents,
        private readonly DocumentService $documentService,
        private readonly EmployeeRepository $employees,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly AssetTypeRepository $types
    ) {
        parent::__construct($view, $currentUser);
    }

    /** Bewegungen mit Zeitraum – Standard: heute. */
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $range = $request->queryString('range', $filters['date_from'] === '' && $filters['date_to'] === '' ? 'today' : 'custom');
        [$filters['date_from'], $filters['date_to']] = $this->rangeDates($range, $filters['date_from'], $filters['date_to']);
        $paginator = new Paginator($this->movements->countSearch($filters), $request->int('page', 1) ?? 1, $request->int('per_page', 50) ?? 50);

        return $this->render('movements.index', array_merge($this->formOptions(), [
            'title' => 'Bewegungen',
            'activeNav' => 'movements',
            'rows' => $this->movements->search($filters, $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'range' => $range,
            'summary' => $this->movements->summary($filters['date_from'] ?: '1970-01-01', $filters['date_to'] ?: '2999-12-31'),
            'paginator' => $paginator,
            'basePath' => '/movements',
            'query' => $request->query(),
        ]));
    }

    /** Arbeitsliste: offene Entnahmen/Retouren zur Nachbearbeitung. */
    public function open(Request $request): Response
    {
        $filters = $this->filters($request);
        $filters['status'] = 'open';
        $paginator = new Paginator($this->movements->countSearch($filters), $request->int('page', 1) ?? 1, $request->int('per_page', 50) ?? 50);

        return $this->render('movements.open', array_merge($this->formOptions(), [
            'title' => 'Offene Vorgänge',
            'activeNav' => 'open-checkouts',
            'rows' => $this->movements->search($filters, $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'summary' => $this->movements->summary(date('Y-m-d'), date('Y-m-d')),
            'paginator' => $paginator,
            'basePath' => '/movements/open',
            'query' => $request->query(),
        ]));
    }

    public function show(Request $request): Response
    {
        $row = $this->findOrFail($this->movements->find($request->paramInt('id')), 'Vorgang nicht gefunden');

        return $this->render('movements.show', array_merge($this->formOptions(), [
            'title' => ($row['type'] === 'checkout' ? 'Entnahme' : 'Retoure') . ' ' . $row['inventory_number'],
            'activeNav' => $row['status'] === 'open' ? 'open-checkouts' : 'movements',
            'row' => $row,
            'documents' => $this->documents->forEntity('movement', (int) $row['id']),
            'missing' => MovementService::missingLabels($row),
            'back' => $this->backUrl($request, $row['status'] === 'open' ? '/movements/open' : '/movements'),
        ]));
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->movements->find($id), 'Vorgang nicht gefunden');
        $complete = $request->string('action') === 'complete';
        try {
            $updated = $this->service->update($id, $row, $request->all(), $complete);
            foreach ($this->photoUploads($request) as $photo) {
                $this->documentService->store('movement', $id, 'photo', $photo, null, $this->documentService->imageExtensions(), 'photos');
            }
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/movements/' . $id);
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/movements/' . $id);
        }
        $this->flash('success', $updated['status'] === 'completed' && $row['status'] !== 'completed' ? 'Vorgang abgeschlossen.' : 'Vorgang gespeichert.');

        return $this->redirect($updated['status'] === 'completed' && $row['status'] === 'open' ? '/movements/open' : '/movements/' . $id);
    }

    public function cancel(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->movements->find($id), 'Vorgang nicht gefunden');
        try {
            $this->service->cancel($id, $row, $request->string('reason'));
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->errors()));

            return $this->redirect('/movements/' . $id);
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/movements/' . $id);
        }
        $this->flash('success', 'Vorgang storniert; das Asset wurde zurückgesetzt.');

        return $this->redirect('/assets/' . (int) $row['asset_id']);
    }

    /** @return array<string,string> */
    private function filters(Request $request): array
    {
        return [
            'q' => $request->queryString('q'),
            'type' => $request->queryString('type'),
            'status' => $request->queryString('status'),
            'date_from' => $request->queryString('date_from'),
            'date_to' => $request->queryString('date_to'),
            'employee_id' => $request->queryString('employee_id'),
            'location_id' => $request->queryString('location_id'),
            'cost_center_id' => $request->queryString('cost_center_id'),
            'asset_type_id' => $request->queryString('asset_type_id'),
            'missing' => $request->queryString('missing'),
            'source' => $request->queryString('source'),
        ];
    }

    /** @return array{0:string,1:string} */
    private function rangeDates(string $range, string $from, string $to): array
    {
        $today = date('Y-m-d');

        return match ($range) {
            'today' => [$today, $today],
            'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
            'week' => [date('Y-m-d', strtotime('monday this week')), $today],
            'month' => [date('Y-m-01'), $today],
            'all' => ['', ''],
            default => [$from, $to],
        };
    }

    /** @return array<string,mixed> */
    private function formOptions(): array
    {
        return [
            'employees' => $this->employees->activeForSelect(),
            'locationOptions' => LocationService::flatten($this->locations->all(true)),
            'costCenters' => $this->costCenters->activeForSelect(),
            'types' => $this->types->all(true),
            'conditions' => MovementService::CONDITIONS,
            'returnTargets' => MovementService::RETURN_TARGETS,
            'sources' => MovementService::SOURCES,
        ];
    }

    /** Mehrfach-Upload `photos[]` in einzelne $_FILES-Einträge zerlegen. @return array<int,array<string,mixed>> */
    public static function normalizeUploads(?array $files): array
    {
        if ($files === null || !isset($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files];
        }
        $out = [];
        foreach ($files['name'] as $i => $name) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => $files['error'][$i] ?? UPLOAD_ERR_OK, 'size' => $files['size'][$i] ?? 0];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function photoUploads(Request $request): array
    {
        return self::normalizeUploads($request->files()['photos'] ?? null);
    }
}
