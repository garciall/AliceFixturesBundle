<?php

/*
 * This file is part of the h4cc/AliceFixtureBundle package.
 *
 * (c) Julius Beckmann <github@h4cc.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace h4cc\AliceFixturesBundle\Fixtures;

use Nelmio\Alice\Fixtures;
use Nelmio\Alice\Loader\Base;
use Nelmio\Alice\LoaderInterface;
use Nelmio\Alice\ORMInterface;
use Nelmio\Alice\ProcessorInterface;
use Psr\Log\LoggerInterface;
use Doctrine\Common\Persistence\ObjectManager;
use h4cc\AliceFixturesBundle\ORM\SchemaTool;
use h4cc\AliceFixturesBundle\ORM\SchemaToolInterface;
use h4cc\AliceFixturesBundle\Loader\FactoryInterface;
use h4cc\AliceFixturesBundle\ORM\Doctrine;
use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * Class FixtureManager
 * Manager for fixture files and also fixture sets.
 *
 * @author Julius Beckmann <github@h4cc.de>
 */
class FixtureManager implements FixtureManagerInterface
{
    /**
     * Global provider for Faker.
     *
     * @var array
     */
    protected $providers = array();
    /**
     * @var ProcessorInterface[]
     */
    protected $processors = array();
    /**
     * Optional logger.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;
    /**
     * Default options for new FixtureSets.
     *
     * @var array
     */
    protected $options = array();
    /**
     * @var \Nelmio\Alice\ORM\Doctrine
     */
    protected $orm;
    /**
     * @var SchemaToolInterface
     */
    protected $schemaTool;

    protected $managerRegistry;

    /**
     * @param array $options
     * @param ObjectManager $objectManager
     * @param \h4cc\AliceFixturesBundle\Loader\FactoryInterface $loaderFactory
     * @param \h4cc\AliceFixturesBundle\ORM\SchemaToolInterface $schemaTool
     * @param LoggerInterface $logger
     */
    public function __construct(
        array $options,
        ManagerRegistry $managerRegistry,
        FactoryInterface $loaderFactory,
        SchemaToolInterface $schemaTool,
        LoggerInterface $logger = null
    )
    {
        $this->options = array_merge(
            $this->getDefaultOptions(),
            $options
        );
        $this->loaderFactory = $loaderFactory;
        $this->schemaTool = $schemaTool;
        $this->logger = $logger;
        $this->managerRegistry = $managerRegistry;
        $this->orm = new Doctrine($managerRegistry->getManager(), $this->options['do_flush']);
    }

    /**
     * @return array
     */
    public function getDefaultOptions()
    {
        return array(
            'seed' => 1,
            'locale' => 'en_EN',
            'do_flush' => true,
        );
    }

    /**
     * Loads entites from file, does _not_ persist them.
     *
     * @param array $files
     * @param string $type
     * @return array
     */
    public function loadFiles(array $files, $type = 'yaml')
    {
        $set = $this->createFixtureSet();
        foreach ($files as $file) {
            $set->addFile($file, $type);
        }
        $set->setDoPersist(false);

        return $this->load($set);
    }

    /**
     * Returns a new configured fixture set.
     *
     * @return FixtureSet
     */
    public function createFixtureSet()
    {
        return new FixtureSet($this->options);
    }

    /**
     * {@inheritDoc}
     */
    public function load(FixtureSet $set)
    {
        /** @var \Nelmio\Alice\LoaderInterface[] $loaders */
        $loaders = array();

        // Create needed loaders
        foreach ($set->getFiles() as $file) {
            $type = $file['type'];
            if (!isset($loaders[$type])) {
                $loader = $this->loaderFactory->getLoader($type, $set->getLocale());
                $this->configureLoader($loader);
                $loaders[$type] = $loader;
                $this->logDebug("Created loader for type '$type'.");
            }
        }

        // Objects are the loaded entities without "local".
        $objects = array();
        // References contain, _all_ objects loaded.
        $references = array();

        // Load each file
        foreach ($set->getFiles() as $file) {
            // Use seed before each loading, so results will be more predictable.
            $this->initSeedFromSet($set);

            $loader = $loaders[$file['type']];

            $loader->setReferences($references);
            $newObjects = $loader->load($file['path']);
            $references = $loader->getReferences();

            $this->logDebug("Loaded ".count($newObjects)." file '" . $file['path'] . "'.");
            $objects = array_merge($objects, $newObjects);
        }

        // Need to remove the "local" objects from references table.
        // This can be skipped, when this change has been released:
        // https://github.com/nelmio/alice/commit/d3bdb0d0e67e0b00d6e0b4df6b99a89bc7db882a
        $objects = $this->removeLocalReferences($references, $objects);

        if ($set->getDoPersist()) {
            $this->persist($objects, $set->getDoDrop());
            $this->logDebug("Persisted " . count($objects) . " loaded objects.");
        }

        // Detach entities
        $this->orm->detach($objects);

        return $objects;
    }

