<?php
/*
 * ModNotice Plus 1.8
 * Author: Prtik / p00lz mod
 * Copyright: © 2011-2014 Prtik
 *
 * $Id: modnotice.php 3991 2014-01-13 08:50:13Z Prtik $
 */

if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

$plugins->add_hook("postbit", "modnotice_mod_editbutton");
$plugins->add_hook("postbit", "modnotice_show_notice");
$plugins->add_hook("postbit_prev", "modnotice_show_notice");

function modnotice_info()
{
	global $lang;
	$lang->load("modnotice");

	return array(
		"name"			=> $lang->modnotice,
		"description"	=> $lang->modnotice_desc,
		"website"		=> "http://community.mybb.com/thread-104136.html",
		"author"		=> "Prtik (p00lz mod)",
		"authorsite"	=> "http://community.mybb.com/thread-104136.html",
		"version"		=> "1.8",
		"compatibility" => "18*",
		"guid" => "9a5d622cdbb6ce6a4abaf19d967bbcf2"
	);
}

function modnotice_is_installed() {
	global $db;

	if($db->field_exists('modnotice', "posts") && $db->field_exists('modedituid', "posts") && $db->field_exists('modedittime', "posts")) {
		return true;
	}
	return false;
}

function modnotice_install() {
	global $db, $lang;

	$lang->load("modnotice");

	$db->write_query("ALTER TABLE `".TABLE_PREFIX."posts`
	ADD `modnotice` TEXT NULL DEFAULT NULL,
	ADD `modedituid` INT( 10 ) DEFAULT '0' NOT NULL ,
	ADD `modedittime` INT( 10 ) DEFAULT '0' NOT NULL;");

	$query = $db->simple_select("settinggroups", "COUNT(*) as c");
	$rows = $db->fetch_field($query, "c");

	$insertarray = array(
		'name' => 'modnotice',
		'title' => $db->escape_string($lang->modnotice),
		'description' => $db->escape_string($lang->modnotice_desc),
		'disporder' => $rows+1,
		'isdefault' => 0
	);
	$gid = $db->insert_query("settinggroups", $insertarray);

	unset($insertarray);
	$insertarray = array(
		'name' => 'modnotice_pm',
		'title' => $db->escape_string($lang->modnotice_pm_title),
		'description' => $db->escape_string($lang->modnotice_pm_desc),
		'optionscode' => 'onoff',
		'value' => 1,
		'disporder' => 1,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

}

function modnotice_uninstall() {
	global $db;

	$db->delete_query("settinggroups", "name='modnotice'");
	$db->delete_query("settings", "name='modnotice_pm'");
	$db->write_query("ALTER TABLE `".TABLE_PREFIX."posts` DROP `modnotice` ,DROP `modedituid` ,DROP `modedittime`");
}

function modnotice_activate()
{
	global $db;

	$db->delete_query("templates", "title='postbit_moderator_edit'");
	$db->delete_query("templates", "title='editpost_moderate'");
	$db->delete_query("templates", "title='postbit_moderator_notice'");

	$insert_query[] = array("title" => "postbit_moderator_edit", "template" => $db->escape_string("<span class=\"pbbutton\"><a href=\"editpost.php?pid={\$post['pid']}\" id=\"edit_post_{\$post['pid']}\" title=\"{\$lang->postbit_edit}\"><i style=\"font-size: 14px;\" class=\"fa fa-pencil fa-fw\"></i> Edit</a></span>\n<div id=\"edit_post_{\$post['pid']}_popup\" class=\"popup_menu\" style=\"display: none;\"><div class=\"popup_item_container\"><a href=\"javascript:;\" class=\"popup_item quick_edit_button\" id=\"quick_edit_post_{\$post['pid']}\">{\$lang->postbit_quick_edit}</a></div><div class=\"popup_item_container\"><a href=\"editpost.php?pid={\$post['pid']}\" class=\"popup_item\">{\$lang->postbit_full_edit}</a></div><div class=\"popup_item_container\"><a href=\"modnotice.php?pid={\$post['pid']}\" class=\"popup_item\">{\$lang->postbit_post_moderate}</a></div></div>\n<script type=\"text/javascript\">\n// <!--\n	if(use_xmlhttprequest == \"1\")\n	{\n		\$(\"#edit_post_{\$post['pid']}\").popupMenu();\n	}\n// -->\n</script>"), "sid" => "-1");
	$insert_query[] = array("title" => "editpost_moderate", "template" => $db->escape_string("<html>\n<head>\n<title>{\$mybb->settings['bbname']} - {\$lang->edit_post}</title>\n{\$headerinclude}\n<script type=\"text/javascript\" src=\"jscripts/post.js?ver=1400\"></script>\n</head>\n<body>\n{\$header}\n{\$preview}\n{\$post_errors}\n{\$attacherror}\n<form action=\"modnotice.php?pid={\$pid}&amp;processed=1\" method=\"post\" enctype=\"multipart/form-data\" name=\"input\">\n<input type=\"hidden\" name=\"my_post_key\" value=\"{\$mybb->post_code}\" />\n<table border=\"0\" cellspacing=\"{\$theme['borderwidth']}\" cellpadding=\"{\$theme['tablespace']}\" class=\"tborder\">\n<tr>\n<td class=\"thead\" colspan=\"2\"><strong>{\$lang->edit_post}</strong></td>\n</tr>\n<tr>\n<td class=\"trow2\"><strong>{\$lang->subject}</strong></td>\n<td class=\"trow2\">{\$prefixselect}<input type=\"text\" class=\"textbox\" name=\"subject\" size=\"40\" maxlength=\"85\" value=\"{\$subject}\" tabindex=\"1\" /></td>\n</tr>\n{\$posticons}\n<tr>\n<td class=\"trow2\" width=\"235\" valign=\"top\"><strong>{\$lang->your_message}:</strong><br /><div style=\"text-align: center;\">{\$smilieinserter}</div></td>\n<td class=\"trow2\">\n<textarea name=\"message\" id=\"message\" rows=\"20\" cols=\"70\" tabindex=\"3\">{\$message}</textarea>\n{\$codebuttons}\n</td>\n</tr>\n<tr>\n<td class=\"trow2\" valign=\"top\">\n{\$lang->moderator_message}<br />\n<label><input type=\"checkbox\" class=\"checkbox\" name=\"remove\" value=\"yes\" tabindex=\"6\" {\$optionschecked['remove']} /> {\$lang->moderator_message_remove}</label>{\$send_pm}\n</td>\n<td class=\"trow2\">\n<textarea name=\"modnotice\" id=\"modnotice\" rows=\"4\" cols=\"70\" tabindex=\"3\">{\$modnotice}</textarea>\n</td>\n</tr>\n{\$subscriptionmethod}\n{\$pollbox}\n</table>\n{\$attachbox}\n<br />\n<div align=\"center\">\n<input type=\"submit\" class=\"button\" name=\"submit\" value=\"{\$lang->update_post}\" tabindex=\"3\" />\n<input type=\"submit\" class=\"button\" name=\"moderate_previewpost\" value=\"{\$lang->preview_post}\" tabindex=\"4\" /></div>\n<input type=\"hidden\" name=\"action\" value=\"mod_do_editpost\" />\n<input type=\"hidden\" name=\"pid\" value=\"{\$pid}\" />\n<input type=\"hidden\" name=\"posthash\" value=\"{\$posthash}\" />\n<input type=\"hidden\" name=\"attachmentaid\" value=\"\" />\n<input type=\"hidden\" name=\"attachmentact\" value=\"\" />\n</form>\n{\$footer}\n</body>\n</html>"), "sid" => "-1");
	$insert_query[] = array("title" => "postbit_moderator_notice", "template" => $db->escape_string("<br /><br />\n<table border=\"0\" width=\"100%\" cellspacing=\"{\$theme['borderwidth']}\" cellpadding=\"{\$theme['tablespace']}\">\n<tr>\n<td class=\"modnotice\">\n{\$mod_note}\n<br />\n<p>{\$notice}</p>\n</td>\n</tr>\n</table>\n"), "sid" => "-1");

	$db->insert_query_multiple("templates", $insert_query);
}

function modnotice_deactivate()
{
	global $db;

	$db->delete_query("templates", "title='postbit_moderator_edit'");
	$db->delete_query("templates", "title='editpost_moderate'");
	$db->delete_query("templates", "title='postbit_moderator_notice'");
}

function modnotice_mod_editbutton(&$post)
{
	global $db, $lang, $templates, $theme, $fid;

	$lang->load("modnotice");

	if(is_moderator($fid, "caneditposts"))
	{
		eval("\$post['button_edit'] = \"".$templates->get("postbit_moderator_edit")."\";");
	}
	
	return $post;
}

function modnotice_show_notice(&$post)
{
	global $templates, $parser, $lang, $db, $theme, $mybb;
	
	if(!is_object($parser))
    {
        require_once MYBB_ROOT.'inc/class_parser.php';
        $parser = new PostParser;
    }
	
	$lang->load("modnotice");

	if($post['modnotice']) {
		$result = $db->simple_select("users", "username, usergroup, displaygroup", "uid='".$post['modedituid']."'");
		$row = $db->fetch_array($result);

		$mod_editdate = my_date($mybb->settings['dateformat'], $post['modedittime']);
		$mod_edittime = my_date($mybb->settings['timeformat'], $post['modedittime']);
		$user = "<a href=\"".str_replace("{uid}", $post['modedituid'], PROFILE_URL)."\">".format_name($row['username'], $row['usergroup'], $row['displaygroup'])."</a>";
		$mod_note = sprintf($lang->postbit_post_moderator_message, $user, $mod_editdate, $mod_edittime);
		$parser_options = array(
				"allow_html" => 0,
				"allow_mycode" => 1,
				"allow_imgcode" => 0,
				"filter_badwords" => 1,
				"allow_smilies" => 1
		);

		$notice = $parser->parse_message($post['modnotice'], $parser_options);

		eval("\$post['message'] .= \"".$templates->get("postbit_moderator_notice")."\";");
	}

	return $post;
}
?>
