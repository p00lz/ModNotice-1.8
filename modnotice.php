<?php
/**
 * ModNotice Plus 1.5.5
 * Author: Prtik
 * Copyright: © 2011-2014 Prtik
 *
 * $Id: modnotice.php 3991 2014-01-13 08:50:13Z Prtik $
 */

define("IN_MYBB", 1);

$templatelist = "moderate_editpost,previewpost,redirect_postedited,posticons,attachment,posticons,codebuttons,smilieinsert,post_attachments_attachment_postinsert,post_attachments_attachment_mod_approve,post_attachments_attachment_unapproved,post_attachments_attachment_mod_unapprove,post_attachments_attachment,post_attachments_new,post_attachments,newthread_postpoll,editpost_disablesmilies";

require_once "./global.php";
require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_upload.php";
require_once MYBB_ROOT."inc/functions_user.php";

// Load global language phrases
$lang->load("modnotice");

// No permission for guests
if(!$mybb->user['uid'])
{
	error_no_permission();
}

// Get post info
$pid = intval($mybb->input['pid']);

// if we already have the post information...
if(isset($style) && $style['pid'] == $pid)
{
	$post = &$style;
}
else
{
	$query = $db->simple_select("posts", "*", "pid='$pid'");
	$post = $db->fetch_array($query);
}

if(!$post['pid'])
{
	error($lang->error_invalidpost);
}

// Get thread info
$tid = $post['tid'];
$thread = get_thread($tid);

if(!$thread['tid'])
{
	error($lang->error_invalidthread);
}

$thread['subject'] = htmlspecialchars_uni($thread['subject']);

// Get forum info
$fid = $post['fid'];
$forum = get_forum($fid);
if(!$forum || $forum['type'] != "f")
{
	error($lang->error_closedinvalidforum);
}
if($forum['open'] == 0 || $mybb->user['suspendposting'] == 1 || !is_moderator($fid, "caneditposts"))
{
	error_no_permission();
}

// Add prefix to breadcrumb
$query = $db->simple_select('threadprefixes', 'displaystyle', "pid='{$thread['prefix']}'");
$breadcrumbprefix = $db->fetch_field($query, 'displaystyle');

if($breadcrumbprefix)
{
	$breadcrumbprefix .= '&nbsp;';
}

// Make navigation
build_forum_breadcrumb($fid);
add_breadcrumb($breadcrumbprefix.$thread['subject'], get_thread_link($thread['tid']));
add_breadcrumb($lang->nav_editpost);

$forumpermissions = forum_permissions($fid);


if($mybb->settings['bbcodeinserter'] != 0 && $forum['allowmycode'] != 0 && $mybb->user['showcodebuttons'] != 0)
{
	$codebuttons = build_mycode_inserter();
}
if($mybb->settings['smilieinserter'] != 0)
{
	$smilieinserter = build_clickable_smilies();
}

if(!$mybb->input['action'] || $mybb->input['moderate_previewpost'])
{
	$mybb->input['action'] = "moderate";
}


if(!is_moderator($fid, "caneditposts"))
{
	error_no_permission();
}


// Show Post Notice to Autor
$send_pm = "";

if($mybb->request_method != "post")
{
	$optionschecked['send_pm'] = " checked=\"checked\"";
}




// Check if this forum is password protected and we have a valid password
check_forum_password($forum['fid']);

if((empty($_POST) && empty($_FILES)) && $mybb->input['processed'] == '1')
{
	error($lang->error_cannot_upload_php_post);
}

if(!$mybb->input['attachmentaid'] && ($mybb->input['newattachment'] || ($mybb->input['action'] == "mod_do_editpost" && $mybb->input['submit'] && $_FILES['attachment'])))
{
	// If there's an attachment, check it and upload it
	if($_FILES['attachment']['size'] > 0 && $forumpermissions['canpostattachments'] != 0)
	{
		$attachedfile = upload_attachment($_FILES['attachment']);
	}
	if($attachedfile['error'])
	{
		eval("\$attacherror = \"".$templates->get("error_attacherror")."\";");
		$mybb->input['action'] = "moderate";
	}
	if(!$mybb->input['submit'])
	{
		$mybb->input['action'] = "moderate";
	}
}


