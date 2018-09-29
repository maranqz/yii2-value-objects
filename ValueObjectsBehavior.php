<?php

namespace equicolor\valueObjects;

use yii\base\DynamicModel;
use yii\base\Model;
use \yii\db\ActiveRecord;
use \yii\helpers\Json;

/**
 * @property ActiveRecord $owner
 */
class ValueObjectsBehavior extends \yii\base\Behavior
{
	const EVENT_REINITIALIZE = 'reinitialize';
	const EVENT_FIRST_FILL = 'afterFind';

	public static $classMap = [];

	private $jsonMap = [];
	private $objectsMap = [];

	private $_initialized = false;

	public function events()
	{
		$commonEvents = [
			ActiveRecord::EVENT_INIT => 'initObjects',
			ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
			ActiveRecord::EVENT_BEFORE_INSERT => 'putJson',
			ActiveRecord::EVENT_BEFORE_UPDATE => 'putJson',
			ActiveRecord::EVENT_AFTER_INSERT => 'putObjects',
			ActiveRecord::EVENT_AFTER_UPDATE => 'putObjects',

			ActiveRecord::EVENT_AFTER_VALIDATE => 'validateObjects',

			static::EVENT_REINITIALIZE => 'reInitObjects',
			static::EVENT_FIRST_FILL => 'afterFind',
			EventARInterface::EVENT_AFTER_POPULATE_RECORD => 'reInitObjects',
			EventARInterface::EVENT_BEFORE_SET_ATTRIBUTES => 'putJson',
			EventARInterface::EVENT_AFTER_SET_ATTRIBUTES => 'putObjects',
			EventARInterface::EVENT_BEFORE_SET_ATTRIBUTE => 'putJson',
			EventARInterface::EVENT_AFTER_SET_ATTRIBUTE => 'putObjects',
		];

		return $commonEvents;
	}

	protected function getValueObjectAttributes()
	{
		$class = get_class($this->owner);
		if (!isset(self::$classMap[$class]) || true) {
			if (!method_exists($class, 'valueObjects')) {
				// у самых глубоких объектов не будет такого метода
				$attributes = [];
			} else {
				$attributes = $class::valueObjects($this->owner);
			}
			self::$classMap[$class] = $attributes;
		}

		return self::$classMap[$class];
	}

	public function reInitObjects()
	{
		$this->_initialized = false;
		$this->initObjects(true);
	}

	public function initObjects($old = false)
	{
		if (!$this->_initialized) {
			$this->createObjects();
			// BACKLOG В active record нет смысла инстанциировать value objects сразу, но в Model есть
			$this->putObjects($old);
			$this->setOwnerOldAttributes();
			$this->_initialized = true;
		}
	}

	public function afterFind()
	{
		$this->fillObjects();
		$this->putObjects();
		$this->setOwnerOldAttributes();
	}

	protected function fillObjects()
	{
		foreach ($this->objectsMap as $attribute => $object) {
			$this->fillObject($object, $attribute);
			$object->setOldAttributes($object->attributes);
		}
	}

	/**
	 * BACKLOG split decoding and mapping
	 *
	 * @param ActiveRecord $object
	 * @param $attribute
	 * @throws ValueObjectsMappingException
	 */
	protected function fillObject($object, $attribute)
	{
		$json = $this->owner->$attribute;
		if (is_string($json) && strlen($json)) {
			try {
				$array = json_decode($json, true);
				$object->setAttributes($array);
			} catch (\Exception $e) {
				throw new ValueObjectsMappingException('Error on creating object', 0, $e);
			}
		}
	}

	protected function createObjects()
	{
		foreach ($this->getValueObjectAttributes() as $attribute => $class) {
			$this->objectsMap[$attribute] = $this->createObject($attribute, $class);
		}
	}

	protected function createObject($attribute, $class)
	{
		if ($class instanceOf ValueObjectList) {
			return $class;
		} else {
			return new $class;
		}
	}

	protected function createJson($attribute)
	{
		$json = Json::encode($this->objectsMap[$attribute]);
		$this->jsonMap[$attribute] = $json;

		return $json;
	}

	protected function getJson($attribute)
	{
		if (!isset($this->jsonMap[$attribute])) {
			$this->jsonMap[$attribute] = $this->createJson($attribute);
		}

		return $this->jsonMap[$attribute];
	}

	protected function getObject($attribute)
	{
		if (!isset($this->objectsMap[$attribute])) {
			// BACKLOG i dont know how to get class there. Is it neccesarry at all?
			throw new \Error('not implemented');
			// $this->objectsMap[$attribute] = $this->createObject($attribute);
		}

		return $this->objectsMap[$attribute];
	}

	public function putJson()
	{
		foreach (array_keys($this->getValueObjectAttributes()) as $attribute) {
			$this->owner->$attribute = $this->getJson($attribute);
		}
	}

	public function putObjects($old = false)
	{
		foreach (array_keys($this->getValueObjectAttributes()) as $attribute) {
			if ($old) {
				$this->owner->setOldAttribute($attribute, $this->getObject($attribute));
			} else {
				$this->owner->$attribute = $this->getObject($attribute);
			}
		}
	}

	public function validateObjects()
	{
		if (!$this->owner instanceof Model) {
			return;
		}

		foreach (array_keys($this->getValueObjectAttributes()) as $attribute) {
			$object = $this->getObject($attribute);

			if (method_exists($object, 'rules')) {
				$model = DynamicModel::validateData(
					$object->getAttributes(),
					$object->rules()
				);

				if ($model->hasErrors()) {
					$this->owner->addErrors($model->getErrors());
				}

				$this->owner->$attribute = $this->getObject($attribute);
			}
		}
	}

	protected function setOwnerOldAttributes()
	{
		if ($this->owner instanceOf \yii\db\ActiveRecordInterface && !$this->owner->isNewRecord) {
			foreach ($this->objectsMap as $attribute => $object) {
				$this->owner->setOldAttribute($attribute, $object);
			}
		}
	}
}
