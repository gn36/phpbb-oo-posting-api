<?php

namespace gn36\functions_post_oo;

include_once($phpbb_root_path . 'includes/functions_content.' . $phpEx);
include_once(__DIR__ . '/syncer.' . $phpEx);
include_once(__DIR__ . '/topic.' . $phpEx);

class post
{
	var $post_id;
	var $topic_id;
	var $forum_id;

	var $poster_id;
	var $post_username = '';
	var $poster_ip;

	var $icon_id = 0;
	var $post_time;
	var $post_postcount = 1;
	var $post_visibility = ITEM_APPROVED;
	var $post_reported = 0;

	var $enable_bbcode = 1;
	var $enable_smilies = 1;
	var $enable_magic_url = 1;
	var $enable_sig = 1;

	var $post_subject = '';
	var $post_text = '';

	var $post_edit_time = 0;
	var $post_edit_reason = '';
	var $post_edit_user = 0;
	var $post_edit_count = 0;
	var $post_edit_locked = 0;

	var $post_delete_time = 0;
	var $post_delete_reason = '';
	var $post_delete_user = 0;

	var $_topic;

	var $post_attachment = 0;
	var $attachments = array();

	function __construct($topic_id = NULL, $post_text = '')
	{
		$this->topic_id = $topic_id;
		$this->post_text = $post_text;
	}

	/**
	 * Load post from database. Returns false, if the post doesn't exist
	 * @param int $post_id
	 * @return boolean|\gn36\functions_post_oo\post
	 */
	static function get($post_id)
	{
		global $db;
		//$sql = "SELECT p.*, t.topic_first_post_id, t.topic_last_post_id
		//		FROM " . POSTS_TABLE . " p, " . TOPICS_TABLE . " t
		//		WHERE p.post_id=" . intval($this->post_id) . " AND t.topic_id = p.topic_id";
		$sql = "SELECT * FROM " . POSTS_TABLE . " WHERE post_id=" . intval($post_id);
		$result = $db->sql_query($sql);
		$post_data = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if(!$post_data)
		{
			//post does not exist, return false
			return false;
		}


		return post::from_array($post_data);
	}

	/**
	 * Generate post from array data. Data is assumed to be loaded from database, thus the message is decoded.
	 *
	 * @param array $post_data
	 * @return \gn36\functions_post_oo\post
	 */
	static function from_array($post_data)
	{
		global $db;

		if(!is_array($post_data))
		{
			trigger_error('post::from_array - $post_data not an array');
		}

		//create object and fill in data
		$post = new post();
		$post->post_id = $post_data['post_id'];
		$post->topic_id = $post_data['topic_id'];
		$post->forum_id = $post_data['forum_id'];

		$post->poster_id = $post_data['poster_id'];
		$post->post_username = $post_data['post_username'];
		$post->poster_ip = $post_data['poster_ip'];

		$post->icon_id = $post_data['icon_id'];
		$post->post_time = $post_data['post_time'];
		$post->post_postcount = $post_data['post_postcount'];
		$post->post_visibility = $post_data['post_visibility'];
		$post->post_reported = $post_data['post_reported'];

		$post->enable_bbcode = $post_data['enable_bbcode'];
		$post->enable_smilies = $post_data['enable_smilies'];
		$post->enable_magic_url = $post_data['enable_magic_url'];
		$post->enable_sig = $post_data['enable_sig'];

		$post->post_subject = $post_data['post_subject'];
		$post->post_attachment = $post_data['post_attachment'];

		$post->post_edit_time = $post_data['post_edit_time'];
		$post->post_edit_reason = $post_data['post_edit_reason'];
		$post->post_edit_user = $post_data['post_edit_user'];
		$post->post_edit_count = $post_data['post_edit_count'];

		$post->post_delete_reason = $post_data['post_delete_reason'];
		$post->post_delete_time = $post_data['post_delete_time'];
		$post->post_delete_user = $post_data['post_delete_user'];

		//check first/last post
		//$this->_is_first_post = ($post_data['post_id'] == $post_data['topic_first_post_id']);
		//$this->_is_last_post = ($post_data['post_id'] == $post_data['topic_last_post_id']);

		//parse message
		decode_message($post_data['post_text'], $post_data['bbcode_uid']);
		$post_data['post_text'] = str_replace(array('&#58;', '&#46;'), array(':', '.'), $post_data['post_text']);
		$post->post_text = $post_data['post_text'];

		//attachments
		if($post->post_attachment)
		{
			$sql = "SELECT * FROM " . ATTACHMENTS_TABLE . " WHERE post_msg_id=" . $post->post_id;
			$result = $db->sql_query($sql);
			while($attach_row = $db->sql_fetchrow($result))
			{
				$post->attachments[] = attachment::from_array($attach_row);
			}
		}

		return $post;
	}

