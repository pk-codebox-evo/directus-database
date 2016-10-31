<?php
namespace Directus\Database\TableGateway;

use Directus\Bootstrap;
use Directus\Database\Object\Table;
use Directus\Database\Exception;
use Directus\Database\Query\Builder;
use Directus\Database\RowGateway\BaseRowGateway;
use Directus\Database\TableSchema;
use Directus\Util\ArrayUtils;
use Directus\Util\DateUtils;
use Directus\Util\StringUtils;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Predicate\PredicateInterface;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Where;
use Zend\Db\TableGateway\TableGateway;

class RelationalTableGateway extends BaseTableGateway
{
    const ACTIVITY_ENTRY_MODE_DISABLED = 0;
    const ACTIVITY_ENTRY_MODE_PARENT = 1;
    const ACTIVITY_ENTRY_MODE_CHILD = 2;

    protected $toManyCallStack = [];

    /**
     * @var array
     */
    protected $defaultEntriesSelectParams = [
        'orders' => ['sort' => 'ASC'],
        'columns' => '*',
        'limit' => 500,
        'offset' => 0,
        'skip' => null,
        'search' => null,
        'status' => null
    ];

    protected $operatorShorthand = [
        'eq' => ['operator' => 'equal_to', 'not' => false],
        '='  => ['operator' => 'equal_to', 'not' => false],
        'neq' => ['operator' => 'equal_to', 'not' => true],
        '!='  => ['operator' => 'equal_to', 'not' => true],
        '<>'  => ['operator' => 'equal_to', 'not' => true],
        'in' => ['operator' => 'in', 'not' => false],
        'nin' => ['operator' => 'in', 'not' => true],
        'lt' => ['operator' => 'less_than', 'not' => false],
        'lte' => ['operator' => 'less_than_or_equal', 'not' => false],
        'gt' => ['operator' => 'greater_than', 'not' => false],
        'gte' => ['operator' => 'greater_than_or_equal', 'not' => false],

        '<' => ['operator' => 'less_than', 'not' => false],
        '<=' => ['operator' => 'less_than_or_equal', 'not' => false],
        '>' => ['operator' => 'greater_than', 'not' => false],
        '>=' => ['operator' => 'greater_than_or_equal', 'not' => false],

        'null' => ['operator' => 'null'],
    ];

