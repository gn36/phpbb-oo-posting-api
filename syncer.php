<?php

namespace gn36\functions_post_oo;

include_once($phpbb_root_path . 'includes/functions_posting.' . $phpEx);
include_once($phpbb_root_path . 'includes/functions_admin.' . $phpEx);

class syncer
{
	var $data = array();
	var $topic_first_post = array();
	var $topic_last_post = array();
	var $forum_last_post = array();
	var $topic_reported = array();
	var $topic_attachment = array();
	var $check_topic_empty = array();
	var $new_topic_flag = false;

	/**@access private*/
	function init($type, $id)
	{
		if(!isset($this->data[$type]))
		{
			$this->data[$type] = array();
		}
		if(!isset($this->data[$type][$id]))
		{
			$this->data[$type][$id]['set'] = array();
			$this->data[$type][$id]['add'] = array();
			$this->data[$type][$id]['sql'] = array();
		}
	}

	/**increments or decrements a field.
	 * @param $type which table (topic, user, forum or post)
	 * @param $id topic_id, user_id etc.
	 * @param $field field name (e.g. topic_replies)
	 * @param $amount how much to add/subtract (default 1)
	 * example: $sync->add('topic', 123, 'topic_replies', 1)
	 * -> UPDATE phpbb_topics SET topic_replies = topic_replies + 1 WHERE topic_id = 123*/
	function add($type, $id, $field, $amount = 1)
	{
		$this->init($type, $id);
		if(!isset($this->data[$type][$id]['add'][$field]))
		{
			$this->data[$type][$id]['add'][$field] = 0;
		}
		$this->data[$type][$id]['add'][$field] += $amount;
	}

	function set($type, $id, $field, $value = false)
	{
		$this->init($type, $id);
		if(is_array($field))
		{
			$this->data[$type][$id]['set'] += $field;
		}
		else
		{
			$this->data[$type][$id]['set'][$field] = $value;
		}
	}

	function topic_first_post($topic_id)
	{
		$this->topic_first_post[] = $topic_id;
	}

	function topic_last_post($topic_id)
	{
		$this->topic_last_post[] = $topic_id;
	}

	function forum_last_post($forum_id)
	{
		$this->forum_last_post[] = $forum_id;
	}

	function topic_reported($topic_id)
	{
		$this->topic_reported[] = $topic_id;
	}

	function topic_attachment($topic_id)
	{
		$this->topic_attachment[] = $topic_id;
	}

	function check_topic_empty($topic_id)
	{
		$this->check_topic_empty[] = $topic_id;
	}

	function update_first_last_post()
	{
		global $db;

		//topic_first_post
		$this->topic_first_post = array_unique($this->topic_first_post);
		foreach($this->topic_first_post as $topic_id)
		{
			$sql = 'SELECT p.post_id, p.post_visibility, p.poster_id, p.post_subject, p.post_username, p.post_time, u.username, u.user_colour
					FROM ' . POSTS_TABLE . ' p, ' . USERS_TABLE . ' u
					WHERE p.topic_id=' . $topic_id . '
					AND u.user_id = p.poster_id
					ORDER BY post_time ASC';
			$result = $db->sql_query_limit($sql, 1);
			if($row = $db->sql_fetchrow($result))
			{
				$this->set('topic', $topic_id, array(
					'topic_time'				=> $row['post_time'],
					'topic_poster'				=> $row['poster_id'],
					//'topic_visibility'			=> $row['post_visibility'],
					'topic_first_post_id'		=> $row['post_id'],
					'topic_first_poster_name'	=> ($row['poster_id'] == ANONYMOUS) ? $row['post_username'] : $row['username'],
					'topic_first_poster_colour'	=> $row['user_colour']
				));
			}
		}

		//topic_last_post
		if(count($this->topic_last_post))
		{
			$update_sql = update_post_information('topic', $this->topic_last_post, true);
			foreach($update_sql as $topic_id => $sql)
			{
				$this->init('topic', $topic_id);
				$this->data['topic'][$topic_id]['sql'] += $sql;
			}
		}

		//forum_last_post
		if(count($this->forum_last_post))
		{
			$update_sql = update_post_information('forum', $this->forum_last_post, true);
			foreach($update_sql as $forum_id => $sql)
			{
				$this->init('forum', $forum_id);
				$this->data['forum'][$forum_id]['sql'] += $sql;
			}
		}
	}

	function sync_reported_attachment() {
		//no need to check for empty array or apply array_unique here - sync() already does that
		sync('topic_reported', 'topic_id', $this->topic_reported);
		sync('topic_attachment', 'topic_id', $this->topic_attachment);
	}

	function delete_empty_topics() {
		global $db;
		$this->check_topic_empty = array_unique($this->check_topic_empty);
		if(count($this->check_topic_empty) > 0) {
			//get list of topics that still have posts
			$sql = 'SELECT DISTINCT topic_id FROM ' . POSTS_TABLE . ' WHERE ' . $db->sql_in_set('topic_id', $this->check_topic_empty);
			$result = $db->sql_query($sql);
			$not_empty_topics = array();
			while($row = $db->sql_fetchrow($result)) {
				$not_empty_topics[] = $row['topic_id'];
			}
			$db->sql_freeresult($result);

			//the difference are the topics which don't have posts anymore
			$empty_topics = array_diff($this->check_topic_empty, $not_empty_topics);

			if(count($empty_topics) > 0) {
				delete_topics('topic_id', $empty_topics);

				//no need to sync deleted topics anymore
				foreach ($empty_topics as $topic_id) {
					unset($this->data['topic'][$topic_id]);
				}
			}
		}
	}

	function execute()
	{
		global $db;

		$this->update_first_last_post();

		$this->sync_reported_attachment();

		$this->delete_empty_topics();

		$sql_array = array();

		$tables = array(
			'topic'	=> array('table' => TOPICS_TABLE, 'key' => 'topic_id'),
			'user'	=> array('table' => USERS_TABLE, 'key' => 'user_id'),
			'forum'	=> array('table' => FORUMS_TABLE, 'key' => 'forum_id'),
			'post'	=> array('table' => POSTS_TABLE, 'key' => 'post_id')
		);

		foreach($this->data as $type => $items)
		{
			foreach($items as $id => $item)
			{
				$sql = 'UPDATE ' . $tables[$type]['table'] . ' SET ';
				if(count($item['set']))
				{
					$sql .= $db->sql_build_array('UPDATE', $item['set']) . ', ';
				}
				if(count($item['add']))
				{
					//build ', field = field + 1' style queries
					foreach($item['add'] as $field => $value)
					{
						$value = intval($value);
						/*if($value == 0)
						 {
							continue;
						}*/
						$sql .= "$field = $field " . ($value < 0 ? '-' : '+') . abs($value) . ', ';
					}
				}
				if(count($item['sql']))
				{
					$sql .= implode(', ', $item['sql']) . ', ';
				}
				$sql = substr($sql, 0, -2);
				$sql .= ' WHERE ' . $tables[$type]['key'] . ' = ' . $id;
				$sql_array[] = $sql;
			}
		}

		foreach($sql_array as $sql)
		{
			$db->sql_query($sql);
		}
	}
}
