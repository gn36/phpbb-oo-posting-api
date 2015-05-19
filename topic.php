<?php

namespace gn36\functions_post_oo;

include_once($phpbb_root_path . 'includes/functions_content.' . $phpEx);
include_once(__DIR__ . 'syncer' . $phpEx);
include_once(__DIR__ . 'post' . $phpEx);

class topic
{

	var $topic_id;

	var $forum_id;

	var $posts = array();

	var $icon_id = 0;

	var $topic_attachment = 0;

	var $topic_reported = 0;

	var $topic_views = 0;

	var $topic_visibility = ITEM_APPROVED;

	var $topic_posts_approved = 1;

	var $topic_posts_unapproved = 0;

	var $topic_posts_softdeleted = 0;

	var $topic_delete_time = 0;

	var $topic_delete_reason = '';

	var $topic_delete_user = 0;

	var $topic_status = ITEM_UNLOCKED;

	var $topic_moved_id = 0;

	var $topic_type = POST_NORMAL;

	var $topic_time_limit = 0;

	var $topic_title = '';

	var $topic_time;

	var $topic_poster;

	var $topic_first_post_id;

	var $topic_first_poster_name;

	var $topic_first_poster_colour;

	var $topic_last_post_id;

	var $topic_last_poster_id;

	var $topic_last_poster_name;

	var $topic_last_poster_colour;

	var $topic_last_post_subject;

	var $topic_last_post_time;

	var $topic_last_view_time;

	var $topic_bumped = 0;

	var $topic_bumper = 0;

	var $poll_title = '';

	var $poll_start = 0;

	var $poll_length = 0;

	var $poll_max_options = 1;

	var $poll_last_vote = 0;

	var $poll_vote_change = 0;

	var $poll_options = array();

	function __construct($forum_id = 0)
	{
		$this->forum_id = $forum_id;
	}

	/**
	 * Load topic from database
	 *
	 * @param int $topic_id
	 * @param boolean $load_posts Whether to load the posts as well
	 * @return boolean|\gn36\functions_post_oo\topic
	 */
	static function get($topic_id, $load_posts = false)
	{
		global $db;
		$sql = "SELECT * FROM " . TOPICS_TABLE . " WHERE topic_id=" . intval($topic_id);
		$result = $db->sql_query($sql);
		$topic_data = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if (! $topic_data)
		{
			// topic does not exist, return false
			return false;
		}

		// create object and fill in data
		$topic = new topic();

		$topic->topic_id = $topic_data['topic_id'];
		$topic->forum_id = $topic_data['forum_id'];

		$topic->icon_id = $topic_data['icon_id'];
		$topic->topic_attachment = $topic_data['topic_attachment'];
		$topic->topic_reported = $topic_data['topic_reported'];

		$topic->topic_views = $topic_data['topic_views'];

		$topic->topic_visibility = $topic_data['topic_visibility'];
		$topic->topic_posts_approved = $topic_data['topic_posts_approved'];
		$topic->topic_posts_unapproved = $topic_data['topic_posts_unapproved'];
		$topic->topic_posts_softdeleted = $topic_data['topic_posts_softdeleted'];
		$topic->topic_delete_time = $topic_data['topic_delete_time'];
		$topic->topic_delete_reason = $topic_data['topic_delete_reason'];
		$topic->topic_delete_user = $topic_data['topic_delete_user'];

		$topic->topic_status = $topic_data['topic_status'];
		$topic->topic_moved_id = $topic_data['topic_moved_id'];
		$topic->topic_type = $topic_data['topic_type'];
		$topic->topic_time_limit = $topic_data['topic_time_limit'];

		$topic->topic_title = $topic_data['topic_title'];
		$topic->topic_time = $topic_data['topic_time'];
		$topic->topic_poster = $topic_data['topic_poster'];

		$topic->topic_first_post_id = $topic_data['topic_first_post_id'];
		$topic->topic_first_poster_name = $topic_data['topic_first_poster_name'];
		$topic->topic_first_poster_colour = $topic_data['topic_first_poster_colour'];

		$topic->topic_last_post_id = $topic_data['topic_last_post_id'];
		$topic->topic_last_poster_id = $topic_data['topic_last_poster_id'];
		$topic->topic_last_poster_name = $topic_data['topic_last_poster_name'];
		$topic->topic_last_poster_colour = $topic_data['topic_last_poster_colour'];
		$topic->topic_last_post_subject = $topic_data['topic_last_post_subject'];
		$topic->topic_last_post_time = $topic_data['topic_last_post_time'];
		$topic->topic_last_view_time = $topic_data['topic_last_view_time'];

		$topic->topic_bumped = $topic_data['topic_bumped'];
		$topic->topic_bumper = $topic_data['topic_bumper'];

		$topic->poll_title = $topic_data['poll_title'];
		$topic->poll_start = $topic_data['poll_start'];
		$topic->poll_length = $topic_data['poll_length'];
		$topic->poll_max_options = $topic_data['poll_max_options'];
		$topic->poll_last_vote = $topic_data['poll_last_vote'];
		$topic->poll_vote_change = $topic_data['poll_vote_change'];

		if ($load_posts)
		{
			$sql = "SELECT * FROM " . POSTS_TABLE . " WHERE topic_id=" . intval($topic_id) . " ORDER BY post_time ASC";
			$result = $db->sql_query($sql);
			while ($post_data = $db->sql_fetchrow($result))
			{
				$topic->posts[] = post::from_array($post_data);
			}
			$db->sql_freeresult($result);
		}

		return $topic;
	}

