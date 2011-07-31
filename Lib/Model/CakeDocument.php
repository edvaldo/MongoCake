<?php

use Doctrine\ODM\MongoDB\UnitOfWork;

App::uses('ConnectionManager', 'Model');
App::uses('Validation', 'Utility');

class CakeDocument implements ArrayAccess {

/**
 * Specifies which connection name to use for this instance
 *
 * @var string
 */
	public $useDbConfig = 'default';

/**
 * Set of validation rules for this document
 *
 * @var array
 */
	public $validate = array();

/**
 * Holds the list of validation errors for each property of this document
 *
 * @var array
 */
	public $validationErrors = array();

/**
 * Name of the validation string domain to use when translating validation errors.
 *
 * @var string
 * @access public
 */
	public $validationDomain = null;

/**
 * Whitelist of fields allowed to be saved.
 *
 * @var array
 */
	public $whitelist = array();

/**
 * Holds the schema description for this document
 *
 * @var ArrayObject
 */
	protected $_schema = array();

	public function __get($property) {
		$schema = $this->schema();
		if (isset($schema['fieldMappings'][$property]) && method_exists($this, 'get' . $property)) {
			return $this->{'get'.$property}();
		}
	}

	public function __set($property, $value) {
		$schema = $this->schema();
		if (isset($schema['fieldMappings'][$property]) && method_exists($this, 'set' . $property)) {
			$this->{'set'.$property}($value);
		}
	}

	public function offsetExists($property) {
		try {
			$this->extractOffset($property);
		} catch (Exception $e) {
			return false;
		}

		return true;
	}

	public function offsetGet($property) {
		return $this->extractOffset($property);
	}

	public function offsetSet($property, $value) {
		$schema = $this->schema();
		if (isset($schema['fieldMappings'][$property])) {
			$this->{$property} = $value;
		}
	}

	public function offsetUnset($offset) {
		$schema = $this->schema();
		if (isset($schema['fieldMappings'][$property])) {
			$this->{$property} = null;
		}
	}

	protected function extractOffset($property) {
		if ($property == get_class($this)) {
			return $this;
		}

		$schema = $this->schema();
		$isField = isset($schema['fieldMappings'][$property]);

		if ($isField) {
			return $this->{$property};
		}

		foreach ($schema['fieldMappings'] as $p => $field) {
			if (!empty($field['targetDocument']) && $field['targetDocument'] == $property) {
				return $this->{$p};
			}
		}

		throw new Exception('Invalid offset');
	}

	public function getDataSource() {
		return ConnectionManager::getDataSource($this->useDbConfig);
	}

	public function getDocumentManager() {
		return $this->getDataSource()->getDocumentManager();
	}

	public function getRepository() {
		return $this->getDocumentManager()->getRepository(get_class($this));
	}

	public function flush() {
		try {
			$this->getDocumentManager()->flush();
		} catch (OperationCancelledException $e) {}
	}

	public function schema() {
		if (!empty($this->_schema)) {
			return $this->_schema;
		}
		$this->_schema = new ArrayObject(
			$this->getDocumentManager()
			->getMetadataFactory()
			->getMetadataFor(get_class($this))
		);
		return $this->_schema;
	}

	public function exists() {
		$uow = $this->getDocumentManager()->getUnitOfWork();
		$documentState = $uow->getDocumentState($this);
		return $documentState === UnitOfWork::STATE_MANAGED || $documentState ===  UnitOfWork::STATE_DETACHED;
	}

/**
 * This callback is fired just before the data is going to be committed into the persistent storage.
 * Keep in mind this means just after the flush() call, and not inside the save() method, so don't
 * rely on any behavior that cancels a save at this point unless you know what you are doing.
 * 
 * Returning false will cancel the persist operation.
 *
 * @param boolean $isUpdate whether this operation is a create or create operation
 * @return boolean true to continue saving, false to cancel
 */
	public function beforeSave($isUpdate) {
		return true;
	}


	public function afterSave($isUpdate) {}

	public function beforeDelete() {
		return true;
	}

