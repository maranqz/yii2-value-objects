<?php

namespace equicolor\valueObjects;

interface EventARInterface
{
	const EVENT_BEFORE_SET_ATTRIBUTES = 'beforeSetAttributes';
	const EVENT_AFTER_SET_ATTRIBUTES = 'afterSetAttributes';

	const EVENT_BEFORE_SET_ATTRIBUTE = 'beforeSetAttribute';
	const EVENT_AFTER_SET_ATTRIBUTE = 'afterSetAttribute';

	const EVENT_BEFORE_POPULATE_RECORD = 'beforePopulateRecord';
	const EVENT_AFTER_POPULATE_RECORD = 'afterPopulateRecord';
}
