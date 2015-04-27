<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Step\CustomCustomerAttributes;

use Migration\Config;
use Migration\Reader\Groups;
use Migration\Reader\GroupsFactory;
use Migration\Reader\Map;
use Migration\Reader\MapFactory;
use Migration\Reader\MapInterface;
use Migration\Resource\Source;
use Migration\Resource\Destination;
use Migration\App\ProgressBar;
use Migration\Resource\Record;
use Migration\Resource\RecordFactory;
use Migration\Step\CustomCustomerAttributes;
use Migration\Step\DatabaseStage;
use Migration\Logger\Manager as LogManager;

/**
 * Class Data
 */
class Data extends DatabaseStage implements \Migration\App\Step\RollbackInterface
{
    /**
     * @var RecordFactory
     */
    protected $recordFactory;

    /**
     * @var Map
     */
    protected $map;

    /**
     * @var Groups
     */
    protected $groups;

    /**
     * @var Source
     */
    protected $source;

    /**
     * @var Destination
     */
    protected $destination;

    /**
     * @var ProgressBar
     */
    protected $progress;

    /**
     * @param Config $config
     * @param Source $source
     * @param Destination $destination
     * @param ProgressBar $progress
     * @param RecordFactory $recordFactory
     * @param MapFactory $mapFactory
     * @param GroupsFactory $groupsFactory
     */
    public function __construct(
        Config $config,
        Source $source,
        Destination $destination,
        ProgressBar $progress,
        RecordFactory $recordFactory,
        MapFactory $mapFactory,
        GroupsFactory $groupsFactory
    ) {
        parent::__construct($config);
        $this->source = $source;
        $this->destination = $destination;
        $this->progress = $progress;
        $this->recordFactory = $recordFactory;
        $this->groups = $groupsFactory->create('customer_attr_document_groups_file');
        $this->map = $mapFactory->create('customer_attr_map_file');
    }

    /**
     * Run step
     *
     * @return bool
     */
    public function perform()
    {
        /** @var \Migration\Resource\Adapter\Mysql $sourceAdapter */
        $sourceAdapter = $this->source->getAdapter();
        /** @var \Migration\Resource\Adapter\Mysql $destinationAdapter */
        $destinationAdapter = $this->destination->getAdapter();
        $sourceDocuments = array_keys($this->groups->getGroup('source_documents'));
        if (LogManager::getLogLevel() != LogManager::LOG_LEVEL_DEBUG) {
            $this->progress->start(count($sourceDocuments));
        }
        foreach ($sourceDocuments as $sourceDocumentName) {
            if (LogManager::getLogLevel() != LogManager::LOG_LEVEL_DEBUG) {
                $this->progress->advance();
            }

            $destinationDocumentName = $this->map->getDocumentMap($sourceDocumentName, MapInterface::TYPE_SOURCE);
            $sourceTable =  $sourceAdapter->getTableDdlCopy(
                $this->source->addDocumentPrefix($sourceDocumentName),
                $this->destination->addDocumentPrefix($destinationDocumentName)
            );
            $destinationTable = $destinationAdapter->getTableDdlCopy(
                $this->destination->addDocumentPrefix($destinationDocumentName),
                $this->destination->addDocumentPrefix($destinationDocumentName)
            );
            foreach ($sourceTable->getColumns() as $columnData) {
                $destinationTable->setColumn($columnData);
            }
            $destinationAdapter->createTableByDdl($destinationTable);

            $destinationDocument = $this->destination->getDocument($destinationDocumentName);
            $pageNumber = 0;
            if (LogManager::getLogLevel() == LogManager::LOG_LEVEL_DEBUG) {
                $this->progress->start($this->source->getRecordsCount($sourceDocumentName));
            }
            while (!empty($sourceRecords = $this->source->getRecords($sourceDocumentName, $pageNumber))) {
                $pageNumber++;
                $recordsToSave = $destinationDocument->getRecords();
                foreach ($sourceRecords as $recordData) {
                    if (LogManager::getLogLevel() == LogManager::LOG_LEVEL_DEBUG) {
                        $this->progress->advance();
                    }
                    /** @var Record $destinationRecord */
                    $destinationRecord = $this->recordFactory->create(['document' => $destinationDocument]);
                    $destinationRecord->setData($recordData);
                    $recordsToSave->addRecord($destinationRecord);
                }
                $this->destination->saveRecords($destinationDocument->getName(), $recordsToSave);
            };
            if (LogManager::getLogLevel() == LogManager::LOG_LEVEL_DEBUG) {
                $this->progress->finish();
            }
        }
        if (LogManager::getLogLevel() != LogManager::LOG_LEVEL_DEBUG) {
            $this->progress->finish();
        }
        return true;
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
        return true;
    }
}