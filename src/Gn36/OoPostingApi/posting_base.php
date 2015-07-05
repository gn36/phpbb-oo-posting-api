<?php
namespace Gn36\OoPostingApi;

class posting_base
{

	var $enable_indexing	= 1;
	var $notify_set 		= 0;
	var $notify 			= 0;

	//TODO: It would probably be better to replace this by the default phpBB function and doing some tricks with $user and $auth
	// This is currently a modified copy of the phpBB function so it allows posting as a different user and ignores some permissions.
	protected function submit_post($mode, $subject, $username, $topic_type, &$poll, &$data, $update_message = true, $update_search_index = true)
	{
		global $db, $user, $config, $auth, $phpEx, $template, $phpbb_root_path, $phpbb_container, $phpbb_dispatcher;

		/**
		 * Modify the data for post submitting
		 *
		 * @event core.modify_submit_post_data
		 *
		 * @var string containing posting mode value
		 * @var string containing post subject value
		 * @var string containing post author name
		 * @var int containing topic type value
		 * @var array with the poll data for the post
		 * @var array with the data for the post
		 * @var bool indicating if the post will be updated
		 * @var bool indicating if the search index will be updated
		 * @since 3.1.0-a4
		 */
		$vars = array(
			'mode',
			'subject',
			'username',
			'topic_type',
			'poll',
			'data',
			'update_message',
			'update_search_index'
		);
		extract($phpbb_dispatcher->trigger_event('core.modify_submit_post_data', compact($vars)));

		// We do not handle erasing posts here
		if ($mode == 'delete')
		{
			return false;
		}

		// User Info
		if ($mode != 'edit' && isset($data['poster_id']) && $data['poster_id'] && $data['poster_id'] != ANONYMOUS && $data['poster_id'] != $user->data['user_id'])
		{
			$userdata = $this->get_userdata($data['poster_id']);
		}
		else
		{
			$userdata = array(
				'username' 		=> $user->data['username'],
				'user_colour' 	=> $user->data['user_colour'],
				'user_id' 		=> $user->data['user_id'],
				'is_registered' => $user->data['is_registered'],
			);
		}

		if (! empty($data['post_time']))
		{
			$current_time = $data['post_time'];
		}
		else
		{
			$current_time = time();
		}

		if ($mode == 'post')
		{
			$post_mode = 'post';
			$update_message = true;
		}
		else if ($mode != 'edit')
		{
			$post_mode = 'reply';
			$update_message = true;
		}
		else if ($mode == 'edit')
		{
			$post_mode = ($data['topic_posts_approved'] + $data['topic_posts_unapproved'] + $data['topic_posts_softdeleted'] == 1) ? 'edit_topic' : (($data['topic_first_post_id'] == $data['post_id']) ? 'edit_first_post' : (($data['topic_last_post_id'] == $data['post_id']) ? 'edit_last_post' : 'edit'));
		}

		// First of all make sure the subject and topic title are having the correct length.
		// To achieve this without cutting off between special chars we convert to an array and then count the elements.
		$subject = truncate_string($subject, 120);
		$data['topic_title'] = truncate_string($data['topic_title'], 120);

		// Collect some basic information about which tables and which rows to update/insert
		$sql_data = $topic_row = array();
		$poster_id = isset($data['poster_id']) ? $data['poster_id'] : (int) $userdata['user_id'];

		// Retrieve some additional information if not present
		if ($mode == 'edit' && (! isset($data['post_visibility']) || ! isset($data['topic_visibility']) || $data['post_visibility'] === false || $data['topic_visibility'] === false))
		{
			$sql = 'SELECT p.post_visibility, t.topic_type, t.topic_posts_approved, t.topic_posts_unapproved, t.topic_posts_softdeleted, t.topic_visibility
				FROM ' . TOPICS_TABLE . ' t, ' . POSTS_TABLE . ' p
				WHERE t.topic_id = p.topic_id
					AND p.post_id = ' . $data['post_id'];
			$result = $db->sql_query($sql);
			$topic_row = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);

			$data['topic_visibility'] = $topic_row['topic_visibility'];
			$data['post_visibility'] = $topic_row['post_visibility'];
		}

		// This variable indicates if the user is able to post or put into the queue
		$post_visibility = isset($data['post_visibility']) ? $data['post_visibility'] : ITEM_APPROVED;

		// MODs/Extensions are able to force any visibility on posts
		if (isset($data['force_approved_state']))
		{
			$post_visibility = (in_array((int) $data['force_approved_state'], array(
				ITEM_APPROVED,
				ITEM_UNAPPROVED,
				ITEM_DELETED,
				ITEM_REAPPROVE
			))) ? (int) $data['force_approved_state'] : $post_visibility;
		}
		if (isset($data['force_visibility']))
		{
			$post_visibility = (in_array((int) $data['force_visibility'], array(
				ITEM_APPROVED,
				ITEM_UNAPPROVED,
				ITEM_DELETED,
				ITEM_REAPPROVE
			))) ? (int) $data['force_visibility'] : $post_visibility;
		}