if($mybb->input['attachmentaid'] && isset($mybb->input['attachmentact']) && $mybb->input['action'] == "mod_do_editpost" && $mybb->request_method == "post") // Lets remove/approve/unapprove the attachment
{
	$mybb->input['attachmentaid'] = intval($mybb->input['attachmentaid']);
	if($mybb->input['attachmentact'] == "remove" && $mybb->input['posthash'])
	{
		remove_attachment($pid, $mybb->input['posthash'], $mybb->input['attachmentaid']);
	}
	elseif($mybb->input['attachmentact'] == "approve" && is_moderator($fid, 'caneditposts'))
	{
		$update_sql = array("visible" => 1);
		$db->update_query("attachments", $update_sql, "aid='{$mybb->input['attachmentaid']}'");
	}
	elseif($mybb->input['attachmentact'] == "unapprove" && is_moderator($fid, 'caneditposts'))
	{
		$update_sql = array("visible" => 0);
		$db->update_query("attachments", $update_sql, "aid='{$mybb->input['attachmentaid']}'");
	}
	if(!$mybb->input['submit'])
	{
		$mybb->input['action'] = "moderate";
	}
}


if($mybb->input['action'] == "mod_do_editpost" && $mybb->request_method == "post")
{
	// Verify incoming POST request
	verify_post_check($mybb->input['my_post_key']);

	$plugins->run_hooks("editpost_do_editpost_start");

	// Set up posthandler.
	require_once MYBB_ROOT."inc/datahandlers/moderate.php";
	$posthandler = new PostDataHandler();

	// Set the post data that came from the input to the $post array.
	$post = array(
		"pid" => $mybb->input['pid'],
		"prefix" => $mybb->input['threadprefix'],
		"subject" => $mybb->input['subject'],
		"icon" => $mybb->input['icon'],
		"uid" => $mybb->user['uid'],
		"username" => $mybb->user['username'],
		"edit_uid" => $mybb->user['uid'],
		"message" => $mybb->input['message'],
		"modnotice" => $mybb->input['modnotice']
	);

	$post['options'] = array(
		'remove_notice' => $mybb->input['remove']
	);

	$posthandler->set_data($post);

	// Now let the post handler do all the hard work.
	if(!$posthandler->validate_post())
	{
		$post_errors = $posthandler->get_friendly_errors();
		$post_errors = inline_error($post_errors);
		$mybb->input['action'] = "moderate";
	}
	// No errors were found, we can call the update method.
	else
	{
		if($mybb->input['remove'])
		{
			$optionschecked['remove'] = " checked=\"checked\"";
		}

		if($mybb->input['send_pm'])
		{
			$optionschecked['send_pm'] = " checked=\"checked\"";
		}

		$postinfo = $posthandler->update_post();
		$first_post = $postinfo['first_post'];

		// Help keep our attachments table clean.
		$db->delete_query("attachments", "filename='' OR filesize<1");

		// Did the user choose to post a poll? Redirect them to the poll posting page.
		if($mybb->input['postpoll'] && $forumpermissions['canpostpolls'])
		{
			$url = "polls.php?action=newpoll&tid=$tid&polloptions=".$mybb->input['numpolloptions'];
			$lang->redirect_postedited = $lang->redirect_postedited_poll;
		}
		else
		{
			$lang->redirect_postedited .= $lang->redirect_postedited_redirect;
			$url = get_post_link($pid, $tid)."#pid{$pid}";

			$query = $db->simple_select("posts", "uid,username,subject", "pid={$pid} AND tid={$tid}");
			$row = $db->fetch_array($query);

			$posturl = $mybb->settings['bburl']."/".$url;
			$message = sprintf($lang->modnotice_PM_message, $row['username'], $db->escape_string($posturl), $row['subject']);

			// sent a pm to the author of this post - temp solution
			// if($mybb->settings['modnotice_pm'] == 1) {
			if($mybb->input['send_pm']) {
			
				// $privatemessage = array(
					// 'fromid' => 0,
					// 'toid' => $row['uid'],
					// 'uid' => $row['uid'],
					// 'folder' => 1,
					// 'subject' => $db->escape_string($lang->modnotice_PM_subject),
					// 'icon' => 0,
					// 'message' => $db->escape_string($message),
					// 'dateline' => TIME_NOW,
					// 'status' => 0,
					// 'includesig' => 0,
					// 'smilieoff' => 0,
					// 'receipt' => 0,
					// 'readtime' => 0,
				// );
				
				// $db->insert_query("privatemessages", $privatemessage);
				// update_pm_count($row['uid'], 7);
				
				$lang->load('messages');
				
				require_once MYBB_ROOT."inc/datahandlers/pm.php";
				
				$pmhandler = new PMDataHandler();
				
				$fromid = 0;
				$toid = $row['uid'];
				
				if (is_array($toid))
					$recipients_to = $toid;
				else
					$recipients_to = array($toid);
					
				$recipients_bcc = array();
				
				if (intval($fromid) == 0)
					$fromid = intval($mybb->user['uid']);
				elseif (intval($fromid) < 0)
					$fromid = 0;
				
				$pm = array(
					"subject" => $db->escape_string($lang->modnotice_PM_subject),
					"message" => $message,
					"icon" => -1,
					"fromid" => $fromid,
					"toid" => $recipients_to,
					"bccid" => $recipients_bcc,
					"do" => '',
					"pmid" => ''
				);
				
				$pm['options'] = array(
					"signature" => 0,
					"disablesmilies" => 0,
					"savecopy" => 0,
					"readreceipt" => 0
				);
				
				$pm['saveasdraft'] = 0;
				$pmhandler->admin_override = 1;
				$pmhandler->set_data($pm);
				if($pmhandler->validate_pm())
				{
					$pmhandler->insert_pm();
				}
			}
		}
		$plugins->run_hooks("editpost_do_editpost_end");

		redirect($url, $lang->redirect_postedited);
	}
}