	/**
	 *
	 * @param array $post
	 * @return boolean|\gn36\functions_post_oo\topic
	 */
	static function from_post($post)
	{
		if ($post->topic_id != NULL)
		{
			return topic::get($post->topic_id);
		}

		$topic = new topic();
		$topic->topic_id = $post->topic_id;
		$topic->forum_id = $post->forum_id;
		$topic->topic_title = $post->post_subject;
		$topic->topic_poster = $post->poster_id;
		$topic->topic_time = $post->post_time;
		$topic->icon_id = $post->icon_id;
		$topic->topic_attachment = $post->post_attachment;

		$topic->topic_posts_approved = ($post->post_visibility == ITEM_APPROVED) ? 1 : 0;
		$topic->topic_posts_unapproved = ($post->post_visibility == ITEM_UNAPPROVED) ? 1 : 0;
		$topic->topic_posts_softdeleted = ($post->post_visibility == ITEM_DELETED) ? 1 : 0;

		$topic->topic_delete_user = $post->post_delete_user;
		$topic->topic_delete_reason = $post->post_delete_reason;
		$topic->topic_delete_time = $post->post_delete_time;
		$topic->topic_reported = $post->post_reported;

		$topic->posts[] = &$post;
		return $topic;
	}

	// TODO
	function submit($submit_posts = true)
	{
		global $config, $db, $auth, $user;

		if (! $this->topic_id && count($this->posts) == 0)
		{
			trigger_error('cannot create a topic without posts', E_USER_ERROR);
		}

		if (! $this->topic_id)
		{
			// new post, set some default values if not set yet
			if (! $this->topic_poster)
				$this->topic_poster = $user->data['user_id'];
			if (! $this->topic_time)
				$this->topic_time = time();
			$this->posts[0]->post_subject = $this->topic_title;
		}

		if ($this->forum_id == 0)
		{
			// no forum id known, can only insert as global announcement
			$this->topic_type = POST_GLOBAL;
		}

		$this->topic_title = truncate_string($this->topic_title);

		$sql_data = array(
			'icon_id' => $this->icon_id,
			'topic_attachment' => $this->topic_attachment ? 1 : 0,

			// 'topic_visibility' => $this->topic_visibility, // This one will be set later
			'topic_reported' => $this->topic_reported ? 1 : 0,

			// 'topic_views' => $this->topic_views,
			'topic_replies' => $this->topic_replies,
			'topic_replies_real' => $this->topic_replies_real,
			'topic_status' => $this->topic_status,
			'topic_moved_id' => $this->topic_moved_id,
			'topic_type' => $this->topic_type,
			'topic_time_limit' => $this->topic_time_limit,
			'topic_title' => $this->topic_title,
			'topic_time' => $this->topic_time,
			'topic_poster' => $this->topic_poster,
			'topic_bumped' => $this->topic_bumped ? 1 : 0,
			'topic_bumper' => $this->topic_bumper,
			'poll_title' => $this->poll_title,
			'poll_start' => $this->poll_start,
			'poll_length' => $this->poll_length,
			'poll_max_options' => $this->poll_max_options,

			// 'poll_last_vote' => $this->poll_last_vote,
			'poll_vote_change' => $this->poll_vote_change ? 1 : 0
		);

		$sync = new syncer();

		if ($this->topic_id)
		{
			// edit
			$sql = "SELECT *
					FROM " . TOPICS_TABLE . "
					WHERE topic_id=" . intval($this->topic_id);
			$result = $db->sql_query($sql);
			$topic_data = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);

			if (! $topic_data)
			{
				trigger_error("topic_id={$this->topic_id}, but that topic does not exist", E_USER_ERROR);
			}

			$db->sql_transaction('begin');

			$sql = "UPDATE " . TOPICS_TABLE . " SET " . $db->sql_build_array('UPDATE', $sql_data) . " WHERE topic_id=" . $this->topic_id;
			$db->sql_query($sql);

			// move to another forum -> also move posts and update statistics
			if ($this->forum_id != $topic_data['forum_id'])
			{
				$sql = "UPDATE " . POSTS_TABLE . " SET forum_id=" . $this->forum_id . " WHERE topic_id=" . $this->topic_id;
				$db->sql_query($sql);

				// old forum
				if ($topic_data['forum_id'] != 0)
				{
					if ($topic_data['topic_visibility'] == ITEM_APPROVED)
					{
						$sync->add('forum', $topic_data['forum_id'], 'forum_topics_approved', - 1);
					}
					elseif ($topic_data['topic_visibility'] == ITEM_UNAPPROVED || $topic_data['topic_visibility'] == ITEM_REAPPROVE)
					{
						$sync->add('forum', $topic_data['forum_id'], 'forum_topics_unapproved', - 1);
					}
					elseif ($topic_data['topic_visibility'] == ITEM_DELETED)
					{
						$sync->add('forum', $topic_data['forum_id'], 'forum_topics_softdeleted', - 1);
					}
					$sync->add('forum', $topic_data['forum_id'], 'forum_posts_approved', - $topic_data['topic_posts_approved']);
					$sync->add('forum', $topic_data['forum_id'], 'forum_posts_unapproved', - $topic_data['topic_posts_unapproved']);
					$sync->add('forum', $topic_data['forum_id'], 'forum_posts_softdeleted', - $topic_data['topic_posts_softdeleted']);
					$sync->forum_last_post($topic_data['forum_id']);
				}

				// new forum
				if ($this->forum_id != 0)
				{
					if ($this->topic_visibility == ITEM_APPROVED)
					{
						$sync->add('forum', $this->forum_id, 'forum_topics_approved', 1);
					}
					elseif ($this->topic_visibility == ITEM_UNAPPROVED)
					{
						$sync->add('forum', $this->forum_id, 'forum_topics_unapproved', 1);
					}
					elseif ($this->topic_visibility == ITEM_DELETED)
					{
						$sync->add('forum', $this->forum_id, 'forum_topics_deleted', 1);
					}
					$sync->add('forum', $this->forum_id, 'forum_posts_approved', $this->topic_posts_approved);
					$sync->add('forum', $this->forum_id, 'forum_posts_unapproved', $this->topic_posts_unapproved);
					$sync->add('forum', $this->forum_id, 'forum_posts_softdeleted', $this->topic_posts_softdeleted);
					$sync->forum_last_post($this->forum_id);
				}
			}

			// TODO
			// Sync numbers:
			$phpbb_content_visibility = $phpbb_container->get('content.visibility');
			$phpbb_content_visibility->set_topic_visibility($this->topic_visibility, $this->topic_id, $this->forum_id, $this->topic_delete_user, $this->topic_delete_time, $this->topic_delete_reason);

			// //same with total topics+posts
			// if($topic_data['topic_visibility'] == ITEM_APPROVED)
			// {
			// set_config('num_topics', $config['num_topics'] - 1, true);
			// set_config('num_posts', $config['num_posts'] - (1 + $this->topic_posts_approved), true);
			// }
			// elseif($this->topic_visibility == ITEM_APPROVED)
			// {
			// set_config('num_topics', $config['num_topics'] + 1, true);
			// set_config('num_posts', $config['num_posts'] + (1 + $this->topic_posts_approved), true);
			// }
			// }

			$db->sql_transaction('commit');
		}
		else
		{
			// new topic
			$sql_data['forum_id'] = $this->forum_id;

			$sql = 'INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_data);

			$db->sql_query($sql);

			$this->topic_id = $db->sql_nextid();

			if ($this->forum_id != 0)
			{
				if ($this->topic_visibility == ITEM_APPROVED)
				{
					$sync->add('forum', $this->forum_id, 'forum_topics_approved', 1);
				}
				elseif ($this->topic_visibility == ITEM_UNAPPROVED)
				{
					$sync->add('forum', $this->forum_id, 'forum_topics_unapproved', 1);
				}
				elseif ($this->topic_visibility == ITEM_DELETED)
				{
					$sync->add('forum', $this->forum_id, 'forum_topics_deleted', 1);
				}
				$sync->add('forum', $this->forum_id, 'forum_posts_approved', $this->topic_posts_approved);
				$sync->add('forum', $this->forum_id, 'forum_posts_unapproved', $this->topic_posts_unapproved);
				$sync->add('forum', $this->forum_id, 'forum_posts_softdeleted', $this->topic_posts_softdeleted);
				$sync->forum_last_post($this->forum_id);
			}

			$phpbb_content_visibility = $phpbb_container->get('content.visibility');
			$phpbb_content_visibility->set_topic_visibility($this->topic_visibility, $this->topic_id, $this->forum_id, $this->topic_delete_user, $this->topic_delete_time, $this->topic_delete_reason);

			// total topics
			if ($this->topic_approved)
			{
				set_config('num_topics', $config['num_topics'] + 1, true);
			}

			$sync->new_topic_flag = true;
		}

