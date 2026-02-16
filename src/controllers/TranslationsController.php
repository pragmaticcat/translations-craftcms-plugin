<?php

namespace pragmatic\translations\controllers;

use Craft;
use craft\web\Controller;
use pragmatic\translations\PragmaticTranslations;
use craft\fields\PlainText;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class TranslationsController extends Controller
{
    protected int|bool|array $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        $this->requireCpRequest();

        if ($action->id === 'export') {
            $this->requirePermission('pragmatic-translations:export');
        } else {
            $this->requirePermission('pragmatic-translations:manage');
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-translations/static');
    }

    public function actionEntries(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);
        $request = Craft::$app->getRequest();
        $search = (string)$request->getParam('q', '');
        $group = (string)$request->getParam('group', 'site');
        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = (int)$request->getParam('perPage', 50);
        if (!in_array($perPage, [50, 100, 250], true)) {
            $perPage = 50;
        }
        $offset = ($page - 1) * $perPage;

        $service = PragmaticTranslations::$plugin->translations;
        $total = $service->countTranslations($search, $group);
        $translations = $service->getAllTranslations($search, $group, $perPage, $offset);
        $groups = $service->getGroups();
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        return $this->renderTemplate('pragmatic-translations/estaticas', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'sites' => $sites,
            'languages' => $languages,
            'translations' => $translations,
            'groups' => $groups,
            'search' => $search,
            'group' => $group,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    public function actionImportExport(): Response
    {
        return $this->redirect(UrlHelper::url(
            'pragmatic-translations/static',
            Craft::$app->getRequest()->getQueryParams(),
        ));
    }

    public function actionGroups(): Response
    {
        $service = PragmaticTranslations::$plugin->translations;
        $groups = $service->getGroups();
        $request = Craft::$app->getRequest();
        $search = (string)$request->getParam('q', '');
        $perPage = (int)$request->getParam('perPage', 50);
        if (!in_array($perPage, [50, 100, 250], true)) {
            $perPage = 50;
        }
        $page = max(1, (int)$request->getParam('page', 1));
        $sectionId = (int)$request->getParam('section', 0);
        $fieldFilter = (string)$request->getParam('field', '');
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        if ($sectionId && !$this->isSectionAvailableForSite($sectionId, $selectedSiteId)) {
            $sectionId = 0;
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);
        $languageMap = $this->getLanguageMap($sites);

        $entryQuery = Entry::find()->siteId($selectedSiteId)->status(null);
        if ($sectionId) {
            $entryQuery->sectionId($sectionId);
        }
        if ($search !== '') {
            $entryQuery->search($search);
        }

        $entries = $entryQuery->all();

        $rows = [];
        foreach ($entries as $entry) {
            $layout = $entry->getFieldLayout();
            $fields = $layout ? $layout->getCustomFields() : [];

            $eligibleFields = [];
            foreach ($fields as $field) {
                if (!$this->isEligibleTranslatableField($field, $fieldFilter)) {
                    continue;
                }
                $eligibleFields[] = $field;
            }

            if ($fieldFilter === '' || $fieldFilter === 'title') {
                $rows[] = [
                    'entry' => $entry,
                    'fieldHandle' => 'title',
                    'fieldLabel' => Craft::t('app', 'Title'),
                ];
            }

            foreach ($eligibleFields as $field) {
                $rows[] = [
                    'entry' => $entry,
                    'fieldHandle' => $field->handle,
                    'fieldLabel' => $field->name,
                ];
            }
        }

        $total = count($rows);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);

        // Pre-load entry data for all sites to avoid N+1 queries in the template
        $entryIds = array_unique(array_map(fn($r) => $r['entry']->id, $pageRows));
        $siteEntries = [];
        if (!empty($entryIds)) {
            $allSiteIds = [];
            foreach ($languageMap as $siteIds) {
                foreach ($siteIds as $siteId) {
                    $allSiteIds[$siteId] = true;
                }
            }
            foreach (array_keys($allSiteIds) as $siteId) {
                $entries = Entry::find()->id($entryIds)->siteId($siteId)->status(null)->all();
                foreach ($entries as $e) {
                    $siteEntries[$siteId][$e->id] = $e;
                }
            }
        }

        foreach ($pageRows as &$row) {
            $row['values'] = [];
            foreach ($languageMap as $lang => $siteIds) {
                $value = '';
                foreach ($siteIds as $siteId) {
                    if (isset($siteEntries[$siteId][$row['entry']->id])) {
                        $entry = $siteEntries[$siteId][$row['entry']->id];
                        if ($row['fieldHandle'] === 'title') {
                            $value = (string)$entry->title;
                        } else {
                            $value = (string)$entry->getFieldValue($row['fieldHandle']);
                        }
                        break;
                    }
                }
                $row['values'][$lang] = $value;
            }
        }
        unset($row);

        $entryRowCounts = [];
        foreach ($pageRows as $r) {
            $id = $r['entry']->id;
            $entryRowCounts[$id] = ($entryRowCounts[$id] ?? 0) + 1;
        }

        $sections = $this->getEntrySectionsForSite($selectedSiteId, $fieldFilter);
        $fieldOptions = $this->getEntryFieldOptions();

        $settings = PragmaticTranslations::$plugin->getSettings();
        $apiKey = \craft\helpers\App::env($settings->googleApiKeyEnv);
        $autotranslateAvailable = trim($settings->googleProjectId) !== '' && !empty($apiKey);

        return $this->renderTemplate('pragmatic-translations/entradas', [
            'rows' => $pageRows,
            'entryRowCounts' => $entryRowCounts,
            'languages' => $languages,
            'languageMap' => $languageMap,
            'sections' => $sections,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'sectionId' => $sectionId,
            'fieldFilter' => $fieldFilter,
            'search' => $search,
            'perPage' => $perPage,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'fieldOptions' => $fieldOptions,
            'autotranslateAvailable' => $autotranslateAvailable,
            'autotranslateTextUrl' => \craft\helpers\UrlHelper::actionUrl('pragmatic-translations/translations/autotranslate-text'),
        ]);
    }

    public function actionSaveEntryRow(): Response
    {
        $this->requirePostRequest();

        $saveRow = Craft::$app->getRequest()->getBodyParam('saveRow');
        $entries = Craft::$app->getRequest()->getBodyParam('entries', []);
        if ($saveRow === null || !isset($entries[$saveRow])) {
            throw new BadRequestHttpException('Invalid entry payload.');
        }

        $row = $entries[$saveRow];
        $entryId = (int)($row['entryId'] ?? 0);
        $fieldHandle = (string)($row['fieldHandle'] ?? '');
        $values = (array)($row['values'] ?? []);

        if (!$entryId || $fieldHandle === '') {
            throw new BadRequestHttpException('Missing entry data.');
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);

        foreach ($values as $language => $value) {
            if (!isset($languageMap[$language])) {
                continue;
            }
            foreach ($languageMap[$language] as $siteId) {
                $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
                if (!$entry) {
                    continue;
                }
                if ($fieldHandle === 'title') {
                    $entry->title = (string)$value;
                } else {
                    $entry->setFieldValue($fieldHandle, (string)$value);
                }
                Craft::$app->getElements()->saveElement($entry, false, false);
            }
        }

        Craft::$app->getSession()->setNotice('Entry saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionOptions(): Response
    {
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $request = Craft::$app->getRequest();
        $search = (string)$request->getParam('q', '');
        $group = (string)$request->getParam('group', 'site');
        $perPage = (int)$request->getParam('perPage', 50);
        if (!in_array($perPage, [50, 100, 250], true)) {
            $perPage = 50;
        }

        $settings = PragmaticTranslations::$plugin->getSettings();
        $apiKey = \craft\helpers\App::env($settings->googleApiKeyEnv);
        $autotranslateAvailable = true;
        $autotranslateDisabledReason = '';
        if (trim($settings->googleProjectId) === '') {
            $autotranslateAvailable = false;
            $autotranslateDisabledReason = 'Google Translate project ID is missing.';
        } elseif (empty($apiKey)) {
            $autotranslateAvailable = false;
            $autotranslateDisabledReason = 'Google Translate API key is missing.';
        }

        return $this->renderTemplate('pragmatic-translations/options', [
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'search' => $search,
            'group' => $group,
            'perPage' => $perPage,
            'settings' => $settings,
            'autotranslateAvailable' => $autotranslateAvailable,
            'autotranslateDisabledReason' => $autotranslateDisabledReason,
        ]);
    }

    public function actionSaveOptions(): Response
    {
        $this->requirePostRequest();

        $settings = Craft::$app->getRequest()->getBodyParam('settings', []);
        if (!is_array($settings)) {
            throw new BadRequestHttpException('Invalid settings payload.');
        }

        Craft::$app->getPlugins()->savePluginSettings(PragmaticTranslations::$plugin, $settings);
        Craft::$app->getSession()->setNotice('Options saved.');

        return $this->redirectToPostedUrl();
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $items = Craft::$app->getRequest()->getBodyParam('translations', []);
        if (!is_array($items)) {
            throw new BadRequestHttpException('Invalid translations payload.');
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);
        $items = $this->expandLanguageValuesToSites($items, $languageMap);

        PragmaticTranslations::$plugin->translations->saveTranslations($items);
        Craft::$app->getSession()->setNotice('Translations saved.');

        return $this->redirectToPostedUrl();
    }

    public function actionExport(): Response
    {
        $format = strtolower((string)Craft::$app->getRequest()->getQueryParam('format', 'csv'));
        $sites = Craft::$app->getSites()->getAllSites();
        $languages = $this->getLanguages($sites);
        $service = PragmaticTranslations::$plugin->translations;

        if ($format === 'php') {
            return $this->exportPhp($sites, $service);
        }

        $translations = PragmaticTranslations::$plugin->translations->getAllTranslations();
        if ($format === 'json') {
            $payload = [];
            foreach ($translations as $translation) {
                $item = [
                    'group' => $translation['group'],
                    'translations' => [],
                ];
                foreach ($languages as $language) {
                    $item['translations'][$language] = $this->getValueForLanguage($translation, $sites, $language);
                }
                $payload[$translation['key']] = $item;
            }

            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return Craft::$app->getResponse()->sendContentAsFile($json, 'translations.json', [
                'mimeType' => 'application/json',
            ]);
        }

        $tmpFile = Craft::$app->getPath()->getTempPath() . '/pragmatic-translations.csv';
        $handle = fopen($tmpFile, 'wb');
        if (!$handle) {
            throw new \RuntimeException('Unable to create CSV export.');
        }

        $header = ['key', 'group'];
        foreach ($languages as $language) {
            $header[] = $language;
        }
        fputcsv($handle, $header);

        foreach ($translations as $translation) {
            $row = [
                $translation['key'],
                $translation['group'],
            ];
            foreach ($languages as $language) {
                $row[] = $this->getValueForLanguage($translation, $sites, $language);
            }
            fputcsv($handle, $row);
        }

        fclose($handle);

        return Craft::$app->getResponse()->sendFile($tmpFile, 'translations.csv', [
            'mimeType' => 'text/csv',
        ]);
    }

    public function actionImport(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $format = strtolower((string)$request->getBodyParam('format', 'csv'));
        $file = \yii\web\UploadedFile::getInstanceByName('file');

        if (!$file) {
            throw new BadRequestHttpException('No file uploaded.');
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $languageMap = $this->getLanguageMap($sites);

        $items = [];
        if ($format === 'json') {
            $raw = file_get_contents($file->tempName);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new BadRequestHttpException('Invalid JSON payload.');
            }

            foreach ($data as $key => $item) {
                $values = [];
                $translations = $item['translations'] ?? [];
                foreach ($translations as $language => $value) {
                    $values[$language] = (string)$value;
                }
                $items[] = [
                    'key' => (string)$key,
                    'group' => $item['group'] ?? 'site',
                    'values' => $values,
                ];
            }
        } elseif ($format === 'php') {
            $zip = new \ZipArchive();
            if ($zip->open($file->tempName) !== true) {
                throw new BadRequestHttpException('Invalid ZIP file.');
            }

            $tmpDir = Craft::$app->getPath()->getTempPath() . '/pragmatic-translations-import-' . uniqid();
            if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
                throw new \RuntimeException('Unable to create temp directory.');
            }

            $zip->extractTo($tmpDir);
            $zip->close();

            $files = glob($tmpDir . '/translations/*/*.php');
            foreach ($files as $path) {
                $language = basename(dirname($path));
                $group = basename($path, '.php');
                PragmaticTranslations::$plugin->translations->ensureGroupExists($group);
                $map = include $path;
                if (!is_array($map)) {
                    continue;
                }
                foreach ($map as $key => $value) {
                    $items[$key]['key'] = (string)$key;
                    $items[$key]['values'][$language] = (string)$value;
                    $items[$key]['group'] = $group;
                    $items[$key]['preserveMeta'] = true;
                }
            }
        } else {
            $handle = fopen($file->tempName, 'rb');
            if (!$handle) {
                throw new BadRequestHttpException('Unable to read CSV file.');
            }

            $header = fgetcsv($handle);
            if (!$header) {
                throw new BadRequestHttpException('CSV file is empty.');
            }

            $columnMap = [];
            foreach ($header as $index => $column) {
                $column = trim((string)$column);
                if ($column === 'key' || $column === 'group') {
                    $columnMap[$column] = $index;
                    continue;
                }
                $columnMap[$column] = $index;
            }

            while (($row = fgetcsv($handle)) !== false) {
                $key = trim((string)($row[$columnMap['key']] ?? ''));
                if ($key === '') {
                    continue;
                }

                $values = [];
                foreach ($columnMap as $column => $index) {
                    if ($column === 'key' || $column === 'group') {
                        continue;
                    }
                    $values[$column] = (string)($row[$index] ?? '');
                }

                $items[] = [
                    'key' => $key,
                    'group' => trim((string)($row[$columnMap['group']] ?? '')) ?: 'site',
                    'values' => $values,
                ];
            }

            fclose($handle);
        }

        $items = $this->expandLanguageValuesToSites($items, $languageMap);
        PragmaticTranslations::$plugin->translations->saveTranslations($items);
        Craft::$app->getSession()->setNotice('Translations imported.');

        return $this->redirectToPostedUrl();
    }

    public function actionAutotranslate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getBodyParam('entryId');
        $fieldHandle = (string)$request->getBodyParam('fieldHandle');
        $sourceSiteId = (int)$request->getBodyParam('sourceSiteId');
        $targetSiteId = (int)$request->getBodyParam('targetSiteId');

        if (!$entryId || $fieldHandle === '' || !$sourceSiteId || !$targetSiteId) {
            return $this->asJson(['success' => false, 'error' => 'Missing required parameters.']);
        }

        $isTitle = $fieldHandle === 'title';
        $isCkeditor = false;

        if (!$isTitle) {
            $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
            if (!$field) {
                return $this->asJson(['success' => false, 'error' => 'Field not found.']);
            }

            $isPlainText = $field instanceof PlainText;
            $isCkeditor = class_exists(\craft\ckeditor\Field::class) && $field instanceof \craft\ckeditor\Field;
            if (!$isPlainText && !$isCkeditor) {
                return $this->asJson(['success' => false, 'error' => 'Field type not supported.']);
            }
        }

        $entry = Craft::$app->getElements()->getElementById($entryId, null, $sourceSiteId);
        if (!$entry) {
            return $this->asJson(['success' => false, 'error' => 'Source entry not found.']);
        }

        $sourceSite = Craft::$app->getSites()->getSiteById($sourceSiteId);
        $targetSite = Craft::$app->getSites()->getSiteById($targetSiteId);
        if (!$sourceSite || !$targetSite) {
            return $this->asJson(['success' => false, 'error' => 'Invalid site selection.']);
        }

        $text = $isTitle ? (string)$entry->title : (string)$entry->getFieldValue($fieldHandle);
        if (trim($text) === '') {
            return $this->asJson(['success' => false, 'error' => 'Source field is empty.']);
        }

        $translate = PragmaticTranslations::$plugin->googleTranslate;
        $sourceLang = $translate->resolveLanguageCode($sourceSite->language);
        $targetLang = $translate->resolveLanguageCode($targetSite->language);
        $mimeType = $isCkeditor ? 'text/html' : 'text/plain';

        try {
            $translated = $translate->translate($text, $sourceLang, $targetLang, $mimeType);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'text' => $translated]);
    }

    public function actionAutotranslateText(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $texts = $request->getBodyParam('texts');
        $sourceLang = (string)$request->getBodyParam('sourceLang', '');
        $targetLang = (string)$request->getBodyParam('targetLang', '');
        $mimeType = (string)$request->getBodyParam('mimeType', 'text/plain');

        if (!is_array($texts) || $sourceLang === '' || $targetLang === '') {
            return $this->asJson(['success' => false, 'error' => 'Missing required parameters.']);
        }

        if ($sourceLang === $targetLang) {
            return $this->asJson(['success' => true, 'translations' => $texts]);
        }

        // Filter out empty texts, preserving indices
        $toTranslate = [];
        $indexMap = [];
        foreach ($texts as $i => $text) {
            if (trim((string)$text) !== '') {
                $indexMap[] = $i;
                $toTranslate[] = (string)$text;
            }
        }

        if (empty($toTranslate)) {
            return $this->asJson(['success' => true, 'translations' => $texts]);
        }

        $translate = PragmaticTranslations::$plugin->googleTranslate;

        try {
            $translated = $translate->translateBatch($toTranslate, $sourceLang, $targetLang, $mimeType);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        // Rebuild full array with translations in place of non-empty texts
        $results = $texts;
        foreach ($indexMap as $j => $originalIndex) {
            $results[$originalIndex] = $translated[$j] ?? $texts[$originalIndex];
        }

        return $this->asJson(['success' => true, 'translations' => array_values($results)]);
    }

    private function exportPhp(array $sites, $service): Response
    {
        $zipPath = Craft::$app->getPath()->getTempPath() . '/pragmatic-translations-php.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create PHP export.');
        }

        $languages = $this->getLanguages($sites);
        $groups = $service->getGroups();
        $allTranslations = $service->getAllTranslations();

        foreach ($languages as $language) {
            foreach ($groups as $group) {
                $map = [];
                foreach ($allTranslations as $translation) {
                    if (($translation['group'] ?? 'site') !== $group) {
                        continue;
                    }
                    $map[$translation['key']] = $this->getValueForLanguage($translation, $sites, $language);
                }

                $export = "<?php\n\nreturn " . var_export($map, true) . ";\n";
                $filename = 'translations/' . $language . '/' . $group . '.php';
                $zip->addFromString($filename, $export);
            }
        }

        $zip->close();

        return Craft::$app->getResponse()->sendFile($zipPath, 'translations-php.zip', [
            'mimeType' => 'application/zip',
        ]);
    }

    public function actionSaveGroups(): Response
    {
        $this->requirePostRequest();

        $items = Craft::$app->getRequest()->getBodyParam('groups', []);
        if (!is_array($items)) {
            throw new BadRequestHttpException('Invalid groups payload.');
        }

        PragmaticTranslations::$plugin->translations->saveGroups($items);
        Craft::$app->getSession()->setNotice('Groups saved.');

        return $this->redirectToPostedUrl();
    }

    private function getLanguages(array $sites): array
    {
        $languages = [];
        foreach ($sites as $site) {
            $languages[$site->language] = true;
        }
        $languages = array_keys($languages);
        sort($languages);

        return $languages;
    }

    private function getLanguageMap(array $sites): array
    {
        $map = [];
        foreach ($sites as $site) {
            $map[$site->language][] = $site->id;
        }

        return $map;
    }

    private function getEntryFieldOptions(): array
    {
        $options = [
            ['value' => '', 'label' => Craft::t('app', 'All')],
            ['value' => 'title', 'label' => Craft::t('app', 'Title')],
        ];

        $fields = Craft::$app->getFields()->getAllFields();
        foreach ($fields as $field) {
            if (!$this->isEligibleTranslatableField($field)) {
                continue;
            }
            $options[] = ['value' => $field->handle, 'label' => $field->name];
        }

        return $options;
    }

    private function isEligibleTranslatableField(mixed $field, string $fieldFilter = ''): bool
    {
        $isEligibleType = ($field instanceof PlainText) || (get_class($field) === 'craft\\ckeditor\\Field');
        if (!$isEligibleType) {
            return false;
        }

        if ($field->translationMethod === \craft\base\Field::TRANSLATION_METHOD_NONE) {
            return false;
        }

        if ($fieldFilter !== '' && $fieldFilter !== 'title' && $field->handle !== $fieldFilter) {
            return false;
        }

        return true;
    }

    private function entryHasEligibleTranslatableFields(Entry $entry, string $fieldFilter = ''): bool
    {
        if ($fieldFilter === '' || $fieldFilter === 'title') {
            return true;
        }

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($this->isEligibleTranslatableField($field, $fieldFilter)) {
                return true;
            }
        }

        return false;
    }

    private function getEntrySectionsForSite(int $siteId, string $fieldFilter = ''): array
    {
        $sectionCounts = [];
        $entries = Entry::find()
            ->siteId($siteId)
            ->status(null)
            ->all();

        foreach ($entries as $entry) {
            if (!$this->entryHasEligibleTranslatableFields($entry, $fieldFilter)) {
                continue;
            }

            $section = $entry->getSection();
            if (!$section) {
                continue;
            }

            $id = (int)$section->id;
            $sectionCounts[$id] = ($sectionCounts[$id] ?? 0) + 1;
        }

        $rows = [];
        foreach (Craft::$app->entries->getAllSections() as $section) {
            if (!$this->isSectionActiveForSite($section, $siteId)) {
                continue;
            }

            $id = (int)$section->id;
            $rows[$id] = ['id' => $id, 'name' => $section->name, 'count' => $sectionCounts[$id] ?? 0];
        }

        usort($rows, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));

        return array_values($rows);
    }

    private function isSectionAvailableForSite(int $sectionId, int $siteId): bool
    {
        $section = Craft::$app->entries->getSectionById($sectionId);
        return $this->isSectionActiveForSite($section, $siteId);
    }

    private function isSectionActiveForSite(mixed $section, int $siteId): bool
    {
        if (!$section || !method_exists($section, 'getSiteSettings')) {
            return false;
        }

        $allSettings = $section->getSiteSettings();
        if (!is_array($allSettings) || empty($allSettings)) {
            return false;
        }

        if (isset($allSettings[$siteId])) {
            return true;
        }

        foreach ($allSettings as $setting) {
            if ((int)($setting->siteId ?? 0) === $siteId) {
                return true;
            }
        }

        return false;
    }

    private function expandLanguageValuesToSites(array $items, array $languageMap): array
    {
        foreach ($items as &$item) {
            if (!isset($item['values']) || !is_array($item['values'])) {
                continue;
            }
            $valuesByLanguage = $item['values'];
            $valuesBySite = [];
            foreach ($valuesByLanguage as $language => $value) {
                if (!isset($languageMap[$language])) {
                    continue;
                }
                foreach ($languageMap[$language] as $siteId) {
                    $valuesBySite[$siteId] = $value;
                }
            }
            $item['values'] = $valuesBySite;
        }
        unset($item);

        return $items;
    }

    private function getValueForLanguage(array $translation, array $sites, string $language): string
    {
        foreach ($sites as $site) {
            if ($site->language !== $language) {
                continue;
            }
            if (isset($translation['values'][$site->id])) {
                return (string)$translation['values'][$site->id];
            }
        }

        return '';
    }

}
