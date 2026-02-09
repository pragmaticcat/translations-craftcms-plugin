<?php

namespace pragmatic\translations\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%pragmatic_statictranslations}}', [
            'id' => $this->primaryKey(),
            'key' => $this->string()->notNull(),
            'group' => $this->string(),
            'description' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%pragmatic_statictranslation_values}}', [
            'id' => $this->primaryKey(),
            'translationId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'value' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%pragmatic_statictranslations}}', ['key'], true);
        $this->createIndex(null, '{{%pragmatic_statictranslation_values}}', ['translationId', 'siteId'], true);

        $this->addForeignKey(null, '{{%pragmatic_statictranslation_values}}', ['translationId'], '{{%pragmatic_statictranslations}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%pragmatic_statictranslation_values}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%pragmatic_statictranslation_values}}');
        $this->dropTableIfExists('{{%pragmatic_statictranslations}}');

        return true;
    }
}
