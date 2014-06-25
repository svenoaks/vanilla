<?php
/**
 * @copyright 2009-2014 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPLv2
 */

/**
 * Class QueueModel.
 *
 * @todo Add Unique index to ForeignID.
 */
class QueueModel extends Gdn_Model {

   protected $moderatorUserID;

   /**
    * @var int Limits the number af attributes that can be added to an item.
    */
   protected $maxAttributes = 10;

   /**
    * @var array Possible status options.
    */
   protected $statusEnum = array('unread', 'approved', 'denied');

   /**
    * @var int Time to cache the total counts for.
    */
   protected $countTTL = 30;

   /**
    * @var string Default status for adding new items.
    */
   protected $defaultSaveStatus = 'unread';

   /**
    * @var QueueModel
    */
   public static $Instance;

   /**
    * Get an instance of the model.
    *
    * @return QueueModel
    */
   public static function Instance() {
      if (isset(self::$Instance)) {
         return self::$Instance;
      }
      self::$Instance = new QueueModel();
      return self::$Instance;
   }

   public function __construct() {
      parent::__construct('Queue');
      $this->PrimaryKey = 'QueueID';
   }

   /**
    * {@inheritDoc}
    */
   public function Save($data, $Settings = FALSE) {
      $this->DefineSchema();
      $SchemaFields = $this->Schema->Fields();

      $SaveData = array();
      $Attributes = array();

      // Grab the current attachment.
      if (isset($data['QueueID'])) {
         $PrimaryKeyVal = $data['QueueID'];
         $Insert = FALSE;
         $CurrentItem = $this->SQL->GetWhere('Queue', array('QueueID' => $PrimaryKeyVal))->FirstRow(DATASET_TYPE_ARRAY);
         if ($CurrentItem) {
            $Attributes = @unserialize($CurrentItem['Attributes']);
            if (!$Attributes)
               $Attributes = array();
         }
      } else {
         $PrimaryKeyVal = FALSE;
         $Insert = TRUE;
      }
      // Grab any values that aren't in the db schema and stick them in attributes.
      foreach ($data as $Name => $Value) {
         if ($Name == 'Attributes')
            continue;
         if (isset($SchemaFields[$Name])) {
            $SaveData[$Name] = $Value;
         } elseif ($Value === NULL) {
            unset($Attributes[$Name]);
         } else {
            $Attributes[$Name] = $Value;
         }
      }
      $attributeCount = sizeof($Attributes);
      if ($attributeCount > $this->maxAttributes) {
         throw new Gdn_UserException('Maximum number of attributes exceeded (' . $this->maxAttributes . ').');
      } elseif ($attributeCount > 0) {
         $SaveData['Attributes'] = $Attributes;
      } else {
         $SaveData['Attributes'] = NULL;
      }

      if ($Insert) {
         $this->AddInsertFields($SaveData);
         //add any defaults if missing
         if (!GetValue('Status', $data)) {
            $SaveData['Status'] = $this->defaultSaveStatus;
         }
      } else {
         $this->AddUpdateFields($SaveData);
         if (GetValue('Status', $data)) {
            if (Gdn::Session()->UserID) {
               if (!GetValue('StatusUserID', $SaveData)) {
                  $SaveData['StatusUserID'] = Gdn::Session()->UserID;
               }
               $SaveData['DateStatus'] = Gdn_Format::ToDateTime();
            }
         }
      }
      //format fields
      if (GetValue('Format', $SaveData)) {
         $SaveData['Format'] = strtolower($SaveData['Format']);
      }

      // Validate the form posted values.
      if ($this->Validate($SaveData, $Insert) === TRUE) {
         $Fields = $this->Validation->ValidationFields();

         if ($Insert === FALSE) {
            $Fields = RemoveKeyFromArray($Fields, $this->PrimaryKey); // Don't try to update the primary key
            $this->Update($Fields, array($this->PrimaryKey => $PrimaryKeyVal));
         } else {
            $PrimaryKeyVal = $this->Insert($Fields);
         }
      } else {
         $PrimaryKeyVal = FALSE;
      }
      return $PrimaryKeyVal;
   }


