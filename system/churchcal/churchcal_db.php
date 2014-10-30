<?php

include_once(CHURCHCORE."/churchcore_db.php");

/**
 * TODO: i would rename category to calendar for it beeing different calendars in churchcal, not categories
 */

/**
 * meeting request
 * TODO: use lang dependent template for email
 * @param unknown $cal_id
 * @param unknown $params
 */
function churchcal_handleMeetingRequest($cal_id, $params) {
  global $base_url, $user;

  $i = new CTInterface();
  $i->setParam("cal_id");
  $i->setParam("person_id");
  $i->setParam("mailsend_date");
  $i->setParam("event_date");
  $dt = new DateTime();
  foreach ($params["meetingRequest"] as $id=>$param) {
    $param["mailsend_date"]=$dt->format('Y-m-d H:i:s');
    $param["person_id"]=$id;
    $param["event_date"]=$params["startdate"];
    $param["cal_id"]=$cal_id;

    $db=db_query('SELECT mr.*, c.modified_pid
                  FROM {cc_meetingrequest} mr, {cc_cal} c
                  WHERE c.id=mr.cal_id and mr.person_id=:person_id and mr.cal_id=:cal_id',
                  array(":person_id"=>$param["person_id"], ":cal_id"=>$param["cal_id"]))
                  ->fetch();

    if (!$db) {
      db_insert("cc_meetingrequest")
        ->fields($i->getDBInsertArrayFromParams($param))
        ->execute(false);

      $txt = "<h3>" . t('hello') . "[Spitzname]!</h3><p>";

      $txt .= "<P>Du wurdest auf ".getConf('site_name');
      $txt .= ' von <i>'.$user->vorname." ".$user->name."</i>";
      $txt .= " f&uuml;r einen Termin angefragt. ";

      // if person was not yet invited to churchtools send invitation
      $db=db_query("SELECT IF (password IS NULL AND loginstr IS NULL AND lastlogin IS NULL,1,0) as invite
                    FROM {cdb_person}
                    WHERE id=:id",
                    array(":id"=>$id))
                    ->fetch();
      if ($db) {
        if ($db->invite == 1) {
          include_once(CHURCHDB.'/churchdb_ajax.php');
          churchdb_invitePersonToSystem($id);
          $txt.="Da Du noch nicht keinen Zugriff auf das System hast, bekommst Du noch eine separate E-Mail, mit der Du Dich dann anmelden kannst!";
        }

        $txt.="<p>Zum Zu- oder Absagen bitte hier klicken:";
        $loginstr=churchcore_createOnTimeLoginKey($id);
        $txt.='<p><a href="'.$base_url.'?q=home&id='.$id.'&loginstr='.$loginstr.'" class="btn btn-primary">%sitename aufrufen</a>';
        churchcore_sendEMailToPersonids($id, "[".getConf('site_name')."] " . t('new.meeting.request'), $txt, null, true);
      }
    }
    else {
/*      db_update("cc_meetingrequest")
        ->fields($i->getDBInsertArrayFromParams($param))
        ->condition("person_id", $param["person_id"], "=")
        ->condition("cal_id", $param["cal_id"], "=")
        ->execute(false);
      churchcore_sendEMailToPersonids($id, "[".getConf('site_name')."] Anpassung in einer Termin-Anfrage", "anpassung", null, true);*/
    }
  }
}

/**
 *
 * @param array $params
 */
function churchcal_updateMeetingRequest($params) {
  global $user;
  $i = new CTInterface();
  $i->setParam("cal_id");
  $i->setParam("person_id");
  $i->setParam("mailsend_date");
  $i->setParam("event_date");
  $i->setParam("zugesagt_yn", false);
  $i->setParam("response_date");

  $dt = new DateTime();

  if (!$params["zugesagt_yn"]) unset($params["zugesagt_yn"]);

  db_update("cc_meetingrequest")
    ->fields($i->getDBInsertArrayFromParams($params))
    ->condition("id", $params["id"], "=")
    ->execute(false);
}

