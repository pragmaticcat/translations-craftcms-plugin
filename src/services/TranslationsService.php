<?php

namespace pragmatic\translations\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use pragmatic\translations\records\TranslationRecord;
use pragmatic\translations\records\TranslationValueRecord;

class TranslationsService extends Component
{
    private array $requestCache = [];

    public function t(string $key, array $params = [], ?int $siteId = null, bool $fallbackToPrimary = true, bool $createIfMissing = true): string
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $value = $this->getValue($key, $siteId);

        if ($value === null && $fallbackToPrimary) {
            $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
            if ($primarySiteId !== $siteId) {
                $value = $this->getValue($key, $primarySiteId);
            }
        }

        if ($value === null && $createIfMissing) {
            $this->ensureKeyExists($key);
        }

        if ($value === null) {
            $value = $key;
        }

        if ($params) {
            foreach ($params as $paramKey => $paramValue) {
                $value = str_replace('{' . $paramKey . '}', (string)$paramValue, $value);
            }
        }

        return $value;
    }

    public function getAllTranslations(?string $search = null, ?string $group = null): array
    {
        $query = (new Query())
            ->select([
                't.id',
                't.key',
                't.group',
                't.description',
                'v.siteId',
                'v.value',
            ])
            ->from(['t' => TranslationRecord::tableName()])
            ->leftJoin(['v' => TranslationValueRecord::tableName()], '[[v.translationId]] = [[t.id]]');

        if ($group !== null && $group !== '') {
            $query->andWhere(['t.group' => $group]);
        }

        if ($search !== null && $search !== '') {
            $query->andWhere([
                'or',
                ['like', 't.key', $search],
                ['like', 't.description', $search],
            ]);
        }

        $rows = $query
            ->orderBy(['t.key' => SORT_ASC])
            ->all();

        $translations = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (!isset($translations[$id])) {
                $translations[$id] = [
                    'id' => $id,
                    'key' => $row['key'],
                    'group' => $row['group'],
                    'description' => $row['description'],
                    'values' => [],
                ];
            }
            if ($row['siteId'] !== null) {
                $translations[$id]['values'][(int)$row['siteId']] = $row['value'];
            }
        }

        return array_values($translations);
    }

    public function saveTranslations(array $items): void
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            foreach ($items as $item) {
                if (!empty($item['delete']) && !empty($item['id'])) {
                    $this->deleteTranslationById((int)$item['id']);
                    continue;
                }

                $key = trim((string)($item['key'] ?? ''));
                if ($key === '') {
                    continue;
                }

                $record = null;
                if (!empty($item['id'])) {
                    $record = TranslationRecord::findOne((int)$item['id']);
                }

                if (!$record) {
                    $record = new TranslationRecord();
                }

                $record->key = $key;

                $preserveMeta = !empty($item['preserveMeta']);
                $hasGroup = array_key_exists('group', $item);
                $hasDescription = array_key_exists('description', $item);

                if (!$preserveMeta || !$record->id || $hasGroup) {
                    $record->group = trim((string)($item['group'] ?? '')) ?: null;
                }

                if (!$preserveMeta || !$record->id || $hasDescription) {
                    $record->description = trim((string)($item['description'] ?? '')) ?: null;
                }

                if (!$record->save()) {
                    throw new \RuntimeException('Failed to save translation key: ' . $key);
                }

                $translationId = (int)$record->id;
                $values = (array)($item['values'] ?? []);
                foreach ($values as $siteId => $value) {
                    $siteId = (int)$siteId;
                    $value = (string)$value;

                    $valueRecord = TranslationValueRecord::findOne([
                        'translationId' => $translationId,
                        'siteId' => $siteId,
                    ]);

                    if ($value === '') {
                        if ($valueRecord) {
                            $valueRecord->delete();
                        }
                        continue;
                    }

                    if (!$valueRecord) {
                        $valueRecord = new TranslationValueRecord();
                        $valueRecord->translationId = $translationId;
                        $valueRecord->siteId = $siteId;
                    }

                    $valueRecord->value = $value;
                    if (!$valueRecord->save()) {
                        throw new \RuntimeException('Failed to save translation value for key: ' . $key);
                    }
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->requestCache = [];
    }

    public function getTranslationsBySiteId(int $siteId): array
    {
        $rows = (new Query())
            ->select(['t.key', 'v.value'])
            ->from(['t' => TranslationRecord::tableName()])
            ->leftJoin(['v' => TranslationValueRecord::tableName()], '[[v.translationId]] = [[t.id]] AND [[v.siteId]] = :siteId', [':siteId' => $siteId])
            ->orderBy(['t.key' => SORT_ASC])
            ->all();

        $translations = [];
        foreach ($rows as $row) {
            $translations[$row['key']] = $row['value'] ?? '';
        }

        return $translations;
    }

    public function getValueWithFallback(string $key, ?int $siteId = null, bool $fallbackToPrimary = true, bool $createIfMissing = true): ?string
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $value = $this->getValue($key, $siteId);

        if ($value === null && $fallbackToPrimary) {
            $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
            if ($primarySiteId !== $siteId) {
                $value = $this->getValue($key, $primarySiteId);
            }
        }

        if ($value === null && $createIfMissing) {
            $this->ensureKeyExists($key);
        }

        return $value;
    }

    public function ensureKeyExists(string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }

        if (TranslationRecord::find()->where(['key' => $key])->exists()) {
            return;
        }

        $record = new TranslationRecord();
        $record->key = $key;
        $record->group = null;
        $record->description = null;

        try {
            $record->save(false);
        } catch (\Throwable $e) {
            // Ignore race conditions for duplicate keys
        }
    }

    public function getGroups(): array
    {
        $groups = (new Query())
            ->select(['t.group'])
            ->distinct()
            ->from(['t' => TranslationRecord::tableName()])
            ->where(['not', ['t.group' => null]])
            ->orderBy(['t.group' => SORT_ASC])
            ->column();

        return array_values(array_filter($groups, static fn($group) => $group !== ''));
    }

    public function deleteTranslationById(int $id): void
    {
        $record = TranslationRecord::findOne($id);
        if ($record) {
            $record->delete();
        }
    }

    private function getValue(string $key, int $siteId): ?string
    {
        $cacheKey = $siteId . ':' . $key;
        if (array_key_exists($cacheKey, $this->requestCache)) {
            return $this->requestCache[$cacheKey];
        }

        $value = (new Query())
            ->select(['v.value'])
            ->from(['t' => TranslationRecord::tableName()])
            ->innerJoin(['v' => TranslationValueRecord::tableName()], '[[v.translationId]] = [[t.id]]')
            ->where(['t.key' => $key, 'v.siteId' => $siteId])
            ->scalar();

        $value = $value !== false ? (string)$value : null;
        $this->requestCache[$cacheKey] = $value;

        return $value;
    }
}