		// Start the transaction here
		$db->sql_transaction('begin');

		// Collect Information
		switch ($post_mode)
		{
			case 'post':
			case 'reply':
				$sql_data[POSTS_TABLE]['sql'] = array(
					'forum_id' => $data['forum_id'],
					'poster_id' => (int) $userdata['user_id'],
					'icon_id' => $data['icon_id'],
					'poster_ip' => $user->ip,
					'post_time' => $current_time,
					'post_visibility' => $post_visibility,
					'enable_bbcode' => $data['enable_bbcode'],
					'enable_smilies' => $data['enable_smilies'],
					'enable_magic_url' => $data['enable_urls'],
					'enable_sig' => $data['enable_sig'],
					'post_username' => ($userdata['user_id'] != ANONYMOUS) ? $username : '',
					'post_subject' => $subject,
					'post_text' => $data['message'],
					'post_checksum' => $data['message_md5'],
					'post_attachment' => (! empty($data['attachment_data'])) ? 1 : 0,
					'bbcode_bitfield' => $data['bbcode_bitfield'],
					'bbcode_uid' => $data['bbcode_uid'],
					'post_postcount' => isset($data['post_postcount']) ? $data['post_postcount'] : 1,
					'post_edit_locked' => $data['post_edit_locked']
				);
				break;

			case 'edit_first_post':
			case 'edit':

			case 'edit_last_post':
			case 'edit_topic':

				// If edit reason is given always display edit info

				// If editing last post then display no edit info
				// If m_edit permission then display no edit info
				// If normal edit display edit info

				// Display edit info if edit reason given or user is editing his post, which is not the last within the topic.
				if ($data['post_edit_reason'] || ($post_mode == 'edit' || $post_mode == 'edit_first_post'))
				{
					$data['post_edit_reason'] = truncate_string($data['post_edit_reason'], 255, 255, false);

					$sql_data[POSTS_TABLE]['sql'] = array(
						'post_edit_time' => $current_time,
						'post_edit_reason' => $data['post_edit_reason'],
						'post_edit_user' => (int) $data['post_edit_user']
					);

					$sql_data[POSTS_TABLE]['stat'][] = 'post_edit_count = post_edit_count + 1';
				}
				else if (! $data['post_edit_reason'] && $mode == 'edit')
				{
					$sql_data[POSTS_TABLE]['sql'] = array(
						'post_edit_reason' => ''
					);
				}

				// If the person editing this post is different to the one having posted then we will add a log entry stating the edit
				// Could be simplified by only adding to the log if the edit is not tracked - but this may confuse admins/mods
				if ($user->data['user_id'] != $poster_id)
				{
					$log_subject = ($subject) ? $subject : $data['topic_title'];
					add_log('mod', $data['forum_id'], $data['topic_id'], 'LOG_POST_EDITED', $log_subject, (! empty($username)) ? $username : $user->lang['GUEST'], $data['post_edit_reason']);
				}

				if (! isset($sql_data[POSTS_TABLE]['sql']))
				{
					$sql_data[POSTS_TABLE]['sql'] = array();
				}

				$sql_data[POSTS_TABLE]['sql'] = array_merge($sql_data[POSTS_TABLE]['sql'], array(
					'forum_id' => $data['forum_id'],
					'poster_id' => $data['poster_id'],
					'icon_id' => $data['icon_id'],

					// We will change the visibility later
					// 'post_visibility' => $post_visibility,
					'enable_bbcode' => $data['enable_bbcode'],
					'enable_smilies' => $data['enable_smilies'],
					'enable_magic_url' => $data['enable_urls'],
					'enable_sig' => $data['enable_sig'],
					'post_username' => ($username && $data['poster_id'] == ANONYMOUS) ? $username : '',
					'post_subject' => $subject,
					'post_checksum' => $data['message_md5'],
					'post_attachment' => (! empty($data['attachment_data'])) ? 1 : 0,
					'bbcode_bitfield' => $data['bbcode_bitfield'],
					'bbcode_uid' => $data['bbcode_uid'],
					'post_edit_locked' => $data['post_edit_locked']
				));

				if ($update_message)
				{
					$sql_data[POSTS_TABLE]['sql']['post_text'] = $data['message'];
				}

				break;
		}
		$topic_row = array();

