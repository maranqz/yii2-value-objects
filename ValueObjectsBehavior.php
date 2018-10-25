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

			ActiveRecord::EVENT_AFTER_VALIDATE => 'validateObjectsEvent',

			static::EVENT_REINITIALIZE => 'reInitObjects',
			static::EVENT_FIRST_FILL => 'afterFind',

			EventARInterface::EVENT_AFTER_POPULATE_RECORD => 'populateRecordEvent',
			EventARInterface::EVENT_AFTER_SET_ATTRIBUTES => 'setAttributesEvent',
			EventARInterface::EVENT_AFTER_SET_ATTRIBUTE => 'setAttributeEvent',
		];

		return $commonEvents;
	}

	public function init()
	{

		$this->objectsMap = [];
		$this->jsonMap = [];
		$this->_initialized = false;
	}

	protected function getValueObjectAttributes()
	{
		$class = get_class($this->owner);
		if (!isset(self::$classMap[$class])) {
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
		$this->init();
		$this->initObjects();
	}

	public function initObjectsEvent()
	{
		$this->initObjects();
	}

	public function initObjects()
	{
		if (!$this->_initialized) {
			$this->createObjects();
			// BACKLOG В active record нет смысла инстанциировать value objects сразу, но в Model есть
			$this->putObjects();
			$this->setOwnerOldAttributes(true);
			$this->_initialized = true;
		}
	}

	public function populateRecordEvent()
	{
		$this->putObjects();
		$this->setOwnerOldAttributes(true);
	}

	public function afterFind()
	{
		$this->fillObjects();
		$this->putObjects();
		$this->setOwnerOldAttributes();
	}

	protected function fillObjects()
	{
		$attributes = array_keys($this->getValueObjectAttributes());
		foreach ($attributes as $attribute) {
			$object = $this->getObject($attribute);
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
		$attributes = $this->getValueObjectAttributes();
		foreach ($attributes as $attribute => $class) {
			$this->createObject($attribute, $class);
		}
	}

	protected function createObject($attribute, $class = null)
	{
		if (empty($class)) {
			$class = $this->getValueObjectAttributes()[$attribute];
		}

		$object = null;

		if ($class instanceOf ValueObjectList) {
			$object = $class;
		} elseif ($class instanceof DependentProperty) {
			if ($class->canGet($this->owner)) {
				$className = ($class->getClass($this->owner));
				$object = new $className;
			}
		} else {
			$object = new $class;
		}

		return $object;
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
			$this->objectsMap[$attribute] = $this->createObject($attribute);

			$this->owner->$attribute = $this->objectsMap[$attribute];
		}

		return $this->objectsMap[$attribute];
	}

	public function putJson()
	{
		foreach (array_keys($this->getValueObjectAttributes()) as $attribute) {
			$this->owner->$attribute = $this->getJson($attribute);
		}
	}

	public function putObjects()
	{
		$attributes = array_keys($this->getValueObjectAttributes());
		foreach ($attributes as $attribute) {
			$this->owner->$attribute = $this->getObject($attribute);
		}
	}

	final public function setAttributesEvent(ArgumentEvent $event)
	{
		$values = $event->arguments['values'];

		if (empty($values)) {
			return;
		}

		$this->initObjects();
		$this->setAttributes($values);
	}

	final public function setAttributeEvent(ArgumentEvent $event)
	{
		$name = $event->arguments['name'];
		$value = $event->arguments['value'];

		$this->initObjects();
		$this->setAttribute($name, $value);
	}

	protected function setAttributes($values)
	{
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

	protected function setOwnerOldAttributes($exchange = false)
	{
		if ($this->owner instanceOf \yii\db\ActiveRecordInterface && !$this->owner->isNewRecord) {
			foreach ($this->objectsMap as $attribute => $object) {
				if ($exchange) {
					$oldAttribute = $this->owner->getOldAttribute($attribute);
					$this->owner->setAttribute($attribute, $oldAttribute);
				}
				$this->owner->setOldAttribute($attribute, $object);
			}
		}
	}

	protected function setOwnerOldAttribute($attribute, $object)
	{
		if ($this->owner instanceOf \yii\db\ActiveRecordInterface && !$this->owner->isNewRecord) {
			$this->owner->setOldAttribute($attribute, $object);
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
