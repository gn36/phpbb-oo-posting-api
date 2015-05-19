<?php

namespace gn36\functions_post_oo;

include_once($phpbb_root_path . 'includes/functions_content.' . $phpEx);
include_once($phpbb_root_path . 'includes/functions_privmsgs.' . $phpEx);

class privmsg
{
	var $msg_id;
	var $root_level = 0;
	var $reply_from_msg_id = 0;

	var $message_time;
	var $author_id;
	var $author_ip;
	//var $to_address;
	//var $bcc_address;

	/**list of recipients in the form:
	 * array(
	 *   'u' => array(
	 *     12 => 'to',  //user_id 12 as to
	 *     34 => 'bcc'  //user_id 34 as bcc
	 * 	 ),
	 *   'g' => array(
	 *     56 => 'to',  //group_id 56 as to
	 *     78 => 'bcc'  //group_id 78 as bcc
	 *   )
	 * )*/
	var $address_list = array('u'=>array(), 'g'=> array());

	var $icon_id;
	var $enable_bbcode = 1;
	var $enable_smilies = 1;
	var $enable_magic_url = 1;
	var $enable_sig = 1;

	var $message_subject = '';
	var $message_text = '';
	var $message_edit_reason = '';
	var $message_edit_user = 0;
	var $message_edit_time = 0;
	var $message_edit_count = 0;

	var $message_reported = 0;

	//var $message_attachment = 0;

	function __construct()
	{

	}

	/**initializes and returns a new privmsg object as reply to the message with msg_id $msg_id*/
	static function reply_to($msg_id, $quote = true)
	{
		global $db;

		$sql = "SELECT p.msg_id, p.root_level, p.author_id, p.message_subject, p.message_text, p.bbcode_uid, p.to_address, p.bcc_address, u.username
				FROM " . PRIVMSGS_TABLE . " p
				LEFT JOIN " . USERS_TABLE . " u ON (p.author_id = u.user_id)
				WHERE msg_id = " . intval($msg_id);
		$result = $db->sql_query($sql);
		$row = $db->sql_fetchrow($result);
		if(!$row)
		{
			trigger_error('NO_PRIVMSG', E_USER_ERROR);
		}

		$privmsg = new privmsg();
		$privmsg->reply_from_msg_id = $row['msg_id'];
		$privmsg->root_level = ($row['root_level'] ? $row['root_level'] : $row['msg_id']);
		$privmsg->message_subject = ((!preg_match('/^Re:/', $row['message_subject'])) ? 'Re: ' : '') . censor_text($row['message_subject']);

		if($quote)
		{
			decode_message($row['message_text'], $row['bbcode_uid']);
			//for some reason we need &quot; here instead of "
			$privmsg->message_text = '[quote=&quot;' . $row['username'] . '&quot;]' . censor_text(trim($row['message_text'])) . "[/quote]\n";
		}

		//add original sender as recipient
		$privmsg->to($row['author_id']);

		//if message had only a single recipient, use that as sender
		if($row['to_address'] == '' || $row['bcc_address'] == '')
		{
			$to = ($row['to_address'] != '') ? $row['to_address'] : $row['bcc_address'];
			if(preg_match('#^u_(\\d+)$#', $to, $m))
			{
				$privmsg->author_id = $m[1];
			}
		}

		return $privmsg;
	}

	/**adds a recipient. arguments can be (in any order):
	 * - 'to':  set type to 'to' (default)
	 * - 'bcc': set type to 'bcc'
	 * - integer: a user_id or group_id
	 * - 'u' or 'user':	 the number is a user_id (default)
	 * - 'g' or 'group': the number is a group_id
	 * e.g. $pm->to('user', 'to', 123);
	 * */
	function to()
	{
		$type = 'to';
		$ug_type = 'u';
		$id = 0;

		$args = func_get_args();
		$args = array_map('strtolower', $args);

		foreach($args as $arg)
		{
			switch($arg)
			{
				case 'to': $type = 'to'; break;
				case 'bcc': $type = 'bcc'; break;
				case 'user': case 'u': $ug_type = 'u'; break;
				case 'group': case 'g': $ug_type = 'g'; break;
			}
			if(is_numeric($arg)) $id = intval($arg);
		}

		if($id == 0)
		{
			trigger_error('privmsg->to(): no id given', E_USER_ERROR);
		}
		$this->address_list[$ug_type][$id] = $type;
	}

	function get($id)
	{
		trigger_error('not yet implemented', E_USER_ERROR);
	}

	function submit()
	{
		global $user, $db;

		if(!$this->msg_id)
		{
			//new message, set some default values if not set yet
			if(!$this->author_id) $this->author_id = $user->data['user_id'];
			if(!$this->author_ip) $this->author_ip = $user->ip;
			if(!$this->message_time) $this->message_time = time();
		}

		$this->message_subject = truncate_string($this->message_subject);

		if($user->data['user_id'] == $this->author_id)
		{
			$author_username = $user->data['username'];
		}
		else
		{
			$sql = 'SELECT username FROM ' . USERS_TABLE . ' WHERE user_id=' . $this->author_id;
			$result = $db->sql_query($sql);
			$row = $db->sql_fetchrow($result);
			if(!$row)
			{
				trigger_error('NO_USER', E_USER_ERROR);
			}
			$author_username = $row['username'];
		}

		$message = $this->message_text;
		$bbcode_uid = $bbcode_bitfield = $options = '';
		generate_text_for_storage($message, $bbcode_uid, $bbcode_bitfield, $options, $this->enable_bbcode, $this->enable_magic_url, $this->enable_smilies);

		$data = array(
			'msg_id'				=> (int) $this->msg_id,
			'from_user_id'			=> (int) $this->author_id,
			'from_user_ip'			=> $this->author_ip,
			'from_username'			=> $author_username,
			'reply_from_root_level'	=> $this->root_level,
			'reply_from_msg_id'		=> $this->reply_from_msg_id,
			'icon_id'				=> (int) $this->icon_id,
			'enable_sig'			=> (bool) $this->enable_sig,
			'enable_bbcode'			=> (bool) $this->enable_bbcode,
			'enable_smilies'		=> (bool) $this->enable_smilies,
			'enable_urls'			=> (bool) $this->enable_magic_url,
			'bbcode_bitfield'		=> $bbcode_bitfield,
			'bbcode_uid'			=> $bbcode_uid,
			'message'				=> $message,
			'attachment_data'		=> false,
			'filename_data'			=> false,
			'address_list'			=> $this->address_list
		);

		$mode = ($this->msg_id) ? 'edit' : ($this->reply_from_msg_id ? 'reply' : 'post');
		submit_pm($mode, $this->message_subject, $data);
		$this->msg_id = $data['msg_id'];
	}

	function delete()
	{
		trigger_error('not yet implemented', E_USER_ERROR);
	}
}
