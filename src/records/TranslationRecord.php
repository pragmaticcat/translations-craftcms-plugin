<?php

namespace pragmatic\translations\records;

use craft\db\ActiveRecord;

class TranslationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pragmatic_statictranslations}}';
    }
}