	/**
	 * loads and returns the topic for this post
	 *
	 * @param bool all_posts whether to load all other posts of the topic into topic->posts
	 *
	 */
	function get_topic($all_posts = false)
	{
		if(!$this->_topic)
		{
			if($this->post_id)
			{
				//existing post, load existing topic
				$this->_topic = topic::get($this->topic_id, $all_posts);

				//insert $this into topic->posts array
				if($all_posts)
				{
					//this post was also loaded from database, replace it with $this
					for($i=0; $i<sizeof($this->_topic->posts); $i++)
					{
						if($this->_topic->posts[$i]->post_id == $this->post_id)
						{
							//found it
							$this->_topic->posts[$i] = &$this;
							break;
						}
					}
				}
				else
				{
					//no posts were loaded in topic::get(), add our post to topic->posts
					$this->_topic->posts[] = &$this;
				}
			}
			else
			{
				//new post, generate topic
				$this->_topic = topic::from_post($this);
			}
		}
		return $this->_topic;
	}

	/** sets the following variables based on the permissions of $this->poster_id:
	 * post_postcount, post_approved
	 * enable_bbcode, enable_smilies, enable_magic_url, enable_sig
	 * img_status, flash_status, quote_status
	 * by default (if you never call this function) all variables are set to 1 (allowed)
	 * note that this does not check whether the user can post at all - use validate() for that.
	 * @todo */
	function apply_permissions()
	{
		//TODO
	}

	/**checks if $this->poster_id has the permissions required to submit this post.
	 * note that calling this does not change the behaviour of submit()*/
	function validate()
	{
		// ?? $this->apply_permissions();
		//TODO
	}

	/**returns the html representation of this post*/
	function display_format()
	{
		//TODO
	}

	/**
	 * Merge post into topic. Use move_posts directly for merging multiple posts
	 * @param integer $topic_id
	 */
	function merge_into($topic_id)
	{
		move_posts($this->post_id, $topic_id);
		$this->topic_id = $topic_id;
		$this->_topic = null;
	}

	/**
	 * Submits the post
	 */
	function submit()
	{
		$sync = new syncer();
		$this->submit_without_sync($sync);
		$sync->execute();
	}