		// insert or update poll
		if (isset($this->poll_options) && ! empty($this->poll_options))
		{
			$cur_poll_options = array();

			if ($this->poll_start && isset($topic_data))
			{
				$sql = 'SELECT * FROM ' . POLL_OPTIONS_TABLE . '
					WHERE topic_id = ' . $this->topic_id . '
					ORDER BY poll_option_id';
				$result = $db->sql_query($sql);

				$cur_poll_options = array();
				while ($row = $db->sql_fetchrow($result))
				{
					$cur_poll_options[] = $row;
				}
				$db->sql_freeresult($result);
			}

			$sql_insert_ary = array();
			for ($i = 0, $size = sizeof($this->poll_options); $i < $size; $i ++)
			{
				if (trim($this->poll_options[$i]))
				{
					if (empty($cur_poll_options[$i]))
					{
						$sql_insert_ary[] = array(
							'poll_option_id' => (int) $i,
							'topic_id' => (int) $this->topic_id,
							'poll_option_text' => (string) $this->poll_options[$i]
						);
					}
					else
						if ($this->poll_options[$i] != $cur_poll_options[$i])
						{
							$sql = "UPDATE " . POLL_OPTIONS_TABLE . "
							SET poll_option_text = '" . $db->sql_escape($this->poll_options[$i]) . "'
							WHERE poll_option_id = " . $cur_poll_options[$i]['poll_option_id'] . "
								AND topic_id = " . $this->topic_id;
							$db->sql_query($sql);
						}
				}
			}

			$db->sql_multi_insert(POLL_OPTIONS_TABLE, $sql_insert_ary);

			if (sizeof($this->poll_options) < sizeof($cur_poll_options))
			{
				$sql = 'DELETE FROM ' . POLL_OPTIONS_TABLE . '
					WHERE poll_option_id >= ' . sizeof($this->poll_options) . '
						AND topic_id = ' . $this->topic_id;
				$db->sql_query($sql);
			}
		}

