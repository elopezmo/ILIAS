<?php
/*
	+-----------------------------------------------------------------------------+
	| ILIAS open source                                                           |
	+-----------------------------------------------------------------------------+
	| Copyright (c) 1998-2001 ILIAS open source, University of Cologne            |
	|                                                                             |
	| This program is free software; you can redistribute it and/or               |
	| modify it under the terms of the GNU General Public License                 |
	| as published by the Free Software Foundation; either version 2              |
	| of the License, or (at your option) any later version.                      |
	|                                                                             |
	| This program is distributed in the hope that it will be useful,             |
	| but WITHOUT ANY WARRANTY; without even the implied warranty of              |
	| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the               |
	| GNU General Public License for more details.                                |
	|                                                                             |
	| You should have received a copy of the GNU General Public License           |
	| along with this program; if not, write to the Free Software                 |
	| Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA. |
	+-----------------------------------------------------------------------------+
*/


/**
* Class ilObjCategoryGUI
*
* @author Stefan Meyer <smeyer@databay.de> 
* @author Sascha Hofmann <shofmann@databay.de> 
* $Id$
* 
* @extends ilObjectGUI
* @package ilias-core
*/

require_once "class.ilObjectGUI.php";

class ilObjCategoryGUI extends ilObjectGUI
{
	var $ctrl;

	/**
	* Constructor
	* @access public
	*/
	function ilObjCategoryGUI($a_data, $a_id, $a_call_by_reference = true, $a_prepare_output = true)
	{
		global $ilCtrl;

		// CONTROL OPTIONS
		$this->ctrl =& $ilCtrl;
		$this->ctrl->saveParameter($this,array("ref_id","cmdClass"));

		$this->type = "cat";
		$this->ilObjectGUI($a_data, $a_id, $a_call_by_reference, $a_prepare_output);
	}
	function &executeCommand()
	{
		global $rbacsystem;

		$next_class = $this->ctrl->getNextClass($this);
		$cmd = $this->ctrl->getCmd();

		switch($next_class)
		{
			default:
				if(!$cmd)
				{
					$cmd = "view";
				}
				$cmd .= "Object";
				$this->$cmd();
					
				break;
		}
		return true;
	}

	function getTabs(&$tabs_gui)
	{
		global $rbacsystem;


		$this->ctrl->setParameter($this,"ref_id",$this->ref_id);

		#if ($rbacsystem->checkAccess('read',$this->ref_id))
		#{
		#	$tabs_gui->addTarget("view_content",
		#						 $this->ctrl->getLinkTarget($this, ""), "", get_class($this));
		#}
		if ($rbacsystem->checkAccess('write',$this->ref_id))
		{
			$tabs_gui->addTarget("edit_properties",
								 $this->ctrl->getLinkTarget($this, "edit"), "edit", get_class($this));
		}
		if($rbacsystem->checkAccess('cat_edit_user',$this->ref_id))
		{
			$tabs_gui->addTarget("administrate_users",
								 $this->ctrl->getLinkTarget($this, "listUsers"), "", get_class($this));
		}
		if ($rbacsystem->checkAccess('edit_permission',$this->ref_id))
		{
			$tabs_gui->addTarget("perm_settings",
								 $this->ctrl->getLinkTarget($this, "perm"), "perm", get_class($this));
		}

		if ($this->ctrl->getTargetScript() == "adm_object.php")
		{
			$tabs_gui->addTarget("show_owner",
								 $this->ctrl->getLinkTarget($this, "owner"), "owner", get_class($this));
			
			if ($this->tree->getSavedNodeData($this->ref_id))
			{
				$tabs_gui->addTarget("trash",
									 $this->ctrl->getLinkTarget($this, "trash"), "trash", get_class($this));
			}
		}
			
	}