    public function manageRecordUpdate($tableName, $recordData, $activityEntryMode = self::ACTIVITY_ENTRY_MODE_PARENT, &$childLogEntries = null, &$parentCollectionRelationshipsChanged = false, $parentData = [])
    {
        $TableGateway = $this;
        if ($tableName !== $this->table) {
            $TableGateway = new RelationalTableGateway($tableName, $this->adapter, $this->acl);
        }

        $recordIsNew = !array_key_exists($TableGateway->primaryKeyFieldName, $recordData);

        $schemaArray = TableSchema::getSchemaArray($tableName);

        $currentUser = Bootstrap::get('auth')->getUserRecord();

        // Upload file if necessary
        $TableGateway->copyFiles($tableName, $recordData);
        // Delete file if necessary
        $TableGateway->deleteFiles($tableName, $recordData);

        //Dont do for directus users since id is pk
        if ($recordIsNew && $tableName != 'directus_users') {
            $cmsOwnerColumnName = $this->acl->getCmsOwnerColumnByTable($tableName);
            if ($cmsOwnerColumnName) {
                $recordData[$cmsOwnerColumnName] = $currentUser['id'];
            }
        }

        //Dont let non-admins make admins
        if ($tableName == 'directus_users' && $currentUser['group'] != 1) {
            if (isset($recordData['group']) && $recordData['group']['id'] == 1) {
                unset($recordData['group']);
            }
        }

        $thisIsNested = ($activityEntryMode == self::ACTIVITY_ENTRY_MODE_CHILD);

        // Recursive functions will change this value (by reference) as necessary
        // $nestedCollectionRelationshipsChanged = $thisIsNested ? $parentCollectionRelationshipsChanged : false;
        $nestedCollectionRelationshipsChanged = false;
        if ($thisIsNested) {
            $nestedCollectionRelationshipsChanged = &$parentCollectionRelationshipsChanged;
        }

        // Recursive functions will append to this array by reference
        // $nestedLogEntries = $thisIsNested ? $childLogEntries : [];
        $nestedLogEntries = [];
        if ($thisIsNested) {
            $nestedLogEntries = &$childLogEntries;
        }

        // Update and/or Add Many-to-One Associations
        $parentRecordWithForeignKeys = $TableGateway->addOrUpdateManyToOneRelationships($schemaArray, $recordData, $nestedLogEntries, $nestedCollectionRelationshipsChanged);

        // Merge the M21 foreign keys into the recordData array
        $recordData = array_merge($recordData, $parentRecordWithForeignKeys);

        // If more than the record ID is present.
        $newRecordObject = null;
        $parentRecordChanged = $this->recordDataContainsNonPrimaryKeyData($parentRecordWithForeignKeys); // || $recordIsNew;

        if ($parentRecordChanged) {
            // Update the parent row, w/ any new association fields replaced by their IDs
            $newRecordObject = $TableGateway
                ->addOrUpdateRecordByArray($parentRecordWithForeignKeys);

            if (!$newRecordObject) {
                return [];
            }

            if ($newRecordObject) {
                $newRecordObject = $newRecordObject->toArray();
            }
        }

        // Do it this way, because & byref for outcome of ternary operator spells trouble
        $draftRecord = &$parentRecordWithForeignKeys;
        if ($recordIsNew) {
            $draftRecord = &$newRecordObject;
        }

        // Restore X2M relationship / alias fields to the record representation & process these relationships.
        $collectionColumns = TableSchema::getAllAliasTableColumns($tableName);
        foreach ($collectionColumns as $collectionColumn) {
            $colName = $collectionColumn['id'];
            if (isset($recordData[$colName])) {
                $draftRecord[$colName] = $recordData[$colName];
            }
        }

        // parent
        if ($activityEntryMode === self::ACTIVITY_ENTRY_MODE_PARENT) {
            $parentData = [
                'id' => array_key_exists($this->primaryKeyFieldName, $recordData) ? $recordData[$this->primaryKeyFieldName] : null,
                'table_name' => $tableName
            ];
        }

        $draftRecord = $TableGateway->addOrUpdateToManyRelationships($schemaArray, $draftRecord, $nestedLogEntries, $nestedCollectionRelationshipsChanged, $parentData);
        $rowId = $draftRecord[$this->primaryKeyFieldName];

        $columnNames = TableSchema::getAllNonAliasTableColumnNames($tableName);
        $TemporaryTableGateway = new TableGateway($tableName, $this->adapter);
        $fullRecordData = $TemporaryTableGateway->select(function ($select) use ($rowId, $columnNames) {
            $select->where->equalTo($this->primaryKeyFieldName, $rowId);
            $select->limit(1)->columns($columnNames);
        })->current();

        if (!$fullRecordData) {
            $recordType = $recordIsNew ? 'new' : 'pre-existing';
            throw new \RuntimeException('Attempted to load ' . $recordType . ' record post-insert with empty result. Lookup via row id: ' . print_r($rowId, true));
        }

        $fullRecordData = (array)$fullRecordData;


        $deltaRecordData = $recordIsNew ? [] : array_intersect_key((array)$parentRecordWithForeignKeys, (array)$fullRecordData);

        switch ($activityEntryMode) {

            // Activity logging is enabled, and I am a nested action
            case self::ACTIVITY_ENTRY_MODE_CHILD:
                $logEntryAction = $recordIsNew ? DirectusActivityTableGateway::ACTION_ADD : DirectusActivityTableGateway::ACTION_UPDATE;
                $childLogEntries[] = [
                    'type' => DirectusActivityTableGateway::makeLogTypeFromTableName($this->table),
                    'table_name' => $tableName,
                    'action' => $logEntryAction,
                    'user' => $currentUser['id'],
                    'datetime' => DateUtils::now(),
                    'parent_id' => isset($parentData['id']) ? $parentData['id'] : null,
                    'parent_table' => isset($parentData['table_name']) ? $parentData['table_name'] : null,
                    'data' => json_encode($fullRecordData),
                    'delta' => json_encode($deltaRecordData),
                    'row_id' => $rowId,
                    'identifier' => $this->findRecordIdentifier($schemaArray, $fullRecordData),
                    'logged_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
                    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
                ];
                if ($recordIsNew) {
                    /**
                     * This is a nested call, creating a new record w/in a foreign collection.
                     * Indicate by reference that the top-level record's relationships have changed.
                     */
                    $parentCollectionRelationshipsChanged = true;
                }
                break;

            case self::ACTIVITY_ENTRY_MODE_PARENT:
                // Does this act deserve a log?
                $parentRecordNeedsLog = $nestedCollectionRelationshipsChanged || $parentRecordChanged;
                /**
                 * NESTED QUESTIONS!
                 * @todo  what do we do if the foreign record OF a foreign record changes?
                 * is that activity entry also directed towards this parent activity entry?
                 * @todo  how should nested activity entries relate to the revision histories of foreign items?
                 * @todo  one day: treat children as parents if this top-level record was not modified.
                 */
                $recordIdentifier = $this->findRecordIdentifier($schemaArray, $fullRecordData);
                // Produce log if something changed.
                if ($parentRecordChanged || $nestedCollectionRelationshipsChanged) {
                    $logEntryAction = $recordIsNew ? DirectusActivityTableGateway::ACTION_ADD : DirectusActivityTableGateway::ACTION_UPDATE;
                    //If we are updating and active is being set to 0 then we are deleting
                    if (!$recordIsNew && array_key_exists(STATUS_COLUMN_NAME, $deltaRecordData)) {
                        if ($deltaRecordData[STATUS_COLUMN_NAME] == STATUS_DELETED_NUM) {
                            $logEntryAction = DirectusActivityTableGateway::ACTION_DELETE;
                        }
                    }
                    // Save parent log entry
                    $parentLogEntry = BaseRowGateway::makeRowGatewayFromTableName('id', 'directus_activity', $this->adapter, $this->acl);
                    $logData = [
                        'type' => DirectusActivityTableGateway::makeLogTypeFromTableName($this->table),
                        'table_name' => $tableName,
                        'action' => $logEntryAction,
                        'user' => $currentUser['id'],
                        'datetime' => DateUtils::now(),
                        'parent_id' => null,
                        'data' => json_encode($fullRecordData),
                        'delta' => json_encode($deltaRecordData),
                        'parent_changed' => (int)$parentRecordChanged,
                        'identifier' => $recordIdentifier,
                        'row_id' => $rowId,
                        'logged_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
                        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
                    ];
                    $parentLogEntry->populate($logData, false);
                    $parentLogEntry->save();
                    // Update & insert nested activity entries
                    $ActivityGateway = new DirectusActivityTableGateway($this->acl, $this->adapter);
                    foreach ($nestedLogEntries as $entry) {
                        $entry['parent_id'] = $rowId;
                        // @todo ought to insert these in one batch
                        $ActivityGateway->insert($entry);
                    }
                }
                break;
        }

        // Yield record object
        $recordGateway = new BaseRowGateway($TableGateway->primaryKeyFieldName, $tableName, $this->adapter, $this->acl);
        $recordGateway->populate($fullRecordData, true);

        return $recordGateway;
    }

    /**
     * @param string $table
     * @param array $recordData
     * @return bool
     */
    public function deleteFiles($tableName, $recordData)
    {
        if ($tableName != 'directus_files') {
            return false;
        }

        if (!isset($recordData[STATUS_COLUMN_NAME]) || $recordData[STATUS_COLUMN_NAME] != STATUS_DELETED_NUM) {
            return false;
        }

        $filesTableGateway = new RelationalTableGateway($tableName, $this->adapter, $this->acl);
        $primaryKeyFieldName = $filesTableGateway->primaryKeyFieldName;

        $params = [];
        $params[$primaryKeyFieldName] = $recordData[$primaryKeyFieldName];
        $file = $filesTableGateway->getEntries($params);

        $Files = new \Directus\Files\Files();
        $Files->delete($file);

        return true;
    }