		// And the topic ladies and gentlemen
		switch ($post_mode)
		{
			case 'post':
				$sql_data[TOPICS_TABLE]['sql'] = array(
					'topic_poster' => (int) $user->data['user_id'],
					'topic_time' => $current_time,
					'topic_last_view_time' => $current_time,
					'forum_id' => $data['forum_id'],
					'icon_id' => $data['icon_id'],
					'topic_posts_approved' => ($post_visibility == ITEM_APPROVED) ? 1 : 0,
					'topic_posts_softdeleted' => ($post_visibility == ITEM_DELETED) ? 1 : 0,
					'topic_posts_unapproved' => ($post_visibility == ITEM_UNAPPROVED) ? 1 : 0,
					'topic_visibility' => $post_visibility,
					'topic_delete_user' => ($post_visibility != ITEM_APPROVED) ? (int) $user->data['user_id'] : 0,
					'topic_title' => $subject,
					'topic_first_poster_name' => (! $userdata['user_id'] != ANONYMOUS && $username) ? $username : (($userdata['user_id'] != ANONYMOUS) ? $userdata['username'] : ''),
					'topic_first_poster_colour' => $userdata['user_colour'],
					'topic_type' => $topic_type,
					'topic_time_limit' => ($topic_type == POST_STICKY || $topic_type == POST_ANNOUNCE) ? ($data['topic_time_limit'] * 86400) : 0,
					'topic_attachment' => (! empty($data['attachment_data'])) ? 1 : 0,
					'topic_status' => (isset($data['topic_status'])) ? $data['topic_status'] : ITEM_UNLOCKED
				);

				if (isset($poll['poll_options']) && ! empty($poll['poll_options']))
				{
					$poll_start = ($poll['poll_start']) ? $poll['poll_start'] : $current_time;
					$poll_length = $poll['poll_length'] * 86400;
					if ($poll_length < 0)
					{
						$poll_start = $poll_start + $poll_length;
						if ($poll_start < 0)
						{
							$poll_start = 0;
						}
						$poll_length = 1;
					}

					$sql_data[TOPICS_TABLE]['sql'] = array_merge($sql_data[TOPICS_TABLE]['sql'], array(
						'poll_title' => $poll['poll_title'],
						'poll_start' => $poll_start,
						'poll_max_options' => $poll['poll_max_options'],
						'poll_length' => $poll_length,
						'poll_vote_change' => $poll['poll_vote_change']
					));
				}

				$sql_data[USERS_TABLE]['stat'][] = "user_lastpost_time = $current_time" . (($post_visibility == ITEM_APPROVED) ? ', user_posts = user_posts + 1' : '');

				if ($post_visibility == ITEM_APPROVED)
				{
					$sql_data[FORUMS_TABLE]['stat'][] = 'forum_topics_approved = forum_topics_approved + 1';
					$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_approved = forum_posts_approved + 1';
				}
				else if ($post_visibility == ITEM_UNAPPROVED)
				{
					$sql_data[FORUMS_TABLE]['stat'][] = 'forum_topics_unapproved = forum_topics_unapproved + 1';
					$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_unapproved = forum_posts_unapproved + 1';
				}
				else if ($post_visibility == ITEM_DELETED)
				{
					$sql_data[FORUMS_TABLE]['stat'][] = 'forum_topics_softdeleted = forum_topics_softdeleted + 1';
					$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_softdeleted = forum_posts_softdeleted + 1';
				}
				break;

			case 'reply':
				$sql_data[TOPICS_TABLE]['stat'][] = 'topic_last_view_time = ' . $current_time . ',
					topic_bumped = 0,
					topic_bumper = 0' . (($post_visibility == ITEM_APPROVED) ? ', topic_posts_approved = topic_posts_approved + 1' : '') . (($post_visibility == ITEM_UNAPPROVED) ? ', topic_posts_unapproved = topic_posts_unapproved + 1' : '') . (($post_visibility == ITEM_DELETED) ? ', topic_posts_softdeleted = topic_posts_softdeleted + 1' : '') . ((! empty($data['attachment_data']) || (isset($data['topic_attachment']) && $data['topic_attachment'])) ? ', topic_attachment = 1' : '');

				$sql_data[USERS_TABLE]['stat'][] = "user_lastpost_time = $current_time" . ((($data['post_postcount']) && $post_visibility == ITEM_APPROVED) ? ', user_posts = user_posts + 1' : '');

				if ($post_visibility == ITEM_APPROVED)
				{
					$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_approved = forum_posts_approved + 1';
				}
				else if ($post_visibility == ITEM_UNAPPROVED)
				{
					$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_unapproved = forum_posts_unapproved + 1';
				}
				else if ($post_visibility == ITEM_DELETED)
				{
					$sql_data[FORUMS_TABLE]['stat'][] = 'forum_posts_softdeleted = forum_posts_softdeleted + 1';
				}
				break;

			case 'edit_topic':
			case 'edit_first_post':
				if (isset($poll['poll_options']))
				{
					$poll_start = ($poll['poll_start'] || empty($poll['poll_options'])) ? $poll['poll_start'] : $current_time;
					$poll_length = $poll['poll_length'] * 86400;
					if ($poll_length < 0)
					{
						$poll_start = $poll_start + $poll_length;
						if ($poll_start < 0)
						{
							$poll_start = 0;
						}
						$poll_length = 1;
					}
				}

				$sql_data[TOPICS_TABLE]['sql'] = array(
					'forum_id' => $data['forum_id'],
					'icon_id' => $data['icon_id'],
					'topic_title' => $subject,
					'topic_first_poster_name' => $username,
					'topic_type' => $topic_type,
					'topic_time_limit' => ($topic_type == POST_STICKY || $topic_type == POST_ANNOUNCE) ? ($data['topic_time_limit'] * 86400) : 0,
					'poll_title' => (isset($poll['poll_options'])) ? $poll['poll_title'] : '',
					'poll_start' => (isset($poll['poll_options'])) ? $poll_start : 0,
					'poll_max_options' => (isset($poll['poll_options'])) ? $poll['poll_max_options'] : 1,
					'poll_length' => (isset($poll['poll_options'])) ? $poll_length : 0,
					'poll_vote_change' => (isset($poll['poll_vote_change'])) ? $poll['poll_vote_change'] : 0,
					'topic_last_view_time' => $current_time,

					'topic_attachment' => (! empty($data['attachment_data'])) ? 1 : (isset($data['topic_attachment']) ? $data['topic_attachment'] : 0)
				);

				break;
		}