/**
 *
 * @return
 */
function churchcal_getMyMeetingRequest() {
  global $user; // why 2x event_date?
  $db=db_query("SELECT mr.*, mr.event_date, c.startdate, c.enddate, c.bezeichnung,
                  CONCAT(p.vorname,' ',p.name) AS modified_name, p.id modified_pid
                FROM {cc_meetingrequest} mr, {cc_cal} c, {cdb_person} p
                WHERE mr.person_id=:person_id AND c.modified_pid=p.id
                  AND DATEDIFF(mr.event_date, NOW())>0 AND mr.cal_id=c.id",
                array(":person_id"=>$user->id));
  $res=array();
  foreach ($db as $d) $res[$d->id]=$d; //TESTEN!!

  return $res;
}


/**
 * Creates calender event and call other Modules
 *
 * @param array $params
 * @throws CTNoPermission
 * @return int; id of created event
 */
function churchcal_createEvent($params, $callCS=true) {
  global $user;
  // if source is another module rights are already checked
  if (!churchcal_isAllowedToEditCategory($params["category_id"])) {
    throw new CTNoPermission(t('no.create.right.for.cal.id.x', $params["category_id"]), "churchcal");
  }
  $i = new CTInterface();
  $i->setParam("startdate");
  $i->setParam("enddate");
  $i->setParam("bezeichnung");
  $i->setParam("category_id");
  $i->setParam("repeat_id");
  $i->setParam("repeat_until", false);
  $i->setParam("repeat_frequence", false);
  $i->setParam("repeat_option_id", false);
  $i->setParam("intern_yn");
  $i->setParam("notizen");
  $i->setParam("link");
  $i->setParam("ort");
  $i->addModifiedParams();

  $params["id"] = db_insert("cc_cal")
    ->fields($i->getDBInsertArrayFromParams($params))
    ->execute(false);

  // Add exceptions
  if (isset($params["exceptions"])) foreach ($params["exceptions"] as $exception) {
    $res = churchcal_addException(array (
        "cal_id" => $params["id"],
        "except_date_start" => $exception["except_date_start"],
        "except_date_end" => $exception["except_date_end"],
    ));
  }

  // Add additions
  if (isset($params["additions"])) foreach ($params["additions"] as $addition) {
    $res = churchcal_addAddition(array (
        "cal_id" => $params["id"],
        "add_date" => $addition["add_date"],
        "with_repeat_yn" => $addition["with_repeat_yn"],
    ));
  }

  // Meeting request
  if (isset($params["meetingRequest"])) churchcal_handleMeetingRequest($params["id"], $params);

  // Call other modules
  $newBookingIds = null;
  if (churchcore_isModuleActivated("churchresource")) {
    include_once (CHURCHRESOURCE . '/churchresource_db.php');
    $newBookingIds = churchresource_operateResourcesFromChurchCal($params);
  }
  $newCSIds = null;
  if ($callCS) {
    if (churchcore_isModuleActivated("churchservice")) {
      include_once (CHURCHSERVICE . '/churchservice_db.php');
      $newCSIds=churchservice_operateEventFromChurchCal($params);
    }
  }

  // Do Notification (abo)
  $data = db_query("select * from {cc_calcategory} where id=:id", array(":id"=>$params["category_id"]))->fetch();
  $txt = $user->vorname . " " . $user->name . " hat im Kalender ";
  if ($data!=false)
    $txt .= $data->bezeichnung;
  else
    $txt .= $params["category_id"];
  $txt .= " einen neuen Termin angelegt:<br>";
  $txt .= churchcore_CCEventData2String($params);
  ct_notify("category", $params["category_id"], $txt);

  return array("id"=>$params["id"], "cseventIds"=>$newCSIds, "bookingIds"=>$newBookingIds);
}

/**
 *
 * @param int $categoryId
 * @return boolean
 */