if(!$mybb->input['action'] || $mybb->input['action'] == "moderate")
{
	$plugins->run_hooks("editpost_start");

	if(!$mybb->input['moderate_previewpost'])
	{
		$icon = $post['icon'];
	}

	if($forum['allowpicons'] != 0)
	{
		$posticons = get_post_icons();
	}

	// Setup a unique posthash for attachment management
	$posthash = $post['posthash'];

	$bgcolor = "trow1";
	if($forumpermissions['canpostattachments'] != 0)
	{ // Get a listing of the current attachments, if there are any
		$attachcount = 0;
		if($posthash)
		{
			$posthash_query = "posthash='{$posthash}' OR ";
		}
		else
		{
			$posthash_query = "";
		}
		$query = $db->simple_select("attachments", "*", "{$posthash_query}pid='{$pid}'");
		$attachments = '';
		while($attachment = $db->fetch_array($query))
		{
			$attachment['size'] = get_friendly_size($attachment['filesize']);
			$attachment['icon'] = get_attachment_icon(get_extension($attachment['filename']));
			if($mybb->settings['bbcodeinserter'] != 0 && $forum['allowmycode'] != 0 && (!$mybb->user['uid'] || $mybb->user['showcodebuttons'] != 0))
			{
				eval("\$postinsert = \"".$templates->get("post_attachments_attachment_postinsert")."\";");
			}
			// Moderating options
			$attach_mod_options = '';
			if(is_moderator($fid))
			{
				if($attachment['visible'] == 1)
				{
					eval("\$attach_mod_options = \"".$templates->get("post_attachments_attachment_mod_unapprove")."\";");
				}
				else
				{
					eval("\$attach_mod_options = \"".$templates->get("post_attachments_attachment_mod_approve")."\";");
				}
			}
			if($attachment['visible'] != 1)
			{
				eval("\$attachments .= \"".$templates->get("post_attachments_attachment_unapproved")."\";");
			}
			else
			{
				eval("\$attachments .= \"".$templates->get("post_attachments_attachment")."\";");
			}
			$attachcount++;
		}

		$query = $db->simple_select("attachments", "SUM(filesize) AS ausage", "uid='".$mybb->user['uid']."'");
		$usage = $db->fetch_array($query);
		if($usage['ausage'] > ($mybb->usergroup['attachquota']*1024) && $mybb->usergroup['attachquota'] != 0)
		{
			$noshowattach = 1;
		}
		if($mybb->usergroup['attachquota'] == 0)
		{
			$friendlyquota = $lang->unlimited;
		}
		else
		{
			$friendlyquota = get_friendly_size($mybb->usergroup['attachquota']*1024);
		}
		$friendlyusage = get_friendly_size($usage['ausage']);
		$lang->attach_quota = $lang->sprintf($lang->attach_quota, $friendlyusage, $friendlyquota);
		if($mybb->settings['maxattachments'] == 0 || ($mybb->settings['maxattachments'] != 0 && $attachcount < $mybb->settings['maxattachments']) && !$noshowattach)
		{
			eval("\$newattach = \"".$templates->get("post_attachments_new")."\";");
		}
		eval("\$attachbox = \"".$templates->get("post_attachments")."\";");
	}
	if(!$mybb->input['attachmentaid'] && !$mybb->input['newattachment'] && !$mybb->input['moderate_previewpost'] && !$maximageserror)
	{
		$message = $post['message'];
		$subject = $post['subject'];
		$modnotice = $post['modnotice'];
	}
	else
	{
		$message = $mybb->input['message'];
		$subject = $mybb->input['subject'];
		$modnotice = $mybb->input['modnotice'];
	}
	if($mybb->input['moderate_previewpost'] || $post_errors)
	{
		// Set up posthandler.
		require_once MYBB_ROOT."inc/datahandlers/moderate.php";
		$posthandler = new PostDataHandler();

		// Set the post data that came from the input to the $post array.
		$post = array(
			"pid" => $mybb->input['pid'],
			"prefix" => $mybb->input['threadprefix'],
			"subject" => $mybb->input['subject'],
			"icon" => $mybb->input['icon'],
			"uid" => $post['uid'],
			"edit_uid" => $mybb->user['uid'],
			"message" => $mybb->input['message'],
			"modnotice" => $mybb->input['modnotice']
		);

		$post['options'] = array(
			'remove_notice' => $mybb->input['remove']
		);
		
		if(!$mybb->input['moderate_previewpost'])
		{
			$post['uid'] = $mybb->user['uid'];
			$post['username'] = $mybb->user['username'];
		}

		$posthandler->set_data($post);

		// Now let the post handler do all the hard work.
		if(!$posthandler->validate_post())
		{
			$post_errors = $posthandler->get_friendly_errors();
			$post_errors = inline_error($post_errors);
			$mybb->input['action'] = "moderate";
			$mybb->input['moderate_previewpost'] = 0;
			
			if($mybb->input['send_pm'])
			{
				$optionschecked['send_pm'] = " checked=\"checked\"";
			}
		}
		else
		{
			$previewmessage = $message;
			$previewsubject = $subject;
			$previewmodnotice = $modnotice;
			$message = htmlspecialchars_uni($message);
			$subject = htmlspecialchars_uni($subject);
			$modnotice = htmlspecialchars_uni($modnotice);

			if($mybb->input['remove'])
			{
				$optionschecked['remove'] = " checked=\"checked\"";
			}
			
			if($mybb->input['send_pm'])
			{
				$optionschecked['send_pm'] = " checked=\"checked\"";
			}
		}
	}

	if($mybb->input['moderate_previewpost'])
	{
		// Figure out the poster's other information.
		$query = $db->query("
			SELECT u.*, f.*, p.dateline
			FROM ".TABLE_PREFIX."users u
			LEFT JOIN ".TABLE_PREFIX."userfields f ON (f.ufid=u.uid)
			LEFT JOIN ".TABLE_PREFIX."posts p ON (p.uid=u.uid)
			WHERE u.uid='{$post['uid']}' AND p.pid='{$pid}'
			LIMIT 1
		");
		$postinfo = $db->fetch_array($query);

		$query = $db->simple_select("attachments", "*", "pid='{$pid}'");
		while($attachment = $db->fetch_array($query))
		{
			$attachcache[0][$attachment['aid']] = $attachment;
		}

		// Set the values of the post info array.
		$postinfo['userusername'] = $postinfo['username'];
		$postinfo['message'] = $previewmessage;
		$postinfo['modnotice'] = $previewmodnotice;
		$postinfo['subject'] = $previewsubject;
		$postinfo['icon'] = $icon;

		$postbit = build_postbit($postinfo, 1);
		eval("\$preview = \"".$templates->get("previewpost")."\";");
	}
	else if(!$post_errors)
	{
		if($post['remove'])
		{
			$optionschecked['remove'] = " checked=\"checked\"";
		}
		
		
		$modnotice = htmlspecialchars_uni($modnotice);
		$message = htmlspecialchars_uni($message);
		$subject = htmlspecialchars_uni($subject);
	}

	// Generate thread prefix selector if this is the first post of the thread
	if($thread['firstpost'] == $pid)
	{
		if(!intval($mybb->input['threadprefix']))
		{
			$mybb->input['threadprefix'] = $thread['prefix'];
		}

		$prefixselect = build_prefix_select($forum['fid'], $mybb->input['threadprefix']);
	}
	else
	{
		$prefixselect = "";
	}

	// Fetch subscription select box
	// $bgcolor = "trow1";
	// $bgcolor2 = "trow2";
	$bgcolor = "trow1";
	eval("\$subscriptionmethod = \"".$templates->get("post_subscription_method")."\";");

	$bgcolor2 = "trow2";
	$query = $db->simple_select("posts", "*", "tid='{$tid}'", array("limit" => 1, "order_by" => "dateline", "order_dir" => "asc"));
	$firstcheck = $db->fetch_array($query);
	if($firstcheck['pid'] == $pid && $forumpermissions['canpostpolls'] != 0 && $thread['poll'] < 1)
	{
		$lang->max_options = $lang->sprintf($lang->max_options, $mybb->settings['maxpolloptions']);
		$numpolloptions = "2";
		eval("\$pollbox = \"".$templates->get("newthread_postpoll")."\";");
	}

	// Can we disable smilies or are they disabled already?
	if($forum['allowsmilies'] != 0)
	{
		eval("\$disablesmilies = \"".$templates->get("editpost_disablesmilies")."\";");
	}
	else
	{
		$disablesmilies = "<input type=\"hidden\" name=\"postoptions[disablesmilies]\" value=\"no\" />";
	}

	$plugins->run_hooks("editpost_end");
	
	if($mybb->settings['modnotice_pm'] == 1)
	{
		$send_pm = "<br />\n<label><input type=\"checkbox\" class=\"checkbox\" name=\"send_pm\" value=\"yes\" tabindex=\"7\" " . $optionschecked['send_pm'] . " /> " . $lang->moderator_message_send_pm . "</label>";
	}
	
	eval("\$editpost = \"".$templates->get("editpost_moderate")."\";");
	output_page($editpost);
}
?>