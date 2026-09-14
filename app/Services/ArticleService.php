<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\ManufacturerRepository;
use App\Support\Validator;

final class ArticleService
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly ManufacturerRepository $manufacturers,
        private readonly AssetTypeRepository $assetTypes,
        private readonly AuditLogService $audit
    ) {}

    /** @param array<string,mixed> $input */
    public function create(array $input): int
    {
        $data = $this->validate($input, null);
        $id = $this->articles->create($data);
        $this->audit->log('create', 'article', $id, $data['name'], null, $data);

        return $id;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $existing, array $input): void
    {
        $data = $this->validate($input, $id);
        $this->articles->update($id, $data);
        $this->audit->log('update', 'article', $id, $data['name'], $existing, $data);
    }

    /** @param array<string,mixed> $existing */
    public function setActive(int $id, array $existing, bool $active): void
    {
        $this->articles->update($id, ['is_active' => $active ? 1 : 0]);
        $this->audit->log($active ? 'activate' : 'deactivate', 'article', $id, (string) $existing['name'], ['is_active' => $existing['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validate(array $input, ?int $excludeId): array
    {
        $data = (new Validator($input))
            ->id('manufacturer_id', 'Hersteller', true)
            ->id('asset_type_id', 'Assettyp', true)
            ->id('asset_category_id', 'Kategorie')
            ->string('name', 'Bezeichnung', true, 200)
            ->string('article_number', 'Artikelnummer', false, 100)
            ->text('description', 'Beschreibung')
            ->bool('is_active')
            ->validated();

        if ($this->manufacturers->find((int) $data['manufacturer_id']) === null) {
            throw ValidationException::single('manufacturer_id', 'Hersteller nicht gefunden. Bitte zuerst den Hersteller anlegen.');
        }
        if ($this->assetTypes->find((int) $data['asset_type_id']) === null) {
            throw ValidationException::single('asset_type_id', 'Assettyp nicht gefunden.');
        }
        if ($data['asset_category_id'] !== null) {
            $category = $this->assetTypes->findCategory((int) $data['asset_category_id']);
            if ($category === null || (int) $category['asset_type_id'] !== (int) $data['asset_type_id']) {
                throw ValidationException::single('asset_category_id', 'Die Kategorie passt nicht zum gewählten Assettyp.');
            }
        }
        if ($this->articles->findDuplicate((int) $data['manufacturer_id'], $data['name'], $excludeId) !== null) {
            throw ValidationException::single('name', 'Dieser Artikel existiert für den Hersteller bereits.');
        }

        return $data;
    }
}