	/**
	* create new category form
	*
	* @access	public
	*/
	function createObject()
	{
		global $rbacsystem;

		$new_type = $_POST["new_type"] ? $_POST["new_type"] : $_GET["new_type"];

		if (!$rbacsystem->checkAccess("create", $_GET["ref_id"], $new_type))
		{
			$this->ilias->raiseError($this->lng->txt("permission_denied"),$this->ilias->error_obj->MESSAGE);
		}
		else
		{
			// for lang selection include metadata class
			include_once "./classes/class.ilMetaData.php";

			//add template for buttons
			$this->tpl->addBlockfile("BUTTONS", "buttons", "tpl.buttons.html");

			$this->tpl->setCurrentBlock("btn_cell");
			$this->tpl->setVariable("BTN_LINK", "adm_object.php?ref_id=".$this->ref_id."&cmd=importCategoriesForm");
			$this->tpl->setVariable("BTN_TXT", $this->lng->txt("import_categories"));
			$this->tpl->parseCurrentBlock();

			$this->getTemplateFile("edit",$new_type);

			$array_push = true;

			if ($_SESSION["error_post_vars"])
			{
				$_SESSION["translation_post"] = $_SESSION["error_post_vars"];
				$array_push = false;
			}

			// clear session data if a fresh category should be created
			if (($_GET["mode"] != "session"))
			{
				unset($_SESSION["translation_post"]);
			}	// remove a translation from session
			elseif ($_GET["entry"] != 0)
			{
				array_splice($_SESSION["translation_post"]["Fobject"],$_GET["entry"],1,array());

				if ($_GET["entry"] == $_SESSION["translation_post"]["default_language"])
				{
					$_SESSION["translation_post"]["default_language"] = "";
				}
			}

			// stripslashes in form output?
			$strip = isset($_SESSION["translation_post"]) ? true : false;

			$data = $_SESSION["translation_post"];

			if (!is_array($data["Fobject"]))
			{
				$data["Fobject"] = array();
			}

			// add additional translation form
			if (!$_GET["entry"] and $array_push)
			{
				$count = array_push($data["Fobject"],array("title" => "","desc" => ""));
			}
			else
			{
				$count = count($data["Fobject"]);
			}

			foreach ($data["Fobject"] as $key => $val)
			{
				// add translation button
				if ($key == $count -1)
				{
					$this->tpl->setCurrentBlock("addTranslation");
					$this->tpl->setVariable("TXT_ADD_TRANSLATION",$this->lng->txt("add_translation")." >>");
					$this->tpl->parseCurrentBlock();
				}

				// remove translation button
				if ($key != 0)
				{
					$this->tpl->setCurrentBlock("removeTranslation");
					$this->tpl->setVariable("TXT_REMOVE_TRANSLATION",$this->lng->txt("remove_translation"));
					$this->tpl->setVariable("LINK_REMOVE_TRANSLATION", "adm_object.php?cmd=removeTranslation&entry=".$key."&mode=create&ref_id=".$_GET["ref_id"]."&new_type=".$new_type);
					$this->tpl->parseCurrentBlock();
				}

				// lang selection
				$this->tpl->addBlockFile("SEL_LANGUAGE", "sel_language", "tpl.lang_selection.html", false);
				$this->tpl->setVariable("SEL_NAME", "Fobject[".$key."][lang]");

				$languages = ilMetaData::getLanguages();

				foreach($languages as $code => $language)
				{
					$this->tpl->setCurrentBlock("lg_option");
					$this->tpl->setVariable("VAL_LG", $code);
					$this->tpl->setVariable("TXT_LG", $language);

					if ($count == 1 AND $code == $this->ilias->account->getPref("language") AND !isset($_SESSION["translation_post"]))
					{
						$this->tpl->setVariable("SELECTED", "selected=\"selected\"");
					}
					elseif ($code == $val["lang"])
					{
						$this->tpl->setVariable("SELECTED", "selected=\"selected\"");
					}

					$this->tpl->parseCurrentBlock();
				}

				// object data
				$this->tpl->setCurrentBlock("obj_form");

				if ($key == 0)
				{
					$this->tpl->setVariable("TXT_HEADER", $this->lng->txt($new_type."_new"));
				}
				else
				{
					$this->tpl->setVariable("TXT_HEADER", $this->lng->txt("translation")." ".$key);
				}

				if ($key == $data["default_language"])
				{
					$this->tpl->setVariable("CHECKED", "checked=\"checked\"");
				}

				$this->tpl->setVariable("TXT_TITLE", $this->lng->txt("title"));
				$this->tpl->setVariable("TXT_DESC", $this->lng->txt("desc"));
				$this->tpl->setVariable("TXT_DEFAULT", $this->lng->txt("default"));
				$this->tpl->setVariable("TXT_LANGUAGE", $this->lng->txt("language"));
				$this->tpl->setVariable("TITLE", ilUtil::prepareFormOutput($val["title"],$strip));
				$this->tpl->setVariable("DESC", ilUtil::stripSlashes($val["desc"]));
				$this->tpl->setVariable("NUM", $key);
				$this->tpl->parseCurrentBlock();
			}

			// global
			$this->tpl->setVariable("FORMACTION", $this->getFormAction("save","adm_object.php?cmd=gateway&mode=create&ref_id=".$_GET["ref_id"]."&new_type=".$new_type));
			$this->tpl->setVariable("TARGET", $this->getTargetFrame("save"));
			$this->tpl->setVariable("TXT_CANCEL", $this->lng->txt("cancel"));
			$this->tpl->setVariable("TXT_SUBMIT", $this->lng->txt($new_type."_add"));
			$this->tpl->setVariable("CMD_SUBMIT", "save");
			$this->tpl->setVariable("TXT_REQUIRED_FLD", $this->lng->txt("required_field"));
		}
	}