   /**
    * {@inheritDoc}
    */
   public function Get($queue, $page = 'p1', $limit = 30, $where = array(), $orderBy = 'DateInserted', $order = 'desc') {
      list($offset, $limit) = OffsetLimit($page, $limit);

      $order = strtolower($order);
      if ($order != 'asc' || $order != 'asc') {
         $order = 'desc';
      }
      $sql = Gdn::SQL();
      $sql->From('Queue')
         ->Limit($limit, $offset);

      $where['Queue'] = $queue;
      foreach ($where as $key => $value) {
         $sql->Where($key, $value);
      }
      $sql->OrderBy($orderBy, $order);
      $Rows = $sql->Get()->ResultArray();
      foreach ($Rows as &$Row) {
         $Row = $this->CalculateRow($Row);
      }
      return $Rows;

   }

   /**
    * {@inheritDoc}
    */
   public function GetWhere($Where = FALSE, $OrderFields = '', $OrderDirection = 'asc', $Limit = FALSE, $Offset = FALSE) {
      $this->_BeforeGet();

      $results = $this->SQL->GetWhere($this->Name, $Where, $OrderFields, $OrderDirection, $Limit, $Offset);
      foreach($results->ResultArray(DATASET_TYPE_ARRAY) as &$row) {
         $row = $this->CalculateRow($row);
      }
      return $results;
   }

   /**
    * Calculate row.
    *
    * @param Array $Row Row from the database.
    * @return array Modififed Row
    */
   protected function CalculateRow($Row) {
      if (isset($Row['Attributes']) && !empty($Row['Attributes'])) {
         if (is_array($Row['Attributes'])) {
            $Attributes = $Row['Attributes'];
         } else {
            $Attributes = unserialize($Row['Attributes']);
         }
         if (is_array($Attributes)) {
            $Row = array_replace($Row, $Attributes);
         }
      }
      unset($Row['Attributes']);

      return $Row;

   }

   /**
    * Get Queue Counts.
    *
    * @param string $queue Name of the queue.
    * @param int $pageSize Number of results per page.
    * @return array
    */
   public function GetQueueCounts($queue, $pageSize = 30, $where = array()) {

      $where['Queue'] = $queue;
      $cacheKeyFormat = 'Queue:Count:';

      foreach ($where as $key => $value) {
         $cacheKeyFormat .= $key . ':' . $value .':';
      }
      $cacheKeyFormat .= '{queue}';
      $cache = Gdn::Cache();

      $cacheKey = FormatString($cacheKeyFormat, array('queue' => $queue));
      $counts = $cache->Get($cacheKey, array(Gdn_Cache::FEATURE_COMPRESS => TRUE));

      if (!$counts) {
         $sql = Gdn::SQL();
         $sql->Select('Status')
            ->Select('Status', 'count', 'CountStatus')
            ->From('Queue');
         foreach ($where as $key => $value) {
            $sql->Where($key, $value);
         }

         $sql->GroupBy('Status');
         $rows = $sql->Get()->ResultArray();

         $counts = array();
         foreach ($rows as $row) {
            $counts['Status'][$row['Status']] = (int)$row['CountStatus'];
         }
         foreach ($this->statusEnum as $status) {
            //set empty counts to zero
            if (!GetValueR('Status.' . $status, $counts)) {
               $counts['Status'][$status] = 0;
            }
         }
         $total = 0;
         foreach ($counts['Status'] as $statusTotal) {
            $total += $statusTotal;
         }
         $counts['Records'] = $total;
         $counts['PageSize'] = $pageSize;
         $counts['Pages'] = ceil($total/$pageSize);

         $cache->Store($cacheKey, $counts, array(
               Gdn_Cache::FEATURE_EXPIRY  => $this->countTTL,
               Gdn_Cache::FEATURE_COMPRESS => TRUE
         ));
      } else {
         Trace('Using cached queue counts.');
      }


      return $counts;
   }

   /**
    * Get list of possible statuses.
    *
    * @return array
    */
   public function getStatuses() {
      return $this->statusEnum;
   }

   /**
    * {@inheritDoc}
    */
   public function GetID($ID, $datasetType = FALSE, $Options = array()) {
      $Row = parent::GetID($ID, DATASET_TYPE_ARRAY, $Options);
      return $this->CalculateRow($Row);
   }

