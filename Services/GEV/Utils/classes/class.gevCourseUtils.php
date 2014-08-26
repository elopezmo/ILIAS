<?php

/* Copyright (c) 1998-2014 ILIAS open source, Extended GPL, see docs/LICENSE */#

/**
* Course seraching GUI for Generali
*
* @author	Richard Klees <richard.klees@concepts-and-training.de>
* @version	$Id$
*/

require_once("Services/GEV/Utils/classes/class.gevSettings.php");
require_once("Services/AdvancedMetaData/classes/class.ilAdvancedMDFieldDefinition.php");
require_once("Services/Calendar/classes/class.ilDate.php");
require_once("Services/Calendar/classes/class.ilDateTime.php");
require_once("Services/GEV/Utils/classes/class.gevAMDUtils.php");
require_once("Services/GEV/Utils/classes/class.gevObjectUtils.php");
require_once("Services/Calendar/classes/class.ilDatePresentation.php");
require_once("Modules/Course/classes/class.ilObjCourse.php");

class gevCourseUtils {
	static $instances = array();
	
	protected function __construct($a_crs_id) {
		global $ilDB, $ilLog, $lng;
		
		$this->db = &$ilDB;
		$this->log = &$ilLog;
		$this->lng = &$lng;
		
		$this->lng->loadLanguageModule("crs");
		
		$this->crs_id = $a_crs_id;
		$this->crs_obj = null;
		$this->crs_booking_permissions = null;
		$this->crs_participations = null;
		$this->gev_settings = gevSettings::getInstance();
		$this->amd = gevAMDUtils::getInstance();
		$this->local_roles = null;
		
		$this->membership = null;
		$this->main_trainer = null;
		$this->main_admin = null;
	}
	
	static public function getInstance($a_crs_id) {
		if (!is_int($a_crs_id) && !is_numeric($a_crs_id)) {
			throw new Exception("gevCourseUtils::getInstance: no integer crs_id given.");
		}
		
		if (array_key_exists($a_crs_id, self::$instances)) {
			return self::$instances[$a_crs_id];
		}

		self::$instances[$a_crs_id] = new gevCourseUtils($a_crs_id);
		return self::$instances[$a_crs_id];
	}
	
	static public function getInstanceByObj(ilObjCourse $a_crs) {
		$inst = gevCourseUtils::getInstance($a_crs->getId());
		$inst->crs_obj = $a_crs;
		$inst->crs_obj->setRefId(gevObjectUtils::getRefId($inst->crs_id));
		return $inst;
	}
	
	static public function getInstanceByObjOrId($a_course) {
		if (is_int($a_course) || is_numeric($a_course)) {
			return self::getInstance((int)$a_course);
		}
		else {
			return self::getInstanceByObj($a_course);
		}
	}

	static public  function getLinkTo($a_crs_id) {
		return "goto.php?target=crs_".gevObjectUtils::getRefId($a_crs_id)	;
	}
	
	static public function getCancelLinkTo($a_crs_id, $a_usr_id) {
		global $ilCtrl;
		$ilCtrl->setParameterByClass("gevMyCoursesGUI", "crs_id", $a_crs_id);
		$ilCtrl->setParameterByClass("gevMyCoursesGUI", "usr_id", $a_user_id);
		$link = $ilCtrl->getLinkTargetByClass("gevMyCoursesGUI", "cancelBooking");
		$ilCtrl->clearParametersByClass("gevMyCoursesGUI");
		return $link;
	}
	
	static public function getBookingLinkTo($a_crs_id, $a_usr_id) {
		global $ilCtrl;
		$ilCtrl->setParameterByClass("gevBookingGUI", "user_id", $a_usr_id);
		$ilCtrl->setParameterByClass("gevBookingGUI", "crs_id", $a_crs_id);
		$lnk = $ilCtrl->getLinkTargetByClass("gevBookingGUI", "book");
		$ilCtrl->clearParametersByClass("gevBookingGUI");
		return $lnk;
	}

	static public function mkDeadlineDate($a_start_date, $a_deadline) {
		if (!$a_start_date || !$a_deadline) {
			return null;
		}
		
		$date = new ilDate($a_start_date->get(IL_CAL_DATE), IL_CAL_DATE);
		// ILIAS idiosyncracy. Why does it destroy the date, when i increment by 0?
		if ($a_deadline == 0) {
			return $date;
		}
		$date->increment(IL_CAL_DAY, $a_deadline * -1);
		return $date;
	}
	
	// CUSTOM ID LOGIC
	