	/**
	* save category
	* @access	public
	*/
	function saveObject()
	{
		$data = $_POST;

		// default language set?
		if (!isset($data["default_language"]))
		{
			$this->ilias->raiseError($this->lng->txt("msg_no_default_language"),$this->ilias->error_obj->MESSAGE);
		}

		// prepare array fro further checks
		foreach ($data["Fobject"] as $key => $val)
		{
			$langs[$key] = $val["lang"];
		}

		$langs = array_count_values($langs);

		// all languages set?
		if (array_key_exists("",$langs))
		{
			$this->ilias->raiseError($this->lng->txt("msg_no_language_selected"),$this->ilias->error_obj->MESSAGE);
		}

		// no single language is selected more than once?
		if (array_sum($langs) > count($langs))
		{
			$this->ilias->raiseError($this->lng->txt("msg_multi_language_selected"),$this->ilias->error_obj->MESSAGE);
		}

		// copy default translation to variable for object data entry
		$_POST["Fobject"]["title"] = $_POST["Fobject"][$_POST["default_language"]]["title"];
		$_POST["Fobject"]["desc"] = $_POST["Fobject"][$_POST["default_language"]]["desc"];

		// always call parent method first to create an object_data entry & a reference
		$newObj = parent::saveObject();

		// setup rolefolder & default local roles if needed (see ilObjForum & ilObjForumGUI for an example)
		//$roles = $newObj->initDefaultRoles();

		// write translations to object_translation
		foreach ($data["Fobject"] as $key => $val)
		{
			if ($key == $data["default_language"])
			{
				$default = 1;
			}
			else
			{
				$default = 0;
			}

			$newObj->addTranslation(ilUtil::stripSlashes($val["title"]),ilUtil::stripSlashes($val["desc"]),$val["lang"],$default);
		}

		// always send a message
		sendInfo($this->lng->txt("cat_added"),true);
		ilUtil::redirect($this->getReturnLocation("save","adm_object.php?".$this->link_params));
	}