		// delete poll if we had one and poll_start is 0 now
		if (isset($topic_data) && $topic_data['poll_start'] && $this->poll_start == 0)
		{
			$sql = 'DELETE FROM ' . POLL_OPTIONS_TABLE . '
				WHERE topic_id = ' . $this->topic_id;
			$db->sql_query($sql);

			$sql = 'DELETE FROM ' . POLL_VOTES_TABLE . '
				WHERE topic_id = ' . $this->topic_id;
			$db->sql_query($sql);
		}
		// End poll

		if ($submit_posts && count($this->posts))
		{
			// find and sync first post
			if ($sync->new_topic_flag)
			{
				// test if var was not set in post
				$first_post = $this->posts[0];
				if ($first_post->post_subject == '')
				{
					$first_post->post_subject = $this->topic_title;
				}
				if (! $first_post->poster_id)
				{
					$first_post->poster_id = $this->topic_poster;
				}
				if (! $this->topic_approved)
				{
					$first_post->post_approved = 0;
				}
			}
			elseif ($topic_data && $this->topic_first_post_id != 0)
			{
				foreach ($this->posts as $post)
				{
					if ($post->post_id == $this->topic_first_post_id)
					{
						// test if var has been changed in topic. this is like the
						// else($submit_posts) below, but the user might have changed the
						// post object but not the topic, so we can't just overwrite them
						$first_post = $post;
						if ($this->topic_title != $topic_data['topic_title'])
						{
							$first_post->post_subject = $this->topic_title;
						}
						if ($this->topic_time != $topic_data['topic_time'])
						{
							$first_post->post_time = $this->topic_time;
						}
						if ($this->topic_poster != $topic_data['topic_poster'])
						{
							$first_post->poster_id = $this->topic_poster;
							$first_post->post_username = ($this->topic_poster == ANONYMOUS) ? $this->topic_first_poster_name : '';
						}
						if ($this->topic_approved != $topic_data['topic_approved'])
						{
							$first_post->post_approved = $this->topic_approved;
						}
						break;
					}
				}
			}

			// TODO sort by post_time in case user messed with it
			foreach ($this->posts as $post)
			{
				$post->_topic = $this;
				$post->topic_id = $this->topic_id;
				$post->forum_id = $this->forum_id;

				// if(!$post->poster_id) $post->poster_id = $this->topic_poster;

				$post->_submit($sync);
			}
		}
		else
		{
			// sync first post if user edited topic only
			$sync->set('post', $this->topic_first_post_id, array(
				'post_subject' => $this->topic_title,
				'post_time' => $this->topic_time,
				'poster_id' => $this->topic_poster,
				'post_username' => ($this->topic_poster == ANONYMOUS) ? $this->topic_first_poster_name : '',
				'post_visibility' => $this->topic_visibility,
				'post_reported' => $this->topic_reported
			));
		}