    /**
     * Helper for a "intersect" of loaded objects.
     *
     * @param $base
     * @param $extra
     * @return mixed
     */
    private function removeLocalReferences($base, $extra) {
        $intersect = $base;
        foreach ($base as $key => $value){
            if (!in_array($value, $extra)){
                unset($intersect[$key]);
            }
        }
        return $intersect;
    }

    /**
     * Returns the ORM.
     *
     * @throws \InvalidArgumentException
     * @return Doctrine
     */
    public function getORM()
    {
        return $this->orm;
    }

    /**
     * {@inheritDoc}
     */
    public function persist(array $entities, $drop = false)
    {
        if ($drop) {
            $this->recreateSchema();
        }
        $this->persistObjects($this->getORM(), $entities);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(array $entities)
    {
        foreach ($entities as &$entity) {

            $this->managerRegistry->getManagerForClass(get_class($entity))->remove($entity);
        }
    }

    /**
     * Returns the  ORM Schema tool.
     *
     * @throws \InvalidArgumentException
     * @return SchemaTool
     */
    public function getSchemaTool()
    {
        return $this->schemaTool;
    }

    /**
     * Returns global options.
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Adds a processor for processing a entity before and after persisting.
     *
     * @param ProcessorInterface $processor
     */
    public function addProcessor(ProcessorInterface $processor)
    {
        $this->processors[] = $processor;
        $this->logDebug('Added processor: ' . get_class($processor));
    }

    /**
     * Sets a list of providers for Faker.
     *
     * @param array $providers
     */
    public function setProviders(array $providers)
    {
        $this->providers = array();
        foreach ($providers as $provider) {
            $this->addProvider($provider);
        }
    }

    /**
     * Adds a provider for Faker.
     *
     * @param $provider
     */
    public function addProvider($provider)
    {
        $this->providers[] = $provider;
        $this->providers = array_unique($this->providers);
        $this->logDebug('Added provider: ' . get_class($provider));
    }

    /**
     * Sets all needed options and dependencies to a loader.
     *
     * @param LoaderInterface $loader
     */
    protected function configureLoader(LoaderInterface $loader)
    {
        if ($loader instanceof Base) {
            $loader->setORM($this->getORM());
            if ($this->logger) {
                $loader->setLogger($this->logger);
            }
        }
        $loader->setProviders($this->providers);
    }

    /**
     * Logs a message in debug level.
     *
     * @param $message
     */
    protected function logDebug($message)
    {
        if ($this->logger) {
            $this->logger->debug($message);
        }
    }

    /**
     * Initializes the seed for random numbers, given by a fixture set.
     */
    protected function initSeedFromSet(FixtureSet $set)
    {
        if (is_numeric($set->getSeed())) {
            mt_srand($set->getSeed());
            $this->logDebug('Initialized with seed ' . $set->getSeed());
        } else {
            mt_srand();
            $this->logDebug('Initialized with random seed');
        }
    }

    /**
     * Will drop and create the current ORM Schema.
     */
    protected function recreateSchema()
    {
        $schemaTool = $this->getSchemaTool();
        $schemaTool->dropSchema();
        $schemaTool->createSchema();
        $this->logDebug('Recreated Schema');
    }

    /**
     * Persists given objects using ORM persister, and calls registered processors.
     *
     * @param ORMInterface $persister
     * @param $objects
     */
    protected function persistObjects(ORMInterface $persister, array $objects)
    {
        foreach ($this->processors as $proc) {
            foreach ($objects as $obj) {
                $proc->preProcess($obj);
            }
        }

        foreach ($objects as &$obj)
        {
            $this->orm = new Doctrine($this->managerRegistry->getManagerForClass(get_class($obj)), $this->options['do_flush']);
            $em = $this->managerRegistry->getManagerForClass(get_class($obj));
            try {
                $this->orm->persist(array($obj));
            } catch (\Doctrine\ORM\ORMException $e) {
                if ($e->getTrace()[0]['function'] == 'entityMissingForeignAssignedId') {
                    $this->orm->flush();
                    $this->orm->persist(array($obj));
                }
            }
        }

        unset($obj);

        foreach ($this->processors as $proc) {
            foreach ($objects as $obj) {
                $proc->postProcess($obj);
            }
        }
    }
}
