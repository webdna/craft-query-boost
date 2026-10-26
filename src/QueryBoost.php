<?php

namespace webdna\queryboost;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\db\ElementQuery;
use webdna\queryboost\models\Settings;
use yii\base\Event;

/**
 * Query Boost: Craft 6's element query speed-ups for Craft 5.
 *
 * @method Settings getSettings()
 */
class QueryBoost extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'webdna\\queryboost\\console\\controllers';
        }

        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_PREPARE, function(Event $event) {
            /** @var ElementQuery $query */
            $query = $event->sender;
            if ($query->subQuery === null) {
                return;
            }

            $settings = $this->getSettings();
            QueryRewriter::apply(
                $query->subQuery,
                $settings->tiebreaker,
                $settings->straightJoin && Craft::$app->getDb()->getIsMysql(),
            );
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
