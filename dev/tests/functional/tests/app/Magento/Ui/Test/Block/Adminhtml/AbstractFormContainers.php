<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Ui\Test\Block\Adminhtml;

use Magento\Mtf\Block\Form;
use Magento\Mtf\Client\Element\SimpleElement;
use Magento\Mtf\Block\Mapper;
use Magento\Mtf\Block\BlockFactory;
use Magento\Mtf\Client\BrowserInterface;
use Magento\Mtf\Fixture\FixtureInterface;
use Magento\Mtf\Fixture\InjectableFixture;

/**
 * Is used to represent a form with abstract containers.
 */
abstract class AbstractFormContainers extends Form
{
    /**
     * Containers list.
     *
     * @var array
     */
    protected $containers = [];

    /**
     * Fields that are not assigned to any container.
     *
     * @var array
     */
    protected $unassignedFields = [];

    /**
     * Page header selector.
     *
     * @var string
     */
    protected $header = 'header';

    /**
     * @constructor
     * @param SimpleElement $element
     * @param Mapper $mapper
     * @param BlockFactory $blockFactory
     * @param BrowserInterface $browser
     * @param array $config
     */
    public function __construct(
        SimpleElement $element,
        Mapper $mapper,
        BlockFactory $blockFactory,
        BrowserInterface $browser,
        array $config = []
    ) {
        parent::__construct($element, $blockFactory, $mapper, $browser, $config);
    }

    /**
     * Initialize.
     *
     * @return $this
     */
    protected function init()
    {
        $this->containers = $this->getFormMapping();
        return $this;
    }

    /**
     * Get Container class.
     *
     * @param string $containerName
     * @return AbstractContainer
     * @throws \Exception
     */
    protected function getContainer($containerName)
    {
        $containerClass = $this->containers[$containerName]['class'];
        /** @var AbstractContainer $container */
        $container = $this->blockFactory->create($containerClass, ['element' => $this->_rootElement]);
        if (!$container instanceof AbstractContainer) {
            throw new \Exception('Wrong Container Class.');
        }
        $container->setWrapper(
            isset($this->containers[$containerName]['wrapper']) ? $this->containers[$containerName]['wrapper'] : ''
        );
        $container->setMapping(
            isset($this->containers[$containerName]['fields']) ? (array)$this->containers[$containerName]['fields'] : []
        );

        return $container;
    }

    /**
     * Get data of the containers.
     *
     * @param FixtureInterface|null $fixture
     * @param SimpleElement|null $element
     * @return array
     */
    public function getData(FixtureInterface $fixture = null, SimpleElement $element = null)
    {
        $data = [];

        if (null === $fixture) {
            foreach ($this->containers as $containerName => $containerData) {
                $containerData = $this->getContainer($containerName)->getFieldsData();
                $data = array_merge($data, $containerData);
            }
        } else {
            $hasData = ($fixture instanceof InjectableFixture) ? $fixture->hasData() : true;
            $dataByContainers = $hasData ? $this->getFixtureFieldsByContainers($fixture) : [];
            foreach ($dataByContainers as $containerName => $containerFields) {
                if (!$containerName) {
                    continue;
                }
                $this->openContainer($containerName);
                $containerData = $this->getContainer($containerName)->getFieldsData($containerFields);
                $data = array_merge($data, $containerData);
            }
        }

        return $data;
    }

    /**
     * Fill form with containers.
     *
     * @param FixtureInterface $fixture
     * @param SimpleElement|null $element
     * @return $this
     */
    public function fill(FixtureInterface $fixture, SimpleElement $element = null)
    {
        $containers = $this->getFixtureFieldsByContainers($fixture);
        return $this->fillContainers($containers, $element);
    }

    /**
     * Create data array for filling containers
     * Returns data in format
     * [[abstract_container_name => [field_name => [attribute_name => attribute_value, ..], ..], ..]
     * where container name should be set to 'null' if a field is not present on the form.
     *
     * @param InjectableFixture $fixture
     * @return array
     */
    protected function getFixtureFieldsByContainers(InjectableFixture $fixture)
    {
        $dataByContainer = [];
        $data = $fixture->getData();
        foreach ($data as $field => $value) {
            $attributes = $fixture->getDataFieldConfig($field);
            $attributes['value'] = $value;
            if (array_key_exists('group', $attributes) && $attributes['group'] != 'null') {
                $dataByContainer[$attributes['group']][$field] = $attributes;
            } elseif (!array_key_exists('group', $attributes)) {
                $this->unassignedFields[$field] = $attributes;
            }
        }
        return $dataByContainer;
    }

    /**
     * Fill specified form with containers data.
     * Input data in format
     * [[container => [field => [attribute_name => attribute_value, ..], ..], ..]
     * where container name can be empty if a field is not assigned to any container.
     *
     * @param array $dataByContainers
     * @param SimpleElement|null $element
     * @return $this
     */
    protected function fillContainers(array $dataByContainers, SimpleElement $element = null)
    {
        $context = ($element === null) ? $this->_rootElement : $element;
        foreach ($dataByContainers as $containerName => $containerFields) {
            $this->openContainer($containerName);
            /** @var AbstractContainer $container */
            $container = $this->getContainer($containerName);
            $container->setFieldsData($containerFields, $context);
        }
        if (!empty($this->unassignedFields)) {
            $this->fillMissedFields();
        }
        return $this;
    }

    /**
     * Fill fields that were not found on the filled containers.
     *
     * @throws \Exception
     * @return void
     */
    protected function fillMissedFields()
    {
        foreach (array_keys($this->containers) as $containerName) {
            $container = $this->getContainer($containerName);
            if ($this->openContainer($containerName) && $this->isContainerVisible($containerName)) {
                $mapping = $container->dataMapping($this->unassignedFields);
                foreach ($mapping as $fieldName => $data) {
                    $element = $container->_rootElement->find($data['selector'], $data['strategy'], $data['input']);
                    if ($element->isVisible()) {
                        $element->setValue($data['value']);
                        unset($this->unassignedFields[$fieldName]);
                    }
                }
                if (empty($this->unassignedFields)) {
                    break;
                }
            }
        }

        if (!empty($this->unassignedFields)) {
            throw new \Exception(
                'Could not find all elements on the tabs: ' . implode(', ', array_keys($this->unassignedFields))
            );
        }
    }

    /**
     * Open container.
     *
     * @param string $containerName
     * @return $this
     */
    abstract protected function openContainer($containerName);

    /**
     * Check whether container is visible.
     *
     * @param string $containerName
     * @return bool
     */
    abstract protected function isContainerVisible($containerName);
}