	/**
	* edit category
	*
	* @access	public
	*/
	function editObject()
	{
		global $rbacsystem;

		if (!$rbacsystem->checkAccess("write", $this->ref_id))
		{
			$this->ilias->raiseError($this->lng->txt("msg_no_perm_write"),$this->ilias->error_obj->MESSAGE);
		}

		// for lang selection include metadata class
		include_once "./classes/class.ilMetaData.php";

		$this->getTemplateFile("edit",$new_type);
		$array_push = true;

		if ($_SESSION["error_post_vars"])
		{
			$_SESSION["translation_post"] = $_SESSION["error_post_vars"];
			$_GET["mode"] = "session";
			$array_push = false;
		}

		// load from db if edit category is called the first time
		if (($_GET["mode"] != "session"))
		{
			$data = $this->object->getTranslations();
			$_SESSION["translation_post"] = $data;
			$array_push = false;
		}	// remove a translation from session
		elseif ($_GET["entry"] != 0)
		{
			array_splice($_SESSION["translation_post"]["Fobject"],$_GET["entry"],1,array());

			if ($_GET["entry"] == $_SESSION["translation_post"]["default_language"])
			{
				$_SESSION["translation_post"]["default_language"] = "";
			}
		}

		$data = $_SESSION["translation_post"];

		// add additional translation form
		if (!$_GET["entry"] and $array_push)
		{
			$count = array_push($data["Fobject"],array("title" => "","desc" => ""));
		}
		else
		{
			$count = count($data["Fobject"]);
		}

		// stripslashes in form?
		$strip = isset($_SESSION["translation_post"]) ? true : false;

		foreach ($data["Fobject"] as $key => $val)
		{
			// add translation button
			if ($key == $count -1)
			{
				$this->tpl->setCurrentBlock("addTranslation");
				$this->tpl->setVariable("TXT_ADD_TRANSLATION",$this->lng->txt("add_translation")." >>");
				$this->tpl->parseCurrentBlock();
			}

			// remove translation button
			if ($key != 0)
			{
				$this->tpl->setCurrentBlock("removeTranslation");
				$this->tpl->setVariable("TXT_REMOVE_TRANSLATION",$this->lng->txt("remove_translation"));
				$this->tpl->setVariable("LINK_REMOVE_TRANSLATION", "adm_object.php?cmd=removeTranslation&entry=".$key."&mode=edit&ref_id=".$_GET["ref_id"]);
				$this->tpl->parseCurrentBlock();
			}

			// lang selection
			$this->tpl->addBlockFile("SEL_LANGUAGE", "sel_language", "tpl.lang_selection.html", false);
			$this->tpl->setVariable("SEL_NAME", "Fobject[".$key."][lang]");

			$languages = ilMetaData::getLanguages();

			foreach ($languages as $code => $language)
			{
				$this->tpl->setCurrentBlock("lg_option");
				$this->tpl->setVariable("VAL_LG", $code);
				$this->tpl->setVariable("TXT_LG", $language);

				if ($code == $val["lang"])
				{
					$this->tpl->setVariable("SELECTED", "selected=\"selected\"");
				}

				$this->tpl->parseCurrentBlock();
			}

			// object data
			$this->tpl->setCurrentBlock("obj_form");

			if ($key == 0)
			{
				$this->tpl->setVariable("TXT_HEADER", $this->lng->txt($this->object->getType()."_edit"));
			}
			else
			{
				$this->tpl->setVariable("TXT_HEADER", $this->lng->txt("translation")." ".$key);
			}

			if ($key == $data["default_language"])
			{
				$this->tpl->setVariable("CHECKED", "checked=\"checked\"");
			}

			$this->tpl->setVariable("TXT_TITLE", $this->lng->txt("title"));
			$this->tpl->setVariable("TXT_DESC", $this->lng->txt("desc"));
			$this->tpl->setVariable("TXT_DEFAULT", $this->lng->txt("default"));
			$this->tpl->setVariable("TXT_LANGUAGE", $this->lng->txt("language"));
			$this->tpl->setVariable("TITLE", ilUtil::prepareFormOutput($val["title"],$strip));
			$this->tpl->setVariable("DESC", ilUtil::stripSlashes($val["desc"]));
			$this->tpl->setVariable("NUM", $key);
			$this->tpl->parseCurrentBlock();
		}

		// global
		$this->tpl->setCurrentBlock("adm_content");
		$this->tpl->setVariable("FORMACTION", $this->getFormAction("update","adm_object.php?cmd=gateway&mode=edit&ref_id=".$_GET["ref_id"]));
		$this->tpl->setVariable("TARGET", $this->getTargetFrame("update"));
		$this->tpl->setVariable("TXT_CANCEL", $this->lng->txt("cancel"));
		$this->tpl->setVariable("TXT_SUBMIT", $this->lng->txt("save"));
		$this->tpl->setVariable("CMD_SUBMIT", "update");
		$this->tpl->setVariable("TXT_REQUIRED_FLD", $this->lng->txt("required_field"));
	}

