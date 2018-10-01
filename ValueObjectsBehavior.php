<?php

namespace equicolor\valueObjects;

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

	public $attributeSeparator = '.';
	public $initializedAfterFind = false;

	private $jsonMap = [];
	private $objectsMap = [];

	private $_initialized = false;

	public function events()
	{
		$commonEvents = [
			ActiveRecord::EVENT_INIT => 'initObjectsEvent',
			ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
			ActiveRecord::EVENT_BEFORE_INSERT => 'putJson',
			ActiveRecord::EVENT_BEFORE_UPDATE => 'putJson',
			ActiveRecord::EVENT_AFTER_INSERT => 'putObjects',
			ActiveRecord::EVENT_AFTER_UPDATE => 'putObjects',

			ActiveRecord::EVENT_BEFORE_VALIDATE => 'putJson',
			ActiveRecord::EVENT_AFTER_VALIDATE => 'validateObjectsEvent',

			static::EVENT_REINITIALIZE => 'reInitObjects',
			static::EVENT_FIRST_FILL => 'afterFind',
			EventARInterface::EVENT_AFTER_SET_ATTRIBUTES => 'setAttributesEvent',
			EventARInterface::EVENT_AFTER_SET_ATTRIBUTE => 'setAttributeEvent',
		];

		return $commonEvents;
	}

	protected function getValueObjectAttributes($isChanged = false)
	{
		$class = get_class($this->owner);
		if (!isset(self::$classMap[$class]) || $isChanged) {
			if (!method_exists($class, 'valueObjects')) {
				// у самых глубоких объектов не будет такого метода
				$attributes = [];
			} else {
				$attributes = $class::valueObjects($this->owner);
			}
			if ($isChanged) {
				return array_filter($attributes, function ($item) {
					return $item instanceof DependentProperty;
				});
			}

			self::$classMap[$class] = $attributes;
		}

		return self::$classMap[$class];
	}

	public function reInitObjects()
	{
		$this->_initialized = false;
		$this->initObjects();
	}

	public function initObjectsEvent()
	{
		if ($this->initializedAfterFind) {
			$this->initObjects();
		}
	}

	public function initObjects($old = false, $isChanged = false)
	{
		if (!$this->_initialized) {
			$this->createObjects($isChanged);
			// BACKLOG В active record нет смысла инстанциировать value objects сразу, но в Model есть
			$this->putObjects($old);
			$this->setOwnerOldAttributes();
			$this->_initialized = true;
		}
	}

	public function afterFind()
	{
		$this->initObjects(true);
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

	protected function createObjects($isChanged = false)
	{
		foreach ($this->getValueObjectAttributes($isChanged) as $attribute => $class) {
			if ($class instanceof DependentProperty) {
				//if ($class->canGet($this->owner)) {
					$this->objectsMap[$attribute] = $this->createObject($attribute, $class->getClass($this->owner));
				//}
			} else {
				$this->objectsMap[$attribute] = $this->createObject($attribute, $class);
			}
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

	public function getObject($attribute)
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

	final public function setAttributesEvent(ArgumentEvent $event)
	{
		$values = $event->arguments['values'];

		if (empty($values)) {
			return;
		}

		$this->setAttributes($values);
	}

	final public function setAttributeEvent(ArgumentEvent $event)
	{
		$name = $event->arguments['name'];
		$value = $event->arguments['value'];

		$this->setAttribute($name, $value);
	}

	protected function setAttributes($values)
	{
		$this->initObjects(true, true);
		foreach ($values as $name => $value) {
			$this->setAttribute($name, $value);
		}
	}

	protected function setAttribute($name, $value)
	{
		$isNotArray = !is_array($value);
		if ($this->isSimpleAttribute($name) && $isNotArray) {
			return;
		}

		if ($isNotArray) {
			$path = array_slice($this->getPathAttribute($name), 1);

			$arrayValue = [];
			$sectionValue = &$arrayValue;
			foreach ($path as $section) {
				$sectionValue[$section] = [];
				$sectionValue = &$sectionValue[$section];
			}
			$sectionValue = $value;

			$value = $arrayValue;
		}

		$this->setAttributeRecursive($name, $value);
	}

	public function validateObjectsEvent()
	{
		$this->putObjects();
		$this->validateObjects();
	}

	public function validateObjects()
	{
		$attributes = array_keys($this->getValueObjectAttributes());
		foreach ($attributes as $attribute) {
			$object = $this->getObject($attribute);
			if (!$object->validate()) {
				$this->owner->addErrors($object->getErrors());
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

	protected function isSimpleAttribute($name)
	{
		return false === strpos($name, $this->attributeSeparator);
	}

	protected function getFirstSectionName($path)
	{
		if (is_string($path)) {
			$path = $this->getPathAttribute($path);
		}

		return current($path);
	}

	protected function getAttributeNameByPath($path)
	{
		if (is_string($path)) {
			$path = $this->getPathAttribute($path);
		}

		return implode($this->attributeSeparator, array_slice($path, 1));
	}

	protected function getPathAttribute($name)
	{
		return explode($this->attributeSeparator, $name);
	}

	protected function getAttributeByPath($path, $withLastAttribute = false)
	{
		if (is_string($path)) {
			$path = $this->getPathAttribute($path);
		}

		if (!$withLastAttribute) {
			$path = array_slice($path, 0, -1);
		}

		$result = $this;

		foreach ($path as $section) {
			$result = $result->{$section};
		}

		return $result;
	}

	private function setAttributeRecursive($name, $value)
	{
		$nameSection = $this->getFirstSectionName($name);
		$object = $this->getObject($nameSection);
		$object->setAttributes($value);
	}
}
