<?php

namespace pragmatic\translations;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\fields\PlainText;
use craft\fieldlayoutelements\BaseField;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\TitleField;
use craft\services\UserPermissions;
use craft\web\View;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;
use craft\helpers\UrlHelper;
use pragmatic\translations\assets\AutotranslateAsset;
use pragmatic\translations\models\Settings;
use craft\events\RegisterCpNavItemsEvent;
use craft\web\twig\variables\Cp;
use pragmatic\translations\services\TranslationsService;
use pragmatic\translations\services\GoogleTranslateService;
use pragmatic\translations\twig\PragmaticTranslationsTwigExtension;
use pragmatic\translations\variables\PragmaticTranslationsVariable;
use yii\base\Event;

class PragmaticTranslations extends Plugin
{
    public bool $hasCpSection = true;
    public string $templateRoot = 'src/templates';
    public string $schemaVersion = '1.1.0';

    public static PragmaticTranslations $plugin;

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        Craft::$app->i18n->translations['pragmatic-translations'] = [
            'class' => \yii\i18n\PhpMessageSource::class,
            'basePath' => __DIR__ . '/translations',
            'forceTranslation' => true,
        ];

        $this->setComponents([
            'translations' => TranslationsService::class,
            'googleTranslate' => GoogleTranslateService::class,
        ]);

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['pragmatic-translations'] = 'pragmatic-translations/translations/index';
                $event->rules['pragmatic-translations/static'] = 'pragmatic-translations/translations/entries';
                $event->rules['pragmatic-translations/entries'] = 'pragmatic-translations/translations/groups';
                $event->rules['pragmatic-translations/import-export'] = 'pragmatic-translations/translations/import-export';
                $event->rules['pragmatic-translations/options'] = 'pragmatic-translations/translations/options';
                $event->rules['pragmatic-translations/save'] = 'pragmatic-translations/translations/save';
                $event->rules['pragmatic-translations/export'] = 'pragmatic-translations/translations/export';
                $event->rules['pragmatic-translations/import'] = 'pragmatic-translations/translations/import';
                $event->rules['pragmatic-translations/static/save-groups'] = 'pragmatic-translations/translations/save-groups';
                $event->rules['pragmatic-translations/autotranslate'] = 'pragmatic-translations/translations/autotranslate';
                $event->rules['pragmatic-translations/autotranslate-text'] = 'pragmatic-translations/translations/autotranslate-text';
                $event->rules['pragmatic-translations/options/save'] = 'pragmatic-translations/translations/save-options';
                $event->rules['pragmatic-translations/entries/save-row'] = 'pragmatic-translations/translations/save-entry-row';
            }
        );

        // Register nav item under shared "Tools" group
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function(RegisterCpNavItemsEvent $event) {
                $toolsLabel = Craft::t('pragmatic-translations', 'Tools');
                $groupKey = null;
                foreach ($event->navItems as $key => $item) {
                    if (($item['label'] ?? '') === $toolsLabel && isset($item['subnav'])) {
                        $groupKey = $key;
                        break;
                    }
                }

                if ($groupKey === null) {
                    $newItem = [
                        'label' => $toolsLabel,
                        'url' => 'pragmatic-translations',
                        'icon' => __DIR__ . '/icons/icon.svg',
                        'subnav' => [],
                    ];

                    // Insert after the first matching nav item
                    $afterKey = null;
                    $insertAfter = ['users', 'assets', 'categories', 'entries'];
                    foreach ($insertAfter as $target) {
                        foreach ($event->navItems as $key => $item) {
                            if (($item['url'] ?? '') === $target) {
                                $afterKey = $key;
                                break 2;
                            }
                        }
                    }

                    if ($afterKey !== null) {
                        $pos = array_search($afterKey, array_keys($event->navItems)) + 1;
                        $event->navItems = array_merge(
                            array_slice($event->navItems, 0, $pos, true),
                            ['pragmatic' => $newItem],
                            array_slice($event->navItems, $pos, null, true),
                        );
                        $groupKey = 'pragmatic';
                    } else {
                        $event->navItems['pragmatic'] = $newItem;
                        $groupKey = 'pragmatic';
                    }
                }

                $event->navItems[$groupKey]['subnav']['translations'] = [
                    'label' => 'Translations',
                    'url' => 'pragmatic-translations',
                ];

                $path = Craft::$app->getRequest()->getPathInfo();
                if ($path === 'pragmatic-translations' || str_starts_with($path, 'pragmatic-translations/')) {
                    $event->navItems[$groupKey]['url'] = 'pragmatic-translations';
                }
            }
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Pragmatic Translations',
                    'permissions' => [
                        'pragmatic-translations:manage' => ['label' => 'Manage translations'],
                        'pragmatic-translations:export' => ['label' => 'Export translations'],
                    ],
                ];
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('pragmaticTranslations', PragmaticTranslationsVariable::class);
            }
        );

        Craft::$app->getView()->registerTwigExtension(new PragmaticTranslationsTwigExtension());

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $view = Craft::$app->getView();

            $sites = Craft::$app->getSites()->getAllSites();
            $siteData = array_map(static function($site) {
                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'handle' => $site->handle,
                    'language' => $site->language,
                ];
            }, $sites);

            // Check if Google Translate is configured
            $settings = $this->getSettings();
            $apiKey = \craft\helpers\App::env($settings->googleApiKeyEnv);
            $googleConfigured = trim($settings->googleProjectId) !== '' && !empty($apiKey);
            $autotranslateEnabled = (bool)$settings->enableAutotranslate;
            $autotranslateReady = $googleConfigured && $autotranslateEnabled;

            // Register config at POS_HEAD so it's available before asset bundle JS runs
            $view->registerJs('window.PragmaticTranslations = ' . json_encode([
                'sites' => $siteData,
                'currentSiteId' => Craft::$app->getSites()->getCurrentSite()->id,
                'autotranslateUrl' => UrlHelper::actionUrl('pragmatic-translations/translations/autotranslate'),
                'googleTranslateConfigured' => $googleConfigured,
                'readmeUrl' => $this->getReadmeUrl(),
            ]) . ';', View::POS_HEAD);

            if ($autotranslateReady) {
                $view->registerAssetBundle(AutotranslateAsset::class);
            }

            // Add "Translate from site…" to field action menus (Craft 5.9+)
            if ($autotranslateReady && class_exists(\craft\events\DefineFieldActionsEvent::class)) {
                Event::on(
                    CustomField::class,
                    BaseField::EVENT_DEFINE_ACTION_MENU_ITEMS,
                    function (\craft\events\DefineFieldActionsEvent $event) {
                        if ($event->static) {
                            return;
                        }

                        $element = $event->element;
                        if (!$element || !$element->id) {
                            return;
                        }

                        /** @var CustomField $sender */
                        $sender = $event->sender;
                        try {
                            $field = $sender->getField();
                        } catch (\Exception $e) {
                            return;
                        }

                        // Only PlainText and CKEditor fields
                        $isEligible = ($field instanceof PlainText)
                            || get_class($field) === 'craft\\ckeditor\\Field';
                        if (!$isEligible) {
                            return;
                        }

                        // Only translatable fields (different value per site)
                        if ($field->translationMethod === \craft\base\Field::TRANSLATION_METHOD_NONE) {
                            return;
                        }

                        // Need at least 2 sites
                        if (count(Craft::$app->getSites()->getAllSites()) < 2) {
                            return;
                        }

                        $view = Craft::$app->getView();
                        $itemId = sprintf('action-pt-autotranslate-%s', mt_rand());
                        $containerId = $view->namespaceInputId($field->handle) . '-field';

                        $view->registerJsWithVars(
                            fn($btnId, $cId, $eId, $fHandle) => <<<JS
                                $('#' + $btnId).on('activate', function() {
                                    var container = document.getElementById($cId);
                                    if (window.PragmaticTranslations && window.PragmaticTranslations.openModal) {
                                        window.PragmaticTranslations.openModal(container, $eId, $fHandle);
                                    }
                                });
                                JS,
                                                            [
                                $view->namespaceInputId($itemId),
                                $containerId,
                                $element->id,
                                $field->handle,
                            ]
                        );

                        $event->items[] = [
                            'id' => $itemId,
                            'icon' => 'language',
                            'label' => Craft::t('pragmatic-translations', 'Translate from site…'),
                        ];
                    }
                );

                // Add "Translate from site…" to Title field action menu
                Event::on(
                    TitleField::class,
                    BaseField::EVENT_DEFINE_ACTION_MENU_ITEMS,
                    function (\craft\events\DefineFieldActionsEvent $event) {
                        if ($event->static) {
                            return;
                        }

                        $element = $event->element;
                        if (!$element || !$element->id) {
                            return;
                        }

                        // Need at least 2 sites
                        if (count(Craft::$app->getSites()->getAllSites()) < 2) {
                            return;
                        }

                        $view = Craft::$app->getView();
                        $itemId = sprintf('action-pt-autotranslate-%s', mt_rand());
                        $containerId = $view->namespaceInputId('title') . '-field';

                        $view->registerJsWithVars(
                            fn($btnId, $cId, $eId, $fHandle) => <<<JS
$('#' + $btnId).on('activate', function() {
    var container = document.getElementById($cId);
    if (window.PragmaticTranslations && window.PragmaticTranslations.openModal) {
        window.PragmaticTranslations.openModal(container, $eId, $fHandle);
    }
});
JS,
                            [
                                $view->namespaceInputId($itemId),
                                $containerId,
                                $element->id,
                                'title',
                            ]
                        );

                        $event->items[] = [
                            'id' => $itemId,
                            'icon' => 'language',
                            'label' => Craft::t('pragmatic-translations', 'Translate from site…'),
                        ];
                    }
                );
            }
        }
    }

    public function getCpNavItem(): ?array
    {
        return null;
    }

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }


    private function getReadmeUrl(): string
    {
        $composerPath = $this->getBasePath() . '/../composer.json';
        if (file_exists($composerPath)) {
            $data = json_decode(file_get_contents($composerPath), true);
            $docUrl = $data['extra']['documentationUrl'] ?? '';
            if ($docUrl !== '') {
                return $docUrl . '#autotranslate-google-translate-v3';
            }
        }
        return '';
    }

}