	/**
	* updates object entry in object_data
	*
	* @access	public
	*/
	function updateObject()
	{
		global $rbacsystem;

		if (!$rbacsystem->checkAccess("write", $this->object->getRefId()))
		{
			$this->ilias->raiseError($this->lng->txt("msg_no_perm_write"),$this->ilias->error_obj->MESSAGE);
		}
		else
		{
			$data = $_POST;

			// default language set?
			if (!isset($data["default_language"]))
			{
				$this->ilias->raiseError($this->lng->txt("msg_no_default_language"),$this->ilias->error_obj->MESSAGE);
			}

			// prepare array fro further checks
			foreach ($data["Fobject"] as $key => $val)
			{
				$langs[$key] = $val["lang"];
			}

			$langs = array_count_values($langs);

			// all languages set?
			if (array_key_exists("",$langs))
			{
				$this->ilias->raiseError($this->lng->txt("msg_no_language_selected"),$this->ilias->error_obj->MESSAGE);
			}

			// no single language is selected more than once?
			if (array_sum($langs) > count($langs))
			{
				$this->ilias->raiseError($this->lng->txt("msg_multi_language_selected"),$this->ilias->error_obj->MESSAGE);
			}

			// copy default translation to variable for object data entry
			$_POST["Fobject"]["title"] = $_POST["Fobject"][$_POST["default_language"]]["title"];
			$_POST["Fobject"]["desc"] = $_POST["Fobject"][$_POST["default_language"]]["desc"];

			// first delete all translation entries...
			$this->object->removeTranslations();

			// ...and write new translations to object_translation
			foreach ($data["Fobject"] as $key => $val)
			{
				if ($key == $data["default_language"])
				{
					$default = 1;
				}
				else
				{
					$default = 0;
				}

				$this->object->addTranslation(ilUtil::stripSlashes($val["title"]),ilUtil::stripSlashes($val["desc"]),$val["lang"],$default);
			}

			// update object data entry with default translation
			$this->object->setTitle(ilUtil::stripSlashes($_POST["Fobject"]["title"]));
			$this->object->setDescription(ilUtil::stripSlashes($_POST["Fobject"]["desc"]));
			$this->update = $this->object->update();
		}

		sendInfo($this->lng->txt("msg_obj_modified"),true);
		ilUtil::redirect($this->getReturnLocation("update","adm_object.php?".$this->link_params));
	}

	/**
	* adds a translation form & save post vars to session
	*
	* @access	public
	*/
	function addTranslationObject()
	{
		if (!($_GET["mode"] != "create" or $_GET["mode"] != "edit"))
		{
			$message = get_class($this)."::addTranslationObject(): Missing or wrong parameter! mode: ".$_GET["mode"];
			$this->ilias->raiseError($message,$this->ilias->error_obj->WARNING);
		}

		$_SESSION["translation_post"] = $_POST;
		ilUtil::redirect($this->getReturnLocation("addTranslation",
			"adm_object.php?cmd=".$_GET["mode"]."&entry=0&mode=session&ref_id=".$_GET["ref_id"]."&new_type=".$_GET["new_type"]));
	}