function churchcal_isAllowedToEditCategory($categoryId) {
  if (!$categoryId) return false;

  $arr = churchcal_getAuthForAjax();
  if (!isset($arr["edit category"])) return false;
  if (isset($arr["edit category"][$categoryId])) return true;
  return false;
}

/**
 * Store all Exception and Addition changes for communication to other modules
 * @param array $params
 * @param string $sourc; controls cooperation between modules if event comes from another modulee
 */
function churchcal_updateEvent($params, $callCS = true) {
  global $user;
  $changes = array ();

  // can user edit current event category?
  if (!churchcal_isAllowedToEditCategory($params["category_id"])) throw new CTNoPermission("AllowedToEditCategory[" .
       $params["category_id"] . "] (newCat)", "churchcal");
  $old_cal = db_query("SELECT category_id, startdate
                       FROM {cc_cal}
                       WHERE id=:id",
                       array (":id" => $params["id"]))
                       ->fetch();
  // can user edit old event category?
  if (!churchcal_isAllowedToEditCategory($old_cal->category_id)) {
    throw new CTNoPermission("AllowedToEditCategory[" . $old_cal->category_id . "] (oldCat)", "churchcal");
  }

  if (isset($params["notizen"])) $params["notizen"] = str_replace('\"', '"', $params["notizen"]);

  $i = new CTInterface();
  $i->setParam("startdate", false);
  $i->setParam("enddate", false);
  $i->setParam("bezeichnung", false);
  $i->setParam("category_id", false);
  $i->setParam("ort", false);
  $i->setParam("notizen", false);
  $i->setParam("intern_yn", false);
  $i->setParam("link", false);
  $i->setParam("repeat_id", false);
  $i->setParam("repeat_until", false);
  $i->setParam("repeat_frequence", false);
  $i->setParam("repeat_option_id", false);

  $f = $i->getDBInsertArrayFromParams($params);
  if (count($f)) db_update("cc_cal")
                    ->fields($f)
                    ->condition("id", $params["id"], "=")
                    ->execute();


  // get all exceptions
  $exc = churchcore_getTableData("cc_cal_except", null, "cal_id=" . $params["id"]);
  // look which are already in DB
  if (isset($params["exceptions"])) foreach ($params["exceptions"] as $exception) {
    if ($exception["id"] > 0) {
      $exc[$exception["id"]]->vorhanden = true;
    }
    else {
      $add_exc = array ("cal_id" => $params["id"],
                        "except_date_start" => $exception["except_date_start"],
                        "except_date_end" => $exception["except_date_end"],
      );
      churchcal_addException($add_exc);
      $changes["add_exception"][] = $add_exc;
    }
  }
  // delete removed exceptions from DB
  if ($exc) {
    foreach ($exc as $e) if (!isset($e->vorhanden)) {
      $del_exc = array ("id" => $e->id,
                        "except_date_start" => $e->except_date_start,
                        "except_date_end" => $e->except_date_end,
      );
      churchcal_delException($del_exc);
      $changes["del_exception"][] = $del_exc;
    }
  }

  // get all additions
  $add = churchcore_getTableData("cc_cal_add", null, "cal_id=" . $params["id"]);
  // look which are already in DB.
  if (isset($params["additions"])) foreach ($params["additions"] as $addition) {
    if ($addition["id"] > 0) $add[$addition["id"]]->vorhanden = true;
    else {
      $add_add = array ("cal_id" => $params["id"],
                        "add_date" => $addition["add_date"],
                        "with_repeat_yn" => $addition["with_repeat_yn"],
      );
      churchcal_addAddition($add_add);
      $changes["add_addition"][] = $add_add;
    }
  }
  // delete from DB which are deleted.
  if ($add) foreach ($add as $a) {
    if (!isset($a->vorhanden)) {
      $del_add = array ("id" => $a->id, "add_date" => $a->add_date);
      churchcal_delAddition($del_add);
      $changes["del_addition"][] = $del_add;
    }
  }

  // meeting request
  if (isset($params["meetingRequest"])) churchcal_handleMeetingRequest($params["id"], $params);

  // Call other modules
  $newBookingIds = null;
  if (churchcore_isModuleActivated("churchresource")) {
    include_once (CHURCHRESOURCE . '/churchresource_db.php');
    $newBookingIds = churchresource_operateResourcesFromChurchCal($params);
  }
  $newCSIds = null;
  if ($callCS) {
    if (churchcore_isModuleActivated("churchservice")) {
      include_once (CHURCHSERVICE . '/churchservice_db.php');
      $newCSIds=churchservice_operateEventFromChurchCal($params);
    }
  }

  // Notification
  $data = db_query("select * from {cc_calcategory} where id=:id", array(":id"=>$params["category_id"]))->fetch();
  $txt = $user->vorname . " " . $user->name . " hat einen Termin angepasst im Kalender ";
  if ($data!=false)
    $txt .= $data->bezeichnung;
  else
    $txt .= $params["category_id"];
  $txt .= " auf:<br>";
  $txt .= churchcore_CCEventData2String($params);
  ct_notify("category", $params["category_id"], $txt);

  return array("cseventIds" => $newCSIds, "bookingIds" => $newBookingIds);
}