    /**
     * @param string $table
     * @param array $recordData
     * @return bool
     */
    public function copyFiles($tableName, &$recordData)
    {
        $schemaArray = TableSchema::getSchemaArray($tableName);
        foreach ($schemaArray as $column) {
            $colName = $column['id'];

            // Ignore absent values & non-arrays
            if (!isset($recordData[$colName]) || !is_array($recordData[$colName])) {
                continue;
            }

            $foreignRow = $recordData[$colName];

            $colUiType = $column['ui'];

            // $isManyToOne = (array_key_exists('relationship', $column) &&
            //     $column['relationship']['type'] == 'MANYTOONE'
            // );
            // $isManyToMany = (array_key_exists('relationship', $column) &&
            //     $column['relationship']['type'] == 'MANYTOMANY'
            // );

            $foreignTableName = $column['relationship']['related_table'];
            // @todo: rewrite this
            if ($foreignTableName === 'directus_files') {
                // Update/Add foreign record
                $Files = new \Directus\Files\Files();
                if (count(array_filter($foreignRow, 'is_array')) == count($foreignRow)) {
                    $index = 0;
                    foreach ($foreignRow as $row) {
                        if (!isset($row['data'][$this->primaryKeyFieldName]) && isset($row['data']['data'])) {
                            if (array_key_exists('type', $row['data']) && strpos($row['data']['type'], 'embed/') === 0) {
                                $recordData[$colName][$index]['data'] = $Files->saveEmbedData($row['data']);
                            } else {
                                $recordData[$colName][$index]['data'] = $Files->saveData($row['data']['data'], $row['data']['name']);
                            }
                        }

                        unset($recordData[$colName][$index]['data']['data']);
                        $index++;
                    }
                } else {
                    if (!isset($foreignRow[$this->primaryKeyFieldName]) && isset($foreignRow['data'])) {
                        if (array_key_exists('type', $foreignRow) && strpos($foreignRow['type'], 'embed/') === 0) {
                            $recordData[$colName] = $Files->saveEmbedData($foreignRow);
                        } else {
                            $recordData[$colName] = $Files->saveData($foreignRow['data'], $foreignRow['name']);
                        }
                    }
                    unset($recordData[$colName]['data']);
                }
            }
        }

        return true;
    }

    /**
     * @param array $schema The table schema array.
     * @param array $parentRow The parent record being updated.
     * @return  array
     */
    public function addOrUpdateManyToOneRelationships($schema, $parentRow, &$childLogEntries = null, &$parentCollectionRelationshipsChanged = false)
    {
        // Create foreign row and update local column with the data id
        foreach ($schema as $column) {
            $colName = $column['id'];

            if (!isset($column['relationship']) || !is_array($column['relationship'])) {
                continue;
            }

            // Ignore absent values & non-arrays
            if (!isset($parentRow[$colName]) || !is_array($parentRow[$colName])) {
                continue;
            }

            $relationship = $column['relationship'];
            $fieldIsCollectionAssociation = in_array($relationship['type'], TableSchema::$association_types);
            $lowercaseColumnType = strtolower($relationship['type']);

            // Ignore empty OneToMany collections
            $fieldIsOneToMany = ('onetomany' === $lowercaseColumnType);

            // Ignore non-arrays and empty collections
            if (empty($parentRow[$colName])) {
                // Once they're managed, remove the foreign collections from the record array
                unset($parentRow[$colName]);
                continue;
            }

            $foreignDataSet = $parentRow[$colName];

            $colUiType = $column['ui'];

            $isManyToOne = (array_key_exists('relationship', $column) &&
                $column['relationship']['type'] == 'MANYTOONE'
            );

            /** Many-to-One */
            if ($isManyToOne) {
                $foreignRow = $foreignDataSet;
                $foreignTableName = null;

                $foreignTableName = $column['relationship']['related_table'];

                // Update/Add foreign record
                if ($this->recordDataContainsNonPrimaryKeyData($foreignRow)) {
                    $foreignRow = $this->addOrUpdateRecordByArray($foreignRow, $foreignTableName);
                }
                $parentRow[$colName] = $foreignRow['id'];
            } /** One-to-Many, Many-to-Many */
            elseif ($fieldIsCollectionAssociation) {
                unset($parentRow[$colName]);
            }
        }
        return $parentRow;
    }