	/**
	 * Every course template should have a custom id. This id is used to create
	 * an id for a concrete course. The new custom ids have the form $year-$tmplt-$num
	 * where $year is the current year, $tmplt is the custom id from the course template
	 * and $num is a consecutive number of the courses with the same $year-$tmpl part of
	 * the custom id.
	 **/
	static public function createNewCustomId($a_tmplt) {
		global $ilDB;
		$gev_settings = gevSettings::getInstance();
		
		$year = date("Y");
		$head = $year."-".$a_tmplt."-";
		
		$field_id = $gev_settings->getAMDFieldId(gevSettings::CRS_AMD_CUSTOM_ID);
		
		// This query requires knowledge from CourseAMD-Plugin!!
		$res = $ilDB->query("SELECT MAX(value) as m".
							" FROM adv_md_values_text".
							" WHERE value LIKE ".$ilDB->quote($head."%", "text").
							"   AND field_id = ".$ilDB->quote($field_id, "integer")
							);

		if ($val = $ilDB->fetchAssoc($res)) {
			$temp = explode("-", $val["m"]);
			$num = intval($temp[2]) + 1;
		}
		else {
			$num = 1;
		}
		$num = sprintf("%03d", $num);
		return $head.$num;
	}
	
	static public function extractCustomId($a_custom_id) {
		$temp = explode("-", $a_custom_id);
		return $temp[1];
	}
	
	/**
	 * Every course template has an unique id (e.g. SL10001) from a block of
	 * ids (e.g. SL10000). This function creates a fresh unique id from a 
	 * block of ids.
	 *
	 * WARNING: The assumption here is, that an block (= $a_tmplt) is always
	 * constructed from two alphanums, 2 digits that should be used to identify
	 * the block and 3 zeros that will be filled with subsequent numbers for
	 * the template.
	 **/
	static public function createNewTemplateCustomId($a_tmplt) {
		global $ilDB, $ilLog;
		$gev_settings = gevSettings::getInstance();
		$field_id = $gev_settings->getAMDFieldId(gevSettings::CRS_AMD_CUSTOM_ID);
		
		$pre = substr($a_tmplt, 0, 4);
		
		$res = $ilDB->query("SELECT MAX(value) as m "
						   ."  FROM adv_md_values_text "
						   ." WHERE value LIKE ".$ilDB->quote($pre."%", "text")
						   ."   AND field_id = ".$ilDB->quote($field_id, "integer")
						   );
		
		if ($val = $ilDB->fetchAssoc($res)) {
			$num = intval(substr($val["m"], 4)) + 1;
		}
		else {
			$num = 1;
		}
		$num = sprintf("%03d", $num);
		return $pre.$num;
	}
	

	/**
	 * Get custom roles assigned to a course.
	 */
	static public function getCustomRoles($crs_id) {
		global $rbacreview;
		
		$all_roles = $rbacreview->getParentRoleIds(gevObjectUtils::getRefId($crs_id));
		$custom_roles = array();
		
		foreach($all_roles as $role) {
			if ($role["role_type"] == "global"
			||  $role["role_type"] == "linked"
			|| substr($role["title"], 0, 6) == "il_crs") {
				continue;
			}
			
			$custom_roles[] = $role;
		}
		
		return $custom_roles;
	}
	
	
	public function getCourse() {
		require_once("Modules/Course/classes/class.ilObjCourse.php");
		
		if ($this->crs_obj === null) {
			$this->crs_obj = new ilObjCourse($this->crs_id, false);
			$this->crs_obj->setRefId(gevObjectUtils::getRefId($this->crs_id));
		}
		
		return $this->crs_obj;
	}
	
	public function getBookings() {
		require_once("Services/CourseBooking/classes/class.ilCourseBookings.php");
		return ilCourseBookings::getInstance($this->getCourse());
	}
	
	public function getBookingPermissions($a_user_id) {
		require_once("Services/CourseBooking/classes/class.ilCourseBookingPermissions.php");
		return ilCourseBookingPermissions::getInstance($this->getCourse(), $a_user_id);
	}
	
	public function getBookingHelper() {
		require_once("Services/CourseBooking/classes/class.ilCourseBookingHelper.php");
		return ilCourseBookingHelper::getInstance($this->getCourse());
	}
	public function getParticipations() {
		if ($this->crs_participations === null) {
			require_once("Services/ParticipationStatus/classes/class.ilParticipationStatus.php");
			$this->crs_participations = ilParticipationStatus::getInstance($this->getCourse());
		}
		
		return $this->crs_participations;
	}
	
	public function getLocalRoles() {
		if ($this->local_roles === null) {
			require_once("Services/GEV/Utils/classes/class.gevRoleUtils.php");
			$this->local_roles = gevRoleUtils::getInstance()->getLocalRoleIdsAndTitles($this->crs_id);
			
			// rewrite names of member, tutor and admin roles
			foreach ($this->local_roles as $id => $title) {
				$pref = substr($title, 0, 8);
				if ($pref == "il_crs_m") {
					$this->local_roles[$id] = $this->lng->txt("crs_member");
				}
				else if ($pref == "il_crs_t") {
					$this->local_roles[$id] = $this->lng->txt("crs_tutor");
				}
				else if ($pref == "il_crs_a") {
					$this->local_roles[$id] = $this->lng->txt("crs_admin");
				}
			}
		}
		return $this->local_roles;
	}
	
	//
	
	
	public function getId() {
		return $this->crs_id;
	}
	