function churchcal_saveSplittedEvent($params) {
  $res = new stdClass();
  // if no splitDate given it is a new event without impact
  if (!isset($params["splitDate"])) throw new CTException("saveSplittedEvent: splitDate not given!");
  $splitDate = new DateTime($params["splitDate"]);
  $untilEnd_yn = $params["untilEnd_yn"];
  $pastEventId = $params["pastEvent"]["id"];

  // Copy all entries from past to new event, cause CR und CS does not have all infos and doesn't need it :)
  $pastEventDB = db_query("SELECT bezeichnung, ort, notizen, link, intern_yn, category_id  "
                         ."FROM {cc_cal} WHERE id = :id ", array (":id" => $pastEventId)) -> fetch();
  if ($pastEventDB!=false) foreach ($pastEventDB as $key => $entry) {
    if (empty($params["pastEvent"][$key])) $params["pastEvent"][$key] = $entry;
    if (empty($params["newEvent"][$key])) $params["newEvent"][$key] = $entry;
  }
  // Save new Event without impact on CS and CR ...
  $res = churchcal_createEvent($params["newEvent"], false);

  // ... and now bind related bookings and services to the new event
  $newEventId = $res["id"];
  $params["newEvent"]["id"] = $newEventId;

  if (churchcore_isModuleActivated("churchservice")) {
    include_once ('./' . CHURCHSERVICE . '/churchservice_db.php');
    churchservice_rebindServicesToNewEvent($pastEventId, $newEventId, $splitDate, $untilEnd_yn);
    $params["newEvent"]["cal_id"] = $newEventId;
    $startdate = new Datetime($params["newEvent"]["startdate"]);
    if ($splitDate->format("Y-m-d H:i") != $startdate->format("Y-m-d H:i")) {
      $params["newEvent"]["old_startdate"] = $splitDate;
    }
    churchservice_operateEventFromChurchCal($params["newEvent"]);
  }

  // Save old Event
  churchcal_updateEvent($params["pastEvent"], false);

  return array("id" => $newEventId, "bookingIds" => $res["bookingIds"]);
}

/**
 * Checking the depending changes in other modules
 * @param [type] $params with newEvent, originEvent, Only for series: splitDate, untilEnd_yn
 */
function churchcal_getEventChangeImpact($params) {
  $res = new stdClass();

  // Get ChurchService impact
  if (churchcore_isModuleActivated("churchservice")) {
    // Get dependencies from CS
    include_once ('./' . CHURCHSERVICE . '/churchservice_db.php');
    //$res->services = churchservice_getActiveServicesInEvent(
      //                 $params["originEvent"]["id"], $splitDate, $params["untilEnd_yn"]
        //             );
    $res->services = churchservice_getEventChangeImpact($params["newEvent"]["csevents"]);
    if (count($res->services) > 0) $res->warning = true;
  }

  // Get ChurchResource impact
  if (churchcore_isModuleActivated("churchresource")) {
    // Get dependencies from CR
    include_once ('./' . CHURCHRESOURCE . '/churchresource_db.php');
    $res->bookings = churchresource_getActiveBookingsInEvent(
                       $params["originEvent"]["id"], $splitDate, $params["untilEnd_yn"]
                     );
    if (count($res->bookings) > 0) $res->warning = true;
  }


  return $res;
}