    /**
     * @param array $schema The table schema array.
     * @param array $parentRow The parent record being updated.
     * @return  array
     */
    public function addOrUpdateToManyRelationships($schema, $parentRow, &$childLogEntries = null, &$parentCollectionRelationshipsChanged = false, $parentData = [])
    {
        // Create foreign row and update local column with the data id
        foreach ($schema as $column) {
            $colName = $column['id'];

            if (!isset($column['relationship']) || !is_array($column['relationship'])) {
                continue;
            }

            // Ignore absent values & non-arrays
            if (!isset($parentRow[$colName]) || !is_array($parentRow[$colName])) {
                continue;
            }

            $relationship = $column['relationship'];
            $fieldIsCollectionAssociation = in_array($relationship['type'], TableSchema::$association_types);
            $lowercaseColumnType = strtolower($relationship['type']);

            // Ignore empty OneToMany collections
            $fieldIsOneToMany = ('onetomany' === $lowercaseColumnType);

            // Ignore non-arrays and empty collections
            if (empty($parentRow[$colName])) {//} || ($fieldIsOneToMany && )) {
                // Once they're managed, remove the foreign collections from the record array
                unset($parentRow[$colName]);
                continue;
            }

            $foreignDataSet = $parentRow[$colName];
            $colUiType = $column['ui'];

            /** One-to-Many, Many-to-Many */
            if ($fieldIsCollectionAssociation) {
                $this->enforceColumnHasNonNullValues($column['relationship'], ['related_table', 'junction_key_right'], $this->table);
                $foreignTableName = $column['relationship']['related_table'];
                $foreignJoinColumn = $column['relationship']['junction_key_right'];
                switch ($lowercaseColumnType) {

                    /** One-to-Many */
                    case 'onetomany':
                        $ForeignTable = new RelationalTableGateway($foreignTableName, $this->adapter, $this->acl);
                        foreach ($foreignDataSet as &$foreignRecord) {
                            if (empty($foreignRecord)) {
                                continue;
                            }

                            $foreignSchemaArray = TableSchema::getSchemaArray($ForeignTable->table);
                            $hasActiveColumn = $this->schemaHasActiveColumn($foreignSchemaArray);
                            $foreignColumn = TableSchema::getColumnSchemaArray($ForeignTable->table, $foreignJoinColumn);
                            $hasPrimaryKey = isset($foreignRecord[$ForeignTable->primaryKeyFieldName]);
                            $canBeNull = $foreignColumn['is_nullable'] === 'YES';

                            if ($hasPrimaryKey && isset($foreignRecord[STATUS_COLUMN_NAME]) && $foreignRecord[STATUS_COLUMN_NAME] === STATUS_DELETED_NUM) {
                                if (!$hasActiveColumn && !$canBeNull) {
                                    $Where = new Where();
                                    $Where->equalTo($ForeignTable->primaryKeyFieldName, $foreignRecord[$ForeignTable->primaryKeyFieldName]);
                                    $ForeignTable->delete($Where);

                                    continue;
                                }

                                if (!$hasActiveColumn || $canBeNull) {
                                    unset($foreignRecord[STATUS_COLUMN_NAME]);
                                }

                                if (!$canBeNull) {
                                    $foreignRecord[$foreignJoinColumn] = $parentRow['id'];
                                }
                            }

                            // only add parent id's to items that are lacking the parent column
                            if (!array_key_exists($foreignJoinColumn, $foreignRecord)) {
                                $foreignRecord[$foreignJoinColumn] = $parentRow['id'];
                            }

                            $foreignRecord = $this->manageRecordUpdate($foreignTableName, $foreignRecord, self::ACTIVITY_ENTRY_MODE_CHILD, $childLogEntries, $parentCollectionRelationshipsChanged, $parentData);
                        }
                        break;

                    /** Many-to-Many */
                    case 'manytomany':
                        /**
                         * [+] Many-to-Many payloads declare collection items this way:
                         * $parentRecord['collectionName1'][0-9]['data']; // record key-value array
                         * [+] With optional association metadata:
                         * $parentRecord['collectionName1'][0-9]['id']; // for updating a pre-existing junction row
                         * $parentRecord['collectionName1'][0-9]['active']; // for disassociating a junction via the '0' value
                         */
                        $noDuplicates = isset($column['options']['no_duplicates']) ? $column['options']['no_duplicates'] : 0;

                        $this->enforceColumnHasNonNullValues($column['relationship'], ['junction_table', 'junction_key_left'], $this->table);
                        $junctionTableName = $column['relationship']['junction_table'];
                        $junctionKeyLeft = $column['relationship']['junction_key_left'];
                        $junctionKeyRight = $column['relationship']['junction_key_right'];
                        $JunctionTable = new RelationalTableGateway($junctionTableName, $this->adapter, $this->acl);
                        $ForeignTable = new RelationalTableGateway($foreignTableName, $this->adapter, $this->acl);
                        foreach ($foreignDataSet as $junctionRow) {
                            /** This association is designated for removal */
                            if (isset($junctionRow[STATUS_COLUMN_NAME]) && $junctionRow[STATUS_COLUMN_NAME] == STATUS_DELETED_NUM) {
                                $Where = new Where;
                                $Where->equalTo($JunctionTable->primaryKeyFieldName, $junctionRow[$JunctionTable->primaryKeyFieldName]);
                                $JunctionTable->delete($Where);
                                // Flag the top-level record as having been altered.
                                // (disassociating w/ existing M2M collection entry)
                                $parentCollectionRelationshipsChanged = true;
                                continue;
                            } else if (isset($junctionRow['data'][$JunctionTable->primaryKeyFieldName])) {
                                // Is this a new element?
                                // if the element `id` exists it's because is not a new element
                                // and already had its id given.
                                $Where = new Where;
                                $Where->equalTo($junctionKeyLeft, $parentRow[$this->primaryKeyFieldName])
                                    ->equalTo($junctionKeyRight, $junctionRow['data'][$JunctionTable->primaryKeyFieldName]);

                                // hard-coded check for sort diff
                                // @todo fix this
                                $junctionRowResult = $JunctionTable->select($Where);
                                if ($junctionRowResult->count()) {
                                    // we are expecting one.
                                    $junctionRowResultArray = $junctionRowResult->toArray();
                                    $junctionRowResultArray = end($junctionRowResultArray);
                                    if (array_key_exists('sort', $junctionRow) && array_key_exists('sort', $junctionRowResultArray)) {
                                        if ($junctionRowResultArray['sort'] === $junctionRow['sort']) {
                                            continue;
                                        }
                                    }
                                }
                            }

                            /** Update foreign record */
                            $foreignRecord = $ForeignTable->manageRecordUpdate($foreignTableName, $junctionRow['data'], self::ACTIVITY_ENTRY_MODE_CHILD, $childLogEntries, $parentCollectionRelationshipsChanged, $parentData);
                            // Junction/Association row
                            $junctionTableRecord = [
                                $junctionKeyLeft => $parentRow[$this->primaryKeyFieldName],
                                $foreignJoinColumn => $foreignRecord[$ForeignTable->primaryKeyFieldName]
                            ];

                            // Update fields on the Junction Record
                            $junctionTableRecord = array_merge($junctionTableRecord, $junctionRow);

                            $foreignRecord = (array)$foreignRecord;

                            $relationshipChanged = $this->recordDataContainsNonPrimaryKeyData($foreignRecord, $ForeignTable->primaryKeyFieldName) ||
                                $this->recordDataContainsNonPrimaryKeyData($junctionTableRecord, $JunctionTable->primaryKeyFieldName);

                            // Update Foreign Record
                            if ($relationshipChanged) {
                                unset($junctionTableRecord['data']);
                                $JunctionTable->addOrUpdateRecordByArray($junctionTableRecord, $junctionTableName);
                            }
                        }
                        break;
                }
                // Once they're managed, remove the foreign collections from the record array
                unset($parentRow[$colName]);
            }

        }

        return $parentRow;
    }

    public function applyDefaultEntriesSelectParams(array $params)
    {
        $defaultParams = $this->defaultEntriesSelectParams;
        // Is not there a sort column?
        $tableColumns = array_flip(TableSchema::getTableColumns($this->table, null, true));
        if (!array_key_exists('sort', $tableColumns)) {
            unset($defaultParams['orders']);
        }

        $params = array_merge($defaultParams, $params);

        array_walk($params, [$this, 'castFloatIfNumeric']);

        return $params;
    }

    public function applyParamsToTableEntriesSelect(array $params, Builder $builder, Table $schema, $hasActiveColumn = false)
    {
        // @TODO: Query Builder Object
        foreach($params as $type => $argument) {
            $method = 'process' . ucfirst($type);
            if (method_exists($this, $method)) {
                call_user_func_array([$this, $method], [$builder, $argument]);
            }
        }

        $this->applyLegacyParams($builder, $params);

        return $builder;
    }