   /**
    * Check if content being posted needs moderation.
    *
    * @param string $recordType Record type.  ie: Discussion, Comment, Activity
    * @param array $data Record data.
    * @param array $Options Additional options.
    * @return bool
    * @throws Gdn_UserException If error updating queue.
    */
   public static function Premoderate($recordType, $data, $Options = array()) {

      $IsPremoderation = FALSE;
      // Allow for Feed Discussions or any other message posted as system.
      if ($data['InsertUserID'] == C('Garden.SystemUserID')) {
         return false;
      }

      $ApprovalRequired = CheckRestriction('Vanilla.Approval.Require');
      if ($ApprovalRequired && !GetValue('Verified', Gdn::Session()->User)) {
         //@todo There is no interface to manage these yet.
         $IsPremoderation = true;
      }

      $Qm = self::Instance();
      $Qm->EventArguments['RecordType'] = $recordType;
      $Qm->EventArguments['Data'] =& $data;
      $Qm->EventArguments['Options'] =& $Options;
      $Qm->EventArguments['Premoderate'] =& $IsPremoderation;

      $Qm->FireEvent('CheckPremoderation');

      $IsPremoderation = $Qm->EventArguments['Premoderate'];

      if ($IsPremoderation) {

         if (GetValue('ForeignID', $Qm->EventArguments)) {
            $data['ForeignID'] = $Qm->EventArguments['ForeignID'];
         }
         $queueRow = self::convertToQueueRow($recordType, $data);
         // Allow InsertUserID to be overwritten
         if (isset($Qm->EventArguments['InsertUserID']) && !$ApprovalRequired) {
            $queueRow['InsertUserID'] = $Qm->EventArguments['InsertUserID'];
         }

         // Save to Queue

         $Saved = $Qm->Save($queueRow);
         if (!$Saved) {
            throw new Gdn_UserException($Qm->Validation->ResultsText());
         }

      }

      return $IsPremoderation;
   }

   /**
    * Approve content in the queue.
    *
    * @param array $where
    * @return bool
    */
   public function approveWhere($where) {
      $queueItems = $this->getQueueItems($where);

      $errors = array();

      if (sizeof($queueItems) == 0) {
         $error = 'Not found';
         if (GetValue('ForeignID', $where)) {
            $error = 'Foreign ID: ' . $where['ForeignID'];
         }
         $errors['Not Found'] = $error;
         Trace('No item(s) found');
      }
      foreach($queueItems as $item) {
         $valid = $this->approve($item);
         if (!$valid) {
            $errors[$item['QueueID']] = $this->Validation->ResultsText();
         }
         $this->Validation->Results(TRUE);
      }

      foreach ($errors as $id => $value) {
         $this->Validation->AddValidationResult('QueueID', "{$id}: $value");
      }

      return sizeof($errors) == 0;
   }