	/**create/update this post in the database
	 * @param $sync used internally by topic->submit() */
	//TODO
	function submit_without_sync(\gn36\functions_post_oo\syncer &$sync)
	{
		global $config, $db, $auth, $user;

		if(!$this->post_id)
		{
			//new post, set some default values if not set yet
			if(!$this->poster_id) $this->poster_id = $user->data['user_id'];
			if(!$this->poster_ip) $this->poster_ip = $user->ip;
			if(!$this->post_time) $this->post_time = time();
		}

		$this->post_subject = truncate_string($this->post_subject);


		$sql_data = array(
			'poster_id' 		=> $this->poster_id,
			'poster_ip' 		=> $this->poster_ip,
			'topic_id'			=> $this->topic_id,
			'forum_id'			=> $this->forum_id,
			'post_username'		=> $this->post_username,
			'icon_id'			=> $this->icon_id,
			'post_time'			=> $this->post_time,
			'post_postcount'	=> $this->post_postcount ? 1 : 0,
			'post_visibility'	=> $this->post_visibility,
			'post_reported'		=> $this->post_reported ? 1 : 0,
			'enable_bbcode'		=> $this->enable_bbcode ? 1 : 0,
			'enable_smilies'	=> $this->enable_smilies ? 1 : 0,
			'enable_magic_url'	=> $this->enable_magic_url ? 1 : 0,
			'enable_sig'		=> $this->enable_sig ? 1 : 0,
			'post_subject'		=> $this->post_subject,
			'bbcode_bitfield'	=> 0,
			'bbcode_uid'		=> '',
			'post_text'			=> $this->post_text,
			'post_checksum'		=> md5($this->post_text),
			//'post_attachment'	=> $this->post_attachment ? 1 : 0,
			'post_edit_time'	=> $this->post_edit_time,
			'post_edit_reason'	=> $this->post_edit_reason,
			'post_edit_user'	=> $this->post_edit_user,
			'post_edit_count'	=> $this->post_edit_count,
			'post_edit_locked'	=> $this->post_edit_locked,
			'post_delete_time' 	=> $this->post_delete_time,
			'post_delete_reason' => $this->post_delete_reason,
			'post_delete_user' 	=> $this->post_delete_user,
		);

		$flags = '';
		generate_text_for_storage($sql_data['post_text'], $sql_data['bbcode_uid'], $sql_data['bbcode_bitfield'], $flags, $this->enable_bbcode, $this->enable_magic_url, $this->enable_smilies);

		if($this->post_id && $this->topic_id)
		{
			//edit
			$sql = "SELECT p.*, t.topic_first_post_id, t.topic_last_post_id, t.topic_approved, t.topic_replies
					FROM " . POSTS_TABLE . " p
					LEFT JOIN " . TOPICS_TABLE . " t ON (t.topic_id = p.topic_id)
					WHERE p.post_id=" . intval($this->post_id);
			//$sql = "SELECT * FROM " . POSTS_TABLE . " WHERE post_id=" . intval($this->post_id);
			$result = $db->sql_query($sql);
			$post_data = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);

			if(!$post_data)
			{
				trigger_error("post_id={$this->post_id}, but that post does not exist", E_USER_ERROR);
			}

			//check first/last post
			$is_first_post = ($post_data['post_id'] == $post_data['topic_first_post_id']);
			$is_last_post = ($post_data['post_id'] == $post_data['topic_last_post_id']);

			$db->sql_transaction('begin');

			$sql = "UPDATE " . POSTS_TABLE . " SET " . $db->sql_build_array('UPDATE', $sql_data) . " WHERE post_id=" . $this->post_id;
			$db->sql_query($sql);

			if($this->topic_id != $post_data['topic_id'])
			{
				//merge into new topic
				//get new topic's forum id and first/last post time
				$sql = "SELECT forum_id, topic_time, topic_last_post_time
						FROM " . TOPICS_TABLE . "
						WHERE topic_id = {$this->topic_id}";
				$result = $db->sql_query($sql);
				$new_topic_data = $db->sql_fetchrow($result);
				if(!$new_topic_data)
				{
					trigger_error("attempted to merge post {$this->post_id} into topic {$this->topic_id}, but that topic does not exist", E_USER_ERROR);
				}

				//sync forum_posts
				//TODO
				if($new_topic_data['forum_id'] != $post_data['forum_id'])
				{
					$sync->add('forum', $post_data['forum_id'], 'forum_posts', $this->post_approved ? -1 : 0);
					$sync->add('forum', $new_topic_data['forum_id'], 'forum_posts', $this->post_approved ? 1 : 0);
					if($this->forum_id != $new_topic_data['forum_id'])
					{
						//user changed topic_id but not forum_id, so we saved the wrong one above. correct it via sync
						$this->forum_id = $new_topic_data['forum_id'];
						$sync->set('post', $this->post_id, 'forum_id', $this->forum_id);
					}
				}

				//sync old topic
				$sync->add('topic', $post_data['topic_id'], 'topic_replies', $this->post_approved ? -1 : 0);
				$sync->add('topic', $post_data['topic_id'], 'topic_replies_real', -1);
				$sync->check_topic_empty($post_data['topic_id']);

				//sync new topic
				$sync->add('topic', $this->topic_id, 'topic_replies', $this->post_approved ? 1 : 0);
				$sync->add('topic', $this->topic_id, 'topic_replies_real', 1);

				//sync topic_reported and topic_attachment if applicable
				if($post_data['post_reported']) {
					$sync->topic_reported($post_data['topic_id']);
				}
				if($post_data['post_attachment']) {
					$sync->topic_attachment($post_data['topic_id']);
				}
				if($this->post_reported) {
					$sync->topic_reported($this->topic_id);
				}
				if($this->post_attachment) {
					$sync->topic_attachment($this->topic_id);
				}

				if($is_first_post)
				{
					//this was the first post in the old topic, sync it
					$sync->topic_first_post($post_data['topic_id']);
					$is_first_post = false; //unset since we dont know status for new topic yet
				}

				if($is_last_post)
				{
					//this was the last post in the old topic, sync it
					$sync->topic_last_post($post_data['topic_id']);
					$sync->forum_last_post($post_data['forum_id']);
					$is_last_post = false; //unset since we dont know status for new topic yet
				}

				if($this->post_time <= $new_topic_data['topic_time'])
				{
					//this will be the first post in the new topic, sync it
					$sync->topic_first_post($this->topic_id);
					$is_first_post = true;
				}
				if($this->post_time >= $new_topic_data['topic_last_post_time'])
				{
					//this will be the last post in the new topic, sync it
					$sync->topic_last_post($this->topic_id);
					$sync->forum_last_post($this->topic_id);
					$is_last_post = true;
				}
			}
			elseif($is_first_post)
			{
				$sync->set('topic', $this->topic_id, array(
					'icon_id'			=> $this->icon_id,
					'topic_approved'	=> $this->post_approved,
					'topic_title'		=> $this->post_subject,
					'topic_poster'		=> $this->poster_id,
					'topic_time'		=> $this->post_time
				));
			}


			//check if some statistics relevant flags have been changed
			if($this->post_approved != $post_data['post_approved'])
			{
				//if topic_id was changed, we've already updated it above.
				if($this->topic_id == $post_data['topic_id'])
				{
					if($is_first_post)
					{
						//first post -> approve/disapprove whole topic if not yet done (should only happen when directly storing the post)
						if($this->post_approved != $post_data['topic_approved'])
						{
							$sync->add('forum', $this->forum_id, 'forum_topics', $this->post_approved ? 1 : -1);
							$sync->add('forum', $this->forum_id, 'forum_posts', $this->post_approved ? (1+$post_data['topic_replies']) : -(1+$post_data['topic_replies']));
							$sync->forum_last_post($this->forum_id);

							//and the total topics+posts
							set_config('num_topics', $this->post_approved ? $config['num_topics'] + 1 : $config['num_topics'] - 1, true);
							set_config('num_posts', $this->post_approved ? $config['num_posts'] + (1+$post_data['topic_replies']) : $config['num_posts'] - (1+$post_data['topic_replies']), true);
						}
					}
					else
					{
						//reply
						$sync->add('topic', $this->topic_id, 'topic_replies', $this->post_approved ? 1 : -1);
						$sync->add('forum', $this->forum_id, 'forum_posts', $this->post_approved ? 1 : -1);
					}
				}

				//update total posts
				if(!$is_first_post)
				{
					set_config('num_posts', $this->post_approved ? $config['num_posts'] + 1 : $config['num_posts'] - 1, true);
				}
			}
			/*if($this->post_postcount != $post_data['post_postcount'] && $this->poster_id != ANONYMOUS)
			 {
				//increase or decrease user_posts
				$sync->add('user', $this->poster_id, 'user_posts', $this->post_approved ? 1 : -1);
				}*/
			if($this->poster_id != $post_data['poster_id'] || $this->post_postcount != $post_data['post_postcount'])
			{
				if($post_data['post_postcount'] && $post_data['poster_id'] != ANONYMOUS)
				{
					$sync->add('user', $post_data['poster_id'], 'user_posts', -1);
				}
				if($this->post_postcount && $this->poster_id != ANONYMOUS)
				{
					$sync->add('user', $this->poster_id, 'user_posts', 1);
				}
			}

			if($is_first_post)
			{
				$sync->topic_first_post($this->topic_id);
			}
			if($is_last_post)
			{
				$sync->topic_last_post($this->topic_id);
				$sync->forum_last_post($this->forum_id);
			}

			$this->reindex('edit', $this->post_id, $sql_data['post_text'], $this->post_subject, $this->poster_id, $this->forum_id);

			$db->sql_transaction('commit');
		}
		elseif($this->topic_id)
		{
			//reply
			$sql = "SELECT t.*, f.forum_name
					FROM " . TOPICS_TABLE . " t
					LEFT JOIN " . FORUMS_TABLE . " f ON (f.forum_id = t.forum_id)
					WHERE t.topic_id=" . intval($this->topic_id);
			$result = $db->sql_query($sql);
			$topic_data = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);

