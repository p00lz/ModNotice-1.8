<?php
/*
 * ModNotice Plus 1.5.5
 * Author: Prtik
 * Copyright: © 2011-2014 Prtik
 *
 * $Id: modnotice.php 3991 2014-01-13 08:50:13Z Prtik $
 *
 * Using a separate datahandler for modnotice, based on post.php
 * - Garlant
 *
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

/**
 * Post handling class, provides common structure to handle post data.
 *
 */
class PostDataHandler extends DataHandler
{
	/**
	* The language file used in the data handler.
	*
	* @var string
	*/
	var $language_file = 'datahandler_moderate';

	/**
	* The prefix for the language variables used in the data handler.
	*
	* @var string
	*/
	var $language_prefix = 'postdata';

	/**
	 * Array of data used to update a post.
	 *
	 * @var array
	 */
	var $post_update_data = array();

	/**
	 * Post ID currently being manipulated by the datahandlers.
	 *
	 * @var int
	 */
	var $pid = 0;

	/**
	 * Thread ID currently being manipulated by the datahandlers.
	 *
	 * @var int
	 */
	var $tid = 0;

	/**
	 * Verifies the author of a post and fetches the username if necessary.
	 *
	 * @return boolean True if the author information is valid, false if invalid.
	 */
	function verify_author()
	{
		global $mybb;

		$post = &$this->data;

		// If we have a user id but no username then fetch the username.
		if($post['uid'] > 0 && $post['username'] == '')
		{
			$user = get_user($post['uid']);
			$post['username'] = $user['username'];
		}

		// Sanitize the username
		$post['username'] = htmlspecialchars_uni($post['username']);
		return true;
	}

	/**
	 * Verifies a post subject.
	 *
	 * @param string True if the subject is valid, false if invalid.
	 * @return boolean True when valid, false when not valid.
	 */
	function verify_subject()
	{
		global $db;
		$post = &$this->data;
		$subject = &$post['subject'];

		$subject = trim($subject);

		if(!$post['tid'])
		{
			$query = $db->simple_select("posts", "tid", "pid='".intval($post['pid'])."'");
			$post['tid'] = $db->fetch_field($query, "tid");
		}
		// Here we determine if we're editing the first post of a thread or not.
		$options = array(
			"limit" => 1,
			"limit_start" => 0,
			"order_by" => "dateline",
			"order_dir" => "asc"
		);
		$query = $db->simple_select("posts", "pid", "tid='".$post['tid']."'", $options);
		$first_check = $db->fetch_array($query);
		if($first_check['pid'] == $post['pid'])
		{
			$first_post = true;
		}
		else
		{
			$first_post = false;
		}
			// If this is the first post there needs to be a subject, else make it the default one.
		if(my_strlen(trim_blank_chrs($subject)) == 0 && $first_post)
		{
			$this->set_error("firstpost_no_subject");
			return false;
		}
		elseif(my_strlen($subject) == 0)
		{
			$thread = get_thread($post['tid']);
			$subject = "RE: ".$thread['subject'];
		}

	return true;
	}

	/**
	 * Verifies a post message.
	 *
	 * @param string The message content.
	 */
	function verify_message()
	{
		global $mybb;

		$post = &$this->data;
		$post['message'] = trim($post['message']);

		// Do we even have a message at all?
		if(my_strlen(trim_blank_chrs($post['message'])) == 0)
		{
			$this->set_error("missing_message");
			return false;
		}

		// If this board has a maximum message length check if we're over it. Use strlen because SQL limits are in bytes
		else if(strlen($post['message']) > $mybb->settings['maxmessagelength'] && $mybb->settings['maxmessagelength'] > 0 && !is_moderator($post['fid'], "", $post['uid']))
		{
			$this->set_error("message_too_long", array($mybb->settings['maxmessagelength'], strlen($post['message'])));
			return false;
		}

		// And if we've got a minimum message length do we meet that requirement too?
		else if(my_strlen($post['message']) < $mybb->settings['minmessagelength'] && $mybb->settings['minmessagelength'] > 0 && !is_moderator($post['fid'], "", $post['uid']))
		{
			$this->set_error("message_too_short", array($mybb->settings['minmessagelength']));
			return false;
		}
		return true;
	}

	/**
	 * Verifies the specified post options are correct.
	 *
	 * @return boolean True
	 */
	function verify_options()
	{
		$options = &$this->data['options'];

		// Verify yes/no options.
		$this->verify_yesno_option($options, 'signature', 0);
		$this->verify_yesno_option($options, 'disablesmilies', 0);

		return true;
	}