/**
 * get user auth
 * @return array auth
 */
function churchcal_getAuthForAjax() {
  global $user;

  $ret = array ();
  if ($user && isset($_SESSION["user"]->auth["churchcal"])) {
    $ret = $_SESSION["user"]->auth["churchcal"];

    // if user has edit right he also get view right
    if (isset($ret["edit category"])) {
      foreach ($ret["edit category"] as $key => $edit)      $ret["view category"][$key] = $edit;
    }
  }
  if (user_access("view", "churchservice"))                 $ret["view churchservice"] = true;
  if (user_access("view", "churchdb")) {
                                                            $ret["view churchdb"] = true;
    if (user_access("view alldata", "churchdb"))            $ret["view alldata"] = true;
  }
  if (user_access("view", "churchresource"))                $ret["view churchresource"] = true;
  if (user_access("create bookings", "churchresource"))     $ret["create bookings"] = true;
  if (user_access("administer bookings", "churchresource")) $ret["administer bookings"] = true;

  return $ret;
}

/**
 * TODO: remove private cals?
 *
 * @param string $withPrivat
 * @param string $onlyIds
 * @return multitype:NULL Ambigous <object, boolean, db_accessor>
 */
function churchcal_getAllowedCategories($withPrivat = true, $onlyIds = false) {
  global $user;
  include_once (CHURCHDB . "/churchdb_db.php");

  $db = db_query("SELECT * FROM {cc_calcategory}");

  $res = array();
  $auth = churchcal_getAuthForAjax();

  foreach ($db as $category) {
    if (($category->privat_yn == 0) || ($withPrivat)) {
      // Zugriff, weil ich View-Rechte auf die Kategorie habe
      if ((isset($auth["view category"]) && isset($auth["view category"][$category->id]))
       || (isset($auth["edit category"]) && isset($auth["edit category"][$category->id]))) {
        $res[$category->id] = ($onlyIds) ? $category->id : $res[$category->id] = $category;
      }
    }
  }
  return $res;
}

/**
 *
 * @param unknown $params
 * @param string $withintern
 * @return multitype:|Ambigous <multitype:multitype: , NULL, object, boolean, db_accessor>
 */