			if(!$topic_data)
			{
				trigger_error("topic_id={$this->topic_id}, but that topic does not exist", E_USER_ERROR);
			}

			//we need topic_id and forum_id
			$this->forum_id = $topic_data['forum_id'];
			$sql_data['forum_id'] = $this->forum_id;
			$sql_data['topic_id'] = $this->topic_id;

			//make sure we have a post_subject (empty subjects are bad for e.g. approving)
			if($this->post_subject == '')
			{
				$this->post_subject = 'Re: ' . $topic_data['topic_title'];
			}

			$db->sql_transaction('begin');

			//insert post
			$sql = "INSERT INTO " . POSTS_TABLE . " " . $db->sql_build_array('INSERT', $sql_data);
			$db->sql_query($sql);
			$this->post_id = $db->sql_nextid();

			//update topic
			if(!$sync->new_topic_flag)
			{
				$sync->add('topic', $this->topic_id, 'topic_replies', $this->post_approved ? 1 : 0);
				$sync->add('topic', $this->topic_id, 'topic_replies_real', 1);
				$sync->set('topic', $this->topic_id, 'topic_bumped', 0);
				$sync->set('topic', $this->topic_id, 'topic_bumper', 0);
			}
			else
			{
				$sync->topic_first_post($this->topic_id);
				$sync->new_topic_flag = false;
			}
			$sync->topic_last_post($this->topic_id);