		/**
		 * Modify sql query data for post submitting
		 *
		 * @event core.submit_post_modify_sql_data
		 *
		 * @var array with the data for the post
		 * @var array with the poll data for the post
		 * @var string containing posting mode value
		 * @var bool with the data for the posting SQL query
		 * @var string containing post subject value
		 * @var int containing topic type value
		 * @var string containing post author name
		 * @since 3.1.3-RC1
		 */
		$vars = array(
			'data',
			'poll',
			'post_mode',
			'sql_data',
			'subject',
			'topic_type',
			'username'
		);
		extract($phpbb_dispatcher->trigger_event('core.submit_post_modify_sql_data', compact($vars)));

		// Submit new topic
		if ($post_mode == 'post')
		{
			$sql = 'INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_data[TOPICS_TABLE]['sql']);
			$db->sql_query($sql);

			$data['topic_id'] = $db->sql_nextid();

			$sql_data[POSTS_TABLE]['sql'] = array_merge($sql_data[POSTS_TABLE]['sql'], array(
				'topic_id' => $data['topic_id']
			));
			unset($sql_data[TOPICS_TABLE]['sql']);
		}

		// Submit new post
		if ($post_mode == 'post' || $post_mode == 'reply')
		{

			if ($post_mode == 'reply')
			{
				$sql_data[POSTS_TABLE]['sql'] = array_merge($sql_data[POSTS_TABLE]['sql'], array(
					'topic_id' => $data['topic_id']
				));
			}

			$sql = 'INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_data[POSTS_TABLE]['sql']);
			$db->sql_query($sql);
			$data['post_id'] = $db->sql_nextid();

			if ($post_mode == 'post' || $post_visibility == ITEM_APPROVED)
			{
				$sql_data[TOPICS_TABLE]['sql'] = array(
					'topic_last_post_id' => $data['post_id'],
					'topic_last_post_time' => $current_time,
					'topic_last_poster_id' => $sql_data[POSTS_TABLE]['sql']['poster_id'],
					'topic_last_poster_name' =>  (! $userdata['user_id'] != ANONYMOUS && $username) ? $username : (($userdata['user_id'] != ANONYMOUS) ? $userdata['username'] : ''),
					'topic_last_poster_colour' => $userdata['user_colour'],
					'topic_last_post_subject' => (string) $subject
				);
			}

			if ($post_mode == 'post')
			{
				$sql_data[TOPICS_TABLE]['sql']['topic_first_post_id'] = $data['post_id'];
			}

			// Update total post count and forum information
			if ($post_visibility == ITEM_APPROVED)
			{
				if ($post_mode == 'post')
				{
					set_config_count('num_topics', 1, true);
				}
				set_config_count('num_posts', 1, true);

				//TODO Name & Color
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_last_post_id = ' . $data['post_id'];
				$sql_data[FORUMS_TABLE]['stat'][] = "forum_last_post_subject = '" . $db->sql_escape($subject) . "'";
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_last_post_time = ' . $current_time;
				$sql_data[FORUMS_TABLE]['stat'][] = 'forum_last_poster_id = ' . ((isset($data['poster_id']) && $data['poster_id']) ? $data['poster_id'] : (int) $userdata['user_id']);
				$sql_data[FORUMS_TABLE]['stat'][] = "forum_last_poster_name = '" . ($userdata['user_id'] == ANONYMOUS ? $username : $userdata['username']) . "'";
				$sql_data[FORUMS_TABLE]['stat'][] = "forum_last_poster_colour = '" . $db->sql_escape($userdata['user_colour']) . "'";
			}

			unset($sql_data[POSTS_TABLE]['sql']);
		}