    /**
     * Relational Getter
     * NOTE: equivalent to old DB#get_entries
     */
    public function getEntries($params = [])
    {
        // Get table column schema
        $schemaArray = TableSchema::getSchemaArray($this->table);

        // table only has one column
        // return an empty array
        if ($schemaArray === false || count($schemaArray->getColumns()) <= 1) {
            return [];
        }

        // Table has `status` column?
        $hasActiveColumn = $schemaArray->hasStatusColumn();//$this->schemaHasActiveColumn($schemaArray);

        $params = $this->applyDefaultEntriesSelectParams($params);
        // @TODO: Create a new TableGateway Query Builder based on Query\Builder
        $builder = new Builder($this->getAdapter());
        $builder->from($this->getTable());
        // $sql = new Sql($this->adapter);
        // $select = $sql->select()->from($this->table);

        // @TODO: Only select the fields not on the currently authenticated user group's read field blacklist
        $builder->columns(TableSchema::getAllNonAliasTableColumnNames($this->table));
        //$columnNames = TableSchema::getAllNonAliasTableColumnNames($this->table);

        // $select->columns($columnNames);

        // if (array_key_exists('related_table_filter', $params)) {
        //    $select->where->equalTo($params['related_table_filter']['column'], $params['related_table_filter']['val']);
        // }

        // $select = $this->applyParamsToTableEntriesSelect($params, $select, $schemaArray, $hasActiveColumn);
        $builder = $this->applyParamsToTableEntriesSelect($params, $builder, $schemaArray, $hasActiveColumn);

        // If we have user field and do not have big view privileges but have view then only show entries we created
        $cmsOwnerId = $this->acl ? $this->acl->getCmsOwnerColumnByTable($this->table) : null;
        $currentUserId = $this->acl ? $this->acl->getUserId() : null;
        if ($cmsOwnerId && !$this->acl->hasTablePrivilege($this->table, 'bigview') && $this->acl->hasTablePrivilege($this->table, 'view')) {
            // $select->where->equalTo($cmsOwnerId, $currentUserId);
            $builder->whereEqualTo($cmsOwnerId, $currentUserId);
        }

        // $results = $this->selectWith($select)->toArray();
        $results = $builder->get()->toArray();

        // Note: ensure this is sufficient, in lieu of incrementing within
        // the foreach loop below.
        $foundRows = count($results);

        // ==========================================================================
        // Perform data casting based on the column types in our schema array
        // and Convert dates into ISO 8601 Format
        // ==========================================================================
        $results = $this->parseRecord($results);

        // Eager-load related ManyToOne records
        $this->toManyCallStack = [];
        $results = $this->loadManyToOneRelationships($schemaArray, $results);

        // =============================================================================
        // HOTFIX: Fetching X2M data and Infinite circle loop
        // =============================================================================
        $aliasColumns = $schemaArray->getAliasColumns();
        foreach($results as $key => $result) {
            $this->toManyCallStack = [];
            $results[$key] = $this->loadToManyRelationships($result, $aliasColumns);
        }

        /**
         * Fetching a set of data
         */

        if (!ArrayUtils::has($params, $this->primaryKeyFieldName)) {
            $set = [];
            if ($hasActiveColumn) {
                $countActive = $this->countActive($hasActiveColumn);
                $set = array_merge($set, $countActive);
            } else {
                $set['total'] = $this->countTotal();
            }
            $set['rows'] = $results;
            return $set;
        }

        if (!$results) {
            return $results;
        }

        list($result) = $results;

        return $result;
    }

    /**
     * Process Select Filters (Where conditions)
     *
     * @param Builder $query
     * @param array $filters
     */
    protected function processFilters(Builder $query, array $filters = [])
    {
        foreach($filters as $column => $condition) {
            $operator = is_array($condition) ? key($condition) : '=';
            $value = is_array($condition) ? current($condition) : $condition;
            $not = false;

            // Get information about the operator shorthand
            if (ArrayUtils::has($this->operatorShorthand, $operator)) {
                $operatorShorthand = $this->operatorShorthand[$operator];
                $operator = ArrayUtils::get($operatorShorthand, 'operator', $operator);
                $not = ArrayUtils::get($operatorShorthand, 'not', !$value);
            }

            $operator = StringUtils::underscoreToCamelCase(strtolower($operator), true);
            $method = 'where' . ($not === true ? 'Not' : '') . $operator;
            if (!method_exists($query, $method)) {
                continue;
            }

            $arguments = [$column, $value];
            $relationship = TableSchema::getColumnRelationship($this->getTable(), $column);
            $relationshipType = $relationship->getType();
            if ($relationship) {
                if ($relationshipType == 'MANYTOMANY') {
                    if (is_string($value)) {
                        $value = array_map(function($item) {
                            return trim($item);
                        }, explode(',', $value));
                    }
                    $arguments = [
                        $this->primaryKeyFieldName,
                        $relationship->getJunctionTable(),
                        $relationship->getJunctionKeyLeft(),
                        $relationship->getJunctionKeyRight(),
                        $value
                    ];
                }
            }

            call_user_func_array([$query, $method], $arguments);
        }
    }

    /**
     * Process Select Order
     *
     * @param Builder $query
     * @param array $orders
     */
    protected function processOrders(Builder $query, array $orders)
    {
        foreach($orders as $orderBy => $orderDirection) {
            $query->orderBy($orderBy, $orderDirection);
        }
    }

    /**
     * Process Select Limit
     *
     * @param Builder $query
     * @param int $limit
     */
    protected function processLimit(Builder $query, $limit)
    {
        $query->limit((int) $limit);
    }

    /**
     * Process Select offset
     *
     * @param Builder $query
     * @param int $offset
     */
    protected function processOffset(Builder $query, $offset)
    {
        $query->offset((int) $offset);
    }

    /**
     * Apply legacy params to support old api requests
     *
     * @param Builder $query
     * @param array $params
     */
    protected function applyLegacyParams(Builder $query, array $params = [])
    {
        // @TODO: Clear query orders
        // "order" will be replace it with "orderBy", if presented
        if (ArrayUtils::has($params, 'orderBy')) {
            $query->clearOrders();
            $query->orderBy($params['orderBy'], ArrayUtils::get($params, 'orderDirection', 'ASC'));
        }

        // sort, sort_order will replace "order" and "orderBy", if presented
        if (ArrayUtils::has($params, 'sort')) {
            $query->clearOrders();
            $query->orderBy($params['sort'], ArrayUtils::get($params, 'sort_order', 'ASC'));
        }

        if (ArrayUtils::has($params, $this->primaryKeyFieldName)) {
            $query->whereEqualTo($this->primaryKeyFieldName, $params[$this->primaryKeyFieldName]);
            $query->limit(1);
        }

        if (ArrayUtils::has($params, 'status') && TableSchema::hasStatusColumn($this->getTable())) {
            $statuses = is_array($params['status']) ? $params['status'] : array_map(function($item) {
                return trim($item);
            }, explode(',', $params['status']));
            $query->whereIn(STATUS_COLUMN_NAME, $statuses);
        }

        if (ArrayUtils::has($params, 'adv_where') && is_array($params['adv_where'])) {
            $query->where(key($params['adv_where']), '=', current($params['adv_where']));
        }

        if (ArrayUtils::has($params, 'ids')) {
            $entriesIds = array_map(function($item) {
                return trim($item);
            }, explode(',', $params['ids']));
            if (count($entriesIds) > 0) {
                $query->whereIn($this->primaryKeyFieldName, $entriesIds);
            }
        }

        if (ArrayUtils::has($params, 'perPage') && ArrayUtils::has($params, 'currentPage')) {
            $query->limit($params['perPage']);
            $query->offset($params['currentPage'] * $params['perPage']);
        }

        if (ArrayUtils::has($params, 'group_by')) {
            $query->groupBy($params['group_by']);
        }
    }