			//update forum
			if($this->forum_id != 0)
			{
				$sync->add('forum', $this->forum_id, 'forum_posts', $this->post_approved ? 1 : 0);
				$sync->forum_last_post($this->forum_id);
			}


			if($this->post_postcount)
			{
				//increase user_posts...
				$sync->add('user', $this->poster_id, 'user_posts', 1);
			}
			if($this->post_approved)
			{
				//...and total posts
				set_config('num_posts', $config['num_posts'] + 1, true);
			}

			$this->reindex('reply', $this->post_id, $sql_data['post_text'], $this->post_subject, $this->poster_id, $this->forum_id);

			$db->sql_transaction('commit');

			// Mark this topic as posted to
			markread('post', $this->forum_id, $this->topic_id, $this->post_time, $this->poster_id);

			// Mark this topic as read
			// We do not use post_time here, this is intended (post_time can have a date in the past if editing a message)
			markread('topic', $this->forum_id, $this->topic_id, time());

			//
			if ($config['load_db_lastread'] && $user->data['is_registered'])
			{
				$sql = 'SELECT mark_time
					FROM ' . FORUMS_TRACK_TABLE . '
					WHERE user_id = ' . $user->data['user_id'] . '
						AND forum_id = ' . $this->forum_id;
				$result = $db->sql_query($sql);
				$f_mark_time = (int) $db->sql_fetchfield('mark_time');
				$db->sql_freeresult($result);
			}
			else if ($config['load_anon_lastread'] || $user->data['is_registered'])
			{
				$f_mark_time = false;
			}