		// Update the topics table
		if (isset($sql_data[TOPICS_TABLE]['sql']))
		{
			$sql = 'UPDATE ' . TOPICS_TABLE . '
				SET ' . $db->sql_build_array('UPDATE', $sql_data[TOPICS_TABLE]['sql']) . '
				WHERE topic_id = ' . $data['topic_id'];
			$db->sql_query($sql);

			unset($sql_data[TOPICS_TABLE]['sql']);
		}

		// Update the posts table
		if (isset($sql_data[POSTS_TABLE]['sql']))
		{
			$sql = 'UPDATE ' . POSTS_TABLE . '
				SET ' . $db->sql_build_array('UPDATE', $sql_data[POSTS_TABLE]['sql']) . '
				WHERE post_id = ' . $data['post_id'];
			$db->sql_query($sql);

			unset($sql_data[POSTS_TABLE]['sql']);
		}

		// Update Poll Tables
		if (isset($poll['poll_options']))
		{
			$cur_poll_options = array();

			if ($mode == 'edit')
			{
				$sql = 'SELECT *
					FROM ' . POLL_OPTIONS_TABLE . '
					WHERE topic_id = ' . $data['topic_id'] . '
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

			for ($i = 0, $size = sizeof($poll['poll_options']); $i < $size; $i ++)
			{
				if (strlen(trim($poll['poll_options'][$i])))
				{
					if (empty($cur_poll_options[$i]))
					{
						// If we add options we need to put them to the end to be able to preserve votes...
						$sql_insert_ary[] = array(
							'poll_option_id' => (int) sizeof($cur_poll_options) + 1 + sizeof($sql_insert_ary),
							'topic_id' => (int) $data['topic_id'],
							'poll_option_text' => (string) $poll['poll_options'][$i]
						);
					}
					else if ($poll['poll_options'][$i] != $cur_poll_options[$i])
					{
						$sql = 'UPDATE ' . POLL_OPTIONS_TABLE . "
						SET poll_option_text = '" . $db->sql_escape($poll['poll_options'][$i]) . "'
						WHERE poll_option_id = " . $cur_poll_options[$i]['poll_option_id'] . '
						AND topic_id = ' . $data['topic_id'];
						$db->sql_query($sql);
					}
				}
			}

			$db->sql_multi_insert(POLL_OPTIONS_TABLE, $sql_insert_ary);

			if (sizeof($poll['poll_options']) < sizeof($cur_poll_options))
			{
				$sql = 'DELETE FROM ' . POLL_OPTIONS_TABLE . '
					WHERE poll_option_id > ' . sizeof($poll['poll_options']) . '
						AND topic_id = ' . $data['topic_id'];
				$db->sql_query($sql);
			}

			// If edited, we would need to reset votes (since options can be re-ordered above, you can't be sure if the change is for changing the text or adding an option
			if ($mode == 'edit' && sizeof($poll['poll_options']) != sizeof($cur_poll_options))
			{
				$db->sql_query('DELETE FROM ' . POLL_VOTES_TABLE . ' WHERE topic_id = ' . $data['topic_id']);
				$db->sql_query('UPDATE ' . POLL_OPTIONS_TABLE . ' SET poll_option_total = 0 WHERE topic_id = ' . $data['topic_id']);
			}
		}

		// Submit Attachments
		if (! empty($data['attachment_data']) && $data['post_id'] && in_array($mode, array(
			'post',
			'reply',
			'quote',
			'edit'
		)))
		{
			$space_taken = $files_added = 0;
			$orphan_rows = array();

			foreach ($data['attachment_data'] as $pos => $attach_row)
			{
				$orphan_rows[(int) $attach_row['attach_id']] = array();
			}

			if (sizeof($orphan_rows))
			{
				$sql = 'SELECT attach_id, filesize, physical_filename
			FROM ' . ATTACHMENTS_TABLE . '
					WHERE ' . $db->sql_in_set('attach_id', array_keys($orphan_rows)) . '
						AND is_orphan = 1
						AND poster_id = ' . $user->data['user_id'];
				$result = $db->sql_query($sql);

				$orphan_rows = array();
				while ($row = $db->sql_fetchrow($result))
				{
					$orphan_rows[$row['attach_id']] = $row;
				}
				$db->sql_freeresult($result);
			}

			foreach ($data['attachment_data'] as $pos => $attach_row)
			{
				if ($attach_row['is_orphan'] && ! isset($orphan_rows[$attach_row['attach_id']]))
				{
					continue;
				}

				if (! $attach_row['is_orphan'])
				{
					// update entry in db if attachment already stored in db and filespace
					$sql = 'UPDATE ' . ATTACHMENTS_TABLE . "
						SET attach_comment = '" . $db->sql_escape($attach_row['attach_comment']) . "'
						WHERE attach_id = " . (int) $attach_row['attach_id'] . '
							AND is_orphan = 0';
					$db->sql_query($sql);
				}
				else
				{
					// insert attachment into db
					if (! @file_exists($phpbb_root_path . $config['upload_path'] . '/' . utf8_basename($orphan_rows[$attach_row['attach_id']]['physical_filename'])))
					{
						continue;
					}

					$space_taken += $orphan_rows[$attach_row['attach_id']]['filesize'];
					$files_added ++;

					$attach_sql = array(
						'post_msg_id' => $data['post_id'],
						'topic_id' => $data['topic_id'],
						'is_orphan' => 0,
						'poster_id' => $poster_id,
						'attach_comment' => $attach_row['attach_comment']
					);

					$sql = 'UPDATE ' . ATTACHMENTS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $attach_sql) . '
						WHERE attach_id = ' . $attach_row['attach_id'] . '
						AND is_orphan = 1
							AND poster_id = ' . $user->data['user_id'];
					$db->sql_query($sql);
				}
			}

			if ($space_taken && $files_added)
			{
				set_config_count('upload_dir_size', $space_taken, true);
				set_config_count('num_files', $files_added, true);
			}
		}

