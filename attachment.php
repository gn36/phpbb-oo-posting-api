<?php

namespace gn36\functions_post_oo;

class attachment
{
	//TODO
	var $attach_id;
	var $post_msg_id = 0;
	var $topic_id = 0;
	var $in_message = 0;
	var $poster_id = 0;
	var $is_orphan = 1;
	var $physical_filename = '';
	var $real_filename = '';
	var $download_count = 0;
	var $attach_comment = '';
	var $extension = '';
	var $mimetype = '';
	var $filesize = 0;
	var $filetime = 0;
	var $thumbnail = 0;

	function __construct()
	{
		//dont call new attachment(), call attachment::create() or attachment::create_checked()
	}

	/**directly creats an attachment (bypassing checks like allowed extensione etc.)*/
	function create($file, $filename)
	{
		//TODO
		/*global $user;

		$attachment = new attachment();
		if($user)
		{
		$attachment->poster_id = $user->data['user_id'];
		}
		$upload = new fileupload();
		$upload->local_upload($file);

		$attachment->real_filename = $filename;
		copy($file, $attachment->get_file());

		return $attachment;*/
	}

	/**creates an attachment through the phpBB function upload_attachment. i.e.
	 * quota, allowed extensions etc. will be checked.
	 * returns an attachment object on success or an array of error messages on failure
	 * submit() is automatically called so that this attachment appears in the acp
	 * "orphaned attachments" list if you dont assign it to a post.
	 * WARNING: $file will be moved to the attachment storage*/
	function create_checked($file, $forum_id, $mimetype = 'application/octetstream')
	{
		global $user;

		if(!file_exists($file))
		{
			trigger_error('FILE_NOT_FOUND', E_USER_ERROR);
		}

		$filedata = array(
			'realname'	=> basename($file),
			'size'		=> filesize($file),
			'type'		=> $mimetype
		);
		$filedata = upload_attachment(false, $forum_id, true, $file, false, $filedata);
		if ($filedata['post_attach'] && !sizeof($filedata['error']))
		{
			$attachment = new attachment();
			$attachment->poster_id = $user->data['user_id'];
			$attachment->physical_filename = $filedata['physical_filename'];
			$attachment->real_filename = $filedata['real_filename'];
			$attachment->extension = $filedata['extension'];
			$attachment->mimetype = $filedata['mimetype'];
			$attachment->filesize = $filedata['filesize'];
			$attachment->filetime = $filedata['filetime'];
			$attachment->thumbnail = $filedata['thumbnail'];
			$attachment->submit();
			return $attachment;
		}
		else {
			trigger_error(implode('<br/>', $filedata['error']), E_USER_ERROR);
		}
	}

	function get($attach_id)
	{
		global $db;
		$sql = "SELECT * FROM " . ATTACHMENTS_TABLE . " WHERE attach_id=" . intval($attach_id);
		$result = $db->sql_query($sql);
		$attach_data = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if(!$attach_data)
		{
			//attachment does not exist, return false
			return false;
		}


		return attachment::from_array($attach_data);
	}

	function from_array($data)
	{
		$attachment = new attachment();
		$attachment->attach_id = $data['attach_id'];
		$attachment->post_msg_id = $data['post_msg_id'];
		$attachment->topic_id = $data['topic_id'];
		$attachment->in_message = $data['in_message'];
		$attachment->poster_id = $data['poster_id'];
		$attachment->is_orphan = $data['is_orphan'];
		$attachment->physical_filename = $data['physical_filename'];
		$attachment->real_filename = $data['real_filename'];
		$attachment->download_count = $data['download_count'];
		$attachment->attach_comment = $data['attach_comment'];
		$attachment->extension = $data['extension'];
		$attachment->mimetype = $data['mimetype'];
		$attachment->filesize = $data['filesize'];
		$attachment->filetime = $data['filetime'];
		$attachment->thumbnail = $data['thumbnail'];
		return $attachment;
	}

	function submit()
	{
		global $config, $db, $auth, $user;

		if(!$this->attach_id)
		{
			//new attachment, set some default values if not set yet
			if(!$this->poster_id) $this->poster_id = $user->data['user_id'];
			if(!$this->filetime) $this->filetime = time();
		}

		$sql_data = array(
			'post_msg_id'		=> $this->post_msg_id,
			'topic_id'			=> $this->topic_id,
			'in_message'		=> $this->in_message,
			'poster_id'			=> $this->poster_id,
			'is_orphan'			=> $this->is_orphan,
			'physical_filename'	=> $this->physical_filename,
			'real_filename'		=> $this->real_filename,
			//'download_count'	=> $this->download_count,
			'attach_comment'	=> $this->attach_comment,
			'extension'			=> $this->extension,
			'mimetype'			=> $this->mimetype,
			'filesize'			=> $this->filesize,
			'filetime'			=> $this->filetime,
			'thumbnail'			=> $this->thumbnail
		);

		$update_post_topic = false;

		if($this->attach_id)
		{
			//edit
			$sql = "SELECT * FROM " . ATTACHMENTS_TABLE . " WHERE attach_id=" . $this->attach_id;
			$result = $db->sql_query($sql);
			$attach_data = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);

			if(!$attach_data)
			{
				trigger_error("attach_id={$this->attach_id}, but that attachment does not exist", E_USER_ERROR);
			}

			$sql = "UPDATE " . ATTACHMENTS_TABLE . " SET " . $db->sql_build_array('UPDATE', $sql_data) . " WHERE attach_id=" . $this->attach_id;
			$db->sql_query($sql);

			if($attach_data['post_msg_id'] != $this->post_msg_id || $attach_data['topic_id'] != $this->topic_id)
			{
				$update_post_topic = true;
			}
		}
		else
		{
			//insert attachment
			$sql = "INSERT INTO " . ATTACHMENTS_TABLE . " " . $db->sql_build_array('INSERT', $sql_data);
			$db->sql_query($sql);
			$this->attach_id = $db->sql_nextid();

			$update_post_topic = true;
		}

		if($update_post_topic)
		{
			//update post and topic tables
			if($this->topic_id)
			{
				$sql = 'UPDATE ' . TOPICS_TABLE . ' SET topic_attachment=1 WHERE topic_id=' . $this->topic_id;
				$db->sql_query($sql);
			}
			if($this->post_msg_id)
			{
				$sql = 'UPDATE ' . POSTS_TABLE . ' SET post_attachment=1 WHERE post_id=' . $this->post_msg_id;
				$db->sql_query($sql);
			}
		}
	}

	function delete()
	{
		delete_attachments('attach', $this->attach_id);
	}

	function get_file()
	{
		global $phpbb_root_path, $config;
		return $phpbb_root_path . $config['upload_path'] . '/' . $this->physical_filename;
	}

	function get_thumbnail()
	{
		global $phpbb_root_path, $config;
		return $phpbb_root_path . $config['upload_path'] . '/thumb_' . $this->physical_filename;
	}
}