	public function getTitle() {
		return $this->getCourse()->getTitle();
	}
	
	public function getSubtitle() {
		return $this->getCourse()->getDescription();
	}

	public function getLink() {
		return self::getLinkTo($this->crs_id);
	}

	public function getCustomId() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_CUSTOM_ID);
	}
	
	public function setCustomId($a_id) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_CUSTOM_ID, $a_id);
	}
	
	public function getTemplateCustomId() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_CUSTOM_ID_TEMPLATE);
	}
	
	public function getTemplateTitle() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_TEMPLATE_TITLE);
	}
	
	public function setTemplateTitle($a_title) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_TEMPLATE_TITLE, $a_title);
	}
	
	public function getTemplateRefId() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_TEMPLATE_REF_ID);
	}
	
	public function setTemplateRefId($a_ref_id) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_TEMPLATE_REF_ID, $a_ref_id);
	}
	
	public function isTemplate() {
		return "Ja" == $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_IS_TEMPLATE);
	}
	
	public function setIsTemplate($a_val) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_IS_TEMPLATE, ($a_val === true)? "Ja" : "Nein" );
	}
	
	public function getType() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_TYPE);
	}
	
	public function getStartDate() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_START_DATE);
	}
	
	public function getFormattedStartDate() {
		$d = $this->getStartDate();
		if (!$d) {
			return null;
		}
		$val = ilDatePresentation::formatDate($d);
		return $val;
	}
	
	public function setStartDate($a_date) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_START_DATE, $a_date);
	}
	
	public function getEndDate() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_END_DATE);
	}
	
	public function getFormattedEndDate() {
		$d = $this->getEndDate();
		if (!$d) {
			return null;
		}
		$val = ilDatePresentation::formatDate($d);
		return $val;
	}
	
	public function setEndDate($a_date) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_END_DATE, $a_date);
	}
	
	public function getSchedule() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_SCHEDULE);
	}
	
	public function getFormattedStartTime() {
		$schedule = $this->getSchedule();
		if (count($schedule) == 0) {
			return "";
		}
		
		$spl = explode("-", $schedule[0]);
		return $spl[0];
	}
	
	public function getFormattedEndTime() {
		$schedule = $this->getSchedule();
		if (count($schedule) == 0) {
			return "";
		}
		
		$spl = explode("-", $schedule[count($schedule) - 1]);
		return $spl[1];
	}
	
	public function getFormattedAppointment() {
		$start = $this->getStartDate();
		$end = $this->getEndDate();
		if ($start && $end) {
			$val = ilDatePresentation::formatPeriod($start, $end);
			return $val;
		}
		return "";
	}
	
	public function getFormattedBookingDeadlineDate() {
		$dl = $this->getBookingDeadlineDate();
		if (!$dl) {
			return "";
		}
		$val = ilDatePresentation::formatDate($dl);
		return $val;
	}
	
	public function getAmountHours() {
		$type = $this->getType();
		if ( $type === null
		  || in_array($type, array("POT-Termin", "Selbstlernkurs"))) {
			return null;
		}
		$schedule = $this->getSchedule();
		$hours = 0;
		foreach ($schedule as $day) {
			$spl = split("-", $day);
			$spl[0] = split(":", $spl[0]);
			$spl[1] = split(":", $spl[1]);
			$hours += $spl[1][0] - $spl[0][0] + ($spl[1][1] - $spl[0][1])/60.0;
		}
		return round($hours);
	}
	
	public function getTopics() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_TOPIC);
	}
	
	public function getContents() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_CONTENTS);
	}
	
	public function getGoals() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_GOALS);
	}
	
	public function getMethods() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_METHODS);
	}
	
	public function getMedia() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_MEDIA);
	}
	
	public function getTargetGroup() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_TARGET_GROUP);
	}
	
	public function getTargetGroupDesc() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_TARGET_GROUP_DESC);
	}
	
	public function getIsExpertTraining() {
		return "Ja" == $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_EXPERT_TRAINING);
	}
	
	public function getCreditPoints() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_CREDIT_POINTS);
	}
	
	public function getFee() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_FEE);
	}
	
	public function getFormattedFee() {
		$fee = $this->getFee();
		if ($fee) {
			return gevCourseUtils::formatFee($fee);
		}
	}
	
	static public function formatFee($a_fee) {
		require_once("Services/GEV/Utils/classes/class.gevBillingUtils.php");
		return gevBillingUtils::formatPrize($a_fee);
	}
	
	public function getMinParticipants() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_MIN_PARTICIPANTS);
	}
	
	public function setMinParticipants($a_min) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_MIN_PARTICIPANTS, $a_min);
	}
	
	public function getMaxParticipants() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_MAX_PARTICIPANTS);
	}
	
	public function setMaxParticipants($a_min, $a_update_course = true) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_MAX_PARTICIPANTS, $a_min);
		
		if ($a_update_course) {
			$this->getCourse()->setSubscriptionMaxMembers($a_min);
			$this->getCourse()->update();
		}
	}

	public function getWaitingListActive() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_WAITING_LIST_ACTIVE) == "Ja";
	}

	public function setWaitingListActive($a_active, $a_update_course = true) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_WAITING_LIST_ACTIVE, $a_active ? "Ja" : "Nein");
		
		if ($a_update_course) {
			$this->getCourse()->enableSubscriptionMembershipLimitation(true);
			$this->getCourse()->enableWaitingList(true);
			$this->getCourse()->update();
		}
	}

	public function getCancelDeadline() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_CANCEL_DEADLINE);
	}
	
	public function setCancelDeadline($a_dl) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_CANCEL_DEADLINE, $a_dl);
	}
	
	public function getCancelDeadlineDate() {
		return self::mkDeadlineDate($this->getStartDate(), $this->getCancelDeadline());
	}
	
	public function getFormattedCancelDeadline() {
		$dl = $this->getCancelDeadlineDate();
		if (!$dl) {
			return "";
		}
		$val = ilDatePresentation::formatDate($dl);
		return $val;
	}
	
	public function isCancelDeadlineExpired() {
		$dl = $this->getCancelDeadlineDate();
		
		if (!$dl) {
			return false;
		}
		
		$now = new ilDateTime(time(), IL_CAL_UNIX);
		return ilDateTime::_before($dl, $now);
	}
	
	public function getBookingDeadline() {
		$val = $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_BOOKING_DEADLINE);
		if (!$val) {
			$val = 1;
		}
		return $val;
	}
	
	public function setBookingDeadline($a_dl) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_BOOKING_DEADLINE, $a_dl);
	}
	
	public function getBookingDeadlineDate() {
		return self::mkDeadlineDate($this->getStartDate(), $this->getBookingDeadline());
	}
	
	public function getCancelWaitingList() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_CANCEL_WAITING);
	}
	
	public function getCancelWaitingListDate() {
		return self::mkDeadlineDate($this->getStartDate(), $this->getCancelWaitingList());
	}
	
	public function getFreePlaces() {
		return $this->getBookings()->getFreePlaces();
	}
	
	public function getProviderId() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_PROVIDER);
	}
	
	public function setProviderId($a_provider) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_PROVIDER, $a_provider);
	}
	
	public function getProvider() {
		require_once("Services/GEV/Utils/classes/class.gevOrgUnitUtils.php");
		$id = $this->getProviderId();
		if ($id === null) {
			return null;
		}
		return gevOrgUnitUtils::getInstance($id);
	}
	
	public function getProviderTitle() {
		$prv = $this->getProvider();
		if ($prv === null) {
			return "";
		}
		
		return $prv->getLongTitle();
	}
	
	// Venue Info
	
	public function getVenueId() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_VENUE);
	}
	
	public function setVenueId($a_venue) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_VENUE, $a_venue);
	}
	
	public function getVenue() {
		require_once("Services/GEV/Utils/classes/class.gevOrgUnitUtils.php");
		$id = $this->getVenueId();
		if ($id === null) {
			return null;
		}
		return gevOrgUnitUtils::getInstance($id);
	}
	
	public function getVenueTitle() {
		$ven = $this->getVenue();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getLongTitle();
	}
	
	public function getVenueStreet() {
		$ven = $this->getVenue();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getStreet();
	}
	
	public function getVenueHouseNumber() {
		$ven = $this->getVenue();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getHouseNumber();
	}
	
	public function getVenueZipcode() {
		$ven = $this->getVenue();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getZipcode();
	}
	
	public function getVenueCity() {
		$ven = $this->getVenue();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getCity();
	}
	
	public function getVenuePhone() {
		$ven = $this->getVenue();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getContactPhone();
	}
	
	public function getVenueEmail() {
		$ven = $this->getVenue();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getContactEmail();
	}
	
	// Accomodation Info
	
	public function getAccomodationId() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_ACCOMODATION);
	}
	
	public function setAccomodationId($a_accom) {
		$this->amd->setField($this->crs_id, gevSettings::CRS_AMD_ACCOMODATION, $a_accom);
	}
	
	public function getAccomodation() {
		require_once("Services/GEV/Utils/classes/class.gevOrgUnitUtils.php");
		$id = $this->getAccomodationId();
		if ($id === null) {
			return null;
		}
		return gevOrgUnitUtils::getInstance($id);	
	}
	
	public function getAccomodationTitle() {
		$ven = $this->getAccomodation();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getLongTitle();
	}
	
	public function getAccomodationStreet() {
		$ven = $this->getAccomodation();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getStreet();
	}
	
	public function getAccomodationHouseNumber() {
		$ven = $this->getAccomodation();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getHouseNumber();
	}
	
	public function getAccomodationZipcode() {
		$ven = $this->getAccomodation();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getZipcode();
	}
	
	public function getAccomodationCity() {
		$ven = $this->getAccomodation();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getCity();
	}
	
	public function getAccomodationPhone() {
		$ven = $this->getAccomodation();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getContactPhone();
	}
	
	public function getAccomodationEmail() {
		$ven = $this->getAccomodation();
		if ($ven === null) {
			return "";
		}
		
		return $ven->getContactEmail();
	}
	
	public function getWebExLink() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_WEBEX_LINK);
	}
	
	public function getWebExPassword() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_WEBEX_PASSWORD);
	}
	
	public function getCSNLink() {
		return $this->amd->getField($this->crs_id, gevSettings::CRS_AMD_CSN_LINK);
	}
	
	public function getFormattedPreconditions() {
		// TODO: implement this!
		return "NYI!";
	}
	
	// derived courses for templates
	
	public function getDerivedCourseIds() {
		if (!$this->isTemplate()) {
			throw new Exception("gevCourseUtils::getDerivedCourseIds: this course is no template and thus has no derived courses.");
		}
		
		$ref_id_field = $this->amd->getFieldId(gevSettings::CRS_AMD_TEMPLATE_REF_ID);
		
		$ref_ids = gevObjectUtils::getAllRefIds($this->crs_id);
		
		$res = $this->db->query( "SELECT obj_id FROM adv_md_values_int"
								." WHERE field_id = ".$this->db->quote($ref_id_field, "integer")
								."  AND ".$this->db->in("value", $ref_ids, false, "integer")
								);
		$obj_ids = array();
		while ($rec = $this->db->fetchAssoc($res)) {
			$obj_ids[] = $rec["obj_id"];
		}
		
		return $obj_ids;
	}
	
	public function updateDerivedCourses() {
		if (!$this->isTemplate()) {
			throw new Exception("gevCourseUtils::updateDerivedCourses: this course is no template and thus has no derived courses.");
		}

		$obj_ids = $this->getDerivedCourseIds();
		
		$tmplt_title_field = $this->amd->getFieldId(gevSettings::CRS_AMD_TEMPLATE_TITLE);
		
		$this->db->manipulate( "UPDATE adv_md_values_text "
							  ."   SET value = ".$this->db->quote($this->getTitle(), "text")
							  ." WHERE ".$this->db->in("obj_id", $obj_ids, false, "integer")
							  ."   AND field_id = ".$this->db->quote($tmplt_title_field, "integer")
							 );
	}
	
	// Participants, Trainers and other members
	
	public function getMembership() {
		return $this->getCourse()->getMembersObject();
	}
	
	public function getMembersExceptForAdmins() {
		$ms = $this->getMembership();
		return array_merge($ms->getMembers(), $ms->getTutors());
	}
	
	public function getParticipants() {
		require_once("Services/GEV/Utils/classes/class.gevRoleUtils.php");
		$role = $this->getCourse()->getDefaultMemberRole();
		return gevRoleUtils::getInstance()->getRbacReview()->assignedUsers($role);
	}
	
	public function getTrainers() {
		return $this->getMembership()->getTutors();
	}
	
	public function getAdmins() {
		return $this->getMembership()->getAdmins();
	}
	
	public function getMembers() {
		return array_merge($this->getMembership()->getMembers(), $this->getTrainers(), $this->getAdmins());
	}
	
	public function getSpecialMembers() {		
		return array_diff( $this->getMembers()
						 , $this->getParticipants()
						 , $this->getAdmins()
						 , $this->getTrainers()
						 );
	}
	
	public function getMainTrainer() {
		if ($this->main_trainer === null) {
			$tutors = $this->getTrainers();
			sort($tutors);
			if(count($tutors) != 0) {
				$this->main_trainer = new ilObjUser($tutors[0]);
			}
		}
		
		return $this->main_trainer;
	}
	
	public function getMainAdmin() {
		if ($this->main_admin === null) {
			$admins = $this->getAdmins();
			sort($admins);
			if (count($admins) != 0) {
				$this->main_admin = new ilObjUser($admins[0]);
			}
		}
		
		return $this->main_admin;
	}
	
	public function getCancelledMembers() {
		return $this->getBookings()->getCancelledUsers();
	}
	
	public function getCancelledWithCostsMembers() {
		return $this->getBookings()->getCancelledWithCostsUsers();
	}
	
	public function getCancelledWithoutCostsMembers() {
		return $this->getBookings()->getCancelledWithoutCostsUsers();
	}
	
	public function getWaitingMembers() {
		return $this->getBookings()->getWaitingUsers();
	}
	
	public function getSuccessfullParticipants() {
		return $this->getParticipations()->getSuccessfullUsers();
	}
	
	public function getAbsentParticipants() {
		return $this->getParticipations()->getAbsentNotExcusedUsers();
	}
	
	public function getExcusedParticipants() {
		return $this->getParticipations()->getAbsentExcusedUsers();
	}
	
	// Training Officer Info (Themenverantwortlicher)
	
	public function getTrainingOfficerName() {
		return $this->getCourse()->getContactName();
	}
	
	public function getTrainingOfficerEMail() {
		return $this->getCourse()->getContactEmail();
	}
	
	public function getTrainingOfficerPhone() {
		return $this->getCourse()->getContactPhone();
	}
	
	// Main Trainer Info
	
	public function getMainTrainerFirstname() {
		$tr = $this->getMainTrainer();
		if ($tr !== null) {
			return $tr->getFirstname();
		}
		return "";
	}
	
	public function getMainTrainerLastname() {
		$tr = $this->getMainTrainer();
		if ($tr !== null) {
			return $this->getMainTrainer()->getLastname();
		}
		return "";
	}
	
	public function getMainTrainerName() {
		$tr = $this->getMainTrainer();
		if ($tr !== null) {
			return $this->getMainTrainerFirstname()." ".$this->getMainTrainerLastname();
		}
		return "";
	}
	
	public function getMainTrainerPhone() {
		$tr = $this->getMainTrainer();
		if ($tr !== null) {
			return $this->getMainTrainer()->getPhoneOffice();
		}
		return "";
	}
	
	public function getMainTrainerEMail() {
		$tr = $this->getMainTrainer();
		if ($tr !== null) {
			return $this->getMainTrainer()->getEmail();
		}
		return "";
	}
	
	// Main Admin info
	
	public function getMainAdminFirstname() {
		$tr = $this->getMainAdmin();
		if ($tr !== null) {
			return $tr->getFirstname();
		}
		return "";
	}
	
	public function getMainAdminLastname() {
		$tr = $this->getMainAdmin();
		if ($tr !== null) {
			return $tr->getLastname();
		}
		return "";
	}
	
	public function getMainAdminName() {
		$tr = $this->getMainAdmin();
		if ($tr !== null) {
			return $this->getMainAdminFirstname()." ".$this->getMainAdminLastname();
		}
		return "";
	}
	
	public function getMainAdminPhone() {
		$tr = $this->getMainAdmin();
		if ($tr !== null) {
			return $tr->getPhoneOffice();
		}
		return "";
	}
	
	public function getMainAdminEMail() {
		$tr = $this->getMainAdmin();
		if ($tr !== null) {
			return $tr->getEmail();
		}
		return "";
	}
	
	
	// Memberlist creation
	
	public function deliverMemberList($a_hotel_list) {
		$this->buildMemberList(true, null, $a_hotel_list);
	}
	
	public function buildMemberList($a_send, $a_filename, $a_hotel_list) {
		require_once("Services/GEV/Utils/classes/class.gevUserUtils.php");
		
		global $lng;
		
		$lng->loadLanguageModule("common");
		$lng->loadLanguageModule("gev");

		if ($a_filename === null) {
			if(!$a_send)
			{
				$a_filename = ilUtil::ilTempnam();
			}
			else
			{
				$a_filename = "list.xls";
			}
		}

		include_once "./Services/Excel/classes/class.ilExcelUtils.php";
		include_once "./Services/Excel/classes/class.ilExcelWriterAdapter.php";
		$adapter = new ilExcelWriterAdapter($a_filename, $a_send);
		$workbook = $adapter->getWorkbook();
		$worksheet = $workbook->addWorksheet();
		$worksheet->setLandscape();

		// what is this good for
		//$txt = array();

		$columns = array( $lng->txt("gender")
						, $lng->txt("firstname")
						, $lng->txt("lastname")
						, $lng->txt("gev_org_unit_short")
						);

		$worksheet->setColumn(0, 0, 16);		// gender
		$worksheet->setColumn(1, 1, 20); 	// firstname
		$worksheet->setColumn(2, 2, 20);	// lastname
		$worksheet->setColumn(3, 3, 20);	// org-unit
		
		if($a_hotel_list)
		{
			$columns[] = $lng->txt("gev_crs_book_overnight_details"); // #3764

			$worksheet->setColumn(4, 4, 50); // #4481
		}
		else
		{
			$columns[] = $lng->txt("status");
			$columns[] = $lng->txt("birthday");
			$columns[] = $lng->txt("gev_signature");
			
			$worksheet->setColumn(4, 4, 20);
			$worksheet->setColumn(5, 5, 25);
			$worksheet->setColumn(6, 6, 20);
		}
		
		$row = $this->buildListMeta( $workbook
							   , $worksheet
							   , $lng->txt("gev_excel_member_title")." ".
										( !$a_hotel_list 
										? $lng->txt("obj_crs") 
										: $lng->txt("gev_hotel")
										)
							   , $lng->txt("gev_excel_member_row_title")
							   , $columns
							   );

		$user_ids = $this->getCourse()->getMembersObject()->getMembers();
		$tutor_ids = $this->getCourse()->getMembersObject()->getTutors();

		$user_ids = array_merge($user_ids, $tutor_ids);

		if($user_ids)
		{
			$format_wrap = $workbook->addFormat();
			$format_wrap->setTextWrap();

			foreach($user_ids as $user_id)
			{
				$row++;
				//$txt[] = "";
				$user_utils = gevUserUtils::getInstance($user_id);


				//$txt[] = $lng->txt("name").": ".$user_data["name"];
				//$txt[] = $lng->txt("phone_office").": ".$user_data["fon"];
				//$txt[] = $lng->txt("vofue_org_unit_short").": ". $user_data["ounit"];

				$worksheet->write($row, 0, $user_utils->getGender(), $format_wrap);
				$worksheet->writeString($row, 1, $user_utils->getFirstname(), $format_wrap);
				$worksheet->write($row, 2, $user_utils->getLastname(), $format_wrap);
				$worksheet->write($row, 3, $user_utils->getFirstname(), $format_wrap);
				
				if($a_hotel_list)
				{
					// vfstep3.1
					$worksheet->write($row, 4, $user_utils->getFormattedOvernightDetailsForCourse($this->getCourse()), $format_wrap);

					//$txt[] = $lng->txt("vofue_crs_book_overnight_details").": ".$user_data["ov"];
				}
				else
				{
					$worksheet->write($row, 4, $user_utils->getFunctionAtCourse($this->crs_id), $format_wrap);
					$worksheet->write($row, 5, $user_utils->getFormattedBirthday(), $format_wrap);
					$worksheet->write($row, 6, "", $format_wrap);
					
					//$txt[] = $lng->txt("vofue_udf_join_date").": ".$user_data["jdate"];
					//$txt[] = $lng->txt("birthday").": ".$user_data["bdate"];
					//$txt[] = $lng->txt("vofue_crs_function").": ".$user_data["func"];
					//$txt[] = $lng->txt("vofue_udf_adp_number").": ". $user_data["adp"];
					//$txt[] = $lng->txt("vofue_crs_book_goals").": ".$user_data["goals"];
				}
			}
		}

		$workbook->close();

		if($a_send)
		{
			exit();
		}

		return array($filename, "Teilnehmer.xls");//, implode("\n", $txt));
	}
	
	protected function buildListMeta($workbook, $worksheet, $title, $row_title, array $column_titles)
	{
		global $lng;

		$num_cols = sizeof($column_titles);

		$format_bold = $workbook->addFormat(array("bold" => 1));
		$format_title = $workbook->addFormat(array("bold" => 1, "size" => 14));
		$format_subtitle = $workbook->addFormat(array("bold" => 1, "bottom" => 6));

		$worksheet->writeString(0, 0, $title, $format_title);
		$worksheet->mergeCells(0, 0, 0, $num_cols-1);
		$worksheet->mergeCells(1, 0, 1, $num_cols-1);

		$worksheet->writeString(2, 0, $lng->txt("gev_excel_course_title"), $format_subtitle);
		for($loop = 1; $loop < $num_cols; $loop++)
		{
			$worksheet->writeString(2, $loop, "", $format_subtitle);
		}
		$worksheet->mergeCells(2, 0, 2, $num_cols-1);
		$worksheet->mergeCells(3, 0, 3, $num_cols-1);

		// course info
		$row = 4;
		foreach($this->getListMetaData() as $caption => $value)
		{
			$worksheet->writeString($row, 0, $caption, $format_bold);

			if(!is_array($value))
			{
				$worksheet->writeString($row, 1, $value);
				$worksheet->mergeCells($row, 1, $row, $num_cols-1);
			}
			else
			{
				$first = array_shift($value);
				$worksheet->writeString($row, 1, $first);
				$worksheet->mergeCells($row, 1, $row, $num_cols-1);

				foreach($value as $line)
				{
					if(trim($line))
					{
						$row++;
						$worksheet->write($row, 0, "");
						$worksheet->writeString($row, 1, $line);
						$worksheet->mergeCells($row, 1, $row, $num_cols-1);
					}
				}
			}

			$row++;
		}

		// empty row
		$worksheet->mergeCells($row, 0, $row, $num_cols-1);
		$row++;
		$worksheet->mergeCells($row, 0, $row, $num_cols-1);
		$row++;

		// row_title
		$worksheet->writeString($row, 0, $row_title, $format_subtitle);
		for($loop = 1; $loop < $num_cols; $loop++)
		{
			$worksheet->writeString($row, $loop, "", $format_subtitle);
		}
		$worksheet->mergeCells($row, 0, $row, $num_cols-1);
		$row++;
		$worksheet->mergeCells($row, 0, $row, $num_cols-1);
		$row++;

		// title row
		for($loop = 0; $loop < $num_cols; $loop++)
		{
			$worksheet->writeString($row, $loop, $column_titles[$loop], $format_bold);
		}

		return $row;
	}
	
	protected function getListMetaData() {
		$start_date = $this->getStartDate();
		$end_date = $this->getEndDate();
		$arr = array("Titel" => $this->getTitle()
					, "Untertitel" => $this->getSubtitle()
					, "Nummer der Maßnahme" => $this->getCustomId()
					, "Datum" => ($start_date !== null && $end_date !== null)
								 ? ilDatePresentation::formatPeriod($this->getStartDate(), $this->getEndDate())
								 : ""
					, "Veranstaltungsort" => $this->getVenueTitle()
					, "Trainer" => ($this->getMainTrainer() !== null)
								   ?$this->getMainTrainerLastname().", ".$this->getMainTrainerFirstname()
								   :""
					, "Trainingsbetreuer" => $this->getMainAdminName()
					, "Bildungspunkte" => $this->getCreditPoints()
					);
		return $arr;
	}
	
	
	// Desk Display creation
	
	public function canBuildDeskDisplays() {
		return count($this->getMembersExceptForAdmins()) > 0;
	}
	
	public function buildDeskDisplays($a_path = null) {
		require_once("Services/DeskDisplays/classes/class.ilDeskDisplay.php");
		$dd = new ilDeskDisplay($this->db, $this->log);
		
		// Generali-Konzept, Kapitel "Tischaufsteller"
		$dd->setLine1Font("Arial", 48, false, false);
		$dd->setLine1Color(150, 150, 150);
		$dd->setLine2Font("Arial", 96, false, false);
		$dd->setLine2Color(0, 0, 0);
		$dd->setSpaceLeft(2);
		$dd->setSpaceBottom1(10.0);
		$dd->setSpaceBottom2(6.5);
		
		$dd->setUsers($this->getMembersExceptForAdmins());
		if ($a_path === null) {
			$dd->deliver();
		}
		else {
			$dd->build($a_path);
		}
	}

	// Booking
	
	public function bookUser($a_user_id) {
		return $this->getBookings()->join($a_user_id);
	}
	
	public function getBookingStatusOf($a_user_id) {
		require_once("Services/CourseBooking/classes/class.ilCourseBooking.php");
		return ilCourseBooking::getUserStatus($this->crs_id, $a_user_id);
	}
	
	public function getBookingStatusLabelOf($a_user_id) {
		$status = $this->getBookingStatusOf($a_user_id);
		switch ($status) {
			case ilCourseBooking::STATUS_BOOKED:
				return "gebucht";
			case ilCourseBooking::STATUS_WAITING:
				return "auf Warteliste";
			case ilCourseBooking::STATUS_CANCELLED_WITH_COSTS:
				return "kostenpflichtig storniert";
			case ilCourseBooking::STATUS_CANCELLED_WITHOUT_COSTS:
				return "kostenfrei storniert";
			default:
				return "";
		}
	}
	
	public function isWaitingListActivated() {
		return $this->getBookings()->isWaitingListActivated();
	}
	
	public function canBookCourseForOther($a_user_id, $a_other_id) {
		return $this->getBookingPermissions($a_user_id)->bookCourseForUser($a_other_id);
	}
	
	public function isBookableFor($a_user) {
		return $this->getBookingHelper($a_user_id)->isBookable($a_user);
	}
	
	public function cancelBookingOf($a_user_id) {
		return $this->getBookings()->cancel($a_user_id);
	}
	
	// Participation
	
	public function getParticipationStatusOf($a_user_id) {
		$sp = $this->getParticipations()->getStatusAndPoints($a_user_id);
		$status = $sp["status"];
		
		if ($status === null && $this->getBookingStatusOf($a_user_id) == ilCourseBooking::STATUS_BOOKED) {
			return ilParticipationStatus::STATUS_NOT_SET;
		}
		return $status;
	}
	
	public function getParticipationStatusLabelOf($a_user_id) {
		$status = $this->getParticipationStatusOf($a_user_id);
		switch ($status) {
			case ilParticipationStatus::STATUS_NOT_SET:
				return "nicht gesetzt";
			case ilParticipationStatus::STATUS_SUCCESSFUL:
				return "teilgenommen";
			case ilParticipationStatus::STATUS_ABSENT_EXCUSED:
				return "fehlt entschuldigt";
			case ilParticipationStatus::STATUS_ABSENT_NOT_EXCUSED:
				return "fehlt ohne Absage";
			default:
				return "";
		}
	}
	
	public function allParticipationStatusSet() {
		return $this->getParticipations()->allStatusSet(); 
	}
	
	public function getFunctionOfUser($a_user_id) {
		require_once("Services/GEV/Utils/classes/class.gevRoleUtils.php");
		$utils = gevRoleUtils::getInstance();
		$roles = $this->getLocalRoles();
		$res = $this->db->query( "SELECT rol_id FROM rbac_ua "
								." WHERE usr_id = ".$this->db->quote($a_user_id)
								."   AND ".$this->db->in("rol_id", array_keys($roles), false, "integer"));
		if ($rec = $this->db->fetchAssoc($res)) {
			return $roles[$rec["rol_id"]];
		}
		return null;
	}
	
	public function getCreditPointsOf($a_user_id) {
		$sp = $this->getParticipations()->getStatusAndPoints($a_user_id);
		if ($sp["status"] == ilParticipationStatus::STATUS_NOT_SET) {
			return $this->getCreditPoints();
		}
		if ($sp["status"] == ilParticipationStatus::STATUS_SUCCESSFUL) {
			if ($sp["points"] !== null) {
				return $sp["points"];
			}
			return $this->getCreditPoints();
		}
		return 0;
	}
	
}

?>