function churchcal_getCalPerCategory($params, $withintern = null) {
  global $user;

  if ($withintern==null) {
    if ($user==null || $user->id==-1) $withintern=false;
    else $withintern=true;
  }

  $data = array ();
  $from = getConf("churchcal_entries_last_days", 180);

  $res = db_query("
      SELECT cal.*, CONCAT(p.vorname, ' ',p.name) AS modified_name, e.id AS event_id, e.startdate AS event_startdate,
        e.created_by_template_id AS event_template_id, b.id AS booking_id, b.startdate AS booking_startdate, b.enddate AS booking_enddate,
        b.resource_id AS booking_resource_id, b.status_id AS booking_status_id
      FROM {cc_cal} cal
      LEFT JOIN {cs_event} e ON (cal.id=e.cc_cal_id)
      LEFT JOIN {cr_booking} b ON (cal.id=b.cc_cal_id)
      LEFT JOIN {cdb_person} p ON (cal.modified_pid=p.id)
      WHERE cal.category_id IN (". db_implode($params["category_ids"]).") ".(!$withintern ? " and intern_yn=0" : "")
        ." AND(     ( DATEDIFF  ( cal.enddate , NOW() ) > - $from )
                 OR ( cal.repeat_id>0 AND DATEDIFF (cal.repeat_until, NOW() ) > - $from) )
      ");

  $data = null;

  // collect bookings/events if more then one per calendar entry
  foreach ($res as $arr) {
    if (isset($data[$arr->id])) $elem = $data[$arr->id];
    else {
      $elem = $arr;
      $req = churchcore_getTableData("cc_meetingrequest", null, "cal_id=" . $arr->id);
      if ($req) {
        $elem->meetingRequest = array();
        foreach ($req as $r) $elem->meetingRequest[$r->person_id] = $r;
      }
    }
    if ($arr->booking_id) {
      $elem->bookings[$arr->booking_id] = array (
          "id" => $arr->booking_id,
          "minpre" => (strtotime($arr->startdate) - strtotime($arr->booking_startdate)) / 60,
          "minpost" => (strtotime($arr->booking_enddate) - strtotime($arr->enddate)) / 60,
          "resource_id" => $arr->booking_resource_id,
          "status_id" => $arr->booking_status_id,
      );
    }
    if ($arr->event_id) {
      // Get additional Service text infos, like "Preaching with [Vorname]"
      $service_texts = array ();
      $es = db_query("
        SELECT es.name, s.id, es.cdb_person_id, s.cal_text_template from {cs_service} s, {cs_eventservice} es
        WHERE es.event_id=:event_id AND es.service_id=s.id and es.valid_yn=1 and es.zugesagt_yn=1
          AND s.cal_text_template IS NOT NULL AND s.cal_text_template!=''",
        array (":event_id" => $arr->event_id));

      foreach ($es as $e) if ($e) {
        if (strpos($e->cal_text_template, "[") === false) {
          $txt = $e->cal_text_template;
        }
        if ($e->cdb_person_id) {
          include_once (CHURCHDB . "/churchdb_db.php");
          $p = db_query("SELECT * FROM {cdb_person}
                         WHERE id=:id",
                         array (":id" => $e->cdb_person_id))
                         ->fetch();
          if ($p) {
            $txt = churchcore_personalizeTemplate($e->cal_text_template, $p);
          }
        }
        if (!in_array($txt, $service_texts)) { //TODO: maybe use in_array() instead
          $service_texts[] = $txt;
        }
      }
      // Save event info
      $elem->csevents[$arr->event_id] = array (
          "id" => $arr->event_id,
          "startdate" => $arr->event_startdate,
          "service_texts" => $service_texts,
          "eventTemplate" => $arr->event_template_id
      );
    }
    $data[$arr->id] = $elem;
  }

  $exceptions = churchcore_getTableData("cc_cal_except");
  if ($exceptions) foreach ($exceptions as $e) {
    // there may be exceptions without event
    if (isset($data[$e->cal_id])) {
      if (!isset($data[$e->cal_id]->exceptions)) $data[$e->cal_id]->exceptions = array();
      $data[$e->cal_id]->exceptions[$e->id] = new stdClass();
      $data[$e->cal_id]->exceptions[$e->id]->id = $e->id;
      $data[$e->cal_id]->exceptions[$e->id]->except_date_start = $e->except_date_start;
      $data[$e->cal_id]->exceptions[$e->id]->except_date_end = $e->except_date_end;
    }
  }
  $additions = churchcore_getTableData("cc_cal_add");
  if ($additions) foreach ($additions as $e) {
    // there may be additions without event
    if (isset($data[$e->cal_id])) {
      if (!isset($data[$e->cal_id]->additions)) $data[$e->cal_id]->additions = array();
      $data[$e->cal_id]->additions[$e->id] = new stdClass();
      $data[$e->cal_id]->additions[$e->id]->id = $e->id;
      $data[$e->cal_id]->additions[$e->id]->add_date = $e->add_date;
      $data[$e->cal_id]->additions[$e->id]->with_repeat_yn = $e->with_repeat_yn;
    }
  }

  $ret = array ();
  foreach ($params["category_ids"] as $cat) {
    $ret[$cat] = array ();
    foreach ($data as $d) {
      if ($d->category_id == $cat) $ret[$cat][$d->id] = $d;
    }
  }

  return $ret;
}