    /**
     *
     * Association Getter Functions
     *
     **/

    /**
     * Throws error if column or relation is missing values
     * @param  array $column One schema column representation.
     * @param  array $requiredKeys Values requiring definition.
     * @param  string $tableName
     * @return void
     * @throws  \Directus\Database\Exception\RelationshipMetadataException If the required values are undefined.
     */
    private function enforceColumnHasNonNullValues($column, $requiredKeys, $tableName)
    {
        $erroneouslyNullKeys = [];
        foreach ($requiredKeys as $key) {
            if (!isset($column[$key]) || (strlen(trim($column[$key])) === 0)) {
                $erroneouslyNullKeys[] = $key;
            }
        }
        if (!empty($erroneouslyNullKeys)) {
            $msg = 'Required column/ui metadata columns on table ' . $tableName . ' lack values: ';
            $msg .= implode(' ', $requiredKeys);
            throw new Exception\RelationshipMetadataException($msg);
        }
    }

    /**
     * Populate alias/relational One-To-Many and Many-To-Many fields with their foreign data.
     *
     * @param array $entry [description]
     * @param array $aliasColumns [description]
     * @param string|null $parentField
     * @param int $level
     *
     * @return array
     */
    public function loadToManyRelationships($entry, $aliasColumns, $parentField = null, $level = 0)
    {
        foreach ($aliasColumns as $alias) {
            $foreign_data = null;
            $relationship = $alias->getRelationship();

            if ($relationship && TableSchema::canGroupViewTable($relationship->getRelatedTable())) {
                switch ($relationship->getType()) {
                    case 'MANYTOMANY':
                        $this->enforceColumnHasNonNullValues($alias['relationship'], ['related_table', 'junction_table', 'junction_key_left', 'junction_key_right'], $this->table);
                        $foreign_data = $this->loadManyToManyRelationships($this->table, $alias['relationship']['related_table'],
                            $alias['relationship']['junction_table'], $alias['relationship']['junction_key_left'], $alias['relationship']['junction_key_right'],
                            $entry[$this->primaryKeyFieldName],
                            is_null($parentField) ? $alias['name'] : $parentField,
                            $level);
                        $noDuplicates = isset($alias['options']['no_duplicates']) ? $alias['options']['no_duplicates'] : 0;
                        // @todo: better way to handle this.
                        // @TODO: fetch uniques/non-duplicates entries
                        if (isset($foreign_data['rows']) && $noDuplicates) {
                            $uniquesID = [];
                            foreach ($foreign_data['rows'] as $index => $row) {
                                if (!in_array($row['data']['id'], $uniquesID)) {
                                    array_push($uniquesID, $row['data']['id']);
                                } else {
                                    unset($foreign_data['rows'][$index]);
                                }
                            }
                            unset($uniquesID);
                            // =========================================================
                            // Reset keys
                            // ---------------------------------------------------------
                            // This prevent json output using numeric ids as key
                            // Ex:
                            // {
                            //      rows: {
                            //          "1": {
                            //              data: {id: 1}
                            //          },
                            //          "3" {
                            //              data: {id: 2}
                            //          }
                            //      }
                            // }
                            // Instead of:
                            // {
                            //      rows: [
                            //          {
                            //              data: {id: 1}
                            //          },
                            //          {
                            //              data: {id: 2}
                            //          }
                            //      ]
                            // }
                            // =========================================================
                            $foreign_data['rows'] = array_values($foreign_data['rows']);
                        }
                        break;
                    case 'ONETOMANY':
                        $this->enforceColumnHasNonNullValues($alias['relationship'], ['related_table', 'junction_key_right'], $this->table);
                        $foreign_data = $this->loadOneToManyRelationships(
                            $alias['relationship']['related_table'],
                            $alias['relationship']['junction_key_right'],
                            $entry['id'],
                            is_null($parentField) ? $alias['name'] : $parentField,
                            $level
                        );
                        break;
                }
            }

            if (!is_null($foreign_data)) {
                // @TODO: make column name alias of name
                //$column = $alias['column_name'];
                $column = $alias['name'];
                $entry[$column] = $foreign_data;
            }
        }
        return $entry;
    }

    /**
     * Fetch related, foreign rows for one record's OneToMany relationships.
     *
     * @param string $table
     * @param string $column_name
     * @param string $column_equals
     * @param string|null $parentField
     * @param $level
     *
     * @return array|bool
     */
    public function loadOneToManyRelationships($table, $column_name, $column_equals, $parentField = null, $level = 0)
    {
        if (!TableSchema::canGroupViewTable($table)) {
            return false;
        }

        // =============================================================================
        // HOTFIX: prevent infinite circle loop
        // =============================================================================
        if ($parentField && $this->hasToManyCallStack($parentField, $table)) {
            return $column_equals;
        }

        if ($parentField !== null) {
            $this->addToManyCallStack($level, $parentField, $table);
        }

        // Run query
        $select = new Select($table);
        $select->where->equalTo($column_name, $column_equals);

        // Only select the fields not on the currently authenticated user group's read field blacklist
        $columns = TableSchema::getAllNonAliasTableColumnNames($table);
        $select->columns($columns);

        $TableGateway = new RelationalTableGateway($table, $this->adapter, $this->acl);
        $rowset = $TableGateway->selectWith($select);
        $results = $rowset->toArray();

        $schemaArray = TableSchema::getSchemaArray($table);
        $results = $this->loadManyToOneRelationships($schemaArray, $results);

        return ['rows' => $results];
    }

