<?php

namespace equicolor\valueObjects;

use yii\db\ActiveRecord;

trait EventARTrait
{
	public function setAttributes($values, $safeOnly = true)
	{
		$this->trigger(static::EVENT_BEFORE_SET_ATTRIBUTES);

		parent::setAttributes($values, $safeOnly);

		$this->trigger(static::EVENT_AFTER_SET_ATTRIBUTES);
	}

	public function setAttribute($name, $value)
	{
		$this->trigger(static::EVENT_BEFORE_SET_ATTRIBUTE);

		parent::setAttribute($name, $value);

		$this->trigger(static::EVENT_AFTER_SET_ATTRIBUTE);
	}

	/**
	 * @param ActiveRecord $record
	 * @param $row
	 */
	public static function populateRecord($record, $row)
	{
		$record->trigger(static::EVENT_BEFORE_POPULATE_RECORD);

		parent::populateRecord($record, $row);

		$record->trigger(static::EVENT_AFTER_POPULATE_RECORD);
	}
}