	/**
	* removes a translation form & save post vars to session
	*
	* @access	public
	*/
	function removeTranslationObject()
	{
		if (!($_GET["mode"] != "create" or $_GET["mode"] != "edit"))
		{
			$message = get_class($this)."::removeTranslationObject(): Missing or wrong parameter! mode: ".$_GET["mode"];
			$this->ilias->raiseError($message,$this->ilias->error_obj->WARNING);
		}

		ilUtil::redirect("adm_object.php?cmd=".$_GET["mode"]."&entry=".$_GET["entry"]."&mode=session&ref_id=".$_GET["ref_id"]."&new_type=".$_GET["new_type"]);
	}

	/**
	* display form for category import
	*/
	function importCategoriesFormObject ()
	{
		/*$this->tpl->addBlockfile("ADM_CONTENT", "adm_content", "tpl.cat_import_form.html");

		$this->tpl->setVariable("FORMACTION", "adm_object.php?ref_id=".$this->ref_id."&cmd=gateway");

		$this->tpl->setVariable("TXT_IMPORT_CATEGORIES", $this->lng->txt("import_categories"));
		$this->tpl->setVariable("TXT_IMPORT_FILE", $this->lng->txt("import_file"));

		$this->tpl->setVariable("BTN_IMPORT", $this->lng->txt("import"));
		$this->tpl->setVariable("BTN_CANCEL", $this->lng->txt("cancel"));*/
		ilObjCategoryGUI::_importCategoriesForm($this->ref_id, $this->tpl);
	}

	/**
	* display form for category import (static, also called by RootFolderGUI)
	*/
	function _importCategoriesForm ($a_ref_id, &$a_tpl)
	{
		global $lng;

		$a_tpl->addBlockfile("ADM_CONTENT", "adm_content", "tpl.cat_import_form.html");

		$a_tpl->setVariable("FORMACTION", "adm_object.php?ref_id=".$a_ref_id."&cmd=gateway");

		$a_tpl->setVariable("TXT_IMPORT_CATEGORIES", $lng->txt("import_categories"));
		$a_tpl->setVariable("TXT_IMPORT_FILE", $lng->txt("import_file"));

		$a_tpl->setVariable("BTN_IMPORT", $lng->txt("import"));
		$a_tpl->setVariable("BTN_CANCEL", $lng->txt("cancel"));
	}


	/**
	* import cancelled
	*
	* @access private
	*/
	function importCancelledObject()
	{
		sendInfo($this->lng->txt("action_aborted"),true);
		ilUtil::redirect("adm_object.php?ref_id=".$_GET["ref_id"]);
	}

	/**
	* get user import directory name
	*/
	function _getImportDir()
	{
		return ilUtil::getDataDir()."/cat_import";
	}

	/**
	* import categories
	*/
	function importCategoriesObject()
	{
		ilObjCategoryGUI::_importCategories($_GET["ref_id"]);
	}

	/**
	* import categories (static, also called by RootFolderGUI)
	*/
	function _importCategories($a_ref_id)
	{
		global $lng;

		require_once("classes/class.ilCategoryImportParser.php");

		$import_dir = ilObjCategoryGUI::_getImportDir();

		// create user import directory if necessary
		if (!@is_dir($import_dir))
		{
			ilUtil::createDirectory($import_dir);
		}

		// move uploaded file to user import directory
		$file_name = $_FILES["importFile"]["name"];
		$parts = pathinfo($file_name);
		$full_path = $import_dir."/".$file_name;
		move_uploaded_file($_FILES["importFile"]["tmp_name"], $full_path);

		// unzip file
		ilUtil::unzip($full_path);

		$subdir = basename($parts["basename"],".".$parts["extension"]);
		$xml_file = $import_dir."/".$subdir."/".$subdir.".xml";

		$importParser = new ilCategoryImportParser($xml_file, $a_ref_id);
		$importParser->startParsing();

		sendInfo($lng->txt("categories_imported"), true);
		ilUtil::redirect("adm_object.php?ref_id=".$a_ref_id);
	}