		$first_post_has_topic_info = ($post_mode == 'edit_first_post' && (($post_visibility == ITEM_DELETED && $data['topic_posts_softdeleted'] == 1) || ($post_visibility == ITEM_UNAPPROVED && $data['topic_posts_unapproved'] == 1) || ($post_visibility == ITEM_REAPPROVE && $data['topic_posts_unapproved'] == 1) || ($post_visibility == ITEM_APPROVED && $data['topic_posts_approved'] == 1)));
		// Fix the post's and topic's visibility and first/last post information, when the post is edited
		if (($post_mode != 'post' && $post_mode != 'reply') && $data['post_visibility'] != $post_visibility)
		{
			// If the post was not approved, it could also be the starter,
			// so we sync the starter after approving/restoring, to ensure that the stats are correct
			// Same applies for the last post
			$is_starter = ($post_mode == 'edit_first_post' || $post_mode == 'edit_topic' || $data['post_visibility'] != ITEM_APPROVED);
			$is_latest = ($post_mode == 'edit_last_post' || $post_mode == 'edit_topic' || $data['post_visibility'] != ITEM_APPROVED);

			$phpbb_content_visibility = $phpbb_container->get('content.visibility');
			$phpbb_content_visibility->set_post_visibility($post_visibility, $data['post_id'], $data['topic_id'], $data['forum_id'], $userdata['user_id'], time(), '', $is_starter, $is_latest);
		}
		else if ($post_mode == 'edit_last_post' || $post_mode == 'edit_topic' || $first_post_has_topic_info)
		{
			if ($post_visibility == ITEM_APPROVED || $data['topic_visibility'] == $post_visibility)
			{
				// only the subject can be changed from edit
				$sql_data[TOPICS_TABLE]['stat'][] = "topic_last_post_subject = '" . $db->sql_escape($subject) . "'";

				// Maybe not only the subject, but also changing anonymous usernames. ;)
				if ($data['poster_id'] == ANONYMOUS)
				{
					$sql_data[TOPICS_TABLE]['stat'][] = "topic_last_poster_name = '" . $db->sql_escape($username) . "'";
				}

				if ($post_visibility == ITEM_APPROVED)
				{
					// this does not _necessarily_ mean that we must update the info again,
					// it just means that we might have to
					$sql = 'SELECT forum_last_post_id, forum_last_post_subject
						FROM ' . FORUMS_TABLE . '
						WHERE forum_id = ' . (int) $data['forum_id'];
					$result = $db->sql_query($sql);
					$row = $db->sql_fetchrow($result);
					$db->sql_freeresult($result);

					// this post is the latest post in the forum, better update
					if ($row['forum_last_post_id'] == $data['post_id'] && ($row['forum_last_post_subject'] !== $subject || $data['poster_id'] == ANONYMOUS))
					{
						// the post's subject changed
						if ($row['forum_last_post_subject'] !== $subject)
						{
							$sql_data[FORUMS_TABLE]['stat'][] = "forum_last_post_subject = '" . $db->sql_escape($subject) . "'";
						}

						// Update the user name if poster is anonymous... just in case a moderator changed it
						if ($data['poster_id'] == ANONYMOUS)
						{
							$sql_data[FORUMS_TABLE]['stat'][] = "forum_last_poster_name = '" . $db->sql_escape($username) . "'";
						}
					}
				}
			}
		}

		// Update forum stats
		$where_sql = array(
			POSTS_TABLE => 'post_id = ' . $data['post_id'],
			TOPICS_TABLE => 'topic_id = ' . $data['topic_id'],
			FORUMS_TABLE => 'forum_id = ' . $data['forum_id'],
			USERS_TABLE => 'user_id = ' . $poster_id
		);