		$sync->execute();

		// refresh $this->topic_foo variables...
		$this->refresh_statvars();
	}

	/**
	 * synchronizes topic and forum via sync() and updates member variables
	 * ($this->topic_last_post_id etc.)
	 */
	// TODO
	function sync()
	{
		global $db;

		if (! $this->topic_id)
		{
			// topic does not exist yet
			return;
		}

		sync('topic', 'topic_id', $this->topic_id, false, true);
		if ($this->forum_id > 0)
		{
			sync('forum', 'forum_id', $this->forum_id);
		}

		$this->refresh_statvars();
	}

	function refresh_statvars()
	{
		global $db;

		$sql = "SELECT * FROM " . TOPICS_TABLE . " WHERE topic_id=" . $this->topic_id;
		$result = $db->sql_query($sql);
		$topic_data = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if (! $topic_data)
		{
			// topic does not exist although we have a topic_id?
			trigger_error("topic id set ({$this->topic_id}) but topic does not exist?", E_USER_ERROR);
		}

		$this->topic_attachment = $topic_data['topic_attachment'];
		$this->topic_visibility = $topic_data['topic_visibility'];
		$this->topic_reported = $topic_data['topic_reported'];

		$this->topic_views = $topic_data['topic_views'];

		$this->topic_first_post_id = $topic_data['topic_first_post_id'];
		$this->topic_first_poster_name = $topic_data['topic_first_poster_name'];
		$this->topic_first_poster_colour = $topic_data['topic_first_poster_colour'];

		$this->topic_last_post_id = $topic_data['topic_last_post_id'];
		$this->topic_last_poster_id = $topic_data['topic_last_poster_id'];
		$this->topic_last_poster_name = $topic_data['topic_last_poster_name'];
		$this->topic_last_poster_colour = $topic_data['topic_last_poster_colour'];
		$this->topic_last_post_subject = $topic_data['topic_last_post_subject'];
		$this->topic_last_post_time = $topic_data['topic_last_post_time'];
		$this->topic_last_view_time = $topic_data['topic_last_view_time'];

		$this->topic_delete_reason = $topic_data['topic_delete_reason'];
		$this->topic_delete_time = $topic_data['topic_delete_time'];
		$this->topic_delete_user = $topic_data['topic_delete_user'];

		$this->topic_posts_approved = $topic_data['topic_posts_approved'];
		$this->topic_posts_softdeleted = $topic_data['topic_posts_softdeleted'];
		$this->topic_posts_unapproved = $topic_data['topic_posts_unapproved'];
	}

	/**
	 */
	function add_poll($title, $poll_options, $max_options = 1)
	{
		$this->poll_start = time();
		$this->poll_title = $title;
		$this->poll_options = $poll_options;
		$this->poll_max_options = $max_options;
	}

	/**
	 */
	function delete_poll()
	{
		$this->poll_title = '';
		$this->poll_start = 0;
		$this->poll_length = 0;
		$this->poll_last_vote = 0;
		$this->poll_max_options = 0;
		$this->poll_vote_change = 0;

		// POLL_OPTIONS_TABLE and POLL_VOTES_TABLE will be cleared in submit()
	}

	/**
	 * clears all votes (restarts poll)
	 */
	function reset_poll()
	{
		global $db;

		$db->sql_transaction('begin');
		$sql = 'UPDATE ' . POLL_OPTIONS_TABLE . '
			SET poll_option_total = 0
			WHERE topic_id = ' . $this->topic_id;
		$db->sql_query($sql);

		$sql = 'DELETE FROM ' . POLL_VOTES_TABLE . '
			WHERE topic_id = ' . $this->topic_id;
		$db->sql_query($sql);

		$this->poll_start = time();
		$this->poll_last_vote = 0;
		$sql = 'UPDATE ' . TOPICS_TABLE . '
			SET poll_start = ' . $this->poll_start . ',
				poll_last_vote = 0
			WHERE topic_id = ' . $this->topic_id;
		$db->sql_query($sql);

		$db->sql_transaction('commit');
	}

	/**
	 * bumps the topic
	 *
	 * @param
	 */
	function bump($user_id = 0)
	{
		global $db, $user;

		$current_time = time();
		if ($user_id == 0)
			$user_id = $user->data['user_id'];

		$db->sql_transaction('begin');

		$sql = 'UPDATE ' . POSTS_TABLE . "
		SET post_time = $current_time
		WHERE post_id = {$this->topic_last_post_id}
		AND topic_id = {$this->topic_id}";
		$db->sql_query($sql);

		$this->topic_bumped = 1;
		$this->topic_bumper = $user_id;
		$this->topic_last_post_time = $current_time;
		$sql = 'UPDATE ' . TOPICS_TABLE . "
		SET topic_last_post_time = $current_time,
		topic_bumped = 1,
		topic_bumper = $user_id
		WHERE topic_id = $topic_id";
		$db->sql_query($sql);

		update_post_information('forum', $this->forum_id);

		$sql = 'UPDATE ' . USERS_TABLE . "
		SET user_lastpost_time = $current_time
		WHERE user_id = $user_id";
		$db->sql_query($sql);

		$db->sql_transaction('commit');

		markread('post', $this->forum_id, $this->topic_id, $current_time, $user_id);

		add_log('mod', $this->forum_id, $this->topic_id, 'LOG_BUMP_TOPIC', $this->topic_title);
	}

	function move($forum_id)
	{
		move_topics($this->topic_id, $forum_id);

		$this->forum_id = $forum_id;
		foreach ($this->posts as $post)
		{
			$post->forum_id = $forum_id;
		}
	}

	function delete()
	{
		if (! $this->topic_id)
		{
			trigger_error('NO_TOPIC', E_USER_ERROR);
		}

		$ret = delete_topics('topic_id', $this->topic_id);

		// remove references to the deleted topic so calls to submit() will create a
		// new topic instead of trying to update the topich which does not exist anymore
		$this->topic_id = NULL;
		foreach ($this->posts as $post)
		{
			$post->topic_id = NULL;
			$post->post_id = NULL;
		}

		return $ret;
	}
}