    /**
     * Fetch related, foreign rows for a whole rowset's ManyToOne relationships.
     * (Given a table's schema and rows, iterate and replace all of its foreign
     * keys with the contents of these foreign rows.)
     *
     * @param array $schemaArray Table schema array
     * @param array $table_entries Table rows
     * @param string|null $parentField
     * @param int $level
     *
     * @return array Revised table rows, now including foreign rows
     *
     * @throws Exception\RelationshipMetadataException
     */
    public function loadManyToOneRelationships($schemaArray, $table_entries, $parentField = null, $level = 0)
    {
        // Identify the ManyToOne columns
        foreach ($schemaArray->getColumns() as $col) {
            $relationship = $col->getRelationship();
            $isManyToOneColumn = (
                $relationship &&
                $relationship['type'] == 'MANYTOONE'
            );

            if ($isManyToOneColumn) {
                $foreign_id_column = $col['id'];
                if ($relationship) {
                    $foreign_table_name = $col['relationship']['related_table'];
                } else {
                    $message = 'Non single_file Many-to-One relationship lacks `related_table` value.';
                    if (array_key_exists('column_name', $col)) {
                        $message .= ' Column: ' . $col['column_name'];
                    }
                    if (array_key_exists('table_name', $col)) {
                        $message .= ' Table: ' . $col['table_name'];
                    }
                    throw new Exception\RelationshipMetadataException($message);
                }

                // =============================================================================
                // HOTFIX: prevent infinite circle loop
                // =============================================================================
                if ($parentField && $this->hasToManyCallStack($parentField, $foreign_table_name)) {
                    return $table_entries;
                }

                if ($parentField === null) {
                    $parentField = $foreign_id_column;
                }

                $this->addToManyCallStack($level, $parentField, $foreign_table_name);

                // Aggregate all foreign keys for this relationship (for each row, yield the specified foreign id)
                $yield = function ($row) use ($foreign_id_column, $table_entries) {
                    if (array_key_exists($foreign_id_column, $row)) {
                        $value = $row[$foreign_id_column];
                        if (is_array($value)) {
                            // @TODO: Dynamic primary key
                            $value = isset($value['id']) ? $value['id'] : 0;
                        }

                        return $value;
                    }
                };

                $ids = array_map($yield, $table_entries);
                if (empty($ids)) {
                    continue;
                }

                if (!TableSchema::canGroupViewTable($foreign_table_name)) {
                    continue;
                }

                // Fetch the foreign data
                $select = new Select($foreign_table_name);
                $select->where->in('id', $ids);

                $columnNames = TableSchema::getAllNonAliasTableColumnNames($foreign_table_name);
                $select->columns($columnNames);

                $TableGateway = new RelationalTableGateway($foreign_table_name, $this->adapter, $this->acl);
                $rowset = $TableGateway->selectWith($select);
                $results = $rowset->toArray();

                $foreign_table = [];
                foreach ($results as $row) {
                    $row = $this->parseRecord($row, $foreign_table_name);
                    $foreign_table[$row['id']] = $row;
                }

                // Get table column schema
                $schemaArray = TableSchema::getSchemaArray($foreign_table_name);

                // Eager-load related ManyToOne records
                $foreign_table = $this->loadManyToOneRelationships($schemaArray, $foreign_table, $parentField, $level+1);

                // Convert dates into ISO 8601 Format
                $foreign_table = $this->convertDates($foreign_table, $schemaArray->getColumns());

                // Replace foreign keys with foreign rows
                foreach ($table_entries as &$parentRow) {
                    if (array_key_exists($foreign_id_column, $parentRow)) {
                        $foreign_id = (int)$parentRow[$foreign_id_column];
                        $parentRow[$foreign_id_column] = null;
                        // "Did we retrieve the foreign row with this foreign ID in our recent query of the foreign table"?
                        if (array_key_exists($foreign_id, $foreign_table)) {
                            $parentRow[$foreign_id_column] = $foreign_table[$foreign_id];
                        }
                    }
                }
            }
        }

        return $table_entries;
    }

    /**
     * Fetch related, foreign rows for one record's ManyToMany relationships.
     *
     * @param  string $table_name
     * @param  string $foreign_table
     * @param  string $junction_table
     * @param  string $junction_key_left
     * @param  string $junction_key_right
     * @param  string $column_equals
     * @param  string $parentField
     * @param  int    $level
     *
     * @return array                      Foreign rowset
     */
    public function loadManyToManyRelationships($table_name, $foreign_table, $junction_table, $junction_key_left, $junction_key_right, $column_equals, $parentField = null, $level = 0)
    {
        $foreign_table_pk = TableSchema::getTablePrimaryKey($foreign_table);
        $foreign_join_column = $foreign_table . '.' . $foreign_table_pk;
        $junction_join_column = $junction_table . '.' . $junction_key_right;
        $junction_comparison_column = $junction_table . '.' . $junction_key_left;

        // =============================================================================
        // HOTFIX: prevent infinite circle loop
        // =============================================================================
        if ($parentField && $this->hasToManyCallStack($parentField, $foreign_table)) {
            return $column_equals;
        }

        if ($parentField !== null) {
            $this->addToManyCallStack($level, $parentField, $foreign_table);
        }

        $junction_table_pk = TableSchema::getTablePrimaryKey($junction_table);
        $junction_id_column = $junction_table . '.' . $junction_table_pk;

        // Less likely name collision:
        $junction_id_column_alias = 'directus_junction_id_column_518d31856e131';
        $junction_sort_column_alias = 'directus_junction_sort_column_518d318e3f0f5';

        $junctionSelectColumns = [$junction_id_column_alias => $junction_table_pk];

        $sql = new Sql($this->adapter);
        $select = $sql->select();

        // If the Junction Table has a Sort column, do eet.
        // @todo is this the most efficient way?
        // @hint TableSchema#getUniqueColumnName
        $junctionColumns = TableSchema::getAllNonAliasTableColumnNames($junction_table);
        if (in_array('sort', $junctionColumns)) {
            $junctionSelectColumns[$junction_sort_column_alias] = 'sort';
            $select->order($junction_sort_column_alias);
        }

        $select
            ->from($foreign_table)
            ->join($junction_table, $foreign_join_column . '=' . $junction_join_column, $junctionSelectColumns)
            ->where([$junction_comparison_column => $column_equals])
            ->order($junction_id_column . ' ASC');

        // Only select the fields not on the currently authenticated user group's read field blacklist
        $columns = TableSchema::getAllNonAliasTableColumnNames($foreign_table);
        $select->columns($columns);

        $ForeignTable = new RelationalTableGateway($foreign_table, $this->adapter, $this->acl);
        $results = $ForeignTable->selectWith($select);
        $results = $results->toArray();

        $foreign_data = [];
        $columns = TableSchema::getAllNonAliasTableColumns($foreign_table);
        foreach ($results as $row) {
            $row = $recordData = $this->schema->castRecordValues($row, $columns);

            $junction_table_id = (int)$row[$junction_id_column_alias];
            unset($row[$junction_id_column_alias]);

            $entry = [$junction_table_pk => $junction_table_id];
            if (in_array('sort', $junctionColumns)) {
                // @TODO: check why is this a string instead of an integer.
                $entry['sort'] = (int)$row[$junction_sort_column_alias];
                unset($row[$junction_sort_column_alias]);
            }

            $schemaArray = TableSchema::getSchemaArray($foreign_table);
            $aliasColumns = $schemaArray->getAliasColumns();
            $row = $this->loadToManyRelationships($row, $aliasColumns, $parentField, $level+1);

            $entry['data'] = $row;

            $foreign_data[] = $entry;
        }

        return ['rows' => $foreign_data];
    }