		foreach ($sql_data as $table => $update_ary)
		{
			if (isset($update_ary['stat']) && implode('', $update_ary['stat']))
			{
				$sql = "UPDATE $table SET " . implode(', ', $update_ary['stat']) . ' WHERE ' . $where_sql[$table];
				$db->sql_query($sql);
			}
		}

		// Delete topic shadows (if any exist). We do not need a shadow topic for an global announcement
		if ($topic_type == POST_GLOBAL)
		{
			$sql = 'DELETE FROM ' . TOPICS_TABLE . '
							WHERE topic_moved_id = ' . $data['topic_id'];
			$db->sql_query($sql);
		}

		// Committing the transaction before updating search index
		$db->sql_transaction('commit');

		// Delete draft if post was loaded...
		$draft_id = request_var('draft_loaded', 0);
		if ($draft_id)
		{
			$sql = 'DELETE FROM ' . DRAFTS_TABLE . "
								WHERE draft_id = $draft_id
								AND user_id = {$user->data['user_id']}";
			$db->sql_query($sql);
		}

		// Index message contents
		if ($update_search_index && $data['enable_indexing'])
		{
			// Select the search method and do some additional checks to ensure it can actually be utilised
			$search_type = $config['search_type'];

			if (! class_exists($search_type))
			{
				trigger_error('NO_SUCH_SEARCH_MODULE');
			}

			$error = false;
			$search = new $search_type($error, $phpbb_root_path, $phpEx, $auth, $config, $db, $user, $phpbb_dispatcher);

			if ($error)
			{
				trigger_error($error);
			}

			$search->index($mode, $data['post_id'], $data['message'], $subject, $poster_id, $data['forum_id']);
		}

		// Topic Notification, do not change if moderator is changing other users posts...
		if ($userdata['user_id'] == $poster_id)
		{
			if (! $data['notify_set'] && $data['notify'])
			{
				$sql = 'INSERT INTO ' . TOPICS_WATCH_TABLE . ' (user_id, topic_id)
					VALUES (' . $user->data['user_id'] . ', ' . $data['topic_id'] . ')';
				$db->sql_query($sql);
			}
			else if (($config['email_enable'] || $config['jab_enable']) && $data['notify_set'] && ! $data['notify'])
			{
				$sql = 'DELETE FROM ' . TOPICS_WATCH_TABLE . '
					WHERE user_id = ' . $user->data['user_id'] . '
						AND topic_id = ' . $data['topic_id'];
				$db->sql_query($sql);
			}
		}

		if ($mode == 'post' || $mode == 'reply' || $mode == 'quote')
		{
			// Mark this topic as posted to
			markread('post', $data['forum_id'], $data['topic_id']);
		}

		// Mark this topic as read
		// We do not use post_time here, this is intended (post_time can have a date in the past if editing a message)
		markread('topic', $data['forum_id'], $data['topic_id'], time());

		//
		if ($config['load_db_lastread'] && $userdata['is_registered'])
		{
			$sql = 'SELECT mark_time
					FROM ' . FORUMS_TRACK_TABLE . '
					WHERE user_id = ' . $userdata['user_id'] . '
				AND forum_id = ' . $data['forum_id'];
			$result = $db->sql_query($sql);
			$f_mark_time = (int) $db->sql_fetchfield('mark_time');
			$db->sql_freeresult($result);
		}
		else if ($config['load_anon_lastread'] || $userdata['is_registered'])
		{
			$f_mark_time = false;
		}

		if (($config['load_db_lastread'] && $user->data['is_registered']) || $config['load_anon_lastread'] || $userdata['is_registered'])
		{
			// Update forum info
			$sql = 'SELECT forum_last_post_time
				FROM ' . FORUMS_TABLE . '
				WHERE forum_id = ' . $data['forum_id'];
			$result = $db->sql_query($sql);
			$forum_last_post_time = (int) $db->sql_fetchfield('forum_last_post_time');
			$db->sql_freeresult($result);

			update_forum_tracking_info($data['forum_id'], $forum_last_post_time, $f_mark_time, false);
		}

		// If a username was supplied or the poster is a guest, we will use the supplied username.
		// Doing it this way we can use "...post by guest-username..." in notifications when
		// "guest-username" is supplied or ommit the username if it is not.
		$username = ($username !== '' || ! $userdata['is_registered']) ? $username : $userdata['username'];

		// Send Notifications
		$notification_data = array_merge($data, array(
			'topic_title' => (isset($data['topic_title'])) ? $data['topic_title'] : $subject,
			'post_username' => $username,
			'poster_id' => $poster_id,
			'post_text' => $data['message'],
			'post_time' => $current_time,
			'post_subject' => $subject
		));

		$phpbb_notifications = $phpbb_container->get('notification_manager');

