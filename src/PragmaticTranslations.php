<?php

namespace pragmatic\translations;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\View;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;
use craft\helpers\UrlHelper;
use pragmatic\translations\assets\AutotranslateAsset;
use pragmatic\translations\models\Settings;
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

        $this->setComponents([
            'translations' => TranslationsService::class,
            'googleTranslate' => GoogleTranslateService::class,
        ]);

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['pragmatic-translations'] = 'pragmatic-translations/translations/index';
                $event->rules['pragmatic-translations/entries'] = 'pragmatic-translations/translations/entries';
                $event->rules['pragmatic-translations/import-export'] = 'pragmatic-translations/translations/import-export';
                $event->rules['pragmatic-translations/groups'] = 'pragmatic-translations/translations/groups';
                $event->rules['pragmatic-translations/options'] = 'pragmatic-translations/translations/options';
                $event->rules['pragmatic-translations/save'] = 'pragmatic-translations/translations/save';
                $event->rules['pragmatic-translations/export'] = 'pragmatic-translations/translations/export';
                $event->rules['pragmatic-translations/import'] = 'pragmatic-translations/translations/import';
                $event->rules['pragmatic-translations/groups/save'] = 'pragmatic-translations/translations/save-groups';
                $event->rules['pragmatic-translations/autotranslate'] = 'pragmatic-translations/translations/autotranslate';
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
            $view->registerAssetBundle(AutotranslateAsset::class);

            $sites = Craft::$app->getSites()->getAllSites();
            $siteData = array_map(static function($site) {
                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'handle' => $site->handle,
                    'language' => $site->language,
                ];
            }, $sites);

            $view->registerJs('window.PragmaticTranslations = ' . json_encode([
                'sites' => $siteData,
                'currentSiteId' => Craft::$app->getSites()->getCurrentSite()->id,
                'autotranslateUrl' => UrlHelper::actionUrl('pragmatic-translations/translations/autotranslate'),
            ]) . ';');
        }
    }

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }


    public function getCpNavItem(): array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Pragmatic';
        $item['subnav'] = [
            'translations' => [
                'label' => 'Translations',
                'url' => 'pragmatic-translations',
            ],
        ];

        return $item;
    }
}