	public function afterDelete() {}

/**
 * Called during validation operations, before validation. Please note that custom
 * validation rules can be defined in $validate.
 *
 * @return boolean True if validate operation should continue, false to abort
 * @param $options array Options passed from model::save(), see $options of model::save().
 */
	public function beforeValidate($options = array()) {
		return true;
	}

/**
 * Returns true if all fields pass validation.
 *
 * Will validate the currently set data.  Use Model::set() or Model::create() to set the active data.
 *
 * @param array $options An optional array of custom options to be made available in the beforeValidate callback
 * @return boolean True if there are no errors
 * @link http://book.cakephp.org/view/1182/Validating-Data-from-the-Controller
 */
	public function validates($options = array()) {
		$errors = $this->invalidFields($options);
		return empty($errors);
	}

/**
 * Returns an array of fields that have failed validation. On the current model.
 *
 * @param string $options An optional array of custom options to be made available in the beforeValidate callback
 * @return array Array of invalid fields
 * @link http://book.cakephp.org/view/1182/Validating-Data-from-the-Controller
 */
	public function invalidFields($options = array()) {
		//TODO: Add behaviors support
		if ($this->beforeValidate($options) === false) {
			return false;
		}

		if (!isset($this->validate) || empty($this->validate)) {
			return $this->validationErrors;
		}

		$exists = $this->exists();
		$_validate = $this->validate;
		$whitelist = $this->whitelist;
		$methods = get_class_methods($this);

		if (!empty($options['fieldList'])) {
			$whitelist = $options['fieldList'];
		}

		if (!empty($whitelist)) {
			$validate = array();
			foreach ((array)$whitelist as $f) {
				if (!empty($this->validate[$f])) {
					$validate[$f] = $this->validate[$f];
				}
			}
			$this->validate = $validate;
		}

		foreach ($this->validate as $fieldName => $ruleSet) {
			if (!is_array($ruleSet) || (is_array($ruleSet) && isset($ruleSet['rule']))) {
				$ruleSet = array($ruleSet);
			}
			$default = array(
				'allowEmpty' => null,
				'required' => null,
				'rule' => 'blank',
				'last' => true,
				'on' => null
			);

			foreach ($ruleSet as $index => $validator) {
				if (!is_array($validator)) {
					$validator = array('rule' => $validator);
				}
				$validator = array_merge($default, $validator);

				$validationDomain = $this->validationDomain;
				if (empty($validationDomain)) {
					$validationDomain = 'default';
				}
				if (isset($validator['message'])) {
					$message = $validator['message'];
				} else {
					$message = __d('cake_dev', 'This field cannot be left blank');
				}

				$value = $this->{$fieldName};
				if (
					empty($validator['on']) || ($validator['on'] == 'create' &&
					!$exists) || ($validator['on'] == 'update' && $exists
				)) {
					$required = (
						(!isset($value) && $validator['required'] === true) ||
						(
							isset($value) && (empty($value) &&
							!is_numeric($value)) && $validator['allowEmpty'] === false
						)
					);

					if ($required) {
						$this->invalidate($fieldName, __d($validationDomain, $message));
						if ($validator['last']) {
							break;
						}
					} elseif (!is_null($value)) {
						if (empty($value) && $value != '0' && $validator['allowEmpty'] === true) {
							break;
						}
						if (is_array($validator['rule'])) {
							$rule = $validator['rule'][0];
							unset($validator['rule'][0]);
							$ruleParams = array_merge(array($value), array_values($validator['rule']));
						} else {
							$rule = $validator['rule'];
							$ruleParams = array($value);
						}

						$valid = true;

						if (in_array($rule, $methods)) {
							$ruleParams[] = $validator;
							$ruleParams[0] = array($fieldName => $ruleParams[0]);
							$valid = call_user_func_array(array($this, $method), $ruleParams);
						} elseif (method_exists('Validation', $rule)) {
							$valid = call_user_func_array(array('Validation', $rule), $ruleParams);
						} elseif (!is_array($validator['rule'])) {
							$valid = preg_match($rule, $value);
						} elseif (Configure::read('debug') > 0) {
							trigger_error(__d('cake_dev', 'Could not find validation handler %s for %s', $rule, $fieldName), E_USER_WARNING);
						}

						if (!$valid || (is_string($valid) && strlen($valid) > 0)) {
							if (is_string($valid) && strlen($valid) > 0) {
								$validator['message'] = $valid;
							} elseif (!isset($validator['message'])) {
								if (is_string($index)) {
									$validator['message'] = $index;
								} elseif (is_numeric($index) && count($ruleSet) > 1) {
									$validator['message'] = $index + 1;
								} else {
									$validator['message'] = __d($validationDomain, $message);
								}
							}
							$this->invalidate($fieldName, $validator['message']);

							if ($validator['last']) {
								break;
							}
						}
					}
				}
			}
		}
		$this->validate = $_validate;
		return $this->validationErrors;
	}

/**
 * Marks a field as invalid, optionally setting the name of validation
 * rule (in case of multiple validation for field) that was broken.
 *
 * @param string $field The name of the field to invalidate
 * @param mixed $value Name of validation rule that was not failed, or validation message to
 *    be returned. If no validation key is provided, defaults to true.
 */
	public function invalidate($field, $value = true) {
		if (!is_array($this->validationErrors)) {
			$this->validationErrors = array();
		}
		$this->validationErrors[$field][]= $value;
	}

/**
 * For all keys different to 'id' in $one, the keys and values of $one are set to
 * the object properties in this document. If you provide as keys in $one names or aliases
 * for associated documents, this function will recursively set the properties on the associated objects
 *
 * @param array $one Array list of properties to be set indexed by Document name
 * @param boolean $persistAssociated Whether this method should persist associated Documents after being set
 * @return CakeDocument this instance
 */
	public function set($one, $persistAssociated = false) {
		if (!$one) {
			return;
		}

		$name = get_class($this);
		if (is_array($one)) {
			$data = $one;
			if (!isset($one[get_class($this)])) {
				$data = array($name => $one);
			}
		}

		$schema = $this->schema();
		$assotiated = $this->getAssociated();
		foreach ($data as $modelName => $fieldSet) {
			if (is_array($fieldSet)) {
				if (isset($assotiated[$modelName])) {
					$this->_setAssociated($modelName, $fieldSet, $assotiated[$modelName], $persistAssociated);
					continue;
				}
				foreach ($fieldSet as $fieldName => $fieldValue) {
					if (isset($this->validationErrors[$fieldName])) {
						unset($this->validationErrors[$fieldName]);
					}
					if ($modelName === $name) {
						if (!empty($schema['fieldMappings'][$fieldName]['targetDocument'])) {
							//Trying to set an attribute managed by other document
							continue;
						}
						if ($fieldName === 'id') {
							//Only a search or a save can set the id
							continue;
						}
						if (method_exists($this, 'set' . $fieldName)) {
							$this->{'set' . $fieldName}($fieldValue);
						}
					}
				}
			}
		}
		return $this;
	}