	/**
	* Verifies the image count.
	*
	* @return boolean True when valid, false when not valid.
	*/
	function verify_image_count()
	{
		global $mybb, $db;

		$post = &$this->data;

		// Get the permissions of the user who is making this post or thread
		$permissions = user_permissions($post['uid']);

		// Fetch the forum this post is being made in
		$forum = get_forum($post['fid']);

		// Check if this post contains more images than the forum allows
		if($post['savedraft'] != 1 && $mybb->settings['maxpostimages'] != 0 && $permissions['cancp'] != 1)
		{
			require_once MYBB_ROOT."inc/class_parser.php";
			$parser = new postParser;

			// Parse the message.
			$parser_options = array(
				"allow_html" => $forum['allowhtml'],
				"allow_mycode" => $forum['allowmycode'],
				"allow_imgcode" => $forum['allowimgcode'],
				"allow_videocode" => $forum['allowvideocode'],
				"filter_badwords" => 1
			);

			if($post['options']['disablesmilies'] != 1)
			{
				$parser_options['allow_smilies'] = $forum['allowsmilies'];
			}
			else
			{
				$parser_options['allow_smilies'] = 0;
			}

			$image_check = $parser->parse_message($post['message'], $parser_options);

			// And count the number of image tags in the message.
			$image_count = substr_count($image_check, "<img");
			if($image_count > $mybb->settings['maxpostimages'])
			{
				// Throw back a message if over the count with the number of images as well as the maximum number of images per post.
				$this->set_error("too_many_images", array(1 => $image_count, 2 => $mybb->settings['maxpostimages']));
				return false;
			}
		}
	}

	/**
	* Verify the reply-to post.
	*
	* @return boolean True when valid, false when not valid.
	*/
	function verify_reply_to()
	{
		global $db;
		$post = &$this->data;

		// Check if the post being replied to actually exists in this thread.
		if($post['replyto'])
		{
			$query = $db->simple_select("posts", "pid", "pid='".intval($post['replyto'])."'");
			$valid_post = $db->fetch_array($query);
			if(!$valid_post['pid'])
			{
				$post['replyto'] = 0;
			}
			else
			{
				return true;
			}
		}

		// If this post isn't a reply to a specific post, attach it to the first post.
		if(!$post['replyto'])
		{
			$options = array(
				"limit_start" => 0,
				"limit" => 1,
				"order_by" => "dateline",
				"order_dir" => "asc"
			);
			$query = $db->simple_select("posts", "pid", "tid='{$post['tid']}'", $options);
			$reply_to = $db->fetch_array($query);
			$post['replyto'] = $reply_to['pid'];
		}

		return true;
	}

	/**
	* Verify the post icon.
	*
	* @return boolean True when valid, false when not valid.
	*/
	function verify_post_icon()
	{
		global $cache;

		$post = &$this->data;

		// If we don't assign it as 0.
		if(!$post['icon'] || $post['icon'] < 0)
		{
			$post['icon'] = 0;
		}
		return true;
	}

	/**
	* Verify the dateline.
	*
	* @return boolean True when valid, false when not valid.
	*/
	function verify_dateline()
	{
		$dateline = &$this->data['dateline'];

		// The date has to be numeric and > 0.
		if($dateline < 0 || is_numeric($dateline) == false)
		{
			$dateline = TIME_NOW;
		}
	}

	/**
	 * Validate the Mod Message
	 */
	function verify_modnotice () {
		$post = &$this->data;

		if (trim($post['modnotice']) != '')
		{
			$post['modnotice'] = trim($post['modnotice']);
			return true;
		}

		$this->set_error("message_empty_modnotice");
		return false;
	}

	/**
	 * Validate a post.
	 *
	 * @return boolean True when valid, false when invalid.
	 */
	function validate_post()
	{
		global $mybb, $db, $plugins;

		$post = &$this->data;
		$time = TIME_NOW;

		$this->action = "post";

		// Verify all post assets.

		if( array_key_exists('uid', $post))
		{
			$this->verify_author();
		}

		if(array_key_exists('subject', $post))
		{
			$this->verify_subject();
		}

		if(array_key_exists('message', $post))
		{
			$this->verify_message();
			$this->verify_modnotice();
			$this->verify_image_count();
		}

		if(array_key_exists('dateline', $post))
		{
			$this->verify_dateline();
		}

		if(array_key_exists('icon', $post))
		{
			$this->verify_post_icon();
		}

		if(array_key_exists('options', $post))
		{
			$this->verify_options();
		}

		if(array_key_exists('replyto', $post))
		{
			$this->verify_reply_to();
		}

		$plugins->run_hooks("datahandler_moderate_validate_post", $this);

		// We are done validating, return.
		$this->set_validated(true);
		if(count($this->get_errors()) > 0)
		{
			return false;
		}
		else
		{
			return true;
		}
	}