		if ($post_visibility == ITEM_APPROVED)
		{
			switch ($mode)
			{
				case 'post':
					$phpbb_notifications->add_notifications(array(
						'notification.type.quote',
						'notification.type.topic'
					), $notification_data);
					break;

				case 'reply':
				case 'quote':
					$phpbb_notifications->add_notifications(array(
						'notification.type.quote',
						'notification.type.bookmark',
						'notification.type.post'
					), $notification_data);
					break;

				case 'edit_topic':
				case 'edit_first_post':
				case 'edit':
				case 'edit_last_post':
					$phpbb_notifications->update_notifications(array(
						'notification.type.quote',
						'notification.type.bookmark',
						'notification.type.topic',
						'notification.type.post'
					), $notification_data);
					break;
			}
		}
		else if ($post_visibility == ITEM_UNAPPROVED)
		{
			switch ($mode)
			{
				case 'post':
					$phpbb_notifications->add_notifications('notification.type.topic_in_queue', $notification_data);
					break;

				case 'reply':
				case 'quote':
					$phpbb_notifications->add_notifications('notification.type.post_in_queue', $notification_data);
					break;

				case 'edit_topic':
				case 'edit_first_post':
				case 'edit':
				case 'edit_last_post':

					// Nothing to do here
					break;
			}
		}
		else if ($post_visibility == ITEM_REAPPROVE)
		{
			switch ($mode)
			{
				case 'edit_topic':
				case 'edit_first_post':
					$phpbb_notifications->add_notifications('notification.type.topic_in_queue', $notification_data);

					// Delete the approve_post notification so we can notify the user again,
					// when his post got reapproved
					$phpbb_notifications->delete_notifications('notification.type.approve_post', $notification_data['post_id']);
					break;

				case 'edit':
				case 'edit_last_post':
					$phpbb_notifications->add_notifications('notification.type.post_in_queue', $notification_data);

					// Delete the approve_post notification so we can notify the user again,
					// when his post got reapproved
					$phpbb_notifications->delete_notifications('notification.type.approve_post', $notification_data['post_id']);
					break;

				case 'post':
				case 'reply':
				case 'quote':

					// Nothing to do here
					break;
			}
		}
		else if ($post_visibility == ITEM_DELETED)
		{
			switch ($mode)
			{
				case 'post':
				case 'reply':
				case 'quote':
				case 'edit_topic':
				case 'edit_first_post':
				case 'edit':
				case 'edit_last_post':

					// Nothing to do here
					break;
			}
		}

		$params = $add_anchor = '';

		if ($post_visibility == ITEM_APPROVED)
		{
			$params .= '&amp;t=' . $data['topic_id'];

			if ($mode != 'post')
			{
				$params .= '&amp;p=' . $data['post_id'];
				$add_anchor = '#p' . $data['post_id'];
			}
		}
		else if ($mode != 'post' && $post_mode != 'edit_first_post' && $post_mode != 'edit_topic')
		{
			$params .= '&amp;t=' . $data['topic_id'];
		}

		$url = (! $params) ? "{$phpbb_root_path}viewforum.$phpEx" : "{$phpbb_root_path}viewtopic.$phpEx";
		$url = append_sid($url, 'f=' . $data['forum_id'] . $params) . $add_anchor;

		/**
		 * This event is used for performing actions directly after a post or topic
		 * has been submitted.
		 * When a new topic is posted, the topic ID is
		 * available in the $data array.
		 *
		 * The only action that can be done by altering data made available to this
		 * event is to modify the return URL ($url).
		 *
		 * @event core.submit_post_end
		 *
		 * @var string containing posting mode value
		 * @var string containing post subject value
		 * @var string containing post author name
		 * @var int containing topic type value
		 * @var array with the poll data for the post
		 * @var array with the data for the post
		 * @var int containing up to date post visibility
		 * @var bool indicating if the post will be updated
		 * @var bool indicating if the search index will be updated
		 * @var string "Return to topic" URL
		 *
		 * @since 3.1.0-a3
		 *        @change 3.1.0-RC3 Added vars mode, subject, username, topic_type,
		 *        poll, update_message, update_search_index
		 */
		$vars = array(
			'mode',
			'subject',
			'username',
			'topic_type',
			'poll',
			'data',
			'post_visibility',
			'update_message',
			'update_search_index',
			'url'
		);
		extract($phpbb_dispatcher->trigger_event('core.submit_post_end', compact($vars)));

		return $url;
	}

	protected function get_userdata($user_id)
	{
		global $db;
		$sql = 'SELECT username, user_colour, user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $user_id;
		$result = $db->sql_query($sql);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if(!$row)
		{
			$sql = 'SELECT username, user_colour, user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) ANONYMOUS;
			$result = $db->sql_query($sql);
			$row = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);
			$row['is_registered'] = false;
		}
		else
		{
			$row['is_registered'] = ($user_id == ANONYMOUS) ? false : true;
		}
		return $row;
	}
}