    protected function addToManyCallStack($level, $field, $table)
    {
        if (!is_array($this->toManyCallStack)) {
            $this->toManyCallStack = [];
        }

        if (!isset($this->toManyCallStack[$field])) {
            $this->toManyCallStack[$field] = [];
        }

        if (!isset($this->toManyCallStack[$field][$level])) {
            $this->toManyCallStack[$field][$level] = [];
        }

        $this->toManyCallStack[$field][$level][] = $table;
    }

    protected function hasToManyCallStack($field, $table)
    {
        if (!is_array($this->toManyCallStack)) {
            return false;
        }

        if (!array_key_exists($field, $this->toManyCallStack)) {
            return false;
        }

        foreach($this->toManyCallStack[$field] as $level => $tablesCalled) {
            if (in_array($table, $tablesCalled)) {
                return true;
            }
        }

        return false;
    }

    /**
     *
     * HELPER FUNCTIONS
     *
     **/

    /**
     * Does this record representation contain non-primary-key information?
     * Used to determine whether or not to update a foreign record, above and
     * beyond simply assigning it to a parent.
     * @param  array|RowGateway $record
     * @param  string $pkFieldName
     * @return boolean
     */
    public function recordDataContainsNonPrimaryKeyData($record, $pkFieldName = 'id')
    {
        if (is_subclass_of($record, 'Zend\Db\RowGateway\AbstractRowGateway')) {
            $record = $record->toArray();
        } elseif (!is_array($record)) {
            throw new \InvalidArgumentException('$record must an array or a subclass of AbstractRowGateway');
        }
        $keyCount = count($record);
        return array_key_exists($pkFieldName, $record) ? $keyCount > 1 : $keyCount > 0;
    }

    /**
     * Update a collection of records within this table.
     * @param  array $entries Array of records.
     * @return void
     */
    public function updateCollection($entries)
    {
        $entries = is_numeric_array($entries) ? $entries : [$entries];
        foreach ($entries as $entry) {
            $entry = $this->manageRecordUpdate($this->table, $entry);
            $entry->save();
        }
    }

    /**
     * Yield the result-set of a query as record arrays with immediate, foreign
     * relationships populated, ex.
     *
     *   use Zend\Db\Sql\Select;
     *   $select = new Select("instructors");
     *   $InstructorsGateway = new TableGateway($acl, "instructors", $ZendDb);
     *   $instructorsWithRelationships = $InstructorsGateway->selectWithImmediateRelationships($select);
     *
     * @param  Select $select
     * @return array
     */
//    @NOTE: It's not used anywhere.
//    public function selectWithImmediateRelationships(Select $select)
//    {
//        $resultSet = $this->selectWith($select);
//        $entriesWithRelationships = [];
//        foreach ($resultSet as $rowGateway) {
//            $entriesWithRelationships[] = $rowGateway->toArrayWithImmediateRelationships($this);
//        }
//        return $entriesWithRelationships;
//    }

//    /**
//     * Does a table schema array contain an `status` column?
//     * @param  array $schema Table schema array.
//     * @return boolean
//     */
//    public function schemaHasActiveColumn($schema)
//    {
//        foreach ($schema as $col) {
//            if (STATUS_COLUMN_NAME == $col['column_name']) {
//                return true;
//            }
//        }
//        return false;
//    }

    /**
     * Yield total number of rows on a table, irrespective of any status column.
     * @return int
     */
    public function countTotal(PredicateInterface $predicate = null)
    {
        $select = new Select($this->table);
        $select->columns(['total' => new Expression('COUNT(*)')]);
        if (!is_null($predicate)) {
            $select->where($predicate);
        }
        $sql = new Sql($this->adapter, $this->table);
        $statement = $sql->prepareStatementForSqlObject($select);
        $results = $statement->execute();
        $row = $results->current();
        return (int)$row['total'];
    }

    /**
     * Only run on tables which have an status column.
     * @return array
     */
    public function countActive()
    {
        $select = new Select($this->table);
        $select
            ->columns([STATUS_COLUMN_NAME, 'quantity' => new Expression('COUNT(*)')])
            ->group(STATUS_COLUMN_NAME);
        $sql = new Sql($this->adapter, $this->table);
        $statement = $sql->prepareStatementForSqlObject($select);
        $results = $statement->execute();
        $stats = [];
        $statusMap = TableSchema::getStatusMap();
        foreach ($results as $row) {
            if (isset($row[STATUS_COLUMN_NAME])) {
                $statSlug = $statusMap[$row[STATUS_COLUMN_NAME]];
                $stats[$statSlug['name']] = (int)$row['quantity'];
            }
        }
        $vals = [];
        foreach ($statusMap as $value) {
            array_push($vals, $value['name']);
        }
        $possibleValues = array_values($vals);
        $makeMeZero = array_diff($possibleValues, array_keys($stats));
        foreach ($makeMeZero as $unsetActiveColumn) {
            $stats[$unsetActiveColumn] = 0;
        }
        $stats['total'] = array_sum($stats);

        return $stats;
    }

    function countActiveOld($no_active = false)
    {
        $select = new Select($this->table);

        return [
            'active' => 0,
            'inactive' => 0,
            'trash' => 0
        ];

        $result = ['active' => 0];
        if ($no_active) {
            $select->columns(['count' => new \Zend\Db\Sql\Expression('COUNT(*)'), STATUS_COLUMN_NAME => STATUS_COLUMN_NAME]);
        } else {
            $select->columns([
                new \Zend\Db\Sql\Expression('CASE ' . STATUS_COLUMN_NAME . 'WHEN 0 THEN \'trash\'
              WHEN 1 THEN \'active\'
              WHEN 2 THEN \'active\'
            END AS ' . STATUS_COLUMN_NAME), 'count' => new \Zend\Db\Sql\Expression('COUNT(*)')
            ]);
            $select->group(STATUS_COLUMN_NAME);
        }

        $rows = $this->selectWith($select)->toArray();

        print_r($rows);
        die();

        while ($row = $sth->fetch(\PDO::FETCH_ASSOC))
            $result[$row[STATUS_COLUMN_NAME]] = (int)$row['count'];
        $total = 0;
        return $result;
    }
}
