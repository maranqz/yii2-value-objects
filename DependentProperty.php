<?php


namespace equicolor\valueObjects;


use yii\base\BaseObject;
use yii\db\ActiveRecord;

class DependentProperty extends BaseObject
{
	public $attribute;

	/**
	 * @var callable
	 */
	public $getter;

	/**
	 * @var callable
	 */
	public $canGet;

	/**
	 * @var callable
	 */
	public $isChanged;

	public function init()
	{
		parent::init();
		$this->isCallable('getter');

		if (empty($this->canGet)) {
			$this->canGet = function ($owner) {
				return !empty($owner->{$this->attribute});
			};
		}

		$this->isCallable('canGet');

		if (empty($this->isChanged)) {
			$this->isChanged = function ($owner) {
				/** @var ActiveRecord $owner */
				return $owner->isAttributeChanged($owner->{$this->attribute});
			};
		}

		$this->isCallable('isChanged');
	}

	private function isCallable($attribute)
	{
		if (!is_callable($this->$attribute)) {
			throw new \InvalidArgumentException(
				'The "%s" should be callable',
				'$' . $attribute
			);
		}
	}

	public function canGet($model)
	{
		return ($this->canGet)($model);
	}

	public function isChanged($model)
	{
		return ($this->isChanged)($model);
	}

	public function getClass($model)
	{
		return ($this->getter)($model);
	}
}
