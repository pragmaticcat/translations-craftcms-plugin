<?php

namespace pragmatic\translations\records;

use craft\db\ActiveRecord;

class TranslationValueRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pragmatic_statictranslation_values}}';
    }
}