	// METHODS for local user administration
	function listUsersObject()
	{
		include_once './classes/class.ilLocalUser.php';

		global $rbacsystem;

		#$_SESSION['filtered_users'] = isset($_POST['filter']) ? $_POST['filter'] : $_SESSION['filtered_users'];

		if(!$rbacsystem->checkAccess("cat_admin_users",$this->object->getRefId()))
		{
			$this->ilias->raiseError($this->lng->txt("msg_no_perm_admin_users"),$this->ilias->error_obj->MESSAGE);
		}

		if(!count($users = ilLocalUser::_getAllUserIds($this->object->getRefId())))
		{
			sendInfo($this->lng->txt('no_local_user'));

			return true;
		}

		$this->tpl->addBlockfile("BUTTONS", "buttons", "tpl.buttons.html");
		
		// display button
		$this->tpl->setCurrentBlock("btn_cell");
		$this->tpl->setVariable("BTN_LINK",$this->ctrl->getLinkTargetByClass('ilobjusergui','create'));
		$this->tpl->setVariable("BTN_TXT",$this->lng->txt('add_user'));
		$this->tpl->parseCurrentBlock();


		$this->tpl->addBlockfile('ADM_CONTENT','adm_content','tpl.cat_admin_users.html');

		$parent = ilLocalUser::_getFolderIds();
		if(0 and count($parent) > 1)
		{
			$this->tpl->setCurrentBlock("filter");
			$this->tpl->setVariable("FILTER_TXT_FILTER",$this->lng->txt('filter'));
			$this->tpl->setVariable("SELECT_FILTER",$this->__buildFilterSelect($parent));
			$this->tpl->setVariable("FILTER_ACTION",$this->ctrl->getFormAction($this));
			$this->tpl->setVariable("FILTER_NAME",'listUsers');
			$this->tpl->setVariable("FILTER_VALUE",$this->lng->txt('applyFilter'));
			$this->tpl->parseCurrentBlock();
		}
		
		$counter = 0;
		$editable = false;
		foreach($users as $user_id)
		{
			$tmp_obj =& ilObjectFactory::getInstanceByObjId($user_id,false);

			if($tmp_obj->getTimeLimitOwner() == $this->object->getRefId())
			{
				$editable = true;
				$f_result[$counter][]	= ilUtil::formCheckbox(0,"user_ids[]",$tmp_obj->getId());

				$this->ctrl->setParameterByClass('ilobjusergui','obj_id',$user_id);
				$f_result[$counter][]	= '<a href="'.$this->ctrl->getLinkTargetByClass('ilobjusergui','edit').'">'.
					$tmp_obj->getLogin().'</a>';
			}
			else
			{
				$f_result[$counter][]	= '&nbsp;';
				$f_result[$counter][]	= $tmp_obj->getLogin();
			}

			$f_result[$counter][]	= $tmp_obj->getFirstname();
			$f_result[$counter][]	= $tmp_obj->getLastname();

			/*
			switch($tmp_obj->getTimeLimitOwner())
			{
				case ilLocalUser::_getUserFolderId():
					$f_result[$counter][]	= $this->lng->txt('global');
					break;

				default:
					$f_result[$counter][] = ilObject::_lookupTitle(ilObject::_lookupObjId($tmp_obj->getTimeLimitOwner()));
			}
			*/
			// role assignment
			$this->ctrl->setParameter($this,'obj_id',$user_id);
			$f_result[$counter][]	= '[<a href="'.$this->ctrl->getLinkTarget($this,'assignRoles').'">'.
				$this->lng->txt('edit').'</a>]';
			
			unset($tmp_obj);
			++$counter;
		}
		$this->__showUsersTable($f_result,$editable);
		
		return true;
	}

