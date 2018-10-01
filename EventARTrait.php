<?php

namespace equicolor\valueObjects;

use yii\db\ActiveRecord;

trait EventARTrait
{
	public function setAttributes($values, $safeOnly = true)
	{
		$event = new ArgumentEvent([
			'arguments' => [
				'values' => $values,
				'safeOnly' => $safeOnly,
			]
		]);

		$this->trigger(static::EVENT_BEFORE_SET_ATTRIBUTES, $event);

		parent::setAttributes($values, $safeOnly);

		$this->trigger(static::EVENT_AFTER_SET_ATTRIBUTES, $event);
	}

	public function setAttribute($name, $value)
	{
		$event = new ArgumentEvent([
			'arguments' => [
				'name' => $name,
				'value' => $value
			]]);

		$this->trigger(static::EVENT_BEFORE_SET_ATTRIBUTE, $event);

		parent::setAttribute($name, $value);

		$this->trigger(static::EVENT_AFTER_SET_ATTRIBUTE, $event);
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