	protected function _setAssociated($modelName, $fieldSet, $assocOpts, $persist) {
		$assocDocument = $this->{$assocOpts['fieldName']};
		if ($assocOpts['type'] == 'hasMany' && Set::numeric(array_keys($fieldSet))) {
			if ($assocDocument === null || !($assocDocument instanceof Doctrine\Common\Collections\Collection)) {
				$assocDocument =  new \Doctrine\Common\Collections\ArrayCollection;
				$reflection = $this->schema();
				$reflection = $reflection['reflFields'][$assocOpts['fieldName']];
				$reflection->setAccessible(true);
				$reflection->setValue($this, $assocDocument);
			}
			foreach ($fieldSet as $i => $value) {
				if (is_array($value)) {
					if (!empty($value['id'])) {
					}
					
					$v = isset($assocDocument[$i]) ? $assocDocument[$i] : new $assocOpts['targetDocument'];
					$v->set($value);
					if ($persist) {
						$v->save();
					}
					$assocDocument[$i] = $v;
				}
			}
		}
		if (method_exists($this, 'set' . $assocOpts['fieldName'])) {
			$prop = ($assocDocument !== null) ? $assocDocument : new $assocOpts['targetDocument'];
			$prop->set($fieldSet);
			if ($assocDocument === null) {
				$this->{'set' . $assocOpts['fieldName']}($prop);
			}
			if ($persist) {
				$prop->save();
			}
		}
	}

/**
 * Persists a document in the datastore. Keep in mind that issuing flush() is needed after this method
 *
 * @return boolean success
 */
	public function save() {
		try {
			$this->getDocumentManager()->persist($this);
		} catch (OperationCancelledException $e) {
			return false;
		}
		return true;
	}

	public function delete() {
		try {
			$this->getDocumentManager()->remove($this);
			return true;
		} catch(OperationCancelledException $e) {
			return false;
		}
	}

	public function find($type, $options = array()) {
		$repository = $this->getRepository();
		if (!in_array($type, array('all', 'first'))) {
			return $repository->find($type);
		}
		$options += array('conditions' => array());
	}

/**
 * Returns a list of all associated documents mapped by a property in this object
 * pointing to an array containing all the options set for such relation including the
 * the type of relationship (hasOne, hasMany, belongsTo)
 *
 * Return example:
 *
 *	{{{
 *		array(
 *			'Address' => array('type' => 'hasOne')
 *			'PhoneNumber' => array('type' => 'hasMany', 'limit' => 5),
 *			'ParentCompany' => array('type' => 'belongsTo')
 *		)
 *	}}}
 *
 * @return array
 */
	public function getAssociated() {
		$schema = $this->schema();
		$relations = array();
		foreach ($schema['fieldMappings'] as $property => $opts) {
			if (empty($opts['targetDocument'])) {
				continue;
			}
			if ($opts['type'] == 'one') {
				$type = 'hasOne';
				$type = (!empty($opts['belongsTo'])) ? 'belongsTo' : $type;
			}
			if ($opts['type'] == 'many') {
				$type = 'hasMany';
			}
			$alias = $opts['targetDocument'];
			if (!empty($opts['alias'])) {
				$alias = $opts['alias'];
			}
			$opts['type'] = $type;
			$relations[$alias] = $opts;
		}
		return $relations;
	}

}