	function assignRolesObject()
	{
		global $rbacreview;

		if(!isset($_GET['obj_id']))
		{
			sendInfo('no_user_selected');
			$this->listUsersObject();

			return true;
		}
		
		#$assignable_roles = $this->
	}

	// PRIVATE
	function __showUsersTable($a_result_set,$footer = true)
	{
		$tbl =& $this->__initTableGUI();
		$tpl =& $tbl->getTemplateObject();

		// SET FORMAACTION
		$tpl->setCurrentBlock("tbl_form_header");

		$tpl->setVariable("FORMACTION",$this->ctrl->getFormAction($this));
		$tpl->parseCurrentBlock();


		if($footer)
		{
			// SET FOOTER BUTTONS
			$tpl->setVariable("COLUMN_COUNTS",5);
			$tpl->setVariable("IMG_ARROW", ilUtil::getImagePath("arrow_downright.gif"));

			$tpl->setCurrentBlock("tbl_action_button");
			$tpl->setVariable("BTN_NAME","deleteUser");
			$tpl->setVariable("BTN_VALUE",$this->lng->txt("delete"));
			$tpl->parseCurrentBlock();
			
			$tpl->setCurrentBlock("tbl_action_row");
			$tpl->setVariable("TPLPATH",$this->tpl->tplPath);
			$tpl->parseCurrentBlock();
		}

		$tbl->setTitle($this->lng->txt("users"),"icon_usr_b.gif",$this->lng->txt("users"));
		$tbl->setHeaderNames(array('',
								   $this->lng->txt("username"),
								   $this->lng->txt("firstname"),
								   $this->lng->txt("lastname"),
								   $this->lng->txt('role_assignment')));
		$tbl->setHeaderVars(array("",
								  "login",
								  "firstname",
								  "lastname",
								  "roleassignment"),
							array("ref_id" => $this->object->getRefId(),
								  "cmd" => "listUsers",
								  "cmdClass" => "ilobjcategorygui",
								  "cmdNode" => $_GET["cmdNode"]));
		$tbl->setColumnWidth(array("4%","24%","24%","24%","24%"));

		$this->__setTableGUIBasicData($tbl,$a_result_set,$editable);
		$tbl->render();

		$this->tpl->setVariable("USERS_TABLE",$tbl->tpl->get());

		return true;
	}		

	function __setTableGUIBasicData(&$tbl,&$result_set,$footer = true)
	{

		$offset = $_GET["offset"];
		// init sort_by (unfortunatly sort_by is preset with 'title'
		if ($_GET["sort_by"] == "title" or empty($_GET["sort_by"]))
		{
			$_GET["sort_by"] = "login";
		}
		$order = $_GET["sort_by"];
		$direction = $_GET["sort_order"];
	
		$tbl->setOrderColumn($order);
		$tbl->setOrderDirection($direction);
		$tbl->setOffset($offset);
		$tbl->setLimit($_GET["limit"]);
		$tbl->setMaxCount(count($result_set));
		if($footer)
		{
			$tbl->setFooter("tblfooter",$this->lng->txt("previous"),$this->lng->txt("next"));
		}
		else
		{
			$tbl->disable('footer');
		}
		$tbl->setData($result_set);
	}

	function &__initTableGUI()
	{
		include_once "./classes/class.ilTableGUI.php";

		return new ilTableGUI(0,false);
	}

	function __buildFilterSelect($a_parent_ids)
	{
		$action[0] = $this->lng->txt('all_users');

		foreach($a_parent_ids as $parent)
		{
			switch($parent)
			{
				case ilLocalUser::_getUserFolderId():
					$action[ilLocalUser::_getUserFolderId()] = $this->lng->txt('global_users'); 
					
					break;

				default:
					$action[$parent] = $this->lng->txt('users').' ('.ilObject::_lookupTitle(ilObject::_lookupObjId($parent)).')';

					break;
			}
		}
		return ilUtil::formSelect($_SESSION['filtered_users'],"filter",$action,false,true);

	}
	

} // END class.ilObjCategoryGUI
?>