	/**
	 * Updates a post that is already in the database.
	 *
	 */
	function update_post()
	{
		global $db, $mybb, $plugins;

		// Yes, validating is required.
		if($this->get_validated() != true)
		{
			die("The post needs to be validated before inserting it into the DB.");
		}
		if(count($this->get_errors()) > 0)
		{
			die("The post is not valid.");
		}

		$post = &$this->data;

		$post['pid'] = intval($post['pid']);

		$existing_post = get_post($post['pid']);
		$post['tid'] = $existing_post['tid'];

		// take the original uid of the author
		$post['uid'] = $existing_post['uid'];

		$forum = get_forum($post['fid']);
		
		
		// Decide on the visibility of this post.
		if(isset($post['visible']) && $post['visible'] != $existing_post['visible'])
        {
            if($forum['mod_edit_posts'] == 1 && !is_moderator($post['fid'], "", $post['uid']))
            {
                if($existing_post['visible'] == 1)
                {
                    update_thread_data($existing_post['tid']);
                    update_thread_counters($existing_post['tid'], array('replies' => '-1', 'unapprovedposts' => '+1'));
                    update_forum_counters($existing_post['fid'], array('unapprovedthreads' => '+1', 'unapprovedposts' => '+1'));
                    
                    // Subtract from the users post count
                    // Update the post count if this forum allows post counts to be tracked
                    if($forum['usepostcounts'] != 0)
                    {
                        $db->write_query("UPDATE ".TABLE_PREFIX."users SET postnum=postnum-1 WHERE uid='{$existing_post['uid']}'");
                    }
                }
                $visible = 0;
            }
            else
            {
                if($existing_post['visible'] == 0)
                {
                    update_thread_data($existing_post['tid']);
                    update_thread_counters($existing_post['tid'], array('replies' => '+1', 'unapprovedposts' => '-1'));
                    update_forum_counters($existing_post['fid'], array('unapprovedthreads' => '-1', 'unapprovedposts' => '-1'));
                    
                    // Update the post count if this forum allows post counts to be tracked
                    if($forum['usepostcounts'] != 0)
                    {
                        $db->write_query("UPDATE ".TABLE_PREFIX."users SET postnum=postnum+1 WHERE uid='{$existing_post['uid']}'");
                    }
                }
                $visible = 1;
            }
        }
        else
        {
			$visible = 0;
			if($forum['mod_edit_posts'] != 1 || is_moderator($post['fid'], "", $post['uid']))
			{
				$visible = 1;
			}
        }

		// Check if this is the first post in a thread.
		$options = array(
			"order_by" => "dateline",
			"order_dir" => "asc",
			"limit_start" => 0,
			"limit" => 1
		);
		$query = $db->simple_select("posts", "pid", "tid='".intval($post['tid'])."'", $options);
		$first_post_check = $db->fetch_array($query);
		if($first_post_check['pid'] == $post['pid'])
		{
			$first_post = true;
		}
		else
		{
			$first_post = false;
		}
		
		if($existing_post['visible'] == 0)
		{
			$visible = 0;
		}
		
		// Update the thread details that might have been changed first.
		if($first_post)
		{			
			$this->tid = $post['tid'];

			$this->thread_update_data['visible'] = $visible;
			
			if(isset($post['prefix']))
			{
				$this->thread_update_data['prefix'] = intval($post['prefix']);
			}

			if(isset($post['subject']))
			{
				$this->thread_update_data['subject'] = $db->escape_string($post['subject']);
			}

			if(isset($post['icon']))
			{
				$this->thread_update_data['icon'] = intval($post['icon']);
			}
			if(count($this->thread_update_data) > 0)
			{
				$plugins->run_hooks("datahandler_post_update_thread", $this);

				$db->update_query("threads", $this->thread_update_data, "tid='".intval($post['tid'])."'");
			}
		}
		
		
		$this->pid = $post['pid'];

		$this->post_update_data['uid'] = $post['uid'];

		if(isset($post['subject']))
		{
			$this->post_update_data['subject'] = $db->escape_string($post['subject']);
		}

		if(isset($post['message']))
		{
			$this->post_update_data['message'] = $db->escape_string($post['message']);
		}

		if(isset($post['icon']))
		{
			$this->post_update_data['icon'] = intval($post['icon']);
		}
		if(isset($post['options']['remove_notice'])) {
			$this->post_update_data['modnotice'] = '';
			$this->post_update_data['modedituid'] = 0;
			$this->post_update_data['modedittime'] = 0;
		}
		else {
			if(isset($post['modnotice'])) {
				$this->post_update_data['modnotice'] = $db->escape_string($post['modnotice']);
				$this->post_update_data['modedituid'] = $mybb->user['uid'];
				$this->post_update_data['modedittime'] = time();
			}
		}

		$plugins->run_hooks("datahandler_moderate_update", $this);

		$db->update_query("posts", $this->post_update_data, "pid='".intval($post['pid'])."'");

		return array(
				'first_post' => $first_post
			);
	}
}
?>