<?php
use Doctrine\Common\Annotations\AnnotationReader,
	Doctrine\ODM\MongoDB\DocumentManager,
	Doctrine\MongoDB\Connection,
	Doctrine\ODM\MongoDB\Configuration,
	Doctrine\Common\Annotations\AnnotationRegistry,
	Doctrine\ODM\MongoDB\Mapping\Driver\AnnotationDriver,
	Doctrine\ODM\MongoDB\SchemaManager,
	Doctrine\ODM\MongoDB\Events,
	Doctrine\Common\ClassLoader,
	Doctrine\Common\Cache\ApcCache;

App::uses('DataSource', 'Model/Datasource');
App::uses('QueryProxy', 'MongoCake.Query');

class CakeMongoSource extends DataSource {
	
	private $configuration;
	private $connection;
	private $documentManager;

	public function __construct($config = array(), $autoConnect = true) {
		$this->_baseConfig = array(
			'proxyDir' => TMP . 'cache',
			'proxyNamespace' =>'Proxies',
			'hydratorDir' => TMP . 'cache',
			'hydratorNamespace' => 'Hydrators',
			'server' => 'localhost',
			'database' => 'cake'
		);
		parent::__construct($config);
		extract($this->config, EXTR_OVERWRITE);

		$configuration = new Configuration();
		$configuration->setProxyDir($proxyDir);
		$configuration->setProxyNamespace($proxyNamespace);
		$configuration->setHydratorDir($hydratorDir);
		$configuration->setHydratorNamespace($hydratorNamespace);
		$configuration->setDefaultDB($database);
		$configuration->setMetadataDriverImpl($this->_getMetadataReader());

		if (Configure::read('debug') === 0) {
			$configuration->setMetadataCacheImpl(new ApcCache());
		}

		$configuration->setLoggerCallable(function(array $log) {
		});
		$this->configuration = $configuration;
		$this->connection = new Connection($server, array(), $configuration);
		$this->documentManager = DocumentManager::create($this->connection, $configuration);

		$this->documentManager->getEventManager()
			->addEventListener(
				array(
					Events::prePersist,
					Events::preUpdate,
					Events::preRemove,
					Events::postPersist,
					Events::postUpdate,
					Events::postRemove,
				),
				$this
			);
		try {
			if ($autoConnect) {
				$this->connect();
			}
		} catch (Exception $e) {
			throw new MissingConnectionException(array('class' => get_class($this)));
		}
		
	}

	protected function _getMetadataReader() {
		$reader = new AnnotationReader();
		$driver = new AnnotationDriver($reader, App::path('Model'));
		AnnotationDriver::registerAnnotationClasses();
		AnnotationRegistry::registerFile(CakePlugin::path('MongoCake') . 'Lib' . DS . 'MongoCake' . DS . 'Annotation' . DS . 'Annotations.php');
		return $driver;
	}

	public function getConnection() {
		return $this->connection;
	}

	public function getDocumentManager() {
		return $this->documentManager;
	}

	public function getConfiguration() {
		return $this->configuration;
	}

	public function createQueryBuilder($documentName = null) {
		return new QueryProxy($this->documentManager, $this->configuration->getMongoCmd(), $documentName);
	}

	public function getSchemaManager() {
		return $this->getDocumentManager()->getSchemaManager();
	}

	public function connect() {
		return $this->connection->connect();
	}

	public function isConnected() {
		return $this->connection->isConnected();
	}

	public function prePersist(\Doctrine\ODM\MongoDB\Event\LifecycleEventArgs $eventArgs) {
		$document = $eventArgs->getDocument();
		$schema = $document->schema();
		if ($document->hasField('created') && $schema['modified']['type'] == 'date') {
			$document->created = new DateTime();
		}
		$continue = $document->beforeSave(false);
		if (!$continue) {
			throw new OperationCancelledException();
		}
	}

	public function postPersist(\Doctrine\ODM\MongoDB\Event\LifecycleEventArgs $eventArgs) {
		$eventArgs->getDocument()->afterSave(false);
	}

	public function preUpdate(\Doctrine\ODM\MongoDB\Event\LifecycleEventArgs $eventArgs) {
		$document = $eventArgs->getDocument();
		$dm = $eventArgs->getDocumentManager();
		$schema = $document->schema();
		if ($document->hasField('modified') && $schema['modified']['type'] == 'date') {
			$document->modified = new DateTime();
		}
		$continue = $document->beforeSave(true);
		if (!$continue) {
			throw new OperationCancelledException();
		}

		$uow = $dm->getUnitOfWork();
		$class = $dm->getClassMetaData(get_class($document));
		$uow->recomputeSingleDocumentChangeSet($class, $document);
	}

	public function postUpdate(\Doctrine\ODM\MongoDB\Event\LifecycleEventArgs $eventArgs) {
		$eventArgs->getDocument()->afterSave(true);
	}

	public function preRemove(\Doctrine\ODM\MongoDB\Event\LifecycleEventArgs $eventArgs) {
		$continue = $eventArgs->getDocument()->beforeDelete();
		if (!$continue) {
			throw new OperationCancelledException();
		}
	}

	public function postRemove(\Doctrine\ODM\MongoDB\Event\LifecycleEventArgs $eventArgs) {
		$eventArgs->getDocument()->afterDelete();
	}

/**
 * Get list of collection names
 *
 * @return array
 */
	public function listSources() {
		return array_keys($this->getDocumentManager()->getDocumentCollections());
	}

/**
 * Drop a Collection
 *
 * @param CakeSchema $schema Schema object
 * @param string $collection Collection name
 * @return void
 *
 * @todo We'll need to override CakeTestFixture to avoid $db->execute(...) but the implementation here is sound.
 */
	public function dropSchema(CakeSchema $schema, $collection = null) {
		foreach ($schema->tables as $curTable => $columns) {
			if (!$collection || $collection == $curTable) {
				$this->getSchemaManager()->dropDocumentCollection($collection);
			}
		}
	}
}

/**
 * Exception to be thrown if a save operation is cancelled
 *
 */
class OperationCancelledException extends Exception {}