<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\ManufacturerRepository;
use App\Security\CurrentUser;
use App\Services\ArticleService;

final class ArticleController extends CrudController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly ArticleRepository $articles,
        private readonly ManufacturerRepository $manufacturers,
        private readonly AssetTypeRepository $assetTypes,
        private readonly ArticleService $service
    ) {
        parent::__construct($view, $currentUser);
    }

    protected function basePath(): string { return '/articles'; }
    protected function viewPrefix(): string { return 'articles'; }
    protected function label(): string { return 'Artikel'; }
    protected function labelPlural(): string { return 'Artikel'; }
    protected function navKey(): string { return 'articles'; }
    protected function findRow(int $id): ?array { return $this->articles->find($id); }
    protected function searchRows(array $filters, int $limit, int $offset): array { return $this->articles->search($filters, $limit, $offset); }
    protected function countRows(array $filters): int { return $this->articles->countSearch($filters); }
    protected function createRow(array $input): int { return $this->service->create($input); }
    protected function updateRow(int $id, array $existing, array $input): void { $this->service->update($id, $existing, $input); }
    protected function setRowActive(int $id, array $existing, bool $active): void { $this->service->setActive($id, $existing, $active); }

    protected function filters(Request $request): array
    {
        return array_merge(parent::filters($request), [
            'manufacturer_id' => $request->queryString('manufacturer_id'),
            'asset_type_id' => $request->queryString('asset_type_id'),
        ]);
    }

    protected function indexData(Request $request): array
    {
        return [
            'manufacturers' => $this->manufacturers->activeForSelect(),
            'assetTypes' => $this->assetTypes->all(true),
        ];
    }

    protected function formData(?array $row): array
    {
        return [
            'manufacturers' => $this->manufacturers->activeForSelect(),
            'assetTypes' => $this->assetTypes->all(true),
            'categories' => $this->assetTypes->categories(null, true),
        ];
    }
}
