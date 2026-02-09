<?php

namespace pragmatic\translations\controllers;

use Craft;
use craft\web\Controller;
use pragmatic\translations\PragmaticTranslations;
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
        $sites = Craft::$app->getSites()->getAllSites();
        $request = Craft::$app->getRequest();
        $search = (string)$request->getParam('q', '');
        $group = (string)$request->getParam('group', '');

        $translations = PragmaticTranslations::$plugin->translations->getAllTranslations($search, $group);
        $groups = PragmaticTranslations::$plugin->translations->getGroups();

        return $this->renderTemplate('translations/index', [
            'sites' => $sites,
            'translations' => $translations,
            'groups' => $groups,
            'search' => $search,
            'group' => $group,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $items = Craft::$app->getRequest()->getBodyParam('translations', []);
        if (!is_array($items)) {
            throw new BadRequestHttpException('Invalid translations payload.');
        }

        PragmaticTranslations::$plugin->translations->saveTranslations($items);
        Craft::$app->getSession()->setNotice('Translations saved.');

        return $this->redirectToPostedUrl();
    }

    public function actionExport(): Response
    {
        $format = strtolower((string)Craft::$app->getRequest()->getQueryParam('format', 'csv'));
        $sites = Craft::$app->getSites()->getAllSites();
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
                    'description' => $translation['description'],
                    'translations' => [],
                ];
                foreach ($sites as $site) {
                    $item['translations'][$site->handle] = $translation['values'][$site->id] ?? '';
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

        $header = ['key', 'group', 'description'];
        foreach ($sites as $site) {
            $header[] = $site->handle;
        }
        fputcsv($handle, $header);

        foreach ($translations as $translation) {
            $row = [
                $translation['key'],
                $translation['group'],
                $translation['description'],
            ];
            foreach ($sites as $site) {
                $row[] = $translation['values'][$site->id] ?? '';
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
        $file = $request->getUploadedFile('file');

        if (!$file) {
            throw new BadRequestHttpException('No file uploaded.');
        }

        $sites = Craft::$app->getSites()->getAllSites();
        $siteHandles = [];
        $siteLanguages = [];
        foreach ($sites as $site) {
            $siteHandles[$site->handle] = $site->id;
            $siteLanguages[$site->language] = $site->id;
        }

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
                foreach ($translations as $handle => $value) {
                    if (isset($siteHandles[$handle])) {
                        $values[$siteHandles[$handle]] = (string)$value;
                    }
                }
                $items[] = [
                    'key' => (string)$key,
                    'group' => $item['group'] ?? null,
                    'description' => $item['description'] ?? null,
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

            $files = glob($tmpDir . '/translations/*.php');
            foreach ($files as $path) {
                $filename = basename($path, '.php');
                if (!isset($siteLanguages[$filename])) {
                    continue;
                }
                $siteId = $siteLanguages[$filename];
                $map = include $path;
                if (!is_array($map)) {
                    continue;
                }
                foreach ($map as $key => $value) {
                    $items[$key]['key'] = (string)$key;
                    $items[$key]['values'][$siteId] = (string)$value;
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
                if ($column === 'key' || $column === 'group' || $column === 'description') {
                    $columnMap[$column] = $index;
                    continue;
                }
                if (isset($siteHandles[$column])) {
                    $columnMap[$column] = $index;
                }
            }

            while (($row = fgetcsv($handle)) !== false) {
                $key = trim((string)($row[$columnMap['key']] ?? ''));
                if ($key === '') {
                    continue;
                }

                $values = [];
                foreach ($siteHandles as $handleName => $siteId) {
                    if (!isset($columnMap[$handleName])) {
                        continue;
                    }
                    $values[$siteId] = (string)($row[$columnMap[$handleName]] ?? '');
                }

                $items[] = [
                    'key' => $key,
                    'group' => trim((string)($row[$columnMap['group']] ?? '')) ?: null,
                    'description' => trim((string)($row[$columnMap['description']] ?? '')) ?: null,
                    'values' => $values,
                ];
            }

            fclose($handle);
        }

        PragmaticTranslations::$plugin->translations->saveTranslations($items);
        Craft::$app->getSession()->setNotice('Translations imported.');

        return $this->redirectToPostedUrl();
    }

    private function exportPhp(array $sites, $service): Response
    {
        $zipPath = Craft::$app->getPath()->getTempPath() . '/pragmatic-translations-php.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create PHP export.');
        }

        foreach ($sites as $site) {
            $translations = $service->getTranslationsBySiteId($site->id);
            $export = "<?php\n\nreturn " . var_export($translations, true) . ";\n";
            $filename = 'translations/' . $site->language . '.php';
            $zip->addFromString($filename, $export);
        }

        $zip->close();

        return Craft::$app->getResponse()->sendFile($zipPath, 'translations-php.zip', [
            'mimeType' => 'application/zip',
        ]);
    }
}