   /**
    * Approve an item in the queue.
    *
    * @param array|string $queueItem QueueID or array containing queue row.
    * @return bool Item saved.
    * @throws Gdn_UserException Unknown type.
    */
   public function approve($queueItem, $doSave = true) {

      if (!is_array($queueItem)) {
         $queueItem = $this->GetID($queueItem);
      }

      if (!$queueItem) {
         throw new Gdn_UserException("Item not found in queue.", 404);
      }

      if ($queueItem['Status'] != 'unread') {
         Trace('QueueID: ' . $queueItem['QueueID'] . ' already processed.  Skipping.');
         return true;
      }

      if (stristr($queueItem['Queue'], 'testing') !== false) {
         $doSave = false;
      }

      if ($doSave) {
         $ContentType = $queueItem['ForeignType'];
         $Attributes = false;
         switch(strtolower($ContentType)) {
            case 'comment':
               $model = new CommentModel();
               $Attributes = true;
               break;
            case 'discussion':
               $model = new DiscussionModel();
               $Attributes = true;
               break;
            case 'activity':
               $model = new ActivityModel();
               break;
            case 'activitycomment':
               $model = new ActivityModel();
               break;
            default:
               throw new Gdn_UserException('Unknown Type: ' . $ContentType);
         }

         // Am I approving an item that is already in the system?
         if (GetValue('ForeignID', $queueItem)) {
            $parts = explode('-', $queueItem['ForeignID'], 2);
            $validContentParts = array('A', 'C', 'D', 'AC');
            if (in_array($parts[0], $validContentParts)) {
               $exisiting = $model->GetID($parts[1]);
               if ($exisiting) {
                  Trace('Item has already been added');
                  return true;
               }
            }
         }

         $saveData = $this->convertToSaveData($queueItem);
         if ($Attributes) {
            $saveData['Attributes'] = serialize(
               array(
                  'Moderation' =>
                     array(
                        'Approved' => true,
                        'ApprovedUserID' => $this->getModeratorUserID(),
                        'DateApproved' => Gdn_Format::ToDateTime()
                     )
               )
            );
         }
         $saveData['Approved'] = true;

         if (strtolower($queueItem['ForeignType']) == 'activitycomment') {
            $ID = $model->Comment($saveData);
         } else {
            $ID = $model->Save($saveData);
         }
         // Add the validation results from the model to this one.
         $this->Validation->AddValidationResult($model->ValidationResults());
         $valid = count($this->ValidationResults()) == 0;
         if (!$valid) {
            Trace('QueueID: ' . $queueItem['QueueID'] . ' - ' . $this->Validation->ResultsText());
            return false;
         }

         if (method_exists($model, 'Save2')) {
            $model->Save2($ID, true);
         }
      }
      // Update Queue
      $saved = $this->Save(
         array(
            'QueueID' => $queueItem['QueueID'],
            'Status' => 'approved',
            'StatusUserID' => $this->getModeratorUserID(),
            'DateUpdated' => Gdn_Format::ToDateTime(),
            'UpdateUserID' => Gdn::Session()->UserID,
            'ForeignID' => $this->generateForeignID(null, $ID, $ContentType),
            'PreviousForeignID' => $queueItem['ForeignID']
         )
      );
      if (!$saved) {
         $this->Validation->AddValidationResult('Error', 'Error updating queue.');
         return false;
      }

      return true;

   }

   /**
    * Deny items from the queue.
    *
    * @param array $where Key value pair to be removed.
    * @return bool
    */
   public function denyWhere($where) {

      $queueItems = $this->getQueueItems($where);

      $errors = array();

      if (sizeof($queueItems) == 0) {

         $errors[] = 'not found...';
         Trace('No item(s) found');
      }
      foreach($queueItems as $item) {
         $valid = $this->deny($item);
         if (!$valid) {
            $errors[$item['QueueID']] = $this->Validation->ResultsText();
         }
         $this->Validation->Results(TRUE);
      }

      foreach ($errors as $id => $value) {
         $this->Validation->AddValidationResult('QueueID', "Error in id {$id}: $value");
      }

      return sizeof($errors) == 0;

   }

   /**
    * Deny an item from the queue.
    *
    * @param array|string $item QueueID or queue row
    * @return bool true if item was updated
    */
   public function deny($queueItem) {

      if (is_numeric($queueItem)) {
         $queueItem = $this->GetID($queueItem);
      }

      if (!$queueItem) {
         throw new Gdn_UserException("Item not found in queue.", 404);
      }


      if ($queueItem['Status'] != 'unread') {
         Trace('QueueID: ' . $queueItem['QueueID'] . ' already processed.  Skipping.');
         return true;
      }
      $saved = $this->Save(
         array(
            'QueueID' => $queueItem['QueueID'],
            'Status' => 'denied',
            'StatusUserID' => $this->getModeratorUserID()
         )
      );
      if (!$saved) {
         return false;
      }

      return true;

   }

