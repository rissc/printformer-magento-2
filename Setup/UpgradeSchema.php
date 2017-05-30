<?php
namespace Rissc\Printformer\Setup;

use Magento\Framework\DB\Ddl\Table;
use \Magento\Framework\Setup\UpgradeSchemaInterface;
use \Magento\Framework\Setup\ModuleContextInterface;
use \Magento\Framework\Setup\SchemaSetupInterface;
use \Magento\Framework\DB\Ddl\Table as DdlTable;
use \Magento\Framework\DB\Adapter\AdapterInterface;

class UpgradeSchema
    implements UpgradeSchemaInterface
{
    const TABLE_NAME_DRAFT    = 'printformer_draft';
    const TABLE_NAME_PRODUCT  = 'printformer_product';
    const TABLE_NAME_HISTORY  = 'printformer_async_history';
    
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $connection = $setup->getConnection();
        if(version_compare($context->getVersion(), '100.0.1', '<'))
        {
            $table = $connection->newTable(
                $setup->getTable(self::TABLE_NAME_DRAFT)
            )->addColumn(
                'id',
                DdlTable::TYPE_INTEGER,
                null,
                array (
                    'identity' => true,
                    'nullable' => false,
                    'primary' => true,
                    'unsigned' => true
                ),
                'Draft ID'
            )->addColumn(
                'draft_id',
                DdlTable::TYPE_TEXT,
                255,
                [],
                'Draft Hash'
            )->addColumn(
                'order_item_id',
                DdlTable::TYPE_INTEGER,
                null,
                [
                    'unsigned' => true,
                    'nullable' => true,
                ],
                'Order Item ID'
            )->addColumn(
                'store_id',
                DdlTable::TYPE_SMALLINT,
                null,
                [
                    'unsigned' => true,
                    'nullable' => false,
                    'primary' => true
                ],
                'Store ID'
            )->addIndex(
                $setup->getIdxName(self::TABLE_NAME_DRAFT, ['store_id']),
                ['store_id']
            )->addForeignKey(
                $setup->getFkName(self::TABLE_NAME_DRAFT, 'store_id', 'store', 'store_id'),
                'store_id',
                $setup->getTable('store'),
                'store_id',
                DdlTable::ACTION_CASCADE
            )->addColumn(
                'created_at',
                DdlTable::TYPE_TIMESTAMP,
                null,
                [
                    'nullable' => false,
                    'default' => DdlTable::TIMESTAMP_INIT
                ],
                'Create At'
            )->addIndex(
                $setup->getIdxName(
                    self::TABLE_NAME_DRAFT,
                    ['draft_id'],
                    AdapterInterface::INDEX_TYPE_UNIQUE
                ),
                ['draft_id'],
                ['type' => AdapterInterface::INDEX_TYPE_UNIQUE]
            );

            $setup->getConnection()->createTable($table);
        }

        if(version_compare($context->getVersion(), '100.1.5', '<'))
        {
            $connection->addColumn(
                $setup->getTable(self::TABLE_NAME_DRAFT),
                'processing_id',
                [
                    'type' => Table::TYPE_TEXT,
                    'length' => 255,
                    'nullable' => false,
                    'after' => 'created_at',
                    'comment' => 'Printformer Processing ID'
                ]
            );

            $connection->addColumn(
                $setup->getTable(self::TABLE_NAME_DRAFT),
                'processing_status',
                [
                    'type' => Table::TYPE_INTEGER,
                    'nullable' => false,
                    'after' => 'processing_id',
                    'comment' => 'Printformer Processing Status'
                ]
            );
        }

        if(version_compare($context->getVersion(), '100.1.7', '<'))
        {
            $table = $connection->newTable(
                $setup->getTable(self::TABLE_NAME_HISTORY)
            )
                ->addColumn(
                    'id',
                    DdlTable::TYPE_INTEGER,
                    null,
                    array (
                        'identity' => true,
                        'nullable' => false,
                        'primary' => true,
                        'unsigned' => true
                    ),
                    'Draft ID'
                )
                ->addColumn(
                    'draft_id',
                    DdlTable::TYPE_TEXT,
                    255,
                    [],
                    'Draft Hash'
                )
                ->addColumn(
                    'created_at',
                    DdlTable::TYPE_TIMESTAMP,
                    null,
                    [
                        'nullable' => false,
                        'default' => DdlTable::TIMESTAMP_INIT
                    ],
                    'Create At'
                )
                ->addColumn(
                    'direction',
                    DdlTable::TYPE_TEXT,
                    35,
                    [
                        'nullable' => false,
                        'default' => 'outgoing'
                    ],
                    'Communication direction'
                )
                ->addColumn(
                    'status',
                    DdlTable::TYPE_TEXT,
                    35,
                    [
                        'nullable' => false,
                    ],
                    'Request Status'
                )
                ->addColumn(
                    'request_data',
                    DdlTable::TYPE_TEXT,
                    null,
                    [
                        'nullable' => false
                    ]
                )
                ->addColumn(
                    'response_data',
                    DdlTable::TYPE_TEXT,
                    null,
                    [
                        'nullable' => false
                    ],
                    'Response data'
                )
                ->addColumn(
                    'api_url',
                    DdlTable::TYPE_TEXT,
                    null,
                    [
                        'nullable' => false
                    ],
                    'Called URL'
                );

            $setup->getConnection()->createTable($table);
        }

        $setup->endSetup();
    }
}