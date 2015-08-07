<?php

namespace Gn36\OoPostingApi;


class post extends posting_base
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
	var $enable_urls = 1;

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

	/** @var Gn36\OoPostingApi\topic */
	protected $_topic;

	var $post_attachment = 0;
	var $attachments = array();

	function __construct($topic_id = NULL, $post_text = '')
	{
		$this->topic_id = $topic_id;
		$this->post_text = $post_text;

		if(!function_exists('generate_text_from_storage'))
		{
			global $phpbb_root_path, $phpEx;

			include_once($phpbb_root_path . 'includes/functions_content.' . $phpEx);
		}
	}

	/**
	 * Load post from database. Returns false, if the post doesn't exist
	 * @param int $post_id
	 * @return boolean|Gn36\OoPostingApi\post
	 */
	static function get($post_id)
	{
		global $db;

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
	 * @return Gn36\OoPostingApi\post
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
		if(isset($post_data['post_attachment']))
		{
			$post->post_attachment = $post_data['post_attachment'];
		}

		$post->post_edit_time = $post_data['post_edit_time'];
		$post->post_edit_reason = $post_data['post_edit_reason'];
		$post->post_edit_user = $post_data['post_edit_user'];
		$post->post_edit_count = $post_data['post_edit_count'];

		$post->post_delete_reason = $post_data['post_delete_reason'];
		$post->post_delete_time = $post_data['post_delete_time'];
		$post->post_delete_user = $post_data['post_delete_user'];

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
		$this->submit_without_sync();
	}

	/**
	 * Submit the post to database - contrary to naming, this will sync.
	 *
	 */
	function submit_without_sync($sync_not_needed = null)
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
			'enable_urls' 		=> $this->enable_urls ? 1 : 0,
			'enable_sig'		=> $this->enable_sig ? 1 : 0,
			'post_subject'		=> $this->post_subject,
			'bbcode_bitfield'	=> 0,
			'bbcode_uid'		=> '',
			//'post_text'			=> $this->post_text,
			//'post_checksum'		=> md5($this->post_text),
			//'post_attachment'	=> $this->post_attachment ? 1 : 0,
			'post_edit_time'	=> $this->post_edit_time,
			'post_edit_reason'	=> $this->post_edit_reason,
			'post_edit_user'	=> $this->post_edit_user,
			'post_edit_count'	=> $this->post_edit_count,
			'post_edit_locked'	=> $this->post_edit_locked,
			'post_delete_time' 	=> $this->post_delete_time,
			'post_delete_reason' => $this->post_delete_reason,
			'post_delete_user' 	=> $this->post_delete_user,

			'message' 			=> $this->post_text,
			'message_md5' 		=> md5($this->post_text),

			'enable_indexing'	=> $this->enable_indexing,
			'notify_set'		=> $this->notify_set,
			'notify'			=> $this->notify,

			'topic_title' => $this->post_subject,

		);

		$flags = '';
		generate_text_for_storage($sql_data['message'], $sql_data['bbcode_uid'], $sql_data['bbcode_bitfield'], $flags, $this->enable_bbcode, $this->enable_magic_url, $this->enable_smilies);

		$topic_type = isset($this->_topic) ? $this->_topic->topic_type : POST_NORMAL;

		if($this->topic_id && !$this->forum_id)
		{
			$sql = 'SELECT forum_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $this->topic_id;
			$result = $db->sql_query($sql);
			$this->forum_id = $db->sql_fetchfield('forum_id');
			$db->sql_freeresult($result);
			if(!$this->forum_id)
			{
				throw(new \phpbb\exception\runtime_exception('TOPIC_NOT_EXIST'));
			}
		}
		elseif(!$this->forum_id)
		{
			throw(new \phpbb\exception\runtime_exception('Neither topic_id nor forum_id given. Post cannot be created.'));
		}

		// Post:
		if($this->post_id && $this->topic_id)
		{
			// Edit
			$mode = 'edit';
			$sql_data['post_id'] = $this->post_id;
			// TODO: We need more data on topic_posts_approved, topic_posts_unapproved, topic_posts_softdeleted, topic_first_post_id, topic_last_post_id
			// This is required by submit_post currently
			// Somewhere it also needs forum_name in $data for the notifications
			if($this->_topic == null)
			{
				$this->_topic = topic::from_post($this);
			}

			$sql = 'SELECT forum_name FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . (int) $this->forum_id;
			$result = $db->sql_query($sql, 48600);
			$forum_name = $db->sql_fetchfield('forum_name', false, $result);
			$db->sql_freeresult($result);

			$sql_data = array_merge($sql_data, array(
				'topic_posts_approved' 		=> $this->_topic->topic_posts_approved,
				'topic_posts_unapproved' 	=> $this->_topic->topic_posts_unapproved,
				'topic_posts_softdeleted' 	=> $this->_topic->topic_posts_softdeleted,
				'topic_first_post_id'		=> $this->_topic->topic_first_post_id,
				'topic_last_post_id'		=> $this->_topic->topic_last_post_id,
				'forum_name'				=> $forum_name,
			));
		}
		elseif($this->topic_id)
		{
			// Reply
			$mode = 'reply';
		}
		else
		{
			// New Topic
			$mode = 'post';
		}
		$poll = array();

		$post_data = $this->submit_post($mode, $this->post_subject, $this->post_username, $topic_type, $poll, $sql_data);


		// Re-Read topic_id and post_id:
		$this->topic_id = $post_data['topic_id'];
		$this->post_id  = $post_data['post_id'];

		//TODO
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