   /**
    * Convert save data to an array that can be saved in the queue.
    *
    * @param string $recordType Record Type. Discussion, Comment, Activity
    * @param array $data Data fields.
    * @return array Row to be saved to the Model.
    * @throws Gdn_UserException On unknown record type.
    */
   protected function convertToQueueRow($recordType, $data) {

      $queueRow = array(
         'Queue' => val('Queue', $data, 'premoderation'),
         'Status' => val('Status', $data, 'unread'),
         'ForeignUserID' => val('InsertUserID', $data, Gdn::Session()->UserID),
         'ForeignIPAddress' => val('InsertIPAddress', $data, Gdn::Request()->IpAddress()),
         'Body' => $data['Body'],
         'Format' => val('Format', $data, C('Garden.InputFormatter')),
         'ForeignID' => val('ForeignID', $data, self::generateForeignID($data))
      );

      switch (strtolower($recordType)) {
         case 'comment':
            $DiscussionModel = new DiscussionModel();
            $Discussion = $DiscussionModel->GetID($data['DiscussionID'], DATASET_TYPE_OBJECT);
            $queueRow['ForeignType'] = 'Comment';
            $queueRow['DiscussionID'] = $data['DiscussionID'];
            $queueRow['CategoryID'] = $Discussion->CategoryID;
            break;
         case 'discussion':
            $queueRow['ForeignType'] = 'Discussion';
            $queueRow['Name'] = $data['Name'];
            if (GetValue('Announce', $data)) {
               $queueRow['Announce'] = $data['Announce'];
            }
            if (!GetValue('CategoryID', $data)) {
               throw new Gdn_UserException('CateogryID is a required field for discussions.');
            }
            $queueRow['CategoryID'] = $data['CategoryID'];
            break;
         case 'activity':
            $queueRow['ForeignType'] = 'Activity';
            $queueRow['Body'] = $data['Story'];
            $queueRow['HeadlineFormat'] = $data['HeadlineFormat'];
            $queueRow['RegardingUserID'] = $data['RegardingUserID'];
            $queueRow['ActivityUserID'] = $data['ActivityUserID'];
            $queueRow['ActivityType'] = 'WallPost';
            $queueRow['NotifyUserID'] = ActivityModel::NOTIFY_PUBLIC;
            break;
         case 'activitycomment':
            $queueRow['ForeignType'] = 'ActivityComment';
            $queueRow['ActivityID'] = $data['ActivityID'];
            break;
         default:
            throw new Gdn_UserException('Unknown Type: ' . $recordType);
      }

      return $queueRow;

   }

   /**
    * Convert a queue row to an array to be saved to the model.
    *
    * @param array $queueRow Queue row data.
    * @return array Of data to be saved.
    * @throws Gdn_UserException on unknown ForeignType.
    */
   protected function convertToSaveData($queueRow) {
      $data = array(
         'Body' => $queueRow['Body'],
         'Format' => $queueRow['Format'],
         'InsertUserID' => $queueRow['ForeignUserID'],
         'InsertIPAddress' => $queueRow['ForeignIPAddress'],
      );
      switch (strtolower($queueRow['ForeignType'])) {
         case 'comment':
            $data['DiscussionID'] = $queueRow['DiscussionID'];
            $data['CategoryID'] = $queueRow['CategoryID'];
            break;
         case 'discussion':
            $data['Name'] = $queueRow['Name'];
            $data['CategoryID'] = $queueRow['CategoryID'];
            break;
         case 'activity':
            $data['HeadlineFormat'] = $queueRow['HeadlineFormat'];

            if (GetValue('RegardingUserID', $queueRow)) {
               //posting on own wall
               $data['RegardingUserID'] = $queueRow['RegardingUserID'];
            }
            $data['ActivityUserID'] = $queueRow['ActivityUserID'];
            $data['NotifyUserID'] = $queueRow['NotifyUserID'];
            $data['ActivityType'] = $queueRow['ActivityType'];
            $data['Story'] = $queueRow['Body'];

            break;
         case 'activitycomment':
            $data['ActivityID'] = $queueRow['ActivityID'];
            break;
         default:
            throw new Gdn_UserException('Unknown Type');
      }

      return $data;
   }

   /**
    * Get items from the queue.
    *
    * @param array $where key value pair for where clause.
    * @return array|null
    */
   protected function getQueueItems($where) {
      $queueItems = $this->GetWhere($where)->ResultArray();
      return $queueItems;
   }

   /**
    * Get moderator userID
    * @return int Moderator user ID.
    * @throws Gdn_UserException if cant determine moderator id
    */
   public function getModeratorUserID() {

      $userID = false;

      if ($this->moderatorUserID) {
         $userID = $this->moderatorUserID;
      }

      if (!$userID) {
         if (Gdn::Session()->CheckPermission('Garden.Moderation.Manage')) {
            return Gdn::Session()->UserID;
         }
      }

      if (!$userID) {
         throw new Gdn_UserException('Error finding Moderator');
      }


      return $userID;
   }