			if (($config['load_db_lastread'] && $user->data['is_registered']) || $config['load_anon_lastread'] || $user->data['is_registered'])
			{
				// Update forum info
				$sql = 'SELECT forum_last_post_time
					FROM ' . FORUMS_TABLE . '
					WHERE forum_id = ' . $this->forum_id;
				$result = $db->sql_query($sql);
				$forum_last_post_time = (int) $db->sql_fetchfield('forum_last_post_time');
				$db->sql_freeresult($result);

				update_forum_tracking_info($this->forum_id, $forum_last_post_time, $f_mark_time, false);
			}

			// Send Notifications
			user_notification('reply', $this->post_subject, $topic_data['topic_title'], $topic_data['forum_name'], $this->forum_id, $this->topic_id, $this->post_id);
		}
		else
		{
			//new topic
			$this->_topic = topic::from_post($this);
			$this->_topic->submit(true);

			//PHP4 Compatibility:
			if(version_compare(PHP_VERSION, '5.0.0', '<'))
			{
				$this->topic_id = $this->_topic->topic_id;
				$this->post_id = $this->_topic->topic_first_post_id;
			}
			$exec_sync = false;
		}

		foreach($this->attachments as $attachment)
		{
			$attachment->post_msg_id = $this->post_id;
			$attachment->topic_id = $this->topic_id;
			$attachment->poster_id = $this->poster_id;
			$attachment->in_message = 0;
			$attachment->is_orphan = 0;
			$attachment->submit();
		}

	}

	/**
	 * Reindex post in search
	 *
	 * @param string $mode
	 * @param int $post_id
	 * @param string $message
	 * @param string $subject
	 * @param int $poster_id
	 * @param int $forum_id
	 */
	function reindex($mode, $post_id, $message, $subject, $poster_id, $forum_id)
	{
		global $config, $phpbb_root_path, $phpEx;
		// Select the search method and do some additional checks to ensure it can actually be utilised
		$search_type =  basename($config['search_type']);

		if (!file_exists($phpbb_root_path . 'phpbb/search/' . $search_type . '.' . $phpEx))
		{
			trigger_error('NO_SUCH_SEARCH_MODULE', E_USER_ERROR);
		}

		require_once("{$phpbb_root_path}phpbb/search/$search_type.$phpEx");
		$search_type = "\\phpbb\\search\\" . $search_type;

		$error = false;
		$search = new $search_type($error);

		if ($error)
		{
			trigger_error($error);
		}

		$search->index($mode, $post_id, $message, $subject, $poster_id, $forum_id);
	}

	/**delete this post (and if it was the last one in the topic, also delete the topic)*/
	function delete()
	{
		if(!$this->post_id)
		{
			trigger_error('NO_POST', E_USER_ERROR);
		}

		$ret = delete_posts('post_id', $this->post_id);

		//remove references to the deleted post so calls to submit() will create a
		//new post instead of trying to update the post which does not exist anymore
		$this->post_id = NULL;

		return $ret;
	}

	/**mark this post as edited (modify post_edit_* fields).
	 * currently logged in user will be used if user_id = 0*/
	function mark_edited($user_id = 0, $reason = '')
	{
		if($user_id = 0)
		{
			global $user;
			$user_id = $user->data['user_id'];
		}
		$this->post_edit_count++;
		$this->post_edit_time = time();
		$this->post_edit_user = $user_id;
		$this->post_edit_reason = $reason;
	}

	function mark_read()
	{
		if($this->post_id)
		{
			//only when post already stored
			markread('topic', $this->forum_id, $this->topic_id, time());
		}
	}
}