   /**
    * Sets the moderator.
    *
    * @param int $userID Moderator User ID.
    */
   public function setModerator($userID) {
      $this->moderatorUserID = $userID;
   }

   /**
    * Generate Foreign Id's
    *
    * @param null|array $data array of new data.
    * @param null|int $newID New ID for content.
    * @param null $contentType Content Type.
    * @return string ForeignID.
    * @throws Gdn_UserException Unknown content type.
    */
   public static function generateForeignID($data = null, $newID = null, $contentType = null) {

      if ($data == null && $newID != null) {
         switch (strtolower($contentType)) {
            case 'comment':
               return 'C-' . $newID;
               break;
            case 'discussion':
               return 'D-' . $newID;
               break;
            case 'activity':
               return 'A-' . $newID['ActivityID'];
               break;
            case 'activitycomment':
               return 'AC-' . $newID;
               break;
            default:
               throw new Gdn_UserException('Unknown content type');

         }
         return;
      }
      if (GetValue('CommentID', $data)) {
         //comment
         return 'c-' . substr($data['CommentID'], 0, 1);
      }
      if (GetValue('DiscussionID', $data)) {
         //discussion
         return 'd-' . substr($data['DiscussionID'], 0, 1);
      }
      if (GetValue('ActivityID', $data)) {
         //activity comment
         return 'ac-' . substr($data['DiscussionID'], 0, 1);
      }

      return self::generateUUIDFromInts(
         array(self::get32BitRand(), self::get32BitRand(), self::get32BitRand(), self::get32BitRand())
      );

      return 'rand-' . mt_rand(1,500000) . '-' . mt_rand(mt_rand(100,999), 999999);

   }


   /**
    * Given an array of 4 numbers create a UUID
    *
    * @param arrat ints Ints to be converted to UUID.  4 numbers; last 3 default to 0
    * @return string UUID
    *
    * @throws Gdn_UserException
    */
   public static function generateUUIDFromInts($ints) {
      if (sizeof($ints) != 4 && !isset($ints[0])) {
         throw new Gdn_UserException('Invalid arguments passed to ' . __METHOD__);
      }
      if (!isset($ints[1])) {
         $ints[1] = 0;
      }
      if (!isset($ints[2])) {
         $ints[2] = 0;
      }
      if (!isset($ints[3])) {
         $ints[3] = 0;
      }
      $result = static::hexInt($ints[0]) . static::hexInt($ints[1], true) . '-'
         . static::hexInt($ints[2], true).static::hexInt($ints[3]);
      return $result;
   }

   /**
    * @param string $UUID Universal Unique Identifier.
    * @return array Containing the 4 numbers used to generate generateUUIDFromInts
    */
   public static function getIntsFromUUID($UUID) {
      $parts = str_split(str_replace('-', '', $UUID), 8);
      $parts = array_map('hexdec', $parts);
      return $parts;
   }


   /**
    * Get a random 32bit integer.  0x80000000 to 0xFFFFFFFF were not being tested with rand().
    *
    * @return int randon 32bi integer.
    */
   public static function get32BitRand() {
      return mt_rand(0, 0xFFFF) | (mt_rand(0, 0xFFFF) << 16);
   }

   /**
    * Used to help generate UUIDs; pad and convert from decimal to hexadecimal; and split if neeeded
    *
    * @param $int Integer to be converted
    * @param bool $split Split result into parts.
    * @return string
    */
   public static function hexInt($int, $split = false) {
      $result = substr(str_pad(dechex($int), 8, '0', STR_PAD_LEFT), 0, 8);
      if ($split) {
         $result = implode('-', str_split($result, 4));
      }
      return $result;
   }


//   public function validate($FormPostValues, $Insert = FALSE) {
//
//      if (!$Insert) {
//         if (GetValue('Status', $FormPostValues)) {
//            //status update.  Check DateStatus + StatusUserID
//            if (!GetValue('DateStatus', $FormPostValues) && !GetValue('StatusUserID', $FormPostValues)) {
//               $this->Validation->AddValidationResult('Status', 'You must update required fields.' .
//                  ' StatusUserID and DateStatus must be updated with status.');
//            }
//         }
//      }
//      return parent::Validate($FormPostValues, $Insert);
//
//   